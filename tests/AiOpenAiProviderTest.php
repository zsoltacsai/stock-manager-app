<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * OpenAiProvider (OpenAI Responses API HTTP-kliens) VALÓDI HTTP-
 * hívásokkal bizonyított tesztje, egy KIZÁRÓLAG 127.0.0.1-en futó
 * loopback stub-szerver ellen, amely az OpenAI Responses API dokumentált
 * kontraktusát szimulálja (output item-ek, function_call/
 * function_call_output, hibaformátum) — ugyanaz a minta, mint
 * AiAnthropicProviderTest.php/AiLocalProviderTest.php. Nincs valódi
 * OpenAI-API-kulcs-függőség (lásd a kör 15/16. pontja).
 */
final class AiOpenAiProviderTest extends TestCase
{
    private static string $stubRoot;
    private static int $stubPort;
    /** @var resource|null */
    private static $stubServerProcess;
    private static string $baseUrl;

    public static function setUpBeforeClass(): void
    {
        self::$stubRoot = sys_get_temp_dir() . '/sm_ai_openai_stub_' . bin2hex(random_bytes(6));
        mkdir(self::$stubRoot, 0775, true);
        file_put_contents(self::$stubRoot . '/index.php', <<<'PHP'
<?php
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
header('Content-Type: application/json');
$authHeader = $_SERVER['HTTP_AUTHORIZATION'] ?? '';

if ($path === '/v1/responses') {
    $raw = file_get_contents('php://input');
    file_put_contents(__DIR__ . '/last_request.json', $raw);
    $body = json_decode($raw, true);

    if ($authHeader === 'Bearer wrong-key') {
        http_response_code(401);
        echo json_encode(['error' => ['message' => 'Incorrect API key provided', 'type' => 'invalid_request_error', 'code' => 'invalid_api_key']]);
        exit;
    }
    if (strpos($authHeader, 'Bearer ') !== 0) {
        http_response_code(401);
        echo json_encode(['error' => ['message' => 'missing bearer token', 'type' => 'invalid_request_error']]);
        exit;
    }

    $input = $body['input'] ?? [];
    $toolOutputCount = 0;
    foreach ($input as $item) {
        if (is_array($item) && ($item['type'] ?? '') === 'function_call_output') {
            $toolOutputCount++;
        }
    }

    if ($toolOutputCount === 2) {
        echo json_encode([
            'id' => 'resp_3', 'object' => 'response', 'model' => $body['model'],
            'output' => [
                ['id' => 'msg_3', 'type' => 'message', 'role' => 'assistant', 'status' => 'completed',
                 'content' => [['type' => 'output_text', 'text' => 'Végleges válasz a második tool_output után.']]],
            ],
        ]);
        exit;
    }
    if ($toolOutputCount === 1) {
        echo json_encode([
            'id' => 'resp_2', 'object' => 'response', 'model' => $body['model'],
            'output' => [
                ['id' => 'fc_2', 'call_id' => 'call_step_two', 'type' => 'function_call', 'name' => 'step_two', 'arguments' => '{}'],
            ],
        ]);
        exit;
    }

    $first = $input[0] ?? [];
    $trigger = is_string($first['content'] ?? null) ? $first['content'] : '';

    switch ($trigger) {
        case 'trigger_tool_call':
            echo json_encode([
                'id' => 'resp_1', 'object' => 'response', 'model' => $body['model'],
                'output' => [
                    ['id' => 'fc_1', 'call_id' => 'call_1', 'type' => 'function_call', 'name' => 'get_low_stock_products', 'arguments' => '{"filter":"low"}'],
                ],
            ]);
            break;
        case 'trigger_multi_tool_call':
            echo json_encode([
                'id' => 'resp_1', 'object' => 'response', 'model' => $body['model'],
                'output' => [
                    ['id' => 'r_1', 'type' => 'reasoning', 'summary' => []],
                    ['id' => 'fc_a', 'call_id' => 'call_a', 'type' => 'function_call', 'name' => 'tool_a', 'arguments' => '{}'],
                    ['id' => 'fc_b', 'call_id' => 'call_b', 'type' => 'function_call', 'name' => 'tool_b', 'arguments' => '{"x":1}'],
                ],
            ]);
            break;
        case 'trigger_two_step':
            echo json_encode([
                'id' => 'resp_1', 'object' => 'response', 'model' => $body['model'],
                'output' => [
                    ['id' => 'fc_1', 'call_id' => 'call_step_one', 'type' => 'function_call', 'name' => 'step_one', 'arguments' => '{}'],
                ],
            ]);
            break;
        case 'trigger_malformed':
            echo json_encode(['id' => 'resp_1', 'object' => 'response']);
            break;
        case 'trigger_not_json':
            echo 'ez nem { érvényes json';
            break;
        case 'trigger_http_400':
            http_response_code(400);
            echo json_encode(['error' => ['message' => 'invalid request', 'type' => 'invalid_request_error']]);
            break;
        case 'trigger_http_401':
            http_response_code(401);
            echo json_encode(['error' => ['message' => 'invalid key', 'type' => 'invalid_request_error']]);
            break;
        case 'trigger_http_403':
            http_response_code(403);
            echo json_encode(['error' => ['message' => 'forbidden', 'type' => 'permission_error']]);
            break;
        case 'trigger_http_429':
            http_response_code(429);
            echo json_encode(['error' => ['message' => 'rate limited', 'type' => 'rate_limit_error']]);
            break;
        case 'trigger_http_500':
            http_response_code(500);
            echo json_encode(['error' => ['message' => 'internal', 'type' => 'server_error']]);
            break;
        case 'trigger_unexpected_item':
            echo json_encode([
                'id' => 'resp_1', 'object' => 'response', 'model' => $body['model'],
                'output' => [
                    ['id' => 'wsc_1', 'type' => 'web_search_call', 'status' => 'completed'],
                    ['id' => 'msg_1', 'type' => 'message', 'role' => 'assistant', 'status' => 'completed',
                     'content' => [['type' => 'output_text', 'text' => 'Válasz egy ismeretlen elem-típus mellett is.']]],
                ],
            ]);
            break;
        case 'trigger_slow':
            usleep(1500000);
            echo json_encode(['id' => 'resp_1', 'object' => 'response', 'model' => $body['model'], 'output' => [
                ['id' => 'msg_1', 'type' => 'message', 'role' => 'assistant', 'status' => 'completed', 'content' => [['type' => 'output_text', 'text' => 'lassú']]],
            ]]);
            break;
        default:
            echo json_encode([
                'id' => 'resp_1', 'object' => 'response', 'model' => $body['model'],
                'output' => [
                    ['id' => 'msg_1', 'type' => 'message', 'role' => 'assistant', 'status' => 'completed',
                     'content' => [['type' => 'output_text', 'text' => 'Sima szöveges válasz.']]],
                ],
            ]);
    }
    exit;
}

if ($path === '/v1/models') {
    if ($authHeader === 'Bearer wrong-key') {
        http_response_code(401);
        echo json_encode(['error' => ['message' => 'invalid key', 'type' => 'invalid_request_error']]);
        exit;
    }
    echo json_encode(['data' => [
        ['id' => 'gpt-6-sol'],
        ['id' => 'gpt-6-astra'],
    ], 'object' => 'list']);
    exit;
}

http_response_code(404);
echo json_encode(['error' => ['message' => 'unknown stub route', 'type' => 'invalid_request_error']]);
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
            self::fail('Nem sikerült elindítani az OpenAI stub teszt-szervert.');
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
        self::fail('Az OpenAI stub teszt-szerver nem indult el időben.');
    }

    private function lastRequestBody(): array
    {
        $raw = file_get_contents(self::$stubRoot . '/last_request.json');
        $decoded = json_decode((string) $raw, true);
        return is_array($decoded) ? $decoded : [];
    }

    private function chatWithTrigger(string $trigger, int $timeout = 5, string $apiKey = 'sk-openai-teszt-kulcs'): AiChatResponse
    {
        $provider = new OpenAiProvider(self::$baseUrl, $apiKey, 'gpt-6-sol', $timeout);
        return $provider->chat(
            [['role' => 'system', 'content' => 'sys'], ['role' => 'user', 'content' => $trigger]],
            []
        );
    }

    // ------------------------------------------------------------------
    // 1. Sikeres szöveges válasz
    // ------------------------------------------------------------------

    public function testSuccessfulPlainTextResponse(): void
    {
        $response = $this->chatWithTrigger('normal');
        $this->assertSame('Sima szöveges válasz.', $response->content);
        $this->assertFalse($response->hasToolCalls());
    }

    public function test16CorrectResponsesApiRequestStructure(): void
    {
        $this->chatWithTrigger('normal');
        $body = $this->lastRequestBody();
        $this->assertSame('sys', $body['instructions'] ?? null);
        $this->assertSame('gpt-6-sol', $body['model'] ?? null);
        $this->assertFalse($body['store'] ?? true);
        $this->assertSame('user', $body['input'][0]['role'] ?? null);
        $this->assertSame('normal', $body['input'][0]['content'] ?? null);
    }

    public function test15CorrectAuthorizationHeaderHandling(): void
    {
        try {
            $this->chatWithTrigger('normal', 5, 'wrong-key');
            $this->fail('Exception várt volt.');
        } catch (AiProviderException $e) {
            $this->assertSame('auth_error', $e->kind);
        }
    }

    // ------------------------------------------------------------------
    // 2. Egyetlen function/tool call
    // ------------------------------------------------------------------

    public function testSingleFunctionCallResponseParsing(): void
    {
        $response = $this->chatWithTrigger('trigger_tool_call');
        $this->assertTrue($response->hasToolCalls());
        $this->assertCount(1, $response->toolCalls);
        $this->assertSame('get_low_stock_products', $response->toolCalls[0]->name);
        $this->assertSame(['filter' => 'low'], $response->toolCalls[0]->arguments);
        $this->assertSame('call_1', $response->toolCalls[0]->id);
    }

    // ------------------------------------------------------------------
    // 3. Több function/tool call EGY válaszban + 19. ismeretlen elem-típus
    // ------------------------------------------------------------------

    public function testMultipleFunctionCallsInOneResponseAreAllParsedAndUnknownItemTypeIgnored(): void
    {
        $response = $this->chatWithTrigger('trigger_multi_tool_call');
        // A 'reasoning' típusú elem figyelmen kívül marad, nem dob hibát.
        $this->assertCount(2, $response->toolCalls);
        $this->assertSame('tool_a', $response->toolCalls[0]->name);
        $this->assertSame('call_a', $response->toolCalls[0]->id);
        $this->assertSame('tool_b', $response->toolCalls[1]->name);
        $this->assertSame(['x' => 1], $response->toolCalls[1]->arguments);
    }

    public function test19UnexpectedOutputItemTypeIsIgnoredNotFatal(): void
    {
        $response = $this->chatWithTrigger('trigger_unexpected_item');
        $this->assertSame('Válasz egy ismeretlen elem-típus mellett is.', $response->content);
        $this->assertFalse($response->hasToolCalls());
    }

    // ------------------------------------------------------------------
    // 4/18. tool output folytatás — EGY function_call_output ELEM
    // eszköz-eredményenként (nincs Anthropic-stílusú csoportosítás).
    // ------------------------------------------------------------------

    public function testEachToolResultBecomesItsOwnFunctionCallOutputItem(): void
    {
        $provider = new OpenAiProvider(self::$baseUrl, 'sk-openai-teszt-kulcs', 'gpt-6-sol', 5);

        // A ConversationManager::addToolResult() TÉNYLEGES alakja: egy
        // 'tool' szerepű üzenet HÍVÁSONKÉNT.
        $messages = [
            ['role' => 'system', 'content' => 'sys'],
            ['role' => 'user', 'content' => 'kérdés'],
            ['role' => 'assistant', 'content' => null, 'tool_calls' => [
                ['id' => 'call_a', 'function' => ['name' => 'tool_a', 'arguments' => []]],
                ['id' => 'call_b', 'function' => ['name' => 'tool_b', 'arguments' => ['x' => 1]]],
            ]],
            ['role' => 'tool', 'tool_call_id' => 'call_a', 'name' => 'tool_a', 'content' => json_encode(['success' => true, 'data' => ['a' => 1]])],
            ['role' => 'tool', 'tool_call_id' => 'call_b', 'name' => 'tool_b', 'content' => json_encode(['success' => false, 'error' => 'nem sikerült'])],
        ];

        // Ez a hívás a 2-tool-output ágra fut a stub-ban -> végleges válasz.
        $response = $provider->chat($messages, []);
        $this->assertSame('Végleges válasz a második tool_output után.', $response->content);

        $body = $this->lastRequestBody();
        $input = $body['input'];
        // system nem megy az input tömbbe (instructions-ben van), user +
        // 2 function_call (assistant tool_calls) + 2 function_call_output.
        $functionCallOutputs = array_values(array_filter($input, fn ($i) => ($i['type'] ?? '') === 'function_call_output'));
        $this->assertCount(2, $functionCallOutputs, 'KÉT KÜLÖN function_call_output elem várt, nem egy csoportosított.');
        $this->assertSame('call_a', $functionCallOutputs[0]['call_id']);
        $this->assertStringContainsString('1', $functionCallOutputs[0]['output']);
        $this->assertSame('call_b', $functionCallOutputs[1]['call_id']);
        $this->assertStringContainsString('nem sikerült', $functionCallOutputs[1]['output']);

        $functionCalls = array_values(array_filter($input, fn ($i) => ($i['type'] ?? '') === 'function_call'));
        $this->assertCount(2, $functionCalls);
        $this->assertSame('tool_a', $functionCalls[0]['name']);
        $this->assertSame('call_a', $functionCalls[0]['call_id']);
    }

    // ------------------------------------------------------------------
    // 5. Több AgentRunner-iteráció (két lépés + végleges válasz)
    // ------------------------------------------------------------------

    public function testTwoStepMultiIterationFlowReachesTools(): void
    {
        // Csak a chat()-hívást teszteljük közvetlenül (nem az AgentRunneren
        // keresztül) — a step_one hívás egy első tool_call-t vált ki.
        $response = $this->chatWithTrigger('trigger_two_step');
        $this->assertTrue($response->hasToolCalls());
        $this->assertSame('step_one', $response->toolCalls[0]->name);
        $this->assertSame('call_step_one', $response->toolCalls[0]->id);
    }

    public function test17CorrectToolDefinitionTranslation(): void
    {
        $provider = new OpenAiProvider(self::$baseUrl, 'sk-openai-teszt-kulcs', 'gpt-6-sol', 5);
        $tool = new ToolDefinition(
            'get_stock_status',
            'Egy termék készlete.',
            ['type' => 'object', 'properties' => ['id' => ['type' => 'integer']], 'required' => ['id']],
            fn (array $a) => ['ok' => true]
        );
        $provider->chat([['role' => 'user', 'content' => 'normal']], [$tool]);

        $body = $this->lastRequestBody();
        $this->assertCount(1, $body['tools']);
        $sentTool = $body['tools'][0];
        // Lapos alak, NEM egy beágyazott "function" kulcs alatt (lásd az
        // osztály docblokkja) — ez a legfontosabb protokoll-különbség az
        // Ollama/Anthropic-hoz képest.
        $this->assertSame('function', $sentTool['type']);
        $this->assertSame('get_stock_status', $sentTool['name']);
        $this->assertSame('Egy termék készlete.', $sentTool['description']);
        $this->assertArrayNotHasKey('function', $sentTool);
        $this->assertSame(['type' => 'object', 'properties' => ['id' => ['type' => 'integer']], 'required' => ['id']], $sentTool['parameters']);
    }

    // ------------------------------------------------------------------
    // 6-7. Hibásan formázott válaszok
    // ------------------------------------------------------------------

    public function testMalformedResponseMissingOutputFieldThrows(): void
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
    // 8-12. HTTP hibakódok
    // ------------------------------------------------------------------

    public function testHttp400ThrowsHttpErrorKind(): void
    {
        try {
            $this->chatWithTrigger('trigger_http_400');
            $this->fail('Exception várt volt.');
        } catch (AiProviderException $e) {
            $this->assertSame('http_error', $e->kind);
        }
    }

    public function testHttp401ThrowsAuthErrorKind(): void
    {
        try {
            $this->chatWithTrigger('trigger_http_401');
            $this->fail('Exception várt volt.');
        } catch (AiProviderException $e) {
            $this->assertSame('auth_error', $e->kind);
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
    // 13. Hálózati hiba / időtúllépés
    // ------------------------------------------------------------------

    public function testConnectionFailureToClosedPortThrows(): void
    {
        $provider = new OpenAiProvider('http://127.0.0.1:1', 'sk-openai-x', 'gpt-6-sol', 3);
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
    // 14. Titok-redaction
    // ------------------------------------------------------------------

    public function testApiKeyNeverAppearsInExceptionMessageOnAnyFailureMode(): void
    {
        $secret = 'sk-openai-nagyon-titkos-kulcs-12345';
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
        $provider = new OpenAiProvider(self::$baseUrl, '', 'gpt-6-sol', 5);
        $availability = $provider->checkAvailability();
        $this->assertSame('not_configured', $availability->status);
    }

    public function testAvailabilityWhenModelIsPresent(): void
    {
        $provider = new OpenAiProvider(self::$baseUrl, 'sk-openai-teszt-kulcs', 'gpt-6-sol', 5);
        $availability = $provider->checkAvailability();
        $this->assertSame('available', $availability->status);
    }

    public function testAvailabilityWhenModelIsMissing(): void
    {
        $provider = new OpenAiProvider(self::$baseUrl, 'sk-openai-teszt-kulcs', 'nem-letezo-modell', 5);
        $availability = $provider->checkAvailability();
        $this->assertSame('model_error', $availability->status);
    }

    public function testAvailabilityWhenApiKeyInvalidIsAuthError(): void
    {
        $provider = new OpenAiProvider(self::$baseUrl, 'wrong-key', 'gpt-6-sol', 5);
        $availability = $provider->checkAvailability();
        $this->assertSame('auth_error', $availability->status);
    }

    public function testAvailabilityWhenUnreachableIsUnavailable(): void
    {
        $provider = new OpenAiProvider('http://127.0.0.1:1', 'sk-openai-teszt-kulcs', 'gpt-6-sol', 3);
        $availability = $provider->checkAvailability();
        $this->assertSame('unavailable', $availability->status);
        $this->assertNotNull($availability->message);
    }
}
