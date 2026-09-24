<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Fázis 9 — LocalProvider::chatStream() VALÓDI HTTP-n (loopback
 * stub-szerver, NDJSON) bizonyított tesztje — lásd tests/
 * AiLocalProviderTest.php azonos mintája a szinkron chat()-hez. A
 * stub egy VALÓDI, soronként flush()-olt NDJSON-választ ad, hogy a
 * curl WRITEFUNCTION ténylegesen több híváson keresztül dolgozza fel.
 */
final class AiLocalProviderStreamingTest extends TestCase
{
    private static string $stubRoot;
    private static int $stubPort;
    /** @var resource|null */
    private static $stubServerProcess;
    private static string $baseUrl;

    public static function setUpBeforeClass(): void
    {
        self::$stubRoot = sys_get_temp_dir() . '/sm_ai_stream_stub_' . bin2hex(random_bytes(6));
        mkdir(self::$stubRoot, 0775, true);
        file_put_contents(self::$stubRoot . '/index.php', <<<'PHP'
<?php
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
if ($path !== '/api/chat') {
    http_response_code(404);
    echo json_encode(['error' => 'unknown stub route']);
    exit;
}
$body = json_decode(file_get_contents('php://input'), true);
$mode = $body['messages'][1]['content'] ?? '';
header('Content-Type: application/x-ndjson');
while (ob_get_level() > 0) { ob_end_flush(); }

function emit($obj) {
    echo json_encode($obj) . "\n";
    flush();
}

if ($mode === 'stream_text') {
    emit(['message' => ['role' => 'assistant', 'content' => 'Szia, ']]);
    emit(['message' => ['role' => 'assistant', 'content' => 'ez egy streamelt ']]);
    emit(['message' => ['role' => 'assistant', 'content' => 'válasz.']]);
    emit(['done' => true, 'done_reason' => 'stop', 'prompt_eval_count' => 12, 'eval_count' => 34]);
} elseif ($mode === 'stream_tool_call') {
    emit(['message' => ['role' => 'assistant', 'content' => '']]);
    emit(['message' => ['role' => 'assistant', 'content' => '', 'tool_calls' => [
        ['function' => ['name' => 'get_low_stock_products', 'arguments' => ['filter' => 'low']]],
    ]]]);
    emit(['done' => true, 'done_reason' => 'stop', 'prompt_eval_count' => 20, 'eval_count' => 5]);
} elseif ($mode === 'stream_multi_tool') {
    emit(['message' => ['role' => 'assistant', 'content' => '', 'tool_calls' => [
        ['function' => ['name' => 'get_low_stock_products', 'arguments' => ['filter' => 'low']]],
    ]]]);
    emit(['message' => ['role' => 'assistant', 'content' => '', 'tool_calls' => [
        ['function' => ['name' => 'get_sales_summary', 'arguments' => ['period' => 'today']]],
    ]]]);
    emit(['done' => true, 'done_reason' => 'stop', 'prompt_eval_count' => 30, 'eval_count' => 8]);
} elseif ($mode === 'stream_error') {
    emit(['message' => ['role' => 'assistant', 'content' => 'Elkezdett ']]);
    emit(['error' => 'a model futása közben hiba történt']);
} elseif ($mode === 'stream_malformed_line') {
    emit(['message' => ['role' => 'assistant', 'content' => 'Rendben, ']]);
    echo "ez nem { érvényes json\n";
    flush();
    emit(['message' => ['role' => 'assistant', 'content' => 'folytatva.']]);
    emit(['done' => true, 'done_reason' => 'stop', 'prompt_eval_count' => 5, 'eval_count' => 3]);
} elseif ($mode === 'stream_no_done') {
    emit(['message' => ['role' => 'assistant', 'content' => 'Megszakadt válasz...']]);
    // szándékosan nincs "done":true sor
} else {
    emit(['message' => ['role' => 'assistant', 'content' => 'alapértelmezett.']]);
    emit(['done' => true, 'prompt_eval_count' => 1, 'eval_count' => 1]);
}
PHP);
        self::$stubPort = self::findFreePort();
        self::$baseUrl = 'http://127.0.0.1:' . self::$stubPort;
        self::$stubServerProcess = proc_open(
            [PHP_BINARY, '-S', '127.0.0.1:' . self::$stubPort, '-t', self::$stubRoot],
            [1 => ['file', self::$stubRoot . '/log.txt', 'w'], 2 => ['file', self::$stubRoot . '/log.txt', 'w']],
            $pipes
        );
        self::waitForReady(self::$stubPort);
    }

    public static function tearDownAfterClass(): void
    {
        if (self::$stubServerProcess !== null && is_resource(self::$stubServerProcess)) {
            proc_terminate(self::$stubServerProcess);
            proc_close(self::$stubServerProcess);
        }
        self::removeDir(self::$stubRoot);
    }

    private static function findFreePort(): int
    {
        $sock = stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);
        if ($sock === false) self::fail('Nem sikerült szabad portot találni: ' . $errstr);
        $name = stream_socket_get_name($sock, false);
        fclose($sock);
        return (int) substr($name, strrpos($name, ':') + 1);
    }

    private static function waitForReady(int $port): void
    {
        $deadline = microtime(true) + 10;
        while (microtime(true) < $deadline) {
            $fp = @fsockopen('127.0.0.1', $port, $errno, $errstr, 0.2);
            if ($fp) { fclose($fp); return; }
            usleep(100_000);
        }
        self::fail('A stub szerver nem indult el időben.');
    }

    private static function removeDir(string $dir): void
    {
        if (!is_dir($dir)) return;
        foreach (scandir($dir) as $item) {
            if ($item === '.' || $item === '..') continue;
            $path = "$dir/$item";
            is_dir($path) && !is_link($path) ? self::removeDir($path) : @unlink($path);
        }
        @rmdir($dir);
    }

    private function messages(string $mode): array
    {
        return [
            ['role' => 'system', 'content' => 'system'],
            ['role' => 'user', 'content' => $mode],
        ];
    }

    public function testTextStreamEmitsDeltasAndFinalContentWithUsage(): void
    {
        $provider = new LocalProvider(self::$baseUrl, 'qwen3:8b', 5);
        $events = [];
        $response = $provider->chatStream($this->messages('stream_text'), [], function (AiStreamEvent $e) use (&$events) {
            $events[] = $e;
        });

        $this->assertSame('Szia, ez egy streamelt válasz.', $response->content);
        $this->assertFalse($response->hasToolCalls());
        $this->assertNotNull($response->usage);
        $this->assertSame(12, $response->usage->inputTokens);
        $this->assertSame(34, $response->usage->outputTokens);
        $this->assertSame(46, $response->usage->totalTokens);

        $textDeltas = array_filter($events, static fn ($e) => $e->type === AiStreamEvent::TYPE_TEXT_DELTA);
        $this->assertCount(3, $textDeltas);
        $usageEvents = array_filter($events, static fn ($e) => $e->type === AiStreamEvent::TYPE_USAGE);
        $this->assertCount(1, $usageEvents);
    }

    public function testToolCallStreamAssemblesCompleteToolCall(): void
    {
        $provider = new LocalProvider(self::$baseUrl, 'qwen3:8b', 5);
        $response = $provider->chatStream($this->messages('stream_tool_call'), [], function (AiStreamEvent $e) {});

        $this->assertTrue($response->hasToolCalls());
        $this->assertCount(1, $response->toolCalls);
        $this->assertSame('get_low_stock_products', $response->toolCalls[0]->name);
        $this->assertSame(['filter' => 'low'], $response->toolCalls[0]->arguments);
    }

    public function testMultiToolStreamKeepsBothCallsDistinct(): void
    {
        $provider = new LocalProvider(self::$baseUrl, 'qwen3:8b', 5);
        $response = $provider->chatStream($this->messages('stream_multi_tool'), [], function (AiStreamEvent $e) {});

        $this->assertCount(2, $response->toolCalls);
        $names = array_map(static fn ($c) => $c->name, $response->toolCalls);
        $this->assertSame(['get_low_stock_products', 'get_sales_summary'], $names);
        $ids = array_map(static fn ($c) => $c->id, $response->toolCalls);
        $this->assertSame($ids, array_unique($ids), 'A két hívás id-jének különbözőnek kell lennie.');
    }

    public function testStreamCompletionRequiresDoneEvent(): void
    {
        $provider = new LocalProvider(self::$baseUrl, 'qwen3:8b', 5);
        $this->expectException(AiProviderException::class);
        $provider->chatStream($this->messages('stream_no_done'), [], function (AiStreamEvent $e) {});
    }

    public function testStreamErrorEventThrowsProviderException(): void
    {
        $provider = new LocalProvider(self::$baseUrl, 'qwen3:8b', 5);
        try {
            $provider->chatStream($this->messages('stream_error'), [], function (AiStreamEvent $e) {});
            $this->fail('AiProviderException-t vártunk.');
        } catch (AiProviderException $e) {
            $this->assertStringContainsString('hiba', $e->getMessage());
        }
    }

    public function testMalformedLineIsSkippedNotFatal(): void
    {
        $provider = new LocalProvider(self::$baseUrl, 'qwen3:8b', 5);
        $response = $provider->chatStream($this->messages('stream_malformed_line'), [], function (AiStreamEvent $e) {});

        $this->assertSame('Rendben, folytatva.', $response->content);
    }
}
