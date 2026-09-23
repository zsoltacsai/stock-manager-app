<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Fázis 6, kör 21-23. pontja — UGYANAZ a Copilot fut le UGYANAZON az
 * AgentRunner-en, UGYANAZON a ToolRegistry-n (ask_inventory_agent/
 * ask_sales_agent/ask_anomaly_agent) keresztül, mindhárom providerrel
 * (LocalProvider/Ollama, AnthropicProvider/Claude, OpenAiProvider) —
 * ugyanaz a minta, mint tests/AiAnomalyCrossProviderRegressionTest.php.
 * A pontos természetes nyelvi kimenetnek NEM kell egyeznie — az
 * eszköz-szemantikának (UGYANAZ az ügynök-választás, UGYANAZ a
 * determinisztikus eszköz-eredmény) igen.
 */
final class AiCopilotCrossProviderRegressionTest extends TestCase
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
        self::$stubRoot = sys_get_temp_dir() . '/sm_ai_copilot_cross_provider_stub_' . bin2hex(random_bytes(6));
        mkdir(self::$stubRoot, 0775, true);
        file_put_contents(self::$stubRoot . '/index.php', <<<'PHP'
<?php
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
header('Content-Type: application/json');

// --- Ollama-alakú útvonalak ---
if ($path === '/api/tags') {
    echo json_encode(['models' => [['name' => 'qwen3:8b']]]);
    exit;
}
if ($path === '/api/chat') {
    $body = json_decode(file_get_contents('php://input'), true);
    $hasToolResult = false;
    foreach ($body['messages'] ?? [] as $m) {
        if (($m['role'] ?? '') === 'tool') { $hasToolResult = true; }
    }
    if ($hasToolResult) {
        echo json_encode(['message' => ['role' => 'assistant', 'content' => 'Ollama: a készlet alapján válaszoltam.']]);
    } else {
        echo json_encode(['message' => ['role' => 'assistant', 'content' => '', 'tool_calls' => [
            ['function' => ['name' => 'ask_inventory_agent', 'arguments' => ['question' => 'Mi fogyott ki?']]],
        ]]]);
    }
    exit;
}

// --- Anthropic-alakú útvonalak ---
if ($path === '/v1/models') {
    echo json_encode(['data' => [['id' => 'claude-sonnet-5'], ['id' => 'gpt-6-sol']]]);
    exit;
}
if ($path === '/v1/messages') {
    $body = json_decode(file_get_contents('php://input'), true);
    $messages = $body['messages'] ?? [];
    $last = end($messages);
    $hasToolResult = is_array($last) && ($last['role'] ?? '') === 'user' && is_array($last['content'] ?? null)
        && isset($last['content'][0]['type']) && $last['content'][0]['type'] === 'tool_result';
    if ($hasToolResult) {
        echo json_encode([
            'id' => 'msg_2', 'type' => 'message', 'role' => 'assistant',
            'content' => [['type' => 'text', 'text' => 'Anthropic: a készlet alapján válaszoltam.']],
            'model' => $body['model'], 'stop_reason' => 'end_turn',
        ]);
    } else {
        echo json_encode([
            'id' => 'msg_1', 'type' => 'message', 'role' => 'assistant',
            'content' => [['type' => 'tool_use', 'id' => 'toolu_1', 'name' => 'ask_inventory_agent', 'input' => ['question' => 'Mi fogyott ki?']]],
            'model' => $body['model'], 'stop_reason' => 'tool_use',
        ]);
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
    if ($hasToolResult) {
        echo json_encode([
            'id' => 'resp_2', 'object' => 'response', 'model' => $body['model'],
            'output' => [
                ['id' => 'msg_2', 'type' => 'message', 'role' => 'assistant', 'status' => 'completed',
                 'content' => [['type' => 'output_text', 'text' => 'OpenAI: a készlet alapján válaszoltam.']]],
            ],
        ]);
    } else {
        echo json_encode([
            'id' => 'resp_1', 'object' => 'response', 'model' => $body['model'],
            'output' => [
                ['id' => 'fc_1', 'call_id' => 'call_1', 'type' => 'function_call', 'name' => 'ask_inventory_agent', 'arguments' => '{"question":"Mi fogyott ki?"}'],
            ],
        ]);
    }
    exit;
}

http_response_code(404);
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
            self::fail('Nem sikerült elindítani a kombinált AI stub teszt-szervert.');
        }
        self::waitForStubReady();

        self::$db = tests_new_database();
        $pdo = self::$db->pdo();
        $pdo->exec('INSERT INTO products (name, unit, price, net_price, stock_qty, low_stock_threshold, group_name, vat_rate) VALUES ("Cross-provider copilot teszttermék", "db", 1000, 787, 0, 5, "Italok", "27")');
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
        self::fail('A kombinált AI stub teszt-szerver nem indult el időben.');
    }

    private function baseSettings(): array
    {
        return [
            'ai_local_base_url' => self::$baseUrl,
            'ai_local_model' => 'qwen3:8b',
            'ai_timeout_seconds' => 10,
            'ai_max_output_tokens' => null,
            'anthropic_api_key' => 'sk-ant-copilot-cross-teszt',
            'anthropic_model' => 'claude-sonnet-5',
            'anthropic_base_url' => self::$baseUrl,
            'anthropic_timeout_seconds' => 10,
            'openai_api_key' => 'sk-openai-copilot-cross-teszt',
            'openai_model' => 'gpt-6-sol',
            'openai_base_url' => self::$baseUrl,
            'openai_timeout_seconds' => 10,
        ];
    }

    /** @dataProvider providerNames */
    public function testSameCopilotRoutesToTheSameAgentCorrectlyViaEachProvider(string $providerName, string $expectedAnswerFragment): void
    {
        $settings = $this->baseSettings();
        $settings['ai_provider'] = $providerName;

        $provider = AiProviderFactory::create($settings);
        $copilot = new AiCopilot($provider, self::$db, $settings, 5);

        $result = $copilot->answer(self::PROMPT);

        $this->assertTrue($result->success, "$providerName: a Copilot-futásnak sikeresnek kellett volna lennie.");
        $this->assertSame(['inventory'], $result->agentsUsed, "$providerName: ugyanahhoz az ügynökhöz kellett volna irányítania.");
        $this->assertStringContainsString($expectedAnswerFragment, (string) $result->answer, "$providerName: a válasznak a stub végleges szövegét kellett tartalmaznia.");
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
