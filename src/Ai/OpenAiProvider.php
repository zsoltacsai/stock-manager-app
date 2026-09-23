<?php

declare(strict_types=1);

require_once __DIR__ . '/AiProviderInterface.php';
require_once __DIR__ . '/AiProviderException.php';
require_once __DIR__ . '/ToolDefinition.php';
require_once __DIR__ . '/ToolCall.php';

/**
 * OpenAI Responses API (https://api.openai.com/v1/responses) kliens —
 * lásd AiProviderInterface docblokkja a szerződésért, és AnthropicProvider
 * docblokkja az azonos szerepű testvér-implementációért. MINDEN OpenAI-
 * specifikus protokoll-részlet (a "responses" végpont "input" tömbje,
 * function_call/function_call_output elem-típusok, lapos — NEM
 * "function" kulcs alá ágyazott — eszköz-séma) KIZÁRÓLAG ebben az
 * osztályban van — az AgentRunner/ConversationManager a generikus,
 * Ollama/OpenAI-stílusú belső üzenet-alakot látja, sosem az OpenAI-natív
 * szerkezetet.
 *
 * Fontos, a hivatalos dokumentáció (implementáció idején lekérdezve —
 * lásd README "AI asszisztens" szakasza) alapján hozott döntések:
 *
 * 1) NINCS "previous_response_id"-alapú szerver-oldali állapot-láncolás
 *    használva — a hivatalos "function calling" útmutató saját, ajánlott
 *    mintája szerint a TELJES "input" listát (a korábbi assistant/
 *    function_call/function_call_output elemekkel együtt) újraküldjük
 *    minden hívásnál, ugyanúgy, mint az Anthropic/Ollama providerek — ez
 *    közvetlenül illeszkedik a MEGLÉVŐ ConversationManager/AgentRunner
 *    "mindig a teljes előzményt küldd újra" tervéhez, nincs szükség egy
 *    genuinely eltérő állapot-modellre (lásd a kör 9. pontja — nincs
 *    kimutatott interfész-hiány, ami ezt indokolná).
 * 2) `store: false` mindig — mivel sose használunk `previous_response_id`-t,
 *    nincs szükség arra, hogy az OpenAI a válaszainkat (alapértelmezetten
 *    legalább 30 napig) a szerverén tárolja.
 * 3) A tool-eredmény-folytatás EGY `function_call_output` elem
 *    HÍVÁSONKÉNT (nem egyetlen, csoportosított üzenetbe bundle-özve,
 *    ELLENTÉTBEN az Anthropic-kal) — ez pontosan megegyezik a
 *    ConversationManager::addToolResult() meglévő, hívásonkénti
 *    'tool'-üzenet mintájával, ezért itt NEM kell semmilyen
 *    összegyűjtő/buffer logika (lásd AnthropicProvider::translateMessages()
 *    kontrasztban).
 */
final class OpenAiProvider implements AiProviderInterface
{
    public function __construct(
        private readonly string $baseUrl,
        private readonly string $apiKey,
        private readonly string $model,
        private readonly int $timeoutSeconds = 30,
        private readonly ?int $maxOutputTokens = null,
    ) {
    }

    public function name(): string
    {
        return 'openai';
    }

    public function chat(array $messages, array $tools): AiChatResponse
    {
        [$instructions, $input] = self::translateMessages($messages);

        $body = [
            'model' => $this->model,
            'input' => $input,
            // Lásd az osztály docblokkja — sose használunk
            // previous_response_id-t, ezért nincs szükség szerver-oldali
            // tárolásra.
            'store' => false,
        ];
        if ($instructions !== '') {
            $body['instructions'] = $instructions;
        }
        if ($tools) {
            // Az OpenAI Responses API a function-eszközöket LAPOSAN várja
            // (type/name/description/parameters közvetlenül a tömb-elemen),
            // NEM egy beágyazott "function" kulcs alatt, ellentétben az
            // Ollama-stílusú, ToolRegistry::toProviderToolList()-nak
            // megfelelő alakkal — ezért itt közvetlenül a ToolDefinition-
            // ökből építjük, nem a kényelmi metódusból.
            $body['tools'] = array_map(static fn (ToolDefinition $t) => [
                'type' => 'function',
                'name' => $t->name,
                'description' => $t->description,
                'parameters' => $t->inputSchema,
            ], $tools);
        }
        // A max_output_tokens az OpenAI Responses API-nál OPCIONÁLIS
        // (ellentétben az Anthropic KÖTELEZŐ max_tokens mezőjével) — csak
        // akkor küldjük, ha az admin explicit beállított egy pozitív
        // értéket.
        if ($this->maxOutputTokens !== null && $this->maxOutputTokens > 0) {
            $body['max_output_tokens'] = $this->maxOutputTokens;
        }

        $decoded = $this->executeRequest($body);

        if (!isset($decoded['output']) || !is_array($decoded['output'])) {
            throw new AiProviderException('Az OpenAI válasza váratlan szerkezetű (hiányzó "output" mező).', 'malformed_response');
        }

        $textParts = [];
        $toolCalls = [];
        foreach ($decoded['output'] as $item) {
            if (!is_array($item)) {
                continue;
            }
            $type = $item['type'] ?? '';
            if ($type === 'message') {
                foreach (($item['content'] ?? []) as $block) {
                    if (is_array($block) && ($block['type'] ?? '') === 'output_text') {
                        $textParts[] = (string) ($block['text'] ?? '');
                    }
                }
            } elseif ($type === 'function_call') {
                $name = (string) ($item['name'] ?? '');
                if ($name === '') {
                    continue;
                }
                $callId = (string) ($item['call_id'] ?? '');
                $argumentsRaw = $item['arguments'] ?? '{}';
                $arguments = is_string($argumentsRaw) ? json_decode($argumentsRaw, true) : $argumentsRaw;
                $toolCalls[] = new ToolCall(
                    $callId !== '' ? $callId : ('call_' . bin2hex(random_bytes(8))),
                    $name,
                    is_array($arguments) ? $arguments : []
                );
            }
            // Egyéb elem-típusok (pl. 'reasoning') a kör 7. pontja szerint
            // szándékosan figyelmen kívül maradnak — az InventoryAgent
            // system promptja/üzleti logikája nem támaszkodik rájuk.
        }

        $content = $textParts ? implode("\n", array_filter($textParts, static fn ($t) => $t !== '')) : null;
        return new AiChatResponse($content === '' ? null : $content, $toolCalls);
    }

    public function checkAvailability(): AiAvailability
    {
        if (trim($this->apiKey) === '') {
            return AiAvailability::notConfigured('Nincs megadva OpenAI API-kulcs.');
        }

        try {
            $decoded = $this->executeGet('/v1/models?limit=100');
        } catch (AiProviderException $e) {
            if ($e->kind === 'auth_error') {
                return AiAvailability::authError('Érvénytelen OpenAI API-kulcs.');
            }
            return AiAvailability::unavailable('Az OpenAI API jelenleg nem érhető el: ' . match ($e->kind) {
                'timeout' => 'időtúllépés.',
                default => 'kapcsolódási hiba.',
            });
        }

        if (!is_array($decoded) || !isset($decoded['data']) || !is_array($decoded['data'])) {
            return AiAvailability::unavailable('Az OpenAI API válasza váratlan szerkezetű.');
        }

        $modelIds = array_map(static fn ($m) => (string) ($m['id'] ?? ''), $decoded['data']);
        if (in_array($this->model, $modelIds, true)) {
            return AiAvailability::available();
        }

        return AiAvailability::modelError("A konfigurált modell (\"{$this->model}\") nem szerepel az elérhető OpenAI modellek listájában.");
    }

    /**
     * A generikus, Ollama/OpenAI-stílusú belső üzenet-tömböt fordítja le
     * az OpenAI Responses API "instructions" + "input" alakjára — lásd az
     * osztály docblokkja. A 'tool' szerepű belső üzeneteket EGYENKÉNT,
     * KÜLÖN `function_call_output` elemmé alakítja (nincs Anthropic-
     * stílusú csoportosítás/buffer, mert a Responses API ezt nem várja
     * el).
     *
     * @param array<int,array{role:string,content:?string,tool_calls?:array,tool_call_id?:string,name?:string}> $messages
     * @return array{0:string,1:array<int,array<string,mixed>>}
     */
    private static function translateMessages(array $messages): array
    {
        $instructions = '';
        $input = [];

        foreach ($messages as $msg) {
            $role = $msg['role'] ?? '';

            if ($role === 'system') {
                $instructions = (string) ($msg['content'] ?? '');
                continue;
            }

            if ($role === 'user') {
                $input[] = ['role' => 'user', 'content' => (string) ($msg['content'] ?? '')];
                continue;
            }

            if ($role === 'assistant') {
                if (!empty($msg['content'])) {
                    $input[] = ['role' => 'assistant', 'content' => (string) $msg['content']];
                }
                foreach ($msg['tool_calls'] ?? [] as $tc) {
                    $arguments = $tc['function']['arguments'] ?? [];
                    $input[] = [
                        'type' => 'function_call',
                        'call_id' => (string) ($tc['id'] ?? ''),
                        'name' => (string) ($tc['function']['name'] ?? ''),
                        'arguments' => (string) json_encode(is_array($arguments) ? $arguments : [], JSON_UNESCAPED_UNICODE),
                    ];
                }
                continue;
            }

            if ($role === 'tool') {
                $decoded = json_decode((string) ($msg['content'] ?? ''), true);
                $isError = is_array($decoded) && array_key_exists('success', $decoded) && $decoded['success'] === false;
                $outputText = $isError
                    ? (string) ($decoded['error'] ?? 'Hiba történt az eszköz végrehajtása közben.')
                    : (string) json_encode(is_array($decoded) ? ($decoded['data'] ?? null) : null, JSON_UNESCAPED_UNICODE);
                $input[] = [
                    'type' => 'function_call_output',
                    'call_id' => (string) ($msg['tool_call_id'] ?? ''),
                    'output' => $outputText,
                ];
            }
        }

        return [$instructions, $input];
    }

    private function executeRequest(array $body)
    {
        $ch = curl_init(rtrim($this->baseUrl, '/') . '/v1/responses');
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_HTTPHEADER => $this->headers(),
            CURLOPT_POSTFIELDS => json_encode($body),
            CURLOPT_CONNECTTIMEOUT => min(10, $this->timeoutSeconds),
            CURLOPT_TIMEOUT => $this->timeoutSeconds,
        ]);

        $response = curl_exec($ch);
        if ($response === false) {
            $errno = curl_errno($ch);
            $err = curl_error($ch);
            curl_close($ch);
            $kind = $errno === CURLE_OPERATION_TIMEDOUT ? 'timeout' : 'unavailable';
            throw new AiProviderException("Az OpenAI API nem érhető el vagy nem válaszolt időben ($err).", $kind);
        }

        $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        $decoded = json_decode((string) $response, true);

        if ($status >= 400) {
            $errType = is_array($decoded) ? (string) ($decoded['error']['type'] ?? '') : '';
            $errMsg = is_array($decoded) ? (string) ($decoded['error']['message'] ?? '') : (string) $response;
            $kind = match (true) {
                $status === 401 => 'auth_error',
                $status === 403 => 'auth_error',
                $status === 429 => 'rate_limit',
                $status >= 500 => 'unavailable',
                default => 'http_error',
            };
            throw new AiProviderException("Az OpenAI API hibát adott vissza (HTTP $status, $errType): $errMsg", $kind);
        }
        if ($decoded === null && json_last_error() !== JSON_ERROR_NONE) {
            throw new AiProviderException('Az OpenAI API válasza nem érvényes JSON.', 'malformed_response');
        }
        return $decoded;
    }

    private function executeGet(string $path)
    {
        $ch = curl_init(rtrim($this->baseUrl, '/') . $path);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => $this->headers(),
            CURLOPT_CONNECTTIMEOUT => min(5, $this->timeoutSeconds),
            CURLOPT_TIMEOUT => $this->timeoutSeconds,
        ]);
        $response = curl_exec($ch);
        if ($response === false) {
            $errno = curl_errno($ch);
            $err = curl_error($ch);
            curl_close($ch);
            $kind = $errno === CURLE_OPERATION_TIMEDOUT ? 'timeout' : 'unavailable';
            throw new AiProviderException("Az OpenAI API nem érhető el ($err).", $kind);
        }
        $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        $decoded = json_decode((string) $response, true);
        if ($status >= 400) {
            $kind = ($status === 401 || $status === 403) ? 'auth_error' : 'http_error';
            throw new AiProviderException("Az OpenAI API hibát adott vissza (HTTP $status).", $kind);
        }
        if ($decoded === null && json_last_error() !== JSON_ERROR_NONE) {
            throw new AiProviderException('Az OpenAI API válasza nem érvényes JSON.', 'malformed_response');
        }
        return $decoded;
    }

    /**
     * @return string[]
     */
    private function headers(): array
    {
        return [
            'content-type: application/json',
            'authorization: Bearer ' . $this->apiKey,
        ];
    }
}
