<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * AnthropicProvider (Anthropic Messages API HTTP-kliens) VALÓDI HTTP-
 * hívásokkal bizonyított tesztje, egy KIZÁRÓLAG 127.0.0.1-en futó
 * loopback stub-szerver ellen, amely az Anthropic API dokumentált
 * kontraktusát szimulálja (content block-ok, tool_use/tool_result,
 * hibaformátum) — ugyanaz a minta, mint AiLocalProviderTest.php. Nincs
 * valódi Anthropic-API-kulcs-függőség (lásd a kör 13/14. pontja).
 */
final class AiAnthropicProviderTest extends TestCase
{
    private static string $stubRoot;
    private static int $stubPort;
    /** @var resource|null */
    private static $stubServerProcess;
    private static string $baseUrl;

    public static function setUpBeforeClass(): void
    {
        self::$stubRoot = sys_get_temp_dir() . '/sm_ai_anthropic_stub_' . bin2hex(random_bytes(6));
        mkdir(self::$stubRoot, 0775, true);
        file_put_contents(self::$stubRoot . '/index.php', <<<'PHP'
<?php
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
header('Content-Type: application/json');
$apiKey = $_SERVER['HTTP_X_API_KEY'] ?? '';

if ($path === '/v1/messages') {
    $raw = file_get_contents('php://input');
    file_put_contents(__DIR__ . '/last_request.json', $raw);
    $body = json_decode($raw, true);

    if ($apiKey === 'wrong-key') {
        http_response_code(401);
        echo json_encode(['type' => 'error', 'error' => ['type' => 'authentication_error', 'message' => 'invalid x-api-key']]);
        exit;
    }
    if (($_SERVER['HTTP_ANTHROPIC_VERSION'] ?? '') === '') {
        http_response_code(400);
        echo json_encode(['type' => 'error', 'error' => ['type' => 'invalid_request_error', 'message' => 'missing anthropic-version header']]);
        exit;
    }

    $messages = $body['messages'] ?? [];
    $last = end($messages);
    $isToolResultContinuation = is_array($last) && ($last['role'] ?? '') === 'user'
        && is_array($last['content'] ?? null)
        && isset($last['content'][0]['type']) && $last['content'][0]['type'] === 'tool_result';

    if ($isToolResultContinuation) {
        echo json_encode([
            'id' => 'msg_2', 'type' => 'message', 'role' => 'assistant',
            'content' => [['type' => 'text', 'text' => 'Végleges válasz a tool_result után.']],
            'model' => $body['model'], 'stop_reason' => 'end_turn',
        ]);
        exit;
    }

    $first = $messages[0] ?? [];
    $trigger = is_string($first['content'] ?? null) ? $first['content'] : '';

    switch ($trigger) {
        case 'trigger_tool_use':
            echo json_encode([
                'id' => 'msg_1', 'type' => 'message', 'role' => 'assistant',
                'content' => [['type' => 'tool_use', 'id' => 'toolu_1', 'name' => 'get_low_stock_products', 'input' => ['filter' => 'low']]],
                'model' => $body['model'], 'stop_reason' => 'tool_use',
            ]);
            break;
        case 'trigger_multi_tool_use':
            echo json_encode([
                'id' => 'msg_1', 'type' => 'message', 'role' => 'assistant',
                'content' => [
                    ['type' => 'text', 'text' => 'Két dolgot nézek meg.'],
                    ['type' => 'tool_use', 'id' => 'toolu_a', 'name' => 'tool_a', 'input' => []],
                    ['type' => 'tool_use', 'id' => 'toolu_b', 'name' => 'tool_b', 'input' => ['x' => 1]],
                ],
                'model' => $body['model'], 'stop_reason' => 'tool_use',
            ]);
            break;
        case 'trigger_malformed':
            echo json_encode(['id' => 'msg_1', 'type' => 'message', 'role' => 'assistant']);
            break;
        case 'trigger_not_json':
            echo 'ez nem { érvényes json';
            break;
        case 'trigger_http_401':
            http_response_code(401);
            echo json_encode(['type' => 'error', 'error' => ['type' => 'authentication_error', 'message' => 'invalid key']]);
            break;
        case 'trigger_http_403':
            http_response_code(403);
            echo json_encode(['type' => 'error', 'error' => ['type' => 'permission_error', 'message' => 'forbidden']]);
            break;
        case 'trigger_http_429':
            http_response_code(429);
            echo json_encode(['type' => 'error', 'error' => ['type' => 'rate_limit_error', 'message' => 'rate limited']]);
            break;
        case 'trigger_http_500':
            http_response_code(500);
            echo json_encode(['type' => 'error', 'error' => ['type' => 'api_error', 'message' => 'internal']]);
            break;
        case 'trigger_slow':
            usleep(1500000);
            echo json_encode(['id' => 'msg_1', 'type' => 'message', 'role' => 'assistant', 'content' => [['type' => 'text', 'text' => 'lassú']], 'model' => $body['model'], 'stop_reason' => 'end_turn']);
            break;
        default:
            echo json_encode(['id' => 'msg_1', 'type' => 'message', 'role' => 'assistant', 'content' => [['type' => 'text', 'text' => 'Sima szöveges válasz.']], 'model' => $body['model'], 'stop_reason' => 'end_turn']);
    }
    exit;
}

if ($path === '/v1/models') {
    if ($apiKey === 'wrong-key' || $apiKey === '') {
        http_response_code(401);
        echo json_encode(['type' => 'error', 'error' => ['type' => 'authentication_error', 'message' => 'invalid x-api-key']]);
        exit;
    }
    echo json_encode(['data' => [
        ['id' => 'claude-sonnet-5'],
        ['id' => 'claude-opus-5-5'],
    ]]);
    exit;
}

http_response_code(404);
echo json_encode(['type' => 'error', 'error' => ['type' => 'not_found_error', 'message' => 'unknown stub route']]);
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
            self::fail('Nem sikerült elindítani az Anthropic stub teszt-szervert.');
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
        self::fail('Az Anthropic stub teszt-szerver nem indult el időben.');
    }

    private function lastRequestBody(): array
    {
        $raw = file_get_contents(self::$stubRoot . '/last_request.json');
        $decoded = json_decode((string) $raw, true);
        return is_array($decoded) ? $decoded : [];
    }

    private function chatWithTrigger(string $trigger, int $timeout = 5, string $apiKey = 'sk-ant-teszt-kulcs'): AiChatResponse
    {
        $provider = new AnthropicProvider(self::$baseUrl, $apiKey, 'claude-sonnet-5', $timeout);
        return $provider->chat(
            [['role' => 'system', 'content' => 'sys'], ['role' => 'user', 'content' => $trigger]],
            []
        );
    }

    // ------------------------------------------------------------------
    // A. Sikeres szöveges válasz
    // ------------------------------------------------------------------

    public function testSuccessfulPlainTextResponse(): void
    {
        $response = $this->chatWithTrigger('normal');
        $this->assertSame('Sima szöveges válasz.', $response->content);
        $this->assertFalse($response->hasToolCalls());
    }

    public function testRequestBodySeparatesSystemPromptFromMessages(): void
    {
        $this->chatWithTrigger('normal');
        $body = $this->lastRequestBody();
        $this->assertSame('sys', $body['system'] ?? null);
        $this->assertSame('user', $body['messages'][0]['role'] ?? null);
        $this->assertArrayHasKey('max_tokens', $body);
    }

    // ------------------------------------------------------------------
    // B. Egyetlen tool_use blokk
    // ------------------------------------------------------------------

    public function testSingleToolUseResponseParsing(): void
    {
        $response = $this->chatWithTrigger('trigger_tool_use');
        $this->assertTrue($response->hasToolCalls());
        $this->assertCount(1, $response->toolCalls);
        $this->assertSame('get_low_stock_products', $response->toolCalls[0]->name);
        $this->assertSame(['filter' => 'low'], $response->toolCalls[0]->arguments);
        $this->assertSame('toolu_1', $response->toolCalls[0]->id);
    }

    // ------------------------------------------------------------------
    // C. Több tool_use blokk EGY assistant-körben
    // ------------------------------------------------------------------

    public function testMultipleToolUseBlocksInOneResponseAreAllParsed(): void
    {
        $response = $this->chatWithTrigger('trigger_multi_tool_use');
        $this->assertCount(2, $response->toolCalls);
        $this->assertSame('tool_a', $response->toolCalls[0]->name);
        $this->assertSame('toolu_a', $response->toolCalls[0]->id);
        $this->assertSame('tool_b', $response->toolCalls[1]->name);
        $this->assertSame(['x' => 1], $response->toolCalls[1]->arguments);
    }

    // ------------------------------------------------------------------
    // D. tool_result folytatás — a kritikus protokoll-szabály: TÖBB
    // eszköz-eredmény EGYETLEN, következő user-üzenetbe csoportosítva.
    // ------------------------------------------------------------------

    public function testMultipleToolResultsAreBundledIntoASingleUserMessage(): void
    {
        $provider = new AnthropicProvider(self::$baseUrl, 'sk-ant-teszt-kulcs', 'claude-sonnet-5', 5);

        // Ez a belső (Ollama/OpenAI-stílusú) üzenet-alak, amit a
        // ConversationManager ténylegesen épít két tool-hívás UTÁN — lásd
        // ConversationManager::addToolResult(), egy 'tool' szerepű
        // üzenet HÍVÁSONKÉNT. Az AnthropicProvider-nek ezt KELL egyetlen
        // Anthropic user-üzenetbe csoportosítania.
        $messages = [
            ['role' => 'system', 'content' => 'sys'],
            ['role' => 'user', 'content' => 'kérdés'],
            ['role' => 'assistant', 'content' => null, 'tool_calls' => [
                ['id' => 'toolu_a', 'function' => ['name' => 'tool_a', 'arguments' => []]],
                ['id' => 'toolu_b', 'function' => ['name' => 'tool_b', 'arguments' => ['x' => 1]]],
            ]],
            ['role' => 'tool', 'tool_call_id' => 'toolu_a', 'name' => 'tool_a', 'content' => json_encode(['success' => true, 'data' => ['a' => 1]])],
            ['role' => 'tool', 'tool_call_id' => 'toolu_b', 'name' => 'tool_b', 'content' => json_encode(['success' => false, 'error' => 'nem sikerült'])],
        ];

        $response = $provider->chat($messages, []);
        $this->assertSame('Végleges válasz a tool_result után.', $response->content);

        $body = $this->lastRequestBody();
        $sentMessages = $body['messages'];
        // A rendszer + user + assistant(tool_use) + EGYETLEN user(tool_result*2) — 3 üzenet, nem 4.
        $this->assertCount(3, $sentMessages);

        $toolResultMessage = $sentMessages[2];
        $this->assertSame('user', $toolResultMessage['role']);
        $this->assertIsArray($toolResultMessage['content']);
        $this->assertCount(2, $toolResultMessage['content']);
        $this->assertSame('tool_result', $toolResultMessage['content'][0]['type']);
        $this->assertSame('toolu_a', $toolResultMessage['content'][0]['tool_use_id']);
        $this->assertArrayNotHasKey('is_error', $toolResultMessage['content'][0]);
        $this->assertSame('tool_result', $toolResultMessage['content'][1]['type']);
        $this->assertSame('toolu_b', $toolResultMessage['content'][1]['tool_use_id']);
        $this->assertTrue($toolResultMessage['content'][1]['is_error']);
        $this->assertStringContainsString('nem sikerült', $toolResultMessage['content'][1]['content']);

        $assistantMessage = $sentMessages[1];
        $this->assertSame('assistant', $assistantMessage['role']);
        $toolUseBlocks = array_values(array_filter($assistantMessage['content'], fn ($b) => $b['type'] === 'tool_use'));
        $this->assertCount(2, $toolUseBlocks);
    }

    // ------------------------------------------------------------------
    // E-F. Hibásan formázott válaszok
    // ------------------------------------------------------------------

    public function testMalformedResponseMissingContentFieldThrows(): void
    {
        try {
            $this->chatWithTrigger('trigger_malformed');
            $this->fail('Exception várt volt.');
        } catch (AiProviderException $e) {
            $this->assertSame('malformed_response', $e->kind);
        }
    }

    public function testNonJsonResponseThrows(): void
    {
        try {
            $this->chatWithTrigger('trigger_not_json');
            $this->fail('Exception várt volt.');
        } catch (AiProviderException $e) {
            $this->assertSame('malformed_response', $e->kind);
        }
    }

    // ------------------------------------------------------------------
    // G. HTTP hibakódok
    // ------------------------------------------------------------------

    public function testHttp401ThrowsAuthErrorKind(): void
    {
        try {
            $this->chatWithTrigger('trigger_http_401');
            $this->fail('Exception várt volt.');
        } catch (AiProviderException $e) {
            $this->assertSame('auth_error', $e->kind);
            $this->assertStringNotContainsString('sk-ant-teszt-kulcs', $e->getMessage());
        }
    }

    public function testHttp403ThrowsAuthErrorKind(): void
    {
        try {
            $this->chatWithTrigger('trigger_http_403');
            $this->fail('Exception várt volt.');
        } catch (AiProviderException $e) {
            $this->assertSame('auth_error', $e->kind);
        }
    }

    public function testHttp429ThrowsRateLimitKind(): void
    {
        try {
            $this->chatWithTrigger('trigger_http_429');
            $this->fail('Exception várt volt.');
        } catch (AiProviderException $e) {
            $this->assertSame('rate_limit', $e->kind);
        }
    }

    public function testHttp500ThrowsUnavailableKind(): void
    {
        try {
            $this->chatWithTrigger('trigger_http_500');
            $this->fail('Exception várt volt.');
        } catch (AiProviderException $e) {
            $this->assertSame('unavailable', $e->kind);
        }
    }

    // ------------------------------------------------------------------
    // H. Hálózati hiba / időtúllépés
    // ------------------------------------------------------------------

    public function testConnectionFailureToClosedPortThrows(): void
    {
        $provider = new AnthropicProvider('http://127.0.0.1:1', 'sk-ant-x', 'claude-sonnet-5', 3);
        $this->expectException(AiProviderException::class);
        $provider->chat([['role' => 'user', 'content' => 'x']], []);
    }

    public function testTimeoutThrows(): void
    {
        try {
            $this->chatWithTrigger('trigger_slow', 1);
            $this->fail('Exception várt volt.');
        } catch (AiProviderException $e) {
            $this->assertContains($e->kind, ['timeout', 'unavailable']);
        }
    }

    // ------------------------------------------------------------------
    // I. Titok-redaction — az API-kulcs SOSE jelenhet meg a kivétel
    // üzenetében (lásd a kör 9. pontja).
    // ------------------------------------------------------------------

    public function testApiKeyNeverAppearsInExceptionMessageOnAnyFailureMode(): void
    {
        $secret = 'sk-ant-nagyon-titkos-kulcs-12345';
        foreach (['trigger_http_401', 'trigger_http_500', 'trigger_malformed', 'trigger_not_json'] as $trigger) {
            try {
                $this->chatWithTrigger($trigger, 5, $secret);
                $this->fail("Exception várt volt ($trigger).");
            } catch (AiProviderException $e) {
                $this->assertStringNotContainsString($secret, $e->getMessage(), "kiszivárgott a kulcs ($trigger) esetén");
            }
        }
    }

    // ------------------------------------------------------------------
    // checkAvailability()
    // ------------------------------------------------------------------

    public function testAvailabilityWhenApiKeyEmptyIsNotConfiguredWithoutAnyHttpCall(): void
    {
        $provider = new AnthropicProvider(self::$baseUrl, '', 'claude-sonnet-5', 5);
        $availability = $provider->checkAvailability();
        $this->assertSame('not_configured', $availability->status);
    }

    public function testAvailabilityWhenModelIsPresent(): void
    {
        $provider = new AnthropicProvider(self::$baseUrl, 'sk-ant-teszt-kulcs', 'claude-sonnet-5', 5);
        $availability = $provider->checkAvailability();
        $this->assertSame('available', $availability->status);
    }

    public function testAvailabilityWhenModelIsMissing(): void
    {
        $provider = new AnthropicProvider(self::$baseUrl, 'sk-ant-teszt-kulcs', 'nem-letezo-modell', 5);
        $availability = $provider->checkAvailability();
        $this->assertSame('model_error', $availability->status);
    }

    public function testAvailabilityWhenApiKeyInvalidIsAuthError(): void
    {
        $provider = new AnthropicProvider(self::$baseUrl, 'wrong-key', 'claude-sonnet-5', 5);
        $availability = $provider->checkAvailability();
        $this->assertSame('auth_error', $availability->status);
    }

    public function testAvailabilityWhenUnreachableIsUnavailable(): void
    {
        $provider = new AnthropicProvider('http://127.0.0.1:1', 'sk-ant-teszt-kulcs', 'claude-sonnet-5', 3);
        $availability = $provider->checkAvailability();
        $this->assertSame('unavailable', $availability->status);
        $this->assertNotNull($availability->message);
    }
}
