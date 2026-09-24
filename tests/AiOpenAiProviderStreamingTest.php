<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Fázis 9 — OpenAiProvider::chatStream() VALÓDI HTTP-n (loopback
 * stub-szerver, SSE) bizonyított tesztje — a kör 2. pontja szerinti
 * kutatás (OpenAPI-generált SDK-típusok) alapján felépített stub:
 * `response.output_item.added`/`response.output_text.delta`/
 * `response.output_item.done`/`response.completed`/`response.failed`/
 * `error`. Külön teszt bizonyítja, hogy a reasoning item replay
 * ($rawOutputBatches, lásd AiOpenAiReasoningReplayTest.php) streamelt
 * válaszra is helyesen működik (a kör 4. pontja: "multi-step tool
 * calling" streamelve).
 */
final class AiOpenAiProviderStreamingTest extends TestCase
{
    private static string $stubRoot;
    private static int $stubPort;
    /** @var resource|null */
    private static $stubServerProcess;
    private static string $baseUrl;

    public static function setUpBeforeClass(): void
    {
        self::$stubRoot = sys_get_temp_dir() . '/sm_ai_openai_stream_stub_' . bin2hex(random_bytes(6));
        mkdir(self::$stubRoot, 0775, true);
        file_put_contents(self::$stubRoot . '/index.php', <<<'PHP'
<?php
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
if ($path !== '/v1/responses') {
    http_response_code(404);
    echo json_encode(['error' => ['message' => 'unknown stub route']]);
    exit;
}
$raw = file_get_contents('php://input');
$requestNum = 0;
$counterFile = __DIR__ . '/request_count.txt';
if (is_file($counterFile)) { $requestNum = (int) file_get_contents($counterFile); }
$requestNum++;
file_put_contents($counterFile, (string) $requestNum);
file_put_contents(__DIR__ . "/last_request_$requestNum.json", $raw);

$body = json_decode($raw, true);
$input = $body['input'] ?? [];
$toolOutputCount = 0;
foreach ($input as $item) {
    if (is_array($item) && ($item['type'] ?? '') === 'function_call_output') { $toolOutputCount++; }
}
$first = $input[0] ?? [];
$mode = is_string($first['content'] ?? null) ? $first['content'] : '';

if ($mode === 'openai_http_error') {
    http_response_code(401);
    header('Content-Type: application/json');
    echo json_encode(['error' => ['message' => 'invalid api key', 'type' => 'authentication_error']]);
    exit;
}

header('Content-Type: text/event-stream');
while (ob_get_level() > 0) { ob_end_flush(); }
function sse($obj) { echo 'data: ' . json_encode($obj) . "\n\n"; flush(); }

if ($toolOutputCount > 0) {
    // Folytatás egy korábbi tool-hívás után (reasoning replay teszt) —
    // a MÁSODIK kérés tartalmát a teszt maga ellenőrzi.
    sse(['type' => 'response.output_item.added', 'output_index' => 0, 'item' => ['type' => 'message', 'id' => 'msg_final']]);
    sse(['type' => 'response.output_text.delta', 'output_index' => 0, 'delta' => 'Végleges válasz a folytatás után.']);
    sse(['type' => 'response.output_item.done', 'output_index' => 0, 'item' => [
        'id' => 'msg_final', 'type' => 'message', 'role' => 'assistant', 'status' => 'completed',
        'content' => [['type' => 'output_text', 'text' => 'Végleges válasz a folytatás után.']],
    ]]);
    sse(['type' => 'response.completed', 'response' => ['usage' => ['input_tokens' => 90, 'output_tokens' => 12, 'total_tokens' => 102]]]);
    exit;
}

if ($mode === 'openai_stream_text') {
    sse(['type' => 'response.created']);
    sse(['type' => 'response.output_item.added', 'output_index' => 0, 'item' => ['type' => 'message', 'id' => 'msg_1']]);
    sse(['type' => 'response.output_text.delta', 'output_index' => 0, 'delta' => 'Szia, ']);
    sse(['type' => 'response.output_text.delta', 'output_index' => 0, 'delta' => 'itt GPT.']);
    sse(['type' => 'response.output_item.done', 'output_index' => 0, 'item' => [
        'id' => 'msg_1', 'type' => 'message', 'role' => 'assistant', 'status' => 'completed',
        'content' => [['type' => 'output_text', 'text' => 'Szia, itt GPT.']],
    ]]);
    sse(['type' => 'response.completed', 'response' => ['usage' => ['input_tokens' => 20, 'output_tokens' => 6, 'total_tokens' => 26, 'output_tokens_details' => ['reasoning_tokens' => 2]]]]);
} elseif ($mode === 'openai_stream_tool_call') {
    sse(['type' => 'response.output_item.added', 'output_index' => 0, 'item' => ['type' => 'function_call', 'id' => 'fc_1', 'call_id' => 'call_1', 'name' => 'get_low_stock_products']]);
    sse(['type' => 'response.function_call_arguments.delta', 'output_index' => 0, 'delta' => '{"filt']);
    sse(['type' => 'response.function_call_arguments.delta', 'output_index' => 0, 'delta' => 'er": "low"}']);
    sse(['type' => 'response.output_item.done', 'output_index' => 0, 'item' => [
        'id' => 'fc_1', 'type' => 'function_call', 'call_id' => 'call_1', 'name' => 'get_low_stock_products', 'arguments' => '{"filter": "low"}', 'status' => 'completed',
    ]]);
    sse(['type' => 'response.completed', 'response' => ['usage' => ['input_tokens' => 30, 'output_tokens' => 10, 'total_tokens' => 40]]]);
} elseif ($mode === 'openai_stream_multi_tool') {
    sse(['type' => 'response.output_item.added', 'output_index' => 0, 'item' => ['type' => 'function_call', 'id' => 'fc_a', 'call_id' => 'call_a', 'name' => 'get_low_stock_products']]);
    sse(['type' => 'response.output_item.done', 'output_index' => 0, 'item' => ['id' => 'fc_a', 'type' => 'function_call', 'call_id' => 'call_a', 'name' => 'get_low_stock_products', 'arguments' => '{}', 'status' => 'completed']]);
    sse(['type' => 'response.output_item.added', 'output_index' => 1, 'item' => ['type' => 'function_call', 'id' => 'fc_b', 'call_id' => 'call_b', 'name' => 'get_sales_summary']]);
    sse(['type' => 'response.output_item.done', 'output_index' => 1, 'item' => ['id' => 'fc_b', 'type' => 'function_call', 'call_id' => 'call_b', 'name' => 'get_sales_summary', 'arguments' => '{"period": "today"}', 'status' => 'completed']]);
    sse(['type' => 'response.completed', 'response' => ['usage' => ['input_tokens' => 50, 'output_tokens' => 20, 'total_tokens' => 70]]]);
} elseif ($mode === 'openai_stream_reasoning_tool_call') {
    sse(['type' => 'response.output_item.added', 'output_index' => 0, 'item' => ['type' => 'reasoning', 'id' => 'rs_abc']]);
    sse(['type' => 'response.output_item.done', 'output_index' => 0, 'item' => ['id' => 'rs_abc', 'type' => 'reasoning', 'summary' => [], 'encrypted_content' => 'OPAQUE_REASONING_BLOB']]);
    sse(['type' => 'response.output_item.added', 'output_index' => 1, 'item' => ['type' => 'function_call', 'id' => 'fc_r', 'call_id' => 'call_r', 'name' => 'get_low_stock_products']]);
    sse(['type' => 'response.output_item.done', 'output_index' => 1, 'item' => ['id' => 'fc_r', 'type' => 'function_call', 'call_id' => 'call_r', 'name' => 'get_low_stock_products', 'arguments' => '{}', 'status' => 'completed']]);
    sse(['type' => 'response.completed', 'response' => ['usage' => ['input_tokens' => 60, 'output_tokens' => 15, 'total_tokens' => 75]]]);
} elseif ($mode === 'openai_stream_failed') {
    sse(['type' => 'response.output_item.added', 'output_index' => 0, 'item' => ['type' => 'message', 'id' => 'msg_e']]);
    sse(['type' => 'response.output_text.delta', 'output_index' => 0, 'delta' => 'Elkezdve...']);
    sse(['type' => 'response.failed', 'response' => ['error' => ['code' => 'server_error', 'message' => 'internal error']]]);
} elseif ($mode === 'openai_stream_error_event') {
    sse(['type' => 'error', 'code' => null, 'message' => 'stream error occurred']);
} elseif ($mode === 'openai_stream_malformed') {
    sse(['type' => 'response.output_item.added', 'output_index' => 0, 'item' => ['type' => 'message', 'id' => 'msg_m']]);
    echo "data: ez nem { érvényes json\n\n";
    flush();
    sse(['type' => 'response.output_text.delta', 'output_index' => 0, 'delta' => 'Folytatva.']);
    sse(['type' => 'response.output_item.done', 'output_index' => 0, 'item' => ['id' => 'msg_m', 'type' => 'message', 'role' => 'assistant', 'status' => 'completed', 'content' => [['type' => 'output_text', 'text' => 'Folytatva.']]]]);
    sse(['type' => 'response.completed', 'response' => ['usage' => ['input_tokens' => 5, 'output_tokens' => 3, 'total_tokens' => 8]]]);
} elseif ($mode === 'openai_stream_no_terminal') {
    sse(['type' => 'response.output_item.added', 'output_index' => 0, 'item' => ['type' => 'message', 'id' => 'msg_n']]);
    sse(['type' => 'response.output_text.delta', 'output_index' => 0, 'delta' => 'Megszakadt...']);
    // szándékosan nincs response.completed/incomplete/failed
} else {
    sse(['type' => 'response.output_item.added', 'output_index' => 0, 'item' => ['type' => 'message', 'id' => 'msg_x']]);
    sse(['type' => 'response.output_text.delta', 'output_index' => 0, 'delta' => 'alapértelmezett']);
    sse(['type' => 'response.output_item.done', 'output_index' => 0, 'item' => ['id' => 'msg_x', 'type' => 'message', 'role' => 'assistant', 'status' => 'completed', 'content' => [['type' => 'output_text', 'text' => 'alapértelmezett']]]]);
    sse(['type' => 'response.completed', 'response' => ['usage' => ['input_tokens' => 1, 'output_tokens' => 1, 'total_tokens' => 2]]]);
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

    public function testTextStreamEmitsDeltasAndUsageWithReasoningTokens(): void
    {
        $provider = new OpenAiProvider(self::$baseUrl, 'test-key', 'gpt-6-sol', 5);
        $events = [];
        $response = $provider->chatStream($this->messages('openai_stream_text'), [], function (AiStreamEvent $e) use (&$events) {
            $events[] = $e;
        });

        $this->assertSame('Szia, itt GPT.', $response->content);
        $this->assertNotNull($response->usage);
        $this->assertSame(20, $response->usage->inputTokens);
        $this->assertSame(6, $response->usage->outputTokens);
        $this->assertSame(2, $response->usage->reasoningTokens);

        $textDeltas = array_values(array_filter($events, static fn ($e) => $e->type === AiStreamEvent::TYPE_TEXT_DELTA));
        $this->assertCount(2, $textDeltas);
    }

    public function testToolCallStreamProducesCompleteToolCall(): void
    {
        $provider = new OpenAiProvider(self::$baseUrl, 'test-key', 'gpt-6-sol', 5);
        $events = [];
        $response = $provider->chatStream($this->messages('openai_stream_tool_call'), [], function (AiStreamEvent $e) use (&$events) {
            $events[] = $e;
        });

        $this->assertCount(1, $response->toolCalls);
        $this->assertSame('get_low_stock_products', $response->toolCalls[0]->name);
        $this->assertSame('call_1', $response->toolCalls[0]->id);
        $this->assertSame(['filter' => 'low'], $response->toolCalls[0]->arguments);

        $started = array_values(array_filter($events, static fn ($e) => $e->type === AiStreamEvent::TYPE_TOOL_CALL_STARTED));
        $this->assertCount(1, $started);
    }

    public function testMultipleToolCallsStayDistinctByOutputIndex(): void
    {
        $provider = new OpenAiProvider(self::$baseUrl, 'test-key', 'gpt-6-sol', 5);
        $response = $provider->chatStream($this->messages('openai_stream_multi_tool'), [], function (AiStreamEvent $e) {});

        $this->assertCount(2, $response->toolCalls);
        $this->assertSame('get_low_stock_products', $response->toolCalls[0]->name);
        $this->assertSame('get_sales_summary', $response->toolCalls[1]->name);
        $this->assertSame(['period' => 'today'], $response->toolCalls[1]->arguments);
    }

    public function testReasoningItemReplaySurvivesStreamingAcrossTwoCalls(): void
    {
        // Ugyanaz a kritikus regresszió, mint AiOpenAiReasoningReplayTest.php
        // — itt SPECIFIKUSAN streamelt első hívással, annak bizonyítására,
        // hogy a buildResponseFromOutput() megosztott metódus miatt a
        // $rawOutputBatches bookkeeping streamelt válaszra is helyesen
        // működik.
        $provider = new OpenAiProvider(self::$baseUrl, 'test-key', 'gpt-6-sol', 5);
        $first = $provider->chatStream($this->messages('openai_stream_reasoning_tool_call'), [], function (AiStreamEvent $e) {});
        $this->assertCount(1, $first->toolCalls);

        $messagesForSecondCall = $this->messages('openai_stream_reasoning_tool_call');
        $messagesForSecondCall[] = [
            'role' => 'assistant',
            'content' => $first->content,
            'tool_calls' => [[
                'id' => $first->toolCalls[0]->id,
                'function' => ['name' => $first->toolCalls[0]->name, 'arguments' => $first->toolCalls[0]->arguments],
            ]],
        ];
        $messagesForSecondCall[] = [
            'role' => 'tool',
            'tool_call_id' => $first->toolCalls[0]->id,
            'name' => $first->toolCalls[0]->name,
            'content' => json_encode(['success' => true, 'data' => ['products' => []]]),
        ];

        // A megosztott stub-szerver kérés-számlálója a teszt-OSZTÁLY
        // ÖSSZES metódusa között közös (más tesztmetódusok is hívják
        // ugyanazt a szervert korábban) — ezért a TÉNYLEGES sorszámot a
        // számláló-fájlból olvassuk, SOSE feltételezzük, hogy ez az
        // "1./2. kérés" a szerver teljes élettartamában.
        $second = $provider->chatStream($messagesForSecondCall, [], function (AiStreamEvent $e) {});
        $this->assertSame('Végleges válasz a folytatás után.', $second->content);

        $requestNum = (int) trim((string) file_get_contents(self::$stubRoot . '/request_count.txt'));
        $secondRequestPath = self::$stubRoot . "/last_request_$requestNum.json";
        $this->assertFileExists($secondRequestPath);
        $secondRequestBody = json_decode((string) file_get_contents($secondRequestPath), true);
        $reasoningItems = array_values(array_filter($secondRequestBody['input'], static fn ($i) => is_array($i) && ($i['type'] ?? '') === 'reasoning'));
        $this->assertCount(1, $reasoningItems, 'A második kérésnek pontosan az eredeti reasoning elemet kell tartalmaznia.');
        $this->assertSame('OPAQUE_REASONING_BLOB', $reasoningItems[0]['encrypted_content']);
    }

    public function testResponseFailedEventThrows(): void
    {
        $provider = new OpenAiProvider(self::$baseUrl, 'test-key', 'gpt-6-sol', 5);
        try {
            $provider->chatStream($this->messages('openai_stream_failed'), [], function (AiStreamEvent $e) {});
            $this->fail('AiProviderException-t vártunk.');
        } catch (AiProviderException $e) {
            $this->assertStringContainsString('internal error', $e->getMessage());
        }
    }

    public function testTopLevelErrorEventThrows(): void
    {
        $provider = new OpenAiProvider(self::$baseUrl, 'test-key', 'gpt-6-sol', 5);
        try {
            $provider->chatStream($this->messages('openai_stream_error_event'), [], function (AiStreamEvent $e) {});
            $this->fail('AiProviderException-t vártunk.');
        } catch (AiProviderException $e) {
            $this->assertStringContainsString('stream error occurred', $e->getMessage());
        }
    }

    public function testMalformedLineIsSkippedNotFatal(): void
    {
        $provider = new OpenAiProvider(self::$baseUrl, 'test-key', 'gpt-6-sol', 5);
        $response = $provider->chatStream($this->messages('openai_stream_malformed'), [], function (AiStreamEvent $e) {});
        $this->assertSame('Folytatva.', $response->content);
    }

    public function testMissingTerminalEventThrows(): void
    {
        $provider = new OpenAiProvider(self::$baseUrl, 'test-key', 'gpt-6-sol', 5);
        $this->expectException(AiProviderException::class);
        $provider->chatStream($this->messages('openai_stream_no_terminal'), [], function (AiStreamEvent $e) {});
    }

    public function testHttpErrorBeforeStreamStartsThrows(): void
    {
        $provider = new OpenAiProvider(self::$baseUrl, 'test-key', 'gpt-6-sol', 5);
        try {
            $provider->chatStream($this->messages('openai_http_error'), [], function (AiStreamEvent $e) {});
            $this->fail('AiProviderException-t vártunk.');
        } catch (AiProviderException $e) {
            $this->assertSame('auth_error', $e->kind);
        }
    }
}
