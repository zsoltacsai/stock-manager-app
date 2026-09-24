<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Fázis 9 — AnthropicProvider::chatStream() VALÓDI HTTP-n (loopback
 * stub-szerver, SSE) bizonyított tesztje — lásd tests/
 * AiAnthropicProviderTest.php azonos mintája a szinkron chat()-hez, és
 * a kör 2. pontja szerinti kutatás (message_start/content_block_start/
 * content_block_delta/content_block_stop/message_delta/message_stop/
 * ping/error) alapján felépített, VALÓDI SSE-választ adó stub.
 */
final class AiAnthropicProviderStreamingTest extends TestCase
{
    private static string $stubRoot;
    private static int $stubPort;
    /** @var resource|null */
    private static $stubServerProcess;
    private static string $baseUrl;

    public static function setUpBeforeClass(): void
    {
        self::$stubRoot = sys_get_temp_dir() . '/sm_ai_anthropic_stream_stub_' . bin2hex(random_bytes(6));
        mkdir(self::$stubRoot, 0775, true);
        file_put_contents(self::$stubRoot . '/index.php', <<<'PHP'
<?php
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
if ($path !== '/v1/messages') {
    http_response_code(404);
    echo json_encode(['error' => 'unknown stub route']);
    exit;
}
$body = json_decode(file_get_contents('php://input'), true);
$messages = $body['messages'] ?? [];
$last = end($messages);
$mode = is_array($last) ? (string) ($last['content'] ?? '') : '';

if ($mode === 'anthropic_http_error') {
    http_response_code(429);
    header('Content-Type: application/json');
    echo json_encode(['type' => 'error', 'error' => ['type' => 'rate_limit_error', 'message' => 'too many requests']]);
    exit;
}

header('Content-Type: text/event-stream');
while (ob_get_level() > 0) { ob_end_flush(); }

function sse($type, $data) {
    echo "event: $type\n";
    echo 'data: ' . json_encode($data) . "\n\n";
    flush();
}

if ($mode === 'anthropic_stream_text') {
    sse('message_start', ['type' => 'message_start', 'message' => ['id' => 'msg_1', 'usage' => ['input_tokens' => 15, 'cache_read_input_tokens' => 0]]]);
    sse('content_block_start', ['type' => 'content_block_start', 'index' => 0, 'content_block' => ['type' => 'text', 'text' => '']]);
    sse('content_block_delta', ['type' => 'content_block_delta', 'index' => 0, 'delta' => ['type' => 'text_delta', 'text' => 'Szia, ']]);
    sse('content_block_delta', ['type' => 'content_block_delta', 'index' => 0, 'delta' => ['type' => 'text_delta', 'text' => 'ez Claude.']]);
    sse('content_block_stop', ['type' => 'content_block_stop', 'index' => 0]);
    sse('message_delta', ['type' => 'message_delta', 'delta' => ['stop_reason' => 'end_turn'], 'usage' => ['output_tokens' => 9]]);
    sse('message_stop', ['type' => 'message_stop']);
} elseif ($mode === 'anthropic_stream_tool_use') {
    sse('message_start', ['type' => 'message_start', 'message' => ['id' => 'msg_2', 'usage' => ['input_tokens' => 40]]]);
    sse('content_block_start', ['type' => 'content_block_start', 'index' => 0, 'content_block' => ['type' => 'tool_use', 'id' => 'toolu_1', 'name' => 'get_low_stock_products', 'input' => new stdClass()]]);
    sse('content_block_delta', ['type' => 'content_block_delta', 'index' => 0, 'delta' => ['type' => 'input_json_delta', 'partial_json' => '{"filt']]);
    sse('content_block_delta', ['type' => 'content_block_delta', 'index' => 0, 'delta' => ['type' => 'input_json_delta', 'partial_json' => 'er": "l']]);
    sse('content_block_delta', ['type' => 'content_block_delta', 'index' => 0, 'delta' => ['type' => 'input_json_delta', 'partial_json' => 'ow"}']]);
    sse('content_block_stop', ['type' => 'content_block_stop', 'index' => 0]);
    sse('message_delta', ['type' => 'message_delta', 'delta' => ['stop_reason' => 'tool_use'], 'usage' => ['output_tokens' => 22]]);
    sse('message_stop', ['type' => 'message_stop']);
} elseif ($mode === 'anthropic_stream_multi_tool_use') {
    sse('message_start', ['type' => 'message_start', 'message' => ['id' => 'msg_3', 'usage' => ['input_tokens' => 50]]]);
    sse('content_block_start', ['type' => 'content_block_start', 'index' => 0, 'content_block' => ['type' => 'text', 'text' => '']]);
    sse('content_block_delta', ['type' => 'content_block_delta', 'index' => 0, 'delta' => ['type' => 'text_delta', 'text' => 'Megnézem mindkettőt.']]);
    sse('content_block_stop', ['type' => 'content_block_stop', 'index' => 0]);
    sse('content_block_start', ['type' => 'content_block_start', 'index' => 1, 'content_block' => ['type' => 'tool_use', 'id' => 'toolu_a', 'name' => 'get_low_stock_products', 'input' => new stdClass()]]);
    sse('content_block_delta', ['type' => 'content_block_delta', 'index' => 1, 'delta' => ['type' => 'input_json_delta', 'partial_json' => '{}']]);
    sse('content_block_stop', ['type' => 'content_block_stop', 'index' => 1]);
    sse('content_block_start', ['type' => 'content_block_start', 'index' => 2, 'content_block' => ['type' => 'tool_use', 'id' => 'toolu_b', 'name' => 'get_sales_summary', 'input' => new stdClass()]]);
    sse('content_block_delta', ['type' => 'content_block_delta', 'index' => 2, 'delta' => ['type' => 'input_json_delta', 'partial_json' => '{"period": "today"}']]);
    sse('content_block_stop', ['type' => 'content_block_stop', 'index' => 2]);
    sse('message_delta', ['type' => 'message_delta', 'delta' => ['stop_reason' => 'tool_use'], 'usage' => ['output_tokens' => 40]]);
    sse('message_stop', ['type' => 'message_stop']);
} elseif ($mode === 'anthropic_stream_error_event') {
    sse('message_start', ['type' => 'message_start', 'message' => ['id' => 'msg_4', 'usage' => ['input_tokens' => 10]]]);
    sse('content_block_start', ['type' => 'content_block_start', 'index' => 0, 'content_block' => ['type' => 'text', 'text' => '']]);
    sse('content_block_delta', ['type' => 'content_block_delta', 'index' => 0, 'delta' => ['type' => 'text_delta', 'text' => 'Elkezdtem...']]);
    sse('error', ['type' => 'error', 'error' => ['type' => 'overloaded_error', 'message' => 'Overloaded']]);
} elseif ($mode === 'anthropic_stream_with_ping') {
    sse('message_start', ['type' => 'message_start', 'message' => ['id' => 'msg_5', 'usage' => ['input_tokens' => 8]]]);
    sse('ping', ['type' => 'ping']);
    sse('content_block_start', ['type' => 'content_block_start', 'index' => 0, 'content_block' => ['type' => 'text', 'text' => '']]);
    sse('ping', ['type' => 'ping']);
    sse('content_block_delta', ['type' => 'content_block_delta', 'index' => 0, 'delta' => ['type' => 'text_delta', 'text' => 'Ping-tűrő válasz.']]);
    sse('content_block_stop', ['type' => 'content_block_stop', 'index' => 0]);
    sse('message_delta', ['type' => 'message_delta', 'delta' => ['stop_reason' => 'end_turn'], 'usage' => ['output_tokens' => 5]]);
    sse('message_stop', ['type' => 'message_stop']);
} elseif ($mode === 'anthropic_stream_malformed') {
    sse('message_start', ['type' => 'message_start', 'message' => ['id' => 'msg_6', 'usage' => ['input_tokens' => 8]]]);
    echo "data: ez nem { érvényes json\n\n";
    flush();
    sse('content_block_start', ['type' => 'content_block_start', 'index' => 0, 'content_block' => ['type' => 'text', 'text' => '']]);
    sse('content_block_delta', ['type' => 'content_block_delta', 'index' => 0, 'delta' => ['type' => 'text_delta', 'text' => 'Folytatva a hiba után.']]);
    sse('content_block_stop', ['type' => 'content_block_stop', 'index' => 0]);
    sse('message_delta', ['type' => 'message_delta', 'delta' => ['stop_reason' => 'end_turn'], 'usage' => ['output_tokens' => 6]]);
    sse('message_stop', ['type' => 'message_stop']);
} elseif ($mode === 'anthropic_stream_no_stop') {
    sse('message_start', ['type' => 'message_start', 'message' => ['id' => 'msg_7', 'usage' => ['input_tokens' => 8]]]);
    sse('content_block_start', ['type' => 'content_block_start', 'index' => 0, 'content_block' => ['type' => 'text', 'text' => '']]);
    sse('content_block_delta', ['type' => 'content_block_delta', 'index' => 0, 'delta' => ['type' => 'text_delta', 'text' => 'Megszakadt...']]);
    // szándékosan nincs message_stop
} else {
    sse('message_start', ['type' => 'message_start', 'message' => ['id' => 'msg_x', 'usage' => ['input_tokens' => 1]]]);
    sse('content_block_start', ['type' => 'content_block_start', 'index' => 0, 'content_block' => ['type' => 'text', 'text' => '']]);
    sse('content_block_delta', ['type' => 'content_block_delta', 'index' => 0, 'delta' => ['type' => 'text_delta', 'text' => 'alapértelmezett']]);
    sse('content_block_stop', ['type' => 'content_block_stop', 'index' => 0]);
    sse('message_delta', ['type' => 'message_delta', 'delta' => ['stop_reason' => 'end_turn'], 'usage' => ['output_tokens' => 1]]);
    sse('message_stop', ['type' => 'message_stop']);
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

    public function testTextStreamEmitsDeltasAndCumulativeUsage(): void
    {
        $provider = new AnthropicProvider(self::$baseUrl, 'test-key', 'claude-sonnet-5', 5);
        $events = [];
        $response = $provider->chatStream($this->messages('anthropic_stream_text'), [], function (AiStreamEvent $e) use (&$events) {
            $events[] = $e;
        });

        $this->assertSame('Szia, ez Claude.', $response->content);
        $this->assertNotNull($response->usage);
        $this->assertSame(15, $response->usage->inputTokens);
        $this->assertSame(9, $response->usage->outputTokens);

        $textDeltas = array_values(array_filter($events, static fn ($e) => $e->type === AiStreamEvent::TYPE_TEXT_DELTA));
        $this->assertCount(2, $textDeltas);
    }

    public function testToolUseStreamAssemblesCompleteJsonFromFragments(): void
    {
        $provider = new AnthropicProvider(self::$baseUrl, 'test-key', 'claude-sonnet-5', 5);
        $events = [];
        $response = $provider->chatStream($this->messages('anthropic_stream_tool_use'), [], function (AiStreamEvent $e) use (&$events) {
            $events[] = $e;
        });

        $this->assertCount(1, $response->toolCalls);
        $this->assertSame('get_low_stock_products', $response->toolCalls[0]->name);
        $this->assertSame(['filter' => 'low'], $response->toolCalls[0]->arguments);
        $this->assertSame('toolu_1', $response->toolCalls[0]->id);

        $started = array_values(array_filter($events, static fn ($e) => $e->type === AiStreamEvent::TYPE_TOOL_CALL_STARTED));
        $this->assertCount(1, $started);
        $this->assertSame('get_low_stock_products', $started[0]->payload['name']);
    }

    public function testMultipleToolUseBlocksStayDistinctByIndex(): void
    {
        $provider = new AnthropicProvider(self::$baseUrl, 'test-key', 'claude-sonnet-5', 5);
        $response = $provider->chatStream($this->messages('anthropic_stream_multi_tool_use'), [], function (AiStreamEvent $e) {});

        $this->assertSame('Megnézem mindkettőt.', $response->content);
        $this->assertCount(2, $response->toolCalls);
        $this->assertSame('get_low_stock_products', $response->toolCalls[0]->name);
        $this->assertSame('get_sales_summary', $response->toolCalls[1]->name);
        $this->assertSame(['period' => 'today'], $response->toolCalls[1]->arguments);
    }

    public function testMidStreamErrorEventThrows(): void
    {
        $provider = new AnthropicProvider(self::$baseUrl, 'test-key', 'claude-sonnet-5', 5);
        try {
            $provider->chatStream($this->messages('anthropic_stream_error_event'), [], function (AiStreamEvent $e) {});
            $this->fail('AiProviderException-t vártunk.');
        } catch (AiProviderException $e) {
            $this->assertStringContainsString('Overloaded', $e->getMessage());
        }
    }

    public function testPingEventsAreIgnored(): void
    {
        $provider = new AnthropicProvider(self::$baseUrl, 'test-key', 'claude-sonnet-5', 5);
        $response = $provider->chatStream($this->messages('anthropic_stream_with_ping'), [], function (AiStreamEvent $e) {});
        $this->assertSame('Ping-tűrő válasz.', $response->content);
    }

    public function testMalformedEventLineIsSkippedNotFatal(): void
    {
        $provider = new AnthropicProvider(self::$baseUrl, 'test-key', 'claude-sonnet-5', 5);
        $response = $provider->chatStream($this->messages('anthropic_stream_malformed'), [], function (AiStreamEvent $e) {});
        $this->assertSame('Folytatva a hiba után.', $response->content);
    }

    public function testMissingMessageStopThrows(): void
    {
        $provider = new AnthropicProvider(self::$baseUrl, 'test-key', 'claude-sonnet-5', 5);
        $this->expectException(AiProviderException::class);
        $provider->chatStream($this->messages('anthropic_stream_no_stop'), [], function (AiStreamEvent $e) {});
    }

    public function testHttpErrorBeforeStreamStartsThrows(): void
    {
        $provider = new AnthropicProvider(self::$baseUrl, 'test-key', 'claude-sonnet-5', 5);
        try {
            $provider->chatStream($this->messages('anthropic_http_error'), [], function (AiStreamEvent $e) {});
            $this->fail('AiProviderException-t vártunk.');
        } catch (AiProviderException $e) {
            $this->assertSame('rate_limit', $e->kind);
        }
    }
}
