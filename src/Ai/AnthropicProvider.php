<?php

declare(strict_types=1);

require_once __DIR__ . '/AiProviderInterface.php';
require_once __DIR__ . '/AiProviderException.php';
require_once __DIR__ . '/ToolDefinition.php';
require_once __DIR__ . '/ToolCall.php';

/**
 * Anthropic Messages API (https://api.anthropic.com/v1/messages) kliens —
 * lásd AiProviderInterface docblokkja a szerződésért. MINDEN Anthropic-
 * specifikus protokoll-részlet (rendszer-prompt külön mezőben, tool_use/
 * tool_result content block-ok, a több egyidejű eszköz-eredmény EGY
 * user-üzenetbe csoportosítása) KIZÁRÓLAG ebben az osztályban van — az
 * AgentRunner/ConversationManager a generikus, Ollama/OpenAI-stílusú
 * belső üzenet-alakot (role: system|user|assistant|tool, egy 'tool'
 * szerepű üzenet eszköz-eredményenként) látja, sosem az Anthropic-natív
 * szerkezetet.
 *
 * A fejléc-/végpont-/modell-részletek a jelen implementáció idején
 * hatályos, hivatalos Anthropic dokumentáció alapján (Messages API,
 * 2023-06-01 verzió; Claude Sonnet 5 alapértelmezett modell — lásd
 * README "AI asszisztens" szakasza az indoklásért).
 */
final class AnthropicProvider implements AiProviderInterface
{
    private const API_VERSION = '2023-06-01';
    // Az Anthropic Messages API-nál a max_tokens KÖTELEZŐ mező (nincs
    // "korlátlan" opció, ellentétben az Ollama num_predict-jével) — ha az
    // admin nem állított be explicit ai_max_output_tokens-t, erre esünk
    // vissza (ugyanaz a nagyságrend, mint a hivatalos Anthropic példák).
    private const DEFAULT_MAX_TOKENS = 1024;

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
        return 'anthropic';
    }

    public function chat(array $messages, array $tools): AiChatResponse
    {
        [$systemPrompt, $anthropicMessages] = self::translateMessages($messages);

        $body = [
            'model' => $this->model,
            'max_tokens' => $this->maxOutputTokens !== null && $this->maxOutputTokens > 0
                ? $this->maxOutputTokens
                : self::DEFAULT_MAX_TOKENS,
            'messages' => $anthropicMessages,
        ];
        if ($systemPrompt !== '') {
            $body['system'] = $systemPrompt;
        }
        if ($tools) {
            $body['tools'] = array_map(static fn (ToolDefinition $t) => [
                'name' => $t->name,
                'description' => $t->description,
                'input_schema' => $t->inputSchema,
            ], $tools);
        }

        $decoded = $this->executeRequest($body);

        if (!isset($decoded['content']) || !is_array($decoded['content'])) {
            throw new AiProviderException('Az Anthropic válasza váratlan szerkezetű (hiányzó "content" mező).', 'malformed_response');
        }

        $textParts = [];
        $toolCalls = [];
        foreach ($decoded['content'] as $block) {
            if (!is_array($block)) {
                continue;
            }
            $type = $block['type'] ?? '';
            if ($type === 'text') {
                $textParts[] = (string) ($block['text'] ?? '');
            } elseif ($type === 'tool_use') {
                $name = (string) ($block['name'] ?? '');
                if ($name === '') {
                    continue;
                }
                $input = $block['input'] ?? [];
                $toolCalls[] = new ToolCall(
                    (string) ($block['id'] ?? ('toolu_' . bin2hex(random_bytes(8)))),
                    $name,
                    is_array($input) ? $input : []
                );
            }
            // Egyéb block-típusok (pl. 'thinking') a kör 7. pontja szerint
            // szándékosan figyelmen kívül maradnak — az InventoryAgent
            // system promptja/üzleti logikája nem támaszkodik rájuk.
        }

        $content = $textParts ? implode("\n", array_filter($textParts, static fn ($t) => $t !== '')) : null;
        return new AiChatResponse($content === '' ? null : $content, $toolCalls);
    }

    public function checkAvailability(): AiAvailability
    {
        if (trim($this->apiKey) === '') {
            return AiAvailability::notConfigured('Nincs megadva Anthropic API-kulcs.');
        }

        try {
            $decoded = $this->executeGet('/v1/models?limit=100');
        } catch (AiProviderException $e) {
            if ($e->kind === 'auth_error') {
                return AiAvailability::authError('Érvénytelen Anthropic API-kulcs.');
            }
            return AiAvailability::unavailable('Az Anthropic API jelenleg nem érhető el: ' . match ($e->kind) {
                'timeout' => 'időtúllépés.',
                default => 'kapcsolódási hiba.',
            });
        }

        if (!is_array($decoded) || !isset($decoded['data']) || !is_array($decoded['data'])) {
            return AiAvailability::unavailable('Az Anthropic API válasza váratlan szerkezetű.');
        }

        $modelIds = array_map(static fn ($m) => (string) ($m['id'] ?? ''), $decoded['data']);
        if (in_array($this->model, $modelIds, true)) {
            return AiAvailability::available();
        }

        return AiAvailability::modelError("A konfigurált modell (\"{$this->model}\") nem szerepel az elérhető Anthropic modellek listájában.");
    }

    /**
     * A generikus, Ollama/OpenAI-stílusú belső üzenet-tömböt fordítja le
     * az Anthropic-natív alakra — lásd az osztály docblokkja. Visszaadja
     * a rendszer-promptot (KÜLÖN, top-level "system" mező, nem a messages
     * tömbben — így kéri az Anthropic API) és az átalakított üzenet-listát.
     *
     * A legfontosabb szabály (lásd a hivatalos Anthropic dokumentáció
     * "handle-tool-calls" oldala): EGY assistant-kör összes tool_use-ára
     * adott EREDMÉNY egyetlen, KÖVETKEZŐ user-üzenetbe kerül, több
     * tool_result content block-ként — nem külön üzenetenként, ahogy a
     * belső (Ollama-stílusú) alak egy 'tool' szerepű üzenetet ad
     * eszköz-eredményenként. Ez a metódus csoportosítja az egymást
     * követő 'tool' szerepű belső üzeneteket egyetlen Anthropic
     * user-üzenetbe.
     *
     * @param array<int,array{role:string,content:?string,tool_calls?:array,tool_call_id?:string,name?:string}> $messages
     * @return array{0:string,1:array<int,array<string,mixed>>}
     */
    private static function translateMessages(array $messages): array
    {
        $systemPrompt = '';
        $result = [];
        $pendingToolResults = [];

        $flushToolResults = static function () use (&$pendingToolResults, &$result): void {
            if ($pendingToolResults) {
                $result[] = ['role' => 'user', 'content' => $pendingToolResults];
                $pendingToolResults = [];
            }
        };

        foreach ($messages as $msg) {
            $role = $msg['role'] ?? '';

            if ($role === 'system') {
                $systemPrompt = (string) ($msg['content'] ?? '');
                continue;
            }

            if ($role === 'tool') {
                $decoded = json_decode((string) ($msg['content'] ?? ''), true);
                $isError = is_array($decoded) && array_key_exists('success', $decoded) && $decoded['success'] === false;
                $resultText = $isError
                    ? (string) ($decoded['error'] ?? 'Hiba történt az eszköz végrehajtása közben.')
                    : (string) json_encode(is_array($decoded) ? ($decoded['data'] ?? null) : null, JSON_UNESCAPED_UNICODE);
                $block = [
                    'type' => 'tool_result',
                    'tool_use_id' => (string) ($msg['tool_call_id'] ?? ''),
                    'content' => $resultText,
                ];
                if ($isError) {
                    $block['is_error'] = true;
                }
                $pendingToolResults[] = $block;
                continue;
            }

            // Bármilyen NEM 'tool' szerepű üzenet előtt le kell zárni egy
            // esetleg összegyűjtött tool_result-csoportot — lásd docblokk.
            $flushToolResults();

            if ($role === 'user') {
                $result[] = ['role' => 'user', 'content' => (string) ($msg['content'] ?? '')];
            } elseif ($role === 'assistant') {
                $content = [];
                if (!empty($msg['content'])) {
                    $content[] = ['type' => 'text', 'text' => (string) $msg['content']];
                }
                foreach ($msg['tool_calls'] ?? [] as $tc) {
                    $arguments = $tc['function']['arguments'] ?? [];
                    $content[] = [
                        'type' => 'tool_use',
                        'id' => (string) ($tc['id'] ?? ''),
                        'name' => (string) ($tc['function']['name'] ?? ''),
                        // Üres tömb JSON-kódolva "[]" lenne, nem "{}" — az
                        // Anthropic egy objektumot vár "input"-ként.
                        'input' => (is_array($arguments) && $arguments === []) ? new stdClass() : $arguments,
                    ];
                }
                $result[] = ['role' => 'assistant', 'content' => $content];
            }
        }
        $flushToolResults();

        return [$systemPrompt, $result];
    }

    private function executeRequest(array $body)
    {
        $ch = curl_init(rtrim($this->baseUrl, '/') . '/v1/messages');
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
            throw new AiProviderException("Az Anthropic API nem érhető el vagy nem válaszolt időben ($err).", $kind);
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
            throw new AiProviderException("Az Anthropic API hibát adott vissza (HTTP $status, $errType): $errMsg", $kind);
        }
        if ($decoded === null && json_last_error() !== JSON_ERROR_NONE) {
            throw new AiProviderException('Az Anthropic API válasza nem érvényes JSON.', 'malformed_response');
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
            throw new AiProviderException("Az Anthropic API nem érhető el ($err).", $kind);
        }
        $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        $decoded = json_decode((string) $response, true);
        if ($status >= 400) {
            $kind = ($status === 401 || $status === 403) ? 'auth_error' : 'http_error';
            throw new AiProviderException("Az Anthropic API hibát adott vissza (HTTP $status).", $kind);
        }
        if ($decoded === null && json_last_error() !== JSON_ERROR_NONE) {
            throw new AiProviderException('Az Anthropic API válasza nem érvényes JSON.', 'malformed_response');
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
            'x-api-key: ' . $this->apiKey,
            'anthropic-version: ' . self::API_VERSION,
        ];
    }
}
