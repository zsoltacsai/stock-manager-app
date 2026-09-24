<?php

declare(strict_types=1);

require_once __DIR__ . '/AiProviderInterface.php';
require_once __DIR__ . '/AiStreamingProviderInterface.php';
require_once __DIR__ . '/AiProviderException.php';
require_once __DIR__ . '/AiRetryPolicy.php';
require_once __DIR__ . '/ToolDefinition.php';
require_once __DIR__ . '/ToolCall.php';
require_once __DIR__ . '/AiUsage.php';

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
 *
 * Fázis 9 — streamelés (`chatStream()`, a kör 2/3. pontja, hivatalos
 * dokumentáció alapján, 2026-09-24-én ellenőrizve): SSE, `event: <típus>`
 * majd `data: <json>` sorpár, a JSON `"type"` mezője MEGEGYEZIK az
 * `event:` névvel — ez a provider ezért KIZÁRÓLAG a `data:` sorok JSON
 * `type` mezőjére kapcsol, nem tartja külön nyilván az `event:` sort.
 * Esemény-sorrend: `message_start` → (`content_block_start` →
 * `content_block_delta`* → `content_block_stop`) × N → `message_delta`
 * (1+) → `message_stop`, `ping` bárhol közbeékelődhet. A tool-use blokk
 * `input_json_delta` deltái `partial_json` STRING-TÖREDÉKEK — `index`
 * szerint kulcsolva összefűzendők, és KIZÁRÓLAG a `content_block_stop`
 * eseménynél dekódolandók egyetlen JSON objektummá (SOSE korábban). A
 * végső, kumulatív token-használat a `message_delta.usage` mezőben jön
 * (ez FELÜLÍRJA a `message_start.message.usage` kezdeti értékét).
 */
final class AnthropicProvider implements AiProviderInterface, AiStreamingProviderInterface
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

        // Fázis 10 — lásd AiRetryPolicy.php docblokkja.
        $decoded = AiRetryPolicy::run(fn () => $this->executeRequest($body));

        if (!isset($decoded['content']) || !is_array($decoded['content'])) {
            throw new AiProviderException('Az Anthropic válasza váratlan szerkezetű (hiányzó "content" mező).', 'malformed_response');
        }

        return self::buildChatResponse($decoded['content'], is_array($decoded['usage'] ?? null) ? $decoded['usage'] : null);
    }

    /**
     * MEGOSZTOTT a chat() ÉS chatStream() között (a kör 3. pontja —
     * "the smallest provider-neutral streaming abstraction" elve itt
     * ÚGY érvényesül, hogy a két kódútvonal AZONOS módon értelmezi a
     * végleges content-block-listát/usage-ot, akár egy darabban, akár
     * összeszedve érkezett).
     *
     * @param array<int,array<string,mixed>> $contentBlocks
     * @param array<string,mixed>|null $usageRaw
     */
    private static function buildChatResponse(array $contentBlocks, ?array $usageRaw): AiChatResponse
    {
        $textParts = [];
        $toolCalls = [];
        foreach ($contentBlocks as $block) {
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

        // A kör 14. pontja — az Anthropic MINDEN (nem csak streamelt)
        // válaszon megadja a token-használatot, ingyen elérhető, tehát a
        // szinkron chat() is normalizálja (nem csak chatStream()).
        $usage = null;
        if ($usageRaw !== null) {
            $inputTokens = isset($usageRaw['input_tokens']) ? (int) $usageRaw['input_tokens'] : null;
            $outputTokens = isset($usageRaw['output_tokens']) ? (int) $usageRaw['output_tokens'] : null;
            $cachedTokens = isset($usageRaw['cache_read_input_tokens']) ? (int) $usageRaw['cache_read_input_tokens'] : null;
            $totalTokens = ($inputTokens !== null || $outputTokens !== null) ? ($inputTokens ?? 0) + ($outputTokens ?? 0) : null;
            $usage = new AiUsage($inputTokens, $outputTokens, $totalTokens, null, $cachedTokens);
        }

        return new AiChatResponse($content === '' ? null : $content, $toolCalls, $usage);
    }

    /**
     * @param array<int,array{role:string,content:?string,tool_calls?:array,tool_call_id?:string,name?:string}> $messages
     * @param ToolDefinition[] $tools
     * @param callable(AiStreamEvent):void $onEvent
     */
    public function chatStream(array $messages, array $tools, callable $onEvent): AiChatResponse
    {
        [$systemPrompt, $anthropicMessages] = self::translateMessages($messages);

        $body = [
            'model' => $this->model,
            'max_tokens' => $this->maxOutputTokens !== null && $this->maxOutputTokens > 0
                ? $this->maxOutputTokens
                : self::DEFAULT_MAX_TOKENS,
            'messages' => $anthropicMessages,
            'stream' => true,
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

        /** @var array<int,array{type:string,text:string,id?:string,name?:string,jsonBuffer?:string}> $blocksByIndex */
        $blocksByIndex = [];
        $usageRaw = [];
        $streamError = null;
        $sawMessageStop = false;

        $handleEvent = function (array $obj) use (&$blocksByIndex, &$usageRaw, &$streamError, &$sawMessageStop, $onEvent): void {
            $type = $obj['type'] ?? '';
            switch ($type) {
                case 'message_start':
                    if (isset($obj['message']['usage']) && is_array($obj['message']['usage'])) {
                        $usageRaw = array_merge($usageRaw, $obj['message']['usage']);
                    }
                    break;
                case 'content_block_start':
                    $index = (int) ($obj['index'] ?? 0);
                    $block = $obj['content_block'] ?? [];
                    $blockType = (string) ($block['type'] ?? '');
                    if ($blockType === 'tool_use') {
                        $blocksByIndex[$index] = [
                            'type' => 'tool_use',
                            'text' => '',
                            'id' => (string) ($block['id'] ?? ''),
                            'name' => (string) ($block['name'] ?? ''),
                            'jsonBuffer' => '',
                        ];
                        $onEvent(AiStreamEvent::toolCallStarted((string) ($block['id'] ?? ''), (string) ($block['name'] ?? '')));
                    } else {
                        // 'text' és minden más (pl. 'thinking') blokk-típust
                        // ide, szöveg-gyűjtő ágként kezelünk — lásd chat()
                        // azonos elve: csak a 'text' delták adnak ténylegesen
                        // tartalmat, a többi típus üresen marad.
                        $blocksByIndex[$index] = ['type' => $blockType, 'text' => ''];
                    }
                    break;
                case 'content_block_delta':
                    $index = (int) ($obj['index'] ?? 0);
                    $delta = $obj['delta'] ?? [];
                    $deltaType = (string) ($delta['type'] ?? '');
                    if ($deltaType === 'text_delta') {
                        $text = (string) ($delta['text'] ?? '');
                        if (isset($blocksByIndex[$index])) {
                            $blocksByIndex[$index]['text'] .= $text;
                        }
                        if ($text !== '') {
                            $onEvent(AiStreamEvent::textDelta($text));
                        }
                    } elseif ($deltaType === 'input_json_delta') {
                        // KIZÁRÓLAG puffereljük — a kör 4. pontja: "Do NOT
                        // execute a tool on partial JSON" — a tényleges
                        // dekódolás a content_block_stop-nál történik.
                        if (isset($blocksByIndex[$index])) {
                            $blocksByIndex[$index]['jsonBuffer'] = ($blocksByIndex[$index]['jsonBuffer'] ?? '') . (string) ($delta['partial_json'] ?? '');
                        }
                    }
                    break;
                case 'content_block_stop':
                    // A tool_use blokk argumentumai itt válnak VÉGLEGESSé —
                    // lásd a fenti docblokk. A tényleges ToolCall-objektum
                    // építése a stream végén, buildChatResponse()-ban történik.
                    break;
                case 'message_delta':
                    if (isset($obj['usage']) && is_array($obj['usage'])) {
                        // Kumulatív, FELÜLÍRJA a message_start kezdeti értékét.
                        $usageRaw = array_merge($usageRaw, $obj['usage']);
                    }
                    break;
                case 'message_stop':
                    $sawMessageStop = true;
                    break;
                case 'error':
                    $errorObj = $obj['error'] ?? $obj;
                    $streamError = (string) ($errorObj['message'] ?? 'ismeretlen hiba');
                    break;
                case 'ping':
                default:
                    // Ismeretlen/jövőbeli esemény-típus — a kör 2. pontja
                    // szerinti kutatás explicit javaslata: figyelmen kívül
                    // hagyandó, SOSE fatális.
                    break;
            }
        };

        $buffer = '';
        $dataLine = null;
        self::executeStreamingRequest(rtrim($this->baseUrl, '/') . '/v1/messages', $body, $this->headers(), $this->timeoutSeconds, function (string $chunk) use (&$buffer, &$dataLine, $handleEvent): void {
            $buffer .= $chunk;
            while (($pos = strpos($buffer, "\n")) !== false) {
                $line = rtrim(substr($buffer, 0, $pos), "\r");
                $buffer = substr($buffer, $pos + 1);
                if ($line === '') {
                    continue;
                }
                if (str_starts_with($line, 'data:')) {
                    $dataLine = trim(substr($line, 5));
                    $obj = json_decode($dataLine, true);
                    if (is_array($obj)) {
                        $handleEvent($obj);
                    }
                    // Egy 'event:'-sort SZÁNDÉKOSAN nem dolgozunk fel külön
                    // — lásd az osztály docblokkja: a data JSON saját
                    // "type" mezője megegyezik vele.
                }
            }
        });

        if ($streamError !== null) {
            throw new AiProviderException("Az Anthropic API hibát adott vissza streamelés közben: $streamError", 'http_error');
        }
        if (!$sawMessageStop) {
            throw new AiProviderException('Az Anthropic streamelt válasza váratlanul megszakadt (nincs "message_stop" esemény).', 'malformed_response');
        }

        $contentBlocks = [];
        ksort($blocksByIndex);
        foreach ($blocksByIndex as $block) {
            if ($block['type'] === 'tool_use') {
                $decodedInput = json_decode((string) ($block['jsonBuffer'] ?? ''), true);
                $contentBlocks[] = [
                    'type' => 'tool_use',
                    'id' => $block['id'],
                    'name' => $block['name'],
                    'input' => is_array($decodedInput) ? $decodedInput : [],
                ];
            } elseif ($block['type'] === 'text') {
                $contentBlocks[] = ['type' => 'text', 'text' => $block['text']];
            }
        }

        $response = self::buildChatResponse($contentBlocks, $usageRaw ?: null);
        if ($response->usage !== null) {
            $onEvent(AiStreamEvent::usage($response->usage));
        }
        return $response;
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

    /**
     * Fázis 9 — lásd LocalProvider::executeStreamingRequest() azonos
     * docblokkja (megszakítás-kezelés/curl-mechanika) — itt statikus,
     * mert az AnthropicProvider hívási helye (chatStream()) már
     * feloldott fejléceket ad át, nincs szükség $this-re.
     *
     * @param string[] $headers
     * @param callable(string):void $onChunk
     */
    private static function executeStreamingRequest(string $url, array $body, array $headers, int $timeoutSeconds, callable $onChunk): void
    {
        $ch = curl_init($url);
        $aborted = false;
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_POSTFIELDS => json_encode($body),
            CURLOPT_CONNECTTIMEOUT => min(10, $timeoutSeconds),
            CURLOPT_TIMEOUT => $timeoutSeconds,
            CURLOPT_WRITEFUNCTION => static function ($curlHandle, string $data) use ($onChunk, &$aborted): int {
                if (connection_aborted()) {
                    $aborted = true;
                    return 0;
                }
                $onChunk($data);
                return strlen($data);
            },
        ]);

        $ok = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        if ($ok === false && !$aborted) {
            $errno = curl_errno($ch);
            $err = curl_error($ch);
            curl_close($ch);
            $kind = $errno === CURLE_OPERATION_TIMEDOUT ? 'timeout' : 'unavailable';
            throw new AiProviderException("Az Anthropic API nem érhető el vagy nem válaszolt időben ($err).", $kind);
        }
        curl_close($ch);
        // Egy stream ELŐTTI HTTP-hiba (pl. 401/429) esetén az Anthropic
        // NEM SSE-t, hanem egy sima JSON hibaválaszt ad — mivel a
        // WRITEFUNCTION ekkor is megkapja a bájtokat, a hívó (chatStream())
        // saját, "data:"-előtaggal NEM kezdődő JSON-t sose talál 'data:'
        // sorként, tehát a $streamError sose áll be innen — ezt a
        // helyzetet a kör 24. pontja szerinti teszt ("API error") itt, a
        // HTTP-státusz explicit ellenőrzésével fedi le.
        if ($status >= 400) {
            // Fázis 10 — a kör 8/17. pontja: EZ a besorolás korábban
            // ELTÉRT a lenti executeRequest()/executeGet() ugyanerre a
            // logikára írt, teljesebb változatától (hiányzott az 5xx →
            // 'unavailable' ág) — ugyanaz a HTTP-státusz emiatt MÁS
            // kategóriát kapott aszerint, hogy streamelt vagy nem-streamelt
            // hívásban fordult elő. Most egységes mindhárom helyen.
            $kind = match (true) {
                $status === 401, $status === 403 => 'auth_error',
                $status === 429 => 'rate_limit',
                $status >= 500 => 'unavailable',
                default => 'http_error',
            };
            throw new AiProviderException("Az Anthropic API hibát adott vissza (HTTP $status) streamelés előtt.", $kind);
        }
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
