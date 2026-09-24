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
 * Ollama HTTP API-n (/api/chat, /api/tags) keresztül beszélő provider —
 * lásd AiProviderInterface docblokkja a szerződésért. SZÁNDÉKOSAN nem
 * kér API-kulcsot (Ollama helyi/hálózaton belüli telepítésre készült,
 * nincs saját hitelesítése) — a baseUrl-t VÉDI a hívó (Kliens node
 * sose hozza létre ezt az osztályt, lásd _bootstrap.php node_role-
 * elágazása és AiSettings docblokkja), nem ez az osztály.
 *
 * A UrlSafety/SSRF-kapu (lásd WooCommerceClient) itt SZÁNDÉKOSAN nincs
 * bevonva: az az ellenőrzés kifejezetten a LOOPBACK/belső címeket utasítja
 * el egy admin által megadott KÜLSŐ integrációs URL-nél (WooCommerce
 * store, Számlázz.hu stb.) — az Ollama base URL viszont ALAPÉRTELMEZETTEN
 * és tipikusan pont loopback (127.0.0.1:11434), az az elvárt, normális
 * eset, nem egy kizárandó SSRF-célpont.
 *
 * Fázis 9 — streamelés (`chatStream()`, a kör 2/3. pontja). Az Ollama
 * natív `/api/chat` végpontja `"stream": true` esetén NEM SSE, hanem
 * NDJSON-t ad (soronként egy-egy teljes JSON-objektum, `Content-Type:
 * application/x-ndjson`, sorvég-elválasztó, SOSE `event:`/`data:`
 * prefix). Minden sor `message.content` mezője DELTA (nem kumulatív),
 * FŰZNI kell. Az eszköz-hívások (`message.tool_calls`) a hivatalos
 * dokumentáció/szerver-forráskód szerint MINDIG TELJESEN, egy darabban
 * érkeznek (SOSE töredezett JSON-argumentumként) — ez a provider ezért
 * SOSE emittál `tool_call_arguments_delta`-t, csak a kész hívást gyűjti
 * össze, PONTOSAN úgy, mint a szinkron chat(). A token-használat
 * (`prompt_eval_count`/`eval_count`) KIZÁRÓLAG az utolsó, `"done":true`
 * sorban érkezik.
 */
final class LocalProvider implements AiProviderInterface, AiStreamingProviderInterface
{
    public function __construct(
        private readonly string $baseUrl,
        private readonly string $model,
        private readonly int $timeoutSeconds = 30,
        private readonly ?int $maxOutputTokens = null,
    ) {
    }

    public function name(): string
    {
        return 'local';
    }

    public function chat(array $messages, array $tools): AiChatResponse
    {
        $toolRegistrySchemas = array_map(static function (ToolDefinition $t) {
            return [
                'type' => 'function',
                'function' => [
                    'name' => $t->name,
                    'description' => $t->description,
                    'parameters' => $t->inputSchema,
                ],
            ];
        }, $tools);

        $body = [
            'model' => $this->model,
            'messages' => $messages,
            'stream' => false,
        ];
        if ($toolRegistrySchemas) {
            $body['tools'] = $toolRegistrySchemas;
        }
        // Ollama a kimenet-hosszt az "options.num_predict" mezőn keresztül
        // korlátozza — csak akkor küldjük, ha az admin explicit beállított
        // egy pozitív értéket (lásd Settings::DEFAULTS ai_max_output_tokens).
        if ($this->maxOutputTokens !== null && $this->maxOutputTokens > 0) {
            $body['options'] = ['num_predict' => $this->maxOutputTokens];
        }

        // Fázis 10 — lásd AiRetryPolicy.php docblokkja: KIZÁRÓLAG a
        // nem-streamelt útvonalon, KIZÁRÓLAG valódi átmeneti hibákra
        // (timeout/unavailable/rate_limit) — eszköz-végrehajtást/üzleti
        // mutációt SOSE ismétel meg, mert ez a réteg azok ELŐTT fut le.
        $decoded = AiRetryPolicy::run(fn () => self::executeRequest($this->baseUrl . '/api/chat', $body, $this->timeoutSeconds));

        if (!is_array($decoded) || !isset($decoded['message']) || !is_array($decoded['message'])) {
            throw new AiProviderException('Az Ollama válasza váratlan szerkezetű (hiányzó "message" mező).', 'malformed_response');
        }

        $message = $decoded['message'];
        $content = isset($message['content']) && $message['content'] !== '' ? (string) $message['content'] : null;
        $toolCalls = self::parseToolCalls(is_array($message['tool_calls'] ?? null) ? $message['tool_calls'] : []);

        return new AiChatResponse($content, $toolCalls);
    }

    /**
     * A kör 2. pontja szerinti kutatás (Ollama szerver-forráskód):
     * `function.arguments` MINDIG teljes JSON-objektum (SOSE töredezett
     * string), az `id` OPCIONÁLIS (régebbi szerver-verziók nem adják) —
     * ez a metódus MEGOSZTOTT chat() ÉS chatStream() között, hogy a két
     * kódútvonal garantáltan AZONOS módon értelmezze a nyers választ.
     *
     * @param array<int,mixed> $rawToolCalls
     * @return ToolCall[]
     */
    private static function parseToolCalls(array $rawToolCalls): array
    {
        $toolCalls = [];
        foreach ($rawToolCalls as $i => $rawCall) {
            if (!is_array($rawCall) || !isset($rawCall['function']) || !is_array($rawCall['function'])) {
                continue;
            }
            $fn = $rawCall['function'];
            $name = (string) ($fn['name'] ?? '');
            if ($name === '') {
                continue;
            }
            $arguments = $fn['arguments'] ?? [];
            // Az Ollama jellemzően már dekódolt tömbként adja az
            // argumentumokat, de néhány modell/verzió JSON-stringként —
            // mindkettőt kezeljük, hamis "malformed" hiba nélkül.
            if (is_string($arguments)) {
                $decodedArgs = json_decode($arguments, true);
                $arguments = is_array($decodedArgs) ? $decodedArgs : [];
            }
            if (!is_array($arguments)) {
                $arguments = [];
            }
            // Az Ollama tool_calls bejegyzései nem mindig adnak saját
            // id-t (ellentétben az OpenAI formátummal) — generálunk
            // egyet, hogy a ConversationManager/AgentRunner a tool-
            // eredményt egyértelműen vissza tudja korrelálni.
            $id = isset($rawCall['id']) && $rawCall['id'] !== '' ? (string) $rawCall['id'] : 'call_' . $i . '_' . substr(md5($name . microtime(true)), 0, 8);
            $toolCalls[] = new ToolCall($id, $name, $arguments);
        }
        return $toolCalls;
    }

    /**
     * @param array<int,array{role:string,content:?string,tool_calls?:array,tool_call_id?:string,name?:string}> $messages
     * @param ToolDefinition[] $tools
     * @param callable(AiStreamEvent):void $onEvent
     */
    public function chatStream(array $messages, array $tools, callable $onEvent): AiChatResponse
    {
        $toolRegistrySchemas = array_map(static function (ToolDefinition $t) {
            return [
                'type' => 'function',
                'function' => ['name' => $t->name, 'description' => $t->description, 'parameters' => $t->inputSchema],
            ];
        }, $tools);

        $body = ['model' => $this->model, 'messages' => $messages, 'stream' => true];
        if ($toolRegistrySchemas) {
            $body['tools'] = $toolRegistrySchemas;
        }
        if ($this->maxOutputTokens !== null && $this->maxOutputTokens > 0) {
            $body['options'] = ['num_predict' => $this->maxOutputTokens];
        }

        $content = '';
        $rawToolCalls = [];
        $doneChunk = null;
        $streamError = null;

        $handleLine = function (string $line) use (&$content, &$rawToolCalls, &$doneChunk, &$streamError, $onEvent): void {
            $line = trim($line);
            if ($line === '') {
                return;
            }
            $obj = json_decode($line, true);
            if (!is_array($obj)) {
                // Hibásan formázott/csonka sor — a kör 24. pontja
                // ("malformed event") szerint SOSE dobjuk el a teljes
                // streamet emiatt, egyszerűen figyelmen kívül hagyjuk ezt
                // az egy sort.
                return;
            }
            if (isset($obj['error'])) {
                $streamError = (string) $obj['error'];
                return;
            }
            $message = $obj['message'] ?? null;
            if (is_array($message)) {
                if (isset($message['content']) && $message['content'] !== '') {
                    $delta = (string) $message['content'];
                    $content .= $delta;
                    $onEvent(AiStreamEvent::textDelta($delta));
                }
                if (!empty($message['tool_calls']) && is_array($message['tool_calls'])) {
                    foreach ($message['tool_calls'] as $tc) {
                        $rawToolCalls[] = $tc;
                    }
                }
            }
            if (!empty($obj['done'])) {
                $doneChunk = $obj;
            }
        };

        $buffer = '';
        self::executeStreamingRequest($this->baseUrl . '/api/chat', $body, $this->timeoutSeconds, function (string $chunk) use (&$buffer, $handleLine): void {
            $buffer .= $chunk;
            while (($pos = strpos($buffer, "\n")) !== false) {
                $handleLine(substr($buffer, 0, $pos));
                $buffer = substr($buffer, $pos + 1);
            }
        });
        if (trim($buffer) !== '') {
            $handleLine($buffer);
        }

        if ($streamError !== null) {
            throw new AiProviderException("Az Ollama hibát adott vissza streamelés közben: $streamError", 'http_error');
        }
        if ($doneChunk === null) {
            throw new AiProviderException('Az Ollama streamelt válasza váratlanul megszakadt (nincs záró "done" esemény).', 'malformed_response');
        }

        $toolCalls = self::parseToolCalls($rawToolCalls);

        // A kör 14. pontja — a token-használat KIZÁRÓLAG a záró "done"
        // eseményben érkezik; hiányzó mezőnél NULL marad (SOSE 0-t
        // "hazudunk" egy valójában ismeretlen értékre).
        $inputTokens = isset($doneChunk['prompt_eval_count']) ? (int) $doneChunk['prompt_eval_count'] : null;
        $outputTokens = isset($doneChunk['eval_count']) ? (int) $doneChunk['eval_count'] : null;
        $totalTokens = ($inputTokens !== null || $outputTokens !== null) ? ($inputTokens ?? 0) + ($outputTokens ?? 0) : null;
        $usage = new AiUsage($inputTokens, $outputTokens, $totalTokens);
        if ($usage->inputTokens !== null || $usage->outputTokens !== null) {
            $onEvent(AiStreamEvent::usage($usage));
        }

        return new AiChatResponse($content !== '' ? $content : null, $toolCalls, $usage);
    }

    public function checkAvailability(): AiAvailability
    {
        try {
            $decoded = self::executeGet($this->baseUrl . '/api/tags', min(5, $this->timeoutSeconds));
        } catch (AiProviderException $e) {
            return AiAvailability::unavailable('Az Ollama nem érhető el: ' . match ($e->kind) {
                'timeout' => 'időtúllépés.',
                default => 'kapcsolódási hiba.',
            });
        }

        if (!is_array($decoded) || !isset($decoded['models']) || !is_array($decoded['models'])) {
            return AiAvailability::unavailable('Az Ollama válasza váratlan szerkezetű.');
        }

        $modelNames = array_map(static fn ($m) => (string) ($m['name'] ?? $m['model'] ?? ''), $decoded['models']);
        // Az Ollama a modellnevet gyakran ":latest" utótaggal listázza — a
        // beállításban megadott (pl. "qwen3:8b") és a listázott név csak a
        // ":"-ig kell egyezzen, hogy egy explicit taget NEM adó
        // beállítás is helyesen "elérhetőnek" számítson.
        $wanted = strtolower($this->model);
        foreach ($modelNames as $available) {
            $availableLower = strtolower($available);
            if ($availableLower === $wanted || str_starts_with($availableLower, $wanted . ':')) {
                return AiAvailability::available();
            }
        }

        return AiAvailability::modelError("A konfigurált modell (\"{$this->model}\") nincs letöltve ebben az Ollama-példányban.");
    }

    /**
     * Fázis 9 — streamelt cURL-hívás: `CURLOPT_WRITEFUNCTION`-nel a
     * bájtok ÉRKEZÉSKOR (nem a teljes válasz végén) jutnak el az
     * `$onChunk` callback-hez — ez adja a "élő" NDJSON-feldolgozást
     * (lásd chatStream()). A kör 22. pontja — megszakítás: minden
     * bájt-darab előtt `connection_aborted()`-et ellenőrzünk; ha a
     * böngésző-kapcsolat időközben megszakadt, 0-t adunk vissza, ami a
     * curl-t biztonságosan leállítja (SOSE hagy félkész üzleti
     * műveletet — ez a metódus amúgy is KIZÁRÓLAG a modell-válasz
     * generálásáról szól, a Fázis 8B végrehajtás ettől függetlenül,
     * KÜLÖN emberi lépésként történik).
     *
     * @param callable(string):void $onChunk
     */
    private static function executeStreamingRequest(string $url, array $body, int $timeoutSeconds, callable $onChunk): void
    {
        $ch = curl_init($url);
        $aborted = false;
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
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
        if ($ok === false && !$aborted) {
            $errno = curl_errno($ch);
            $err = curl_error($ch);
            curl_close($ch);
            $kind = $errno === CURLE_OPERATION_TIMEDOUT ? 'timeout' : 'unavailable';
            throw new AiProviderException("Az Ollama nem érhető el vagy nem válaszolt időben ($err).", $kind);
        }
        curl_close($ch);
    }

    /**
     * A tényleges cURL-hívás — KÜLÖN, static metódusban (ugyanaz a minta,
     * mint WooCommerceClient::executeRequest()), hogy valódi HTTP-hívásokkal,
     * egy loopback teszt-stub szerver ellen legyen tesztelhető
     * (lásd tests/AiLocalProviderTest.php).
     */
    private static function executeRequest(string $url, array $body, int $timeoutSeconds)
    {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
            CURLOPT_POSTFIELDS => json_encode($body),
            CURLOPT_CONNECTTIMEOUT => min(10, $timeoutSeconds),
            CURLOPT_TIMEOUT => $timeoutSeconds,
        ]);

        $response = curl_exec($ch);
        if ($response === false) {
            $errno = curl_errno($ch);
            $err = curl_error($ch);
            curl_close($ch);
            $kind = $errno === CURLE_OPERATION_TIMEDOUT ? 'timeout' : 'unavailable';
            throw new AiProviderException("Az Ollama nem érhető el vagy nem válaszolt időben ($err).", $kind);
        }

        $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        $decoded = json_decode((string) $response, true);
        if ($status >= 400) {
            $msg = is_array($decoded) && isset($decoded['error']) ? (string) $decoded['error'] : (string) $response;
            // Fázis 10 — a kör 8/17. pontja: 5xx (pl. a modell még
            // betöltés alatt, vagy egy belső Ollama-hiba) egy VALÓDI,
            // jellemzően ÁTMENETI elérhetetlenség, megkülönböztetendő
            // egy determinisztikus 4xx kérés-hibától (lásd
            // AiRetryPolicy.php — csak az 'unavailable' kategória
            // újrapróbálható).
            $kind = $status >= 500 ? 'unavailable' : 'http_error';
            throw new AiProviderException("Az Ollama hibát adott vissza (HTTP $status): $msg", $kind);
        }
        if ($decoded === null && json_last_error() !== JSON_ERROR_NONE) {
            throw new AiProviderException('Az Ollama válasza nem érvényes JSON.', 'malformed_response');
        }
        return $decoded;
    }

    private static function executeGet(string $url, int $timeoutSeconds)
    {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => min(5, $timeoutSeconds),
            CURLOPT_TIMEOUT => $timeoutSeconds,
        ]);
        $response = curl_exec($ch);
        if ($response === false) {
            $errno = curl_errno($ch);
            $err = curl_error($ch);
            curl_close($ch);
            $kind = $errno === CURLE_OPERATION_TIMEDOUT ? 'timeout' : 'unavailable';
            throw new AiProviderException("Az Ollama nem érhető el ($err).", $kind);
        }
        $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if ($status >= 400) {
            throw new AiProviderException("Az Ollama hibát adott vissza (HTTP $status).", 'http_error');
        }
        $decoded = json_decode((string) $response, true);
        if ($decoded === null && json_last_error() !== JSON_ERROR_NONE) {
            throw new AiProviderException('Az Ollama válasza nem érvényes JSON.', 'malformed_response');
        }
        return $decoded;
    }
}
