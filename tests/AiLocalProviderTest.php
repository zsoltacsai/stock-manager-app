<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * LocalProvider (Ollama HTTP kliens) VALÓDI HTTP-hívásokkal bizonyított
 * tesztje, egy KIZÁRÓLAG 127.0.0.1-en futó loopback stub-szerver ellen
 * — ugyanaz a minta, mint WooCommerceClientTest.php-ban. Nincs valódi
 * Ollama-függőség (lásd a kör 14. pontja).
 */
final class AiLocalProviderTest extends TestCase
{
    private static string $stubRoot;
    private static int $stubPort;
    /** @var resource|null */
    private static $stubServerProcess;
    private static string $baseUrl;

    public static function setUpBeforeClass(): void
    {
        self::$stubRoot = sys_get_temp_dir() . '/sm_ai_stub_' . bin2hex(random_bytes(6));
        mkdir(self::$stubRoot, 0775, true);
        file_put_contents(self::$stubRoot . '/index.php', <<<'PHP'
<?php
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
header('Content-Type: application/json');
switch ($path) {
    case '/api/chat':
        $body = json_decode(file_get_contents('php://input'), true);
        $mode = $body['messages'][1]['content'] ?? '';
        if ($mode === 'trigger_tool_call') {
            echo json_encode(['message' => ['role' => 'assistant', 'content' => '', 'tool_calls' => [
                ['function' => ['name' => 'get_low_stock_products', 'arguments' => ['filter' => 'low']]],
            ]]]);
        } elseif ($mode === 'trigger_string_arguments') {
            echo json_encode(['message' => ['role' => 'assistant', 'content' => '', 'tool_calls' => [
                ['function' => ['name' => 'get_product', 'arguments' => '{"id": 5}']],
            ]]]);
        } elseif ($mode === 'trigger_malformed') {
            echo json_encode(['no_message_field' => true]);
        } elseif ($mode === 'trigger_not_json') {
            echo 'ez nem { érvényes json';
        } elseif ($mode === 'trigger_http_error') {
            http_response_code(500);
            echo json_encode(['error' => 'internal error']);
        } elseif ($mode === 'trigger_client_error') {
            http_response_code(400);
            echo json_encode(['error' => 'invalid request']);
        } elseif ($mode === 'trigger_slow') {
            usleep(1500000);
            echo json_encode(['message' => ['role' => 'assistant', 'content' => 'lassú válasz']]);
        } else {
            echo json_encode(['message' => ['role' => 'assistant', 'content' => 'Sima szöveges válasz.']]);
        }
        break;
    case '/api/tags':
        $mode = $_GET['mode'] ?? 'ok';
        if ($mode === 'down') {
            http_response_code(500);
            echo json_encode(['error' => 'down']);
            break;
        }
        echo json_encode(['models' => [
            ['name' => 'qwen3:8b'],
            ['name' => 'llama3:latest'],
        ]]);
        break;
    default:
        http_response_code(404);
        echo json_encode(['error' => 'unknown stub route']);
}
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
            self::fail('Nem sikerült elindítani az AI stub teszt-szervert.');
        }
        self::waitForStubReady();
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
        self::fail('Az AI stub teszt-szerver nem indult el időben.');
    }

    private function chatWithTrigger(string $trigger, int $timeout = 5): AiChatResponse
    {
        $provider = new LocalProvider(self::$baseUrl, 'qwen3:8b', $timeout);
        return $provider->chat(
            [['role' => 'system', 'content' => 'sys'], ['role' => 'user', 'content' => $trigger]],
            []
        );
    }

    public function testSuccessfulPlainTextResponse(): void
    {
        $response = $this->chatWithTrigger('normal');
        $this->assertSame('Sima szöveges válasz.', $response->content);
        $this->assertFalse($response->hasToolCalls());
    }

    public function testToolCallParsing(): void
    {
        $response = $this->chatWithTrigger('trigger_tool_call');
        $this->assertTrue($response->hasToolCalls());
        $this->assertCount(1, $response->toolCalls);
        $this->assertSame('get_low_stock_products', $response->toolCalls[0]->name);
        $this->assertSame(['filter' => 'low'], $response->toolCalls[0]->arguments);
        $this->assertNotSame('', $response->toolCalls[0]->id);
    }

    public function testToolCallArgumentsAsJsonStringAreDecoded(): void
    {
        $response = $this->chatWithTrigger('trigger_string_arguments');
        $this->assertSame(['id' => 5], $response->toolCalls[0]->arguments);
    }

    public function testMalformedResponseMissingMessageFieldThrows(): void
    {
        $this->expectException(AiProviderException::class);
        $this->chatWithTrigger('trigger_malformed');
    }

    public function testNonJsonResponseThrows(): void
    {
        $this->expectException(AiProviderException::class);
        $this->chatWithTrigger('trigger_not_json');
    }

    public function testServerErrorResponseThrowsAsUnavailable(): void
    {
        // Fázis 10 — a kör 8/17. pontja: egy 5xx (pl. a modell még
        // betöltés alatt) egy VALÓDI, jellemzően ÁTMENETI
        // elérhetetlenség — 'unavailable', NEM 'http_error' (utóbbi
        // determinisztikus 4xx kérés-hibát jelent, lásd lent).
        try {
            $this->chatWithTrigger('trigger_http_error');
            $this->fail('Exception várt volt.');
        } catch (AiProviderException $e) {
            $this->assertSame('unavailable', $e->kind);
        }
    }

    public function testClientErrorResponseThrowsAsHttpError(): void
    {
        // Egy 4xx (a kérés maga hibás) determinisztikus — SOSE
        // 'unavailable' (ami újrapróbálást sugallna, lásd AiRetryPolicy.php).
        try {
            $this->chatWithTrigger('trigger_client_error');
            $this->fail('Exception várt volt.');
        } catch (AiProviderException $e) {
            $this->assertSame('http_error', $e->kind);
        }
    }

    public function testConnectionFailureToClosedPortThrows(): void
    {
        $provider = new LocalProvider('http://127.0.0.1:1', 'qwen3:8b', 3);
        $this->expectException(AiProviderException::class);
        $provider->chat([['role' => 'user', 'content' => 'x']], []);
    }

    public function testTimeoutThrows(): void
    {
        $provider = new LocalProvider(self::$baseUrl, 'qwen3:8b', 1);
        try {
            $provider->chat(
                [['role' => 'system', 'content' => 'sys'], ['role' => 'user', 'content' => 'trigger_slow']],
                []
            );
            $this->fail('Exception várt volt.');
        } catch (AiProviderException $e) {
            $this->assertContains($e->kind, ['timeout', 'unavailable']);
        }
    }

    // ------------------------------------------------------------------
    // checkAvailability()
    // ------------------------------------------------------------------

    public function testAvailabilityWhenModelIsPresent(): void
    {
        $provider = new LocalProvider(self::$baseUrl, 'qwen3:8b', 5);
        $availability = $provider->checkAvailability();
        $this->assertSame('available', $availability->status);
    }

    public function testAvailabilityMatchesModelNameIgnoringLatestSuffix(): void
    {
        $provider = new LocalProvider(self::$baseUrl, 'llama3', 5);
        $availability = $provider->checkAvailability();
        $this->assertSame('available', $availability->status);
    }

    public function testAvailabilityWhenModelIsMissing(): void
    {
        $provider = new LocalProvider(self::$baseUrl, 'nem-letezo-modell:1b', 5);
        $availability = $provider->checkAvailability();
        $this->assertSame('model_error', $availability->status);
    }

    public function testAvailabilityWhenOllamaUnreachable(): void
    {
        $provider = new LocalProvider('http://127.0.0.1:1', 'qwen3:8b', 3);
        $availability = $provider->checkAvailability();
        $this->assertSame('unavailable', $availability->status);
        $this->assertNotNull($availability->message);
    }
}
