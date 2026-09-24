<?php

declare(strict_types=1);

require_once __DIR__ . '/AiProviderInterface.php';
require_once __DIR__ . '/AiStreamingProviderInterface.php';
require_once __DIR__ . '/AiProviderException.php';
require_once __DIR__ . '/ToolDefinition.php';
require_once __DIR__ . '/ToolCall.php';
require_once __DIR__ . '/AiUsage.php';

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
 * 4) REASONING ITEM REPLAY (utólagos javítás — lásd a hibajegy "harden
 *    OpenAI response replay" indoklását): a hivatalos dokumentáció
 *    ("Preserve reasoning without stored responses") szerint `store:false`
 *    mellett egy reasoning-képes modell (pl. a konfigurált `gpt-6-sol`,
 *    amely ALAPÉRTELMEZETTEN "medium" reasoning.effort-tal fut) a válasz
 *    `output` tömbjében `type:'reasoning'` elemeket ad vissza, jellemzően
 *    egy `encrypted_content` mezővel — ezeket ÉRINTETLENÜL, EREDETI
 *    POZÍCIÓBAN vissza kell küldeni egy eszköz-hívást folytató kérésben,
 *    különben a modell elveszíti a reasoning-folytonosságát. A generikus,
 *    provider-független belső üzenet-alak (lásd ConversationManager/
 *    AgentRunner — `{role, content, tool_calls}`) NEM tud reasoning
 *    item-et hordozni, és az AgentRunner minden iterációban egy ÚJ
 *    tömböt épít `AiChatResponse`/`ToolCall`-okból, tehát a nyers OpenAI
 *    elemek a `chat()` visszatérése után azonnal elvesznének, ha nem
 *    tárolnánk őket valahol.
 *
 *    Megoldás — a LEHETŐ LEGKISEBB, KIZÁRÓLAG ebbe az osztályba zárt
 *    mechanizmus, a megosztott AiProviderInterface/AiChatResponse/
 *    ToolCall szerződés megváltoztatása NÉLKÜL: ez a példány egy rövid
 *    életű, kizárólag a SAJÁT futása alatt élő, nem perzisztens
 *    `$rawOutputBatches` gyorsítótárat tart (lásd lent) — minden
 *    eszköz-hívást tartalmazó válasz NYERS `output` tömbjét elmenti,
 *    a hozzá tartozó `call_id`-k listájával kulcsolva. A KÖVETKEZŐ
 *    `chat()`-hívásnál, amikor egy korábbi assistant-kör tool_calls-ait
 *    fordítanánk vissza OpenAI-alakra, ha a call_id-k egyeznek egy
 *    tárolt köteggel, a NYERS elemeket (reasoning-gal, `message`-
 *    preambulummal, minden eredeti mezővel együtt) küldjük vissza
 *    VÁLTOZATLANUL — a reasoning-tartalmat SOSE vizsgáljuk/dekódoljuk,
 *    tisztán opak adatként kezeljük. Csak akkor esik vissza az
 *    egyszerűsített (content+tool_calls-ból újraépített) alakra, ha
 *    NINCS egyező tárolt köteg (pl. egy idegen forrásból származó
 *    előzmény) — ez a régi, Fázis 3-as viselkedés, biztonságos
 *    alapértelmezésként megmarad.
 *
 *    Ez a gyorsítótár KIZÁRÓLAG egy PHP-példány élettartamáig él (egy
 *    HTTP-kérés/egy AgentRunner::run() hívás — az AiProviderFactory
 *    minden kérésre friss providert épít), SOSE kerül lemezre/adatbázisba
 *    — nem sérti a "nincs tartós beszélgetés-előzmény" korlátot (lásd a
 *    kör 3. pontja), csak azt teszi lehetővé, hogy UGYANAZON a futáson
 *    belül a reasoning-elemek pontosan visszajátszhatók legyenek.
 *
 *    SZÁNDÉKOSAN NEM váltottunk `previous_response_id`-alapú állapot-
 *    láncolásra — a fenti gyorsítótár a dokumentáció saját "manually
 *    replay the complete response history" ajánlott mintáját követi,
 *    ami kifejezetten `store:false`/stateless üzemmódhoz készült.
 *
 * Fázis 9 — streamelés (`chatStream()`, a kör 2/3. pontja, hivatalos
 * OpenAPI-generált SDK-típusok alapján, 2026-09-24-én ellenőrizve): SSE,
 * `data: <json>` sorok, a JSON `"type"` mezője az esemény neve (pl.
 * `response.output_text.delta`, `response.function_call_arguments.delta`,
 * `response.output_item.done`, `response.completed`). KRITIKUS
 * EGYSZERŰSÍTÉS: a `response.output_item.done` esemény MÁR a szerver
 * által összeállított, VÉGLEGES elemet adja (`item`, `output_index`) —
 * ez output_index szerint kulcsolva PONTOSAN megegyezik a nem-streamelt
 * `$decoded['output']` egy elemével (reasoning-elemekkel/
 * encrypted_content-tel együtt), ezért NINCS szükség saját,
 * darabonkénti function_call_arguments.delta-összefűzésre a VÉGSŐ
 * ToolCall-okhoz — azokat is `buildResponseFromOutput()` (a chat()-tel
 * MEGOSZTOTT metódus) építi, garantálva a reasoning item replay
 * ($rawOutputBatches) helyes működését streamelt válaszra is.
 */
final class OpenAiProvider implements AiProviderInterface, AiStreamingProviderInterface
{
    /**
     * Az adott PHP-példány saját futása alatt kapott, eszköz-hívást
     * tartalmazó válaszok NYERS `output` tömbjei, call_id-listával
     * kulcsolva — lásd az osztály docblokkja ("Reasoning item replay").
     * SOSE perzisztens, SOSE oszlik meg más providerpéldánnyal/kéréssel.
     *
     * @var array<int,array{callIds:string[],items:array<int,array<string,mixed>>}>
     */
    private array $rawOutputBatches = [];

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
        [$instructions, $input] = $this->translateMessages($messages);

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

        return $this->buildResponseFromOutput($decoded['output'], is_array($decoded['usage'] ?? null) ? $decoded['usage'] : null);
    }

    /**
     * MEGOSZTOTT a chat() ÉS chatStream() között — KRITIKUS, hogy a
     * `$rawOutputBatches` (reasoning item replay, lásd az osztály
     * docblokkja) bookkeeping-je AZONOS módon fusson, függetlenül attól,
     * hogy a végső `output` tömb egy darabban vagy streamelve (a kör 3.
     * pontja szerinti `response.output_item.done` eseményekből
     * összegyűjtve, lásd chatStream()) érkezett — egy eltérés itt
     * ÉSZREVÉTLENÜL eltörné a reasoning-folytonosságot egy streamelt,
     * több-körös eszköz-hívási beszélgetésben.
     *
     * @param array<int,mixed> $output
     * @param array<string,mixed>|null $usageRaw
     */
    private function buildResponseFromOutput(array $output, ?array $usageRaw): AiChatResponse
    {
        $textParts = [];
        $toolCalls = [];
        foreach ($output as $item) {
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
            // Egyéb elem-típusok (pl. 'reasoning') a normalizált
            // AiChatResponse felépítéséhez figyelmen kívül maradnak — az
            // InventoryAgent system promptja/üzleti logikája nem
            // támaszkodik rájuk. A NYERS elemek (reasoning-gal együtt)
            // ettől függetlenül megmaradnak a $decoded['output']-ban, és
            // lent, eszköz-hívás esetén, teljes egészében elmentésre
            // kerülnek — lásd az osztály docblokkja ("Reasoning item
            // replay").
        }

        if ($toolCalls) {
            $this->rawOutputBatches[] = [
                'callIds' => array_map(static fn (ToolCall $c) => $c->id, $toolCalls),
                'items' => $output,
            ];
        }

        // A kör 14. pontja — a végleges (`response.completed`) usage
        // KIZÁRÓLAG a terminális eseményben/válaszban érkezik.
        $usage = null;
        if ($usageRaw !== null) {
            $inputTokens = isset($usageRaw['input_tokens']) ? (int) $usageRaw['input_tokens'] : null;
            $outputTokens = isset($usageRaw['output_tokens']) ? (int) $usageRaw['output_tokens'] : null;
            $totalTokens = isset($usageRaw['total_tokens']) ? (int) $usageRaw['total_tokens'] : null;
            $reasoningTokens = isset($usageRaw['output_tokens_details']['reasoning_tokens'])
                ? (int) $usageRaw['output_tokens_details']['reasoning_tokens'] : null;
            $cachedTokens = isset($usageRaw['input_tokens_details']['cached_tokens'])
                ? (int) $usageRaw['input_tokens_details']['cached_tokens'] : null;
            $usage = new AiUsage($inputTokens, $outputTokens, $totalTokens, $reasoningTokens, $cachedTokens);
        }

        $content = $textParts ? implode("\n", array_filter($textParts, static fn ($t) => $t !== '')) : null;
        return new AiChatResponse($content === '' ? null : $content, $toolCalls, $usage);
    }

    /**
     * @param array<int,array{role:string,content:?string,tool_calls?:array,tool_call_id?:string,name?:string}> $messages
     * @param ToolDefinition[] $tools
     * @param callable(AiStreamEvent):void $onEvent
     */
    public function chatStream(array $messages, array $tools, callable $onEvent): AiChatResponse
    {
        [$instructions, $input] = $this->translateMessages($messages);

        $body = ['model' => $this->model, 'input' => $input, 'store' => false, 'stream' => true];
        if ($instructions !== '') {
            $body['instructions'] = $instructions;
        }
        if ($tools) {
            $body['tools'] = array_map(static fn (ToolDefinition $t) => [
                'type' => 'function',
                'name' => $t->name,
                'description' => $t->description,
                'parameters' => $t->inputSchema,
            ], $tools);
        }
        if ($this->maxOutputTokens !== null && $this->maxOutputTokens > 0) {
            $body['max_output_tokens'] = $this->maxOutputTokens;
        }

        /** @var array<int,array<string,mixed>> $outputItemsByIndex */
        $outputItemsByIndex = [];
        $usageRaw = null;
        $streamError = null;
        $sawTerminal = false;

        $handleEvent = function (array $obj) use (&$outputItemsByIndex, &$usageRaw, &$streamError, &$sawTerminal, $onEvent): void {
            $type = (string) ($obj['type'] ?? '');
            switch ($type) {
                case 'response.output_item.added':
                    // A "kész" elem-tartalom a response.output_item.done
                    // eseményben jön (lásd lent) — itt csak a tool-hívás
                    // KEZDETI jelzését (call_id + name MÁR itt megvan)
                    // emittáljuk UX-célra.
                    $item = $obj['item'] ?? [];
                    if (is_array($item) && ($item['type'] ?? '') === 'function_call') {
                        $onEvent(AiStreamEvent::toolCallStarted((string) ($item['call_id'] ?? ''), (string) ($item['name'] ?? '')));
                    }
                    break;
                case 'response.output_text.delta':
                    $delta = (string) ($obj['delta'] ?? '');
                    if ($delta !== '') {
                        $onEvent(AiStreamEvent::textDelta($delta));
                    }
                    break;
                case 'response.output_item.done':
                    // A SZERVER MÁR összeállította a teljes, végleges elemet
                    // (message/function_call/reasoning, minden mezővel,
                    // pl. reasoning esetén encrypted_content) — ez PONTOSAN
                    // megegyezik a nem-streamelt válasz egy $decoded['output']
                    // elemével, tehát nincs szükség saját, manuális
                    // töredék-összefűzésre.
                    $index = (int) ($obj['output_index'] ?? count($outputItemsByIndex));
                    if (isset($obj['item']) && is_array($obj['item'])) {
                        $outputItemsByIndex[$index] = $obj['item'];
                    }
                    break;
                case 'response.completed':
                case 'response.incomplete':
                    $sawTerminal = true;
                    $resp = $obj['response'] ?? [];
                    if (is_array($resp) && isset($resp['usage']) && is_array($resp['usage'])) {
                        $usageRaw = $resp['usage'];
                    }
                    break;
                case 'response.failed':
                    $resp = $obj['response'] ?? [];
                    $err = is_array($resp) ? ($resp['error'] ?? []) : [];
                    $streamError = (string) (is_array($err) ? ($err['message'] ?? 'ismeretlen hiba') : 'ismeretlen hiba');
                    break;
                case 'error':
                    // A kör 2. pontja szerinti kutatás — mindkét alakot
                    // kezeljük: lapos {type:'error',message:...} ÉS
                    // beágyazott {type:'error',error:{message:...}}.
                    $err = $obj['error'] ?? $obj;
                    $streamError = (string) (is_array($err) ? ($err['message'] ?? 'ismeretlen hiba') : 'ismeretlen hiba');
                    break;
                default:
                    // Minden más (reasoning/content_part/egyéb beépített
                    // eszköz-esemény) a kör 2. pontja szerint SZÁNDÉKOSAN
                    // figyelmen kívül marad — ismeretlen jövőbeli
                    // esemény-típus SOSE fatális.
                    break;
            }
        };

        $sseBuffer = '';
        self::executeStreamingRequest(rtrim($this->baseUrl, '/') . '/v1/responses', $body, $this->headers(), $this->timeoutSeconds, function (string $chunk) use (&$sseBuffer, $handleEvent): void {
            $sseBuffer .= $chunk;
            while (($pos = strpos($sseBuffer, "\n")) !== false) {
                $line = rtrim(substr($sseBuffer, 0, $pos), "\r");
                $sseBuffer = substr($sseBuffer, $pos + 1);
                if ($line === '' || !str_starts_with($line, 'data:')) {
                    continue;
                }
                $data = trim(substr($line, 5));
                if ($data === '[DONE]') {
                    continue;
                }
                $obj = json_decode($data, true);
                if (is_array($obj)) {
                    $handleEvent($obj);
                }
            }
        });

        if ($streamError !== null) {
            throw new AiProviderException("Az OpenAI API hibát adott vissza streamelés közben: $streamError", 'http_error');
        }
        if (!$sawTerminal) {
            throw new AiProviderException('Az OpenAI streamelt válasza váratlanul megszakadt (nincs záró esemény).', 'malformed_response');
        }

        ksort($outputItemsByIndex);
        $response = $this->buildResponseFromOutput(array_values($outputItemsByIndex), $usageRaw);
        if ($response->usage !== null) {
            $onEvent(AiStreamEvent::usage($response->usage));
        }
        return $response;
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
     * NEM statikus (a Fázis 3 eredeti verziójában az volt) — a
     * `$rawOutputBatches` gyorsítótárhoz kell hozzáférnie egy korábbi
     * assistant-kör PONTOS, reasoning-item-eket is megőrző visszajátszásához,
     * lásd az osztály docblokkja ("Reasoning item replay").
     *
     * @param array<int,array{role:string,content:?string,tool_calls?:array,tool_call_id?:string,name?:string}> $messages
     * @return array{0:string,1:array<int,array<string,mixed>>}
     */
    private function translateMessages(array $messages): array
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
                $toolCallIds = array_map(static fn ($tc) => (string) ($tc['id'] ?? ''), $msg['tool_calls'] ?? []);
                $rawBatch = $toolCallIds ? $this->findRawBatch($toolCallIds) : null;

                if ($rawBatch !== null) {
                    // Pontos, eredeti visszajátszás — reasoning item(ek)kel
                    // és minden más nyers mezővel (id, status, stb.)
                    // együtt, VÁLTOZATLANUL. A reasoning tartalmát (pl.
                    // encrypted_content) itt sose vizsgáljuk/dekódoljuk.
                    foreach ($rawBatch as $rawItem) {
                        $input[] = $rawItem;
                    }
                    continue;
                }

                // Nincs egyező tárolt köteg (pl. a történet nem ettől a
                // providerpéldánytól származik) — biztonságos,
                // egyszerűsített visszaépítés, ugyanaz, mint a Fázis 3
                // eredeti viselkedése.
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

    /**
     * Megkeresi a $rawOutputBatches-ben azt a köteget, aminek a call_id-
     * listája PONTOSAN (sorrendben és értékben) megegyezik a kérttel —
     * lásd az osztály docblokkja. `null`, ha nincs egyező köteg (biztonságos,
     * a hívó ilyenkor visszaesik az egyszerűsített visszaépítésre).
     *
     * @param string[] $callIds
     * @return array<int,array<string,mixed>>|null
     */
    private function findRawBatch(array $callIds): ?array
    {
        foreach ($this->rawOutputBatches as $batch) {
            if ($batch['callIds'] === $callIds) {
                return $batch['items'];
            }
        }
        return null;
    }

    /**
     * Fázis 9 — lásd AnthropicProvider::executeStreamingRequest() azonos
     * docblokkja (megszakítás-kezelés/curl-mechanika/stream-előtti
     * HTTP-hiba explicit ellenőrzése).
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
            throw new AiProviderException("Az OpenAI API nem érhető el vagy nem válaszolt időben ($err).", $kind);
        }
        curl_close($ch);
        if ($status >= 400) {
            throw new AiProviderException("Az OpenAI API hibát adott vissza (HTTP $status) streamelés előtt.", $status === 401 || $status === 403 ? 'auth_error' : ($status === 429 ? 'rate_limit' : 'http_error'));
        }
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
