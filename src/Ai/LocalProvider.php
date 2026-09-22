<?php

declare(strict_types=1);

require_once __DIR__ . '/AiProviderInterface.php';
require_once __DIR__ . '/AiProviderException.php';
require_once __DIR__ . '/ToolDefinition.php';
require_once __DIR__ . '/ToolCall.php';

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
 */
final class LocalProvider implements AiProviderInterface
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

        $decoded = self::executeRequest($this->baseUrl . '/api/chat', $body, $this->timeoutSeconds);

        if (!is_array($decoded) || !isset($decoded['message']) || !is_array($decoded['message'])) {
            throw new AiProviderException('Az Ollama válasza váratlan szerkezetű (hiányzó "message" mező).', 'malformed_response');
        }

        $message = $decoded['message'];
        $content = isset($message['content']) && $message['content'] !== '' ? (string) $message['content'] : null;

        $toolCalls = [];
        if (!empty($message['tool_calls']) && is_array($message['tool_calls'])) {
            foreach ($message['tool_calls'] as $i => $rawCall) {
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
        }

        return new AiChatResponse($content, $toolCalls);
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
            throw new AiProviderException("Az Ollama hibát adott vissza (HTTP $status): $msg", 'http_error');
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
