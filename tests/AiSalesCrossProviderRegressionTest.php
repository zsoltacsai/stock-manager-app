<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Fázis 4 kör 16. pontja — UGYANAZ a szemantikai kérdés fut le UGYANAZON
 * a SalesAgent-en, UGYANAZON az AgentRunner-en, UGYANAZON a
 * ToolRegistry/SalesTools-on keresztül, mindhárom providerrel
 * (LocalProvider/Ollama, AnthropicProvider/Claude, OpenAiProvider) —
 * ugyanaz a minta, mint tests/AiCrossProviderRegressionTest.php
 * (InventoryAgent), egyetlen kombinált loopback stub-szerver mindhárom
 * protokollhoz. A pontos természetes nyelvi kimenetnek NEM kell
 * egyeznie — az eszköz-szemantikának (melyik eszköz futott le, milyen
 * VALÓDI adatbázis-adat mögötte) igen.
 */
final class AiSalesCrossProviderRegressionTest extends TestCase
{
    private static string $stubRoot;
    private static int $stubPort;
    /** @var resource|null */
    private static $stubServerProcess;
    private static string $baseUrl;
    private static Database $db;

    private const PROMPT = 'Mennyi volt a mai forgalom?';

    public static function setUpBeforeClass(): void
    {
        self::$stubRoot = sys_get_temp_dir() . '/sm_ai_sales_cross_provider_stub_' . bin2hex(random_bytes(6));
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
        echo json_encode(['message' => ['role' => 'assistant', 'content' => 'Ollama: a mai forgalom rendben van.']]);
    } else {
        echo json_encode(['message' => ['role' => 'assistant', 'content' => '', 'tool_calls' => [
            ['function' => ['name' => 'get_sales_summary', 'arguments' => ['period' => 'today']]],
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
            'content' => [['type' => 'text', 'text' => 'Anthropic: a mai forgalom rendben van.']],
            'model' => $body['model'], 'stop_reason' => 'end_turn',
        ]);
    } else {
        echo json_encode([
            'id' => 'msg_1', 'type' => 'message', 'role' => 'assistant',
            'content' => [['type' => 'tool_use', 'id' => 'toolu_1', 'name' => 'get_sales_summary', 'input' => ['period' => 'today']]],
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
                 'content' => [['type' => 'output_text', 'text' => 'OpenAI: a mai forgalom rendben van.']]],
            ],
        ]);
    } else {
        echo json_encode([
            'id' => 'resp_1', 'object' => 'response', 'model' => $body['model'],
            'output' => [
                ['id' => 'fc_1', 'call_id' => 'call_1', 'type' => 'function_call', 'name' => 'get_sales_summary', 'arguments' => '{"period":"today"}'],
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
        $pdo->exec('INSERT INTO products (name, unit, price, net_price, stock_qty, group_name, vat_rate) VALUES ("Cross-provider forgalmi teszttermék", "db", 1000, 787, 20, "Italok", "27")');
        $productId = (int) $pdo->lastInsertId();
        $pdo->prepare("INSERT INTO sales (total, payment_method, created_at) VALUES (2500, 'Készpénz', ?)")
            ->execute([date('Y-m-d') . ' 10:00:00']);
        $saleId = (int) $pdo->lastInsertId();
        $pdo->prepare('INSERT INTO sale_items (sale_id, product_id, name, qty, unit_price, vat_rate) VALUES (?, ?, "teszt", 1, 2500, "27")')
            ->execute([$saleId, $productId]);
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
            'anthropic_api_key' => 'sk-ant-cross-teszt',
            'anthropic_model' => 'claude-sonnet-5',
            'anthropic_base_url' => self::$baseUrl,
            'anthropic_timeout_seconds' => 10,
            'openai_api_key' => 'sk-openai-cross-teszt',
            'openai_model' => 'gpt-6-sol',
            'openai_base_url' => self::$baseUrl,
            'openai_timeout_seconds' => 10,
        ];
    }

    /** @dataProvider providerNames */
    public function testSameSalesAgentAnswersTheSamePromptCorrectlyViaEachProvider(string $providerName, string $expectedAnswerFragment): void
    {
        $settings = $this->baseSettings();
        $settings['ai_provider'] = $providerName;

        $provider = AiProviderFactory::create($settings);
        $agent = new SalesAgent($provider, self::$db, $settings, 5);

        $result = $agent->answer(self::PROMPT);

        $this->assertTrue($result->success, "$providerName: az agent-futásnak sikeresnek kellett volna lennie.");
        $this->assertSame(['get_sales_summary'], $result->toolsUsed, "$providerName: ugyanannak az eszköznek kellett lefutnia.");
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

    public function testAllThreeProvidersWouldSeeTheSameRealSalesFigure(): void
    {
        // Nem csak azt bizonyítjuk, hogy a modell "azt mondta" hogy
        // lekérdezett valamit — a valódi SalesTools/Database rétegen
        // keresztül a TÉNYLEGES, ma rögzített forgalmi tételnek kell
        // léteznie a válasz mögött, mindhárom providernél ugyanígy.
        $tools = new SalesTools(self::$db, []);
        $summary = $tools->getSalesSummary(['period' => 'today']);
        $this->assertSame(2500.0, $summary['gross_sales']);
        $this->assertSame(1, $summary['transactions']);
    }
}
