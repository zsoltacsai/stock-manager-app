<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Fázis 9, kör 30. pontja — UGYANAZ a Copilot ÉLŐ STREAMELVE fut le
 * UGYANAZON az AgentRunner::runStreaming()-en, UGYANAZON a ToolRegistry-n
 * (ask_inventory_agent) keresztül, mindhárom providerrel (LocalProvider/
 * Ollama NDJSON, AnthropicProvider SSE, OpenAiProvider SSE) — a
 * tests/AiCopilotCrossProviderRegressionTest.php NEM-streamelt mintájának
 * streamelt párja, UGYANAZZAL a kombinált stub-szerver-felépítéssel,
 * kiegészítve minden provider VALÓDI, dokumentáció-hű streamelt
 * (`"stream":true`) válasz-alakjával (lásd AiLocalProviderStreamingTest.php/
 * AiAnthropicProviderStreamingTest.php/AiOpenAiProviderStreamingTest.php
 * egyenkénti, már bizonyított stub-mintáit).
 *
 * A pontos természetes nyelvi kimenetnek NEM kell egyeznie — az, hogy
 * MINDHÁROM providernél VALÓBAN streamelt (`streamed:true`), a helyes
 * ügynökhöz irányított, és a teljes agent_started / tool_call_started /
 * tool_call_completed / final eseménysorozatot adja, IGEN.
 */
final class AiCopilotStreamingCrossProviderRegressionTest extends TestCase
{
    private static string $stubRoot;
    private static int $stubPort;
    /** @var resource|null */
    private static $stubServerProcess;
    private static string $baseUrl;
    private static Database $db;

    private const PROMPT = 'Mi fogyott ki a készletből?';

    public static function setUpBeforeClass(): void
    {
        self::$stubRoot = sys_get_temp_dir() . '/sm_ai_copilot_streaming_cross_provider_stub_' . bin2hex(random_bytes(6));
        mkdir(self::$stubRoot, 0775, true);
        file_put_contents(self::$stubRoot . '/index.php', <<<'PHP'
<?php
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);

function sse_line($obj) { echo 'data: ' . json_encode($obj) . "\n\n"; @flush(); }
function ndjson_line($obj) { echo json_encode($obj) . "\n"; @flush(); }

// --- Ollama-alakú útvonalak ---
if ($path === '/api/tags') {
    header('Content-Type: application/json');
    echo json_encode(['models' => [['name' => 'qwen3:8b']]]);
    exit;
}
if ($path === '/api/chat') {
    $body = json_decode(file_get_contents('php://input'), true);
    $hasToolResult = false;
    foreach ($body['messages'] ?? [] as $m) {
        if (($m['role'] ?? '') === 'tool') { $hasToolResult = true; }
    }
    if (empty($body['stream'])) {
        header('Content-Type: application/json');
        echo json_encode(['message' => ['role' => 'assistant', 'content' => 'Ollama: nem-streamelt.']]);
        exit;
    }
    header('Content-Type: application/x-ndjson');
    while (ob_get_level() > 0) { @ob_end_flush(); }
    if ($hasToolResult) {
        ndjson_line(['message' => ['role' => 'assistant', 'content' => 'Ollama: ']]);
        ndjson_line(['message' => ['role' => 'assistant', 'content' => 'a készlet alapján válaszoltam.']]);
        ndjson_line(['done' => true, 'prompt_eval_count' => 20, 'eval_count' => 10]);
    } else {
        ndjson_line(['message' => ['role' => 'assistant', 'content' => '', 'tool_calls' => [
            ['function' => ['name' => 'ask_inventory_agent', 'arguments' => ['question' => 'Mi fogyott ki?']]],
        ]]]);
        ndjson_line(['done' => true, 'prompt_eval_count' => 10, 'eval_count' => 5]);
    }
    exit;
}

// --- Anthropic-alakú útvonalak ---
if ($path === '/v1/models') {
    header('Content-Type: application/json');
    echo json_encode(['data' => [['id' => 'claude-sonnet-5'], ['id' => 'gpt-6-sol']]]);
    exit;
}
if ($path === '/v1/messages') {
    $body = json_decode(file_get_contents('php://input'), true);
    $messages = $body['messages'] ?? [];
    $last = end($messages);
    $hasToolResult = is_array($last) && ($last['role'] ?? '') === 'user' && is_array($last['content'] ?? null)
        && isset($last['content'][0]['type']) && $last['content'][0]['type'] === 'tool_result';

    if (empty($body['stream'])) {
        header('Content-Type: application/json');
        if ($hasToolResult) {
            echo json_encode(['id' => 'msg_2', 'type' => 'message', 'role' => 'assistant', 'content' => [['type' => 'text', 'text' => 'Anthropic: nem-streamelt.']], 'model' => $body['model'], 'stop_reason' => 'end_turn']);
        } else {
            echo json_encode(['id' => 'msg_1', 'type' => 'message', 'role' => 'assistant', 'content' => [['type' => 'tool_use', 'id' => 'toolu_1', 'name' => 'ask_inventory_agent', 'input' => ['question' => 'Mi fogyott ki?']]], 'model' => $body['model'], 'stop_reason' => 'tool_use']);
        }
        exit;
    }

    header('Content-Type: text/event-stream; charset=utf-8');
    while (ob_get_level() > 0) { @ob_end_flush(); }
    if ($hasToolResult) {
        sse_line(['type' => 'message_start', 'message' => ['id' => 'msg_2', 'type' => 'message', 'role' => 'assistant', 'content' => [], 'model' => $body['model'], 'usage' => ['input_tokens' => 15]]]);
        sse_line(['type' => 'content_block_start', 'index' => 0, 'content_block' => ['type' => 'text', 'text' => '']]);
        sse_line(['type' => 'content_block_delta', 'index' => 0, 'delta' => ['type' => 'text_delta', 'text' => 'Anthropic: a készlet alapján válaszoltam.']]);
        sse_line(['type' => 'content_block_stop', 'index' => 0]);
        sse_line(['type' => 'message_delta', 'delta' => ['stop_reason' => 'end_turn'], 'usage' => ['output_tokens' => 8]]);
        sse_line(['type' => 'message_stop']);
    } else {
        sse_line(['type' => 'message_start', 'message' => ['id' => 'msg_1', 'type' => 'message', 'role' => 'assistant', 'content' => [], 'model' => $body['model'], 'usage' => ['input_tokens' => 10]]]);
        sse_line(['type' => 'content_block_start', 'index' => 0, 'content_block' => ['type' => 'tool_use', 'id' => 'toolu_1', 'name' => 'ask_inventory_agent', 'input' => []]]);
        sse_line(['type' => 'content_block_delta', 'index' => 0, 'delta' => ['type' => 'input_json_delta', 'partial_json' => '{"question":"Mi fogyott ki?"}']]);
        sse_line(['type' => 'content_block_stop', 'index' => 0]);
        sse_line(['type' => 'message_delta', 'delta' => ['stop_reason' => 'tool_use'], 'usage' => ['output_tokens' => 5]]);
        sse_line(['type' => 'message_stop']);
    }
    exit;
}

// --- OpenAI-alakú útvonalak ---
if ($path === '/v1/responses') {
    $body = json_decode(file_get_contents('php://input'), true);
    $input = $body['input'] ?? [];
    $hasToolResult = false;
    foreach ($input as $item) {
        if (is_array($item) && ($item['type'] ?? '') === 'function_call_output') { $hasToolResult = true; }
    }

    if (empty($body['stream'])) {
        header('Content-Type: application/json');
        if ($hasToolResult) {
            echo json_encode(['id' => 'resp_2', 'object' => 'response', 'model' => $body['model'], 'output' => [
                ['id' => 'msg_2', 'type' => 'message', 'role' => 'assistant', 'status' => 'completed', 'content' => [['type' => 'output_text', 'text' => 'OpenAI: nem-streamelt.']]],
            ]]);
        } else {
            echo json_encode(['id' => 'resp_1', 'object' => 'response', 'model' => $body['model'], 'output' => [
                ['id' => 'fc_1', 'call_id' => 'call_1', 'type' => 'function_call', 'name' => 'ask_inventory_agent', 'arguments' => '{"question":"Mi fogyott ki?"}'],
            ]]);
        }
        exit;
    }

    header('Content-Type: text/event-stream; charset=utf-8');
    while (ob_get_level() > 0) { @ob_end_flush(); }
    if ($hasToolResult) {
        $item = ['id' => 'msg_2', 'type' => 'message', 'role' => 'assistant', 'status' => 'completed', 'content' => [['type' => 'output_text', 'text' => 'OpenAI: a készlet alapján válaszoltam.']]];
        sse_line(['type' => 'response.output_item.added', 'output_index' => 0, 'item' => ['id' => 'msg_2', 'type' => 'message', 'role' => 'assistant', 'status' => 'in_progress', 'content' => []]]);
        sse_line(['type' => 'response.output_text.delta', 'output_index' => 0, 'item_id' => 'msg_2', 'delta' => 'OpenAI: a készlet alapján válaszoltam.']);
        sse_line(['type' => 'response.output_item.done', 'output_index' => 0, 'item' => $item]);
        sse_line(['type' => 'response.completed', 'response' => ['id' => 'resp_2', 'object' => 'response', 'model' => $body['model'], 'output' => [$item], 'usage' => ['input_tokens' => 15, 'output_tokens' => 8]]]);
    } else {
        $item = ['id' => 'fc_1', 'call_id' => 'call_1', 'type' => 'function_call', 'name' => 'ask_inventory_agent', 'arguments' => '{"question":"Mi fogyott ki?"}'];
        sse_line(['type' => 'response.output_item.added', 'output_index' => 0, 'item' => ['id' => 'fc_1', 'call_id' => 'call_1', 'type' => 'function_call', 'name' => 'ask_inventory_agent', 'arguments' => '']]);
        sse_line(['type' => 'response.function_call_arguments.delta', 'output_index' => 0, 'item_id' => 'fc_1', 'delta' => '{"question":"Mi fogyott ki?"}']);
        sse_line(['type' => 'response.output_item.done', 'output_index' => 0, 'item' => $item]);
        sse_line(['type' => 'response.completed', 'response' => ['id' => 'resp_1', 'object' => 'response', 'model' => $body['model'], 'output' => [$item], 'usage' => ['input_tokens' => 10, 'output_tokens' => 5]]]);
    }
    exit;
}

http_response_code(404);
header('Content-Type: application/json');
echo json_encode(['error' => 'unknown stub route']);
PHP);

        self::$stubPort = self::findFreePort();
        self::$baseUrl = 'http://127.0.0.1:' . self::$stubPort;
        $logFile = self::$stubRoot . '/server.log';
        self::$stubServerProcess = proc_open(
            [PHP_BINARY, '-S', '127.0.0.1:' . self::$stubPort, '-t', self::$stubRoot],
            [1 => ['file', $logFile, 'w'], 2 => ['file', $logFile, 'w']],
            $pipes,
            self::$stubRoot
        );
        if (self::$stubServerProcess === false) {
            self::fail('Nem sikerült elindítani a kombinált AI streamelt stub teszt-szervert.');
        }
        self::waitForStubReady();

        self::$db = tests_new_database();
        $pdo = self::$db->pdo();
        $pdo->exec('INSERT INTO products (name, unit, price, net_price, stock_qty, low_stock_threshold, group_name, vat_rate) VALUES ("Cross-provider streaming copilot teszttermék", "db", 1000, 787, 0, 5, "Italok", "27")');
    }

    public static function tearDownAfterClass(): void
    {
        if (self::$stubServerProcess !== null && is_resource(self::$stubServerProcess)) {
            proc_terminate(self::$stubServerProcess);
            proc_close(self::$stubServerProcess);
        }
    }

    private static function findFreePort(): int
    {
        $sock = stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);
        if ($sock === false) {
            self::fail('Nem sikerült szabad portot találni: ' . $errstr);
        }
        $name = stream_socket_get_name($sock, false);
        fclose($sock);
        return (int) substr($name, strrpos($name, ':') + 1);
    }

    private static function waitForStubReady(): void
    {
        $deadline = microtime(true) + 10;
        while (microtime(true) < $deadline) {
            $fp = @fsockopen('127.0.0.1', self::$stubPort, $errno, $errstr, 0.2);
            if ($fp) {
                fclose($fp);
                return;
            }
            usleep(100_000);
        }
        self::fail('A kombinált AI streamelt stub teszt-szerver nem indult el időben.');
    }

    private function baseSettings(): array
    {
        return [
            'ai_local_base_url' => self::$baseUrl,
            'ai_local_model' => 'qwen3:8b',
            'ai_timeout_seconds' => 10,
            'ai_max_output_tokens' => null,
            'anthropic_api_key' => 'sk-ant-copilot-streaming-cross-teszt',
            'anthropic_model' => 'claude-sonnet-5',
            'anthropic_base_url' => self::$baseUrl,
            'anthropic_timeout_seconds' => 10,
            'openai_api_key' => 'sk-openai-copilot-streaming-cross-teszt',
            'openai_model' => 'gpt-6-sol',
            'openai_base_url' => self::$baseUrl,
            'openai_timeout_seconds' => 10,
            'ai_streaming_enabled' => true,
        ];
    }

    /** @dataProvider providerNames */
    public function testSameCopilotStreamsCorrectlyViaEachProvider(string $providerName, string $expectedAnswerFragment): void
    {
        $settings = $this->baseSettings();
        $settings['ai_provider'] = $providerName;

        $provider = AiProviderFactory::create($settings);
        $copilot = new AiCopilot($provider, self::$db, $settings, 5);

        $events = [];
        $result = $copilot->answerStreaming(self::PROMPT, function (AiStreamEvent $e) use (&$events) {
            $events[] = $e->toArray();
        });

        $this->assertTrue($result->success, "$providerName: a streamelt Copilot-futásnak sikeresnek kellett volna lennie.");
        $this->assertSame(['inventory'], $result->agentsUsed, "$providerName: ugyanahhoz az ügynökhöz kellett volna irányítania streamelve is.");
        $this->assertStringContainsString($expectedAnswerFragment, (string) $result->answer, "$providerName: a válasznak a stub végleges szövegét kellett tartalmaznia.");
        $this->assertTrue($result->streamed, "$providerName: az eredménynek jeleznie kellett volna, hogy ténylegesen streamelve történt.");

        $types = array_column($events, 'type');
        $this->assertContains('agent_started', $types, "$providerName: hiányzik az agent_started esemény.");
        $this->assertContains('tool_call_started', $types, "$providerName: hiányzik a tool_call_started esemény (az ask_inventory_agent meta-eszközre).");
        $this->assertContains('tool_call_completed', $types, "$providerName: hiányzik a tool_call_completed esemény.");
        $this->assertContains('final', $types, "$providerName: hiányzik a final esemény.");

        // A kör 3. pontja — a streamelt esemény-sorozat SOSE tartalmazhat
        // nyers provider-payloadot (pl. Anthropic "content_block_delta"
        // vagy OpenAI "response.output_item.added" natív típusnevet) — csak
        // a szigorúan whitelistelt AiStreamEvent-típusokat.
        foreach ($types as $type) {
            $this->assertContains($type, AiStreamEvent::TYPES, "$providerName: whitelisten kívüli eseménytípus szivárgott ki: $type");
        }
    }

    public static function providerNames(): array
    {
        return [
            'local (Ollama)' => ['local', 'Ollama:'],
            'anthropic (Claude)' => ['anthropic', 'Anthropic:'],
            'openai' => ['openai', 'OpenAI:'],
        ];
    }
}
