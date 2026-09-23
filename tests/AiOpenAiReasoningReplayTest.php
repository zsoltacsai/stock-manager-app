<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * OpenAiProvider reasoning-item visszajátszásának regressziós tesztje —
 * utólagos javítás (lásd "fix: harden OpenAI response replay" commit),
 * mert a hivatalos dokumentáció ("Preserve reasoning without stored
 * responses") szerint `store:false` mellett a konfigurált alapértelmezett
 * modell (`gpt-6-sol`, ALAPÉRTELMEZETT "medium" reasoning.effort-tal)
 * `type:'reasoning'` elemeket ad vissza `encrypted_content` mezővel,
 * amiket EGY eszköz-hívást folytató kérésben ÉRINTETLENÜL, EREDETI
 * POZÍCIÓBAN vissza kell küldeni — a Fázis 3 eredeti, egyszerűsített
 * `translateMessages()` ezt még nem tette meg (lásd
 * OpenAiProvider::$rawOutputBatches docblokkja a pontos indoklásért).
 *
 * Külön fájl az AiOpenAiProviderTest.php mellett — SZÁNDÉKOSAN nem
 * módosítja a meglévő, sok más tesztben is használt stub-szerver
 * scriptet, saját, kifejezetten reasoning-item-eket is tartalmazó
 * stub-válaszokkal dolgozik (lásd a kör 8. pontja: "realistic Responses
 * API output items").
 */
final class AiOpenAiReasoningReplayTest extends TestCase
{
    private static string $stubRoot;
    private static int $stubPort;
    /** @var resource|null */
    private static $stubServerProcess;
    private static string $baseUrl;

    public static function setUpBeforeClass(): void
    {
        self::$stubRoot = sys_get_temp_dir() . '/sm_ai_openai_reasoning_stub_' . bin2hex(random_bytes(6));
        mkdir(self::$stubRoot, 0775, true);
        file_put_contents(self::$stubRoot . '/index.php', <<<'PHP'
<?php
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
header('Content-Type: application/json');

if ($path !== '/v1/responses') {
    http_response_code(404);
    echo json_encode(['error' => ['message' => 'unknown', 'type' => 'invalid_request_error']]);
    exit;
}

$raw = file_get_contents('php://input');
$requestNum = 0;
$counterFile = __DIR__ . '/request_count.txt';
if (is_file($counterFile)) { $requestNum = (int) file_get_contents($counterFile); }
$requestNum++;
file_put_contents($counterFile, (string) $requestNum);
file_put_contents(__DIR__ . "/last_request_$requestNum.json", $raw);
file_put_contents(__DIR__ . '/last_request.json', $raw);

$body = json_decode($raw, true);
$input = $body['input'] ?? [];
$toolOutputCount = 0;
foreach ($input as $item) {
    if (is_array($item) && ($item['type'] ?? '') === 'function_call_output') {
        $toolOutputCount++;
    }
}

$first = $input[0] ?? [];
$trigger = is_string($first['content'] ?? null) ? $first['content'] : '';

if ($toolOutputCount > 0) {
    // Bármelyik folytatás-hívás (a tool_output darabszámtól függetlenül)
    // végleges szöveges választ ad — a lényeg a MÁSODIK kérés input
    // tartalmának ellenőrzése (a tesztek ezt vizsgálják), nem a modell
    // "gondolkodása".
    echo json_encode([
        'id' => 'resp_final', 'object' => 'response', 'model' => $body['model'],
        'output' => [
            ['id' => 'msg_final', 'type' => 'message', 'role' => 'assistant', 'status' => 'completed',
             'content' => [['type' => 'output_text', 'text' => 'Végleges válasz a folytatás után.']]],
        ],
    ]);
    exit;
}

switch ($trigger) {
    case 'trigger_reasoning_single_tool_call':
        echo json_encode([
            'id' => 'resp_1', 'object' => 'response', 'model' => $body['model'],
            'output' => [
                [
                    'id' => 'rs_abc123', 'type' => 'reasoning', 'summary' => [],
                    'encrypted_content' => 'ENC[opaque-reasoning-blob-nem-dekodolhato-XYZ789]',
                ],
                ['id' => 'fc_1', 'call_id' => 'call_1', 'type' => 'function_call', 'name' => 'get_low_stock_products', 'arguments' => '{"filter":"low"}'],
            ],
        ]);
        break;
    case 'trigger_reasoning_multi_tool_call':
        echo json_encode([
            'id' => 'resp_1', 'object' => 'response', 'model' => $body['model'],
            'output' => [
                [
                    'id' => 'rs_multi456', 'type' => 'reasoning', 'summary' => [],
                    'encrypted_content' => 'ENC[opaque-reasoning-blob-multi-ABC123]',
                ],
                ['id' => 'fc_a', 'call_id' => 'call_a', 'type' => 'function_call', 'name' => 'tool_a', 'arguments' => '{}'],
                ['id' => 'fc_b', 'call_id' => 'call_b', 'type' => 'function_call', 'name' => 'tool_b', 'arguments' => '{"x":1}'],
            ],
        ]);
        break;
    case 'trigger_unsupported_item_with_tool_call':
        echo json_encode([
            'id' => 'resp_1', 'object' => 'response', 'model' => $body['model'],
            'output' => [
                ['id' => 'wsc_1', 'type' => 'web_search_call', 'status' => 'completed'],
                ['id' => 'fc_1', 'call_id' => 'call_1', 'type' => 'function_call', 'name' => 'get_low_stock_products', 'arguments' => '{}'],
            ],
        ]);
        break;
    case 'trigger_two_iterations_with_reasoning':
        echo json_encode([
            'id' => 'resp_1', 'object' => 'response', 'model' => $body['model'],
            'output' => [
                ['id' => 'rs_iter1', 'type' => 'reasoning', 'summary' => [], 'encrypted_content' => 'ENC[iter1]'],
                ['id' => 'fc_step1', 'call_id' => 'call_step1', 'type' => 'function_call', 'name' => 'step_one', 'arguments' => '{}'],
            ],
        ]);
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
            self::fail('Nem sikerült elindítani a reasoning-replay stub teszt-szervert.');
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

    protected function setUp(): void
    {
        // Minden teszt friss kérés-számlálóval induljon, hogy a
        // last_request_N.json fájlok determinisztikusan azonosíthatók
        // legyenek egy-egy teszten belül.
        @unlink(self::$stubRoot . '/request_count.txt');
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
        self::fail('A reasoning-replay stub teszt-szerver nem indult el időben.');
    }

    private function requestBody(int $requestNum): array
    {
        $raw = file_get_contents(self::$stubRoot . "/last_request_$requestNum.json");
        $decoded = json_decode((string) $raw, true);
        return is_array($decoded) ? $decoded : [];
    }

    private function tool(string $name, callable $handler): ToolDefinition
    {
        return new ToolDefinition($name, "teszt: $name", ['type' => 'object', 'properties' => []], $handler);
    }

    // ------------------------------------------------------------------
    // A. Sima szöveges válasz (nincs reasoning/tool-hívás)
    // ------------------------------------------------------------------

    public function testA_SimplePlainTextResponse(): void
    {
        $provider = new OpenAiProvider(self::$baseUrl, 'sk-openai-teszt', 'gpt-6-sol', 5);
        $response = $provider->chat([['role' => 'user', 'content' => 'normal']], []);
        $this->assertSame('Sima szöveges válasz.', $response->content);
        $this->assertFalse($response->hasToolCalls());
    }

    // ------------------------------------------------------------------
    // B. Egy function_call + folytatás — a reasoning item ÉRINTETLENÜL,
    //    EREDETI POZÍCIÓBAN visszajátszva.
    // ------------------------------------------------------------------

    public function testB_SingleFunctionCallWithReasoningIsReplayedExactlyOnContinuation(): void
    {
        $provider = new OpenAiProvider(self::$baseUrl, 'sk-openai-teszt', 'gpt-6-sol', 5);

        $first = $provider->chat([['role' => 'user', 'content' => 'trigger_reasoning_single_tool_call']], []);
        $this->assertTrue($first->hasToolCalls());
        $this->assertSame('call_1', $first->toolCalls[0]->id);

        // A ConversationManager UGYANÍGY építi fel a következő kört —
        // lásd ConversationManager::addAssistantMessage()/addToolResult().
        $messages = [
            ['role' => 'user', 'content' => 'trigger_reasoning_single_tool_call'],
            ['role' => 'assistant', 'content' => null, 'tool_calls' => [
                ['id' => 'call_1', 'function' => ['name' => 'get_low_stock_products', 'arguments' => ['filter' => 'low']]],
            ]],
            ['role' => 'tool', 'tool_call_id' => 'call_1', 'name' => 'get_low_stock_products', 'content' => json_encode(['success' => true, 'data' => ['count' => 2]])],
        ];
        $second = $provider->chat($messages, []);
        $this->assertSame('Végleges válasz a folytatás után.', $second->content);

        $secondBody = $this->requestBody(2);
        $input = $secondBody['input'];

        // input[0] = user, input[1] = reasoning (EREDETI, VÁLTOZATLAN),
        // input[2] = function_call (EREDETI), input[3] = function_call_output.
        $this->assertSame('reasoning', $input[1]['type']);
        $this->assertSame('rs_abc123', $input[1]['id']);
        $this->assertSame('ENC[opaque-reasoning-blob-nem-dekodolhato-XYZ789]', $input[1]['encrypted_content']);
        $this->assertSame('function_call', $input[2]['type']);
        $this->assertSame('call_1', $input[2]['call_id']);
        $this->assertSame('get_low_stock_products', $input[2]['name']);
        $this->assertSame('function_call_output', $input[3]['type']);
        $this->assertSame('call_1', $input[3]['call_id']);
    }

    // ------------------------------------------------------------------
    // C. Több function_call EGY válaszban + folytatás — a reasoning item
    //    (ami mindkét hívás ELŐTT állt) is pontosan visszajátszva.
    // ------------------------------------------------------------------

    public function testC_MultipleFunctionCallsWithReasoningAreReplayedExactlyOnContinuation(): void
    {
        $provider = new OpenAiProvider(self::$baseUrl, 'sk-openai-teszt', 'gpt-6-sol', 5);

        $first = $provider->chat([['role' => 'user', 'content' => 'trigger_reasoning_multi_tool_call']], []);
        $this->assertCount(2, $first->toolCalls);

        $messages = [
            ['role' => 'user', 'content' => 'trigger_reasoning_multi_tool_call'],
            ['role' => 'assistant', 'content' => null, 'tool_calls' => [
                ['id' => 'call_a', 'function' => ['name' => 'tool_a', 'arguments' => []]],
                ['id' => 'call_b', 'function' => ['name' => 'tool_b', 'arguments' => ['x' => 1]]],
            ]],
            ['role' => 'tool', 'tool_call_id' => 'call_a', 'name' => 'tool_a', 'content' => json_encode(['success' => true, 'data' => ['a' => 1]])],
            ['role' => 'tool', 'tool_call_id' => 'call_b', 'name' => 'tool_b', 'content' => json_encode(['success' => true, 'data' => ['b' => 2]])],
        ];
        $second = $provider->chat($messages, []);
        $this->assertSame('Végleges válasz a folytatás után.', $second->content);

        $input = $this->requestBody(2)['input'];
        // user, reasoning, function_call(a), function_call(b), function_call_output(a), function_call_output(b)
        $this->assertSame('reasoning', $input[1]['type']);
        $this->assertSame('ENC[opaque-reasoning-blob-multi-ABC123]', $input[1]['encrypted_content']);
        $this->assertSame('function_call', $input[2]['type']);
        $this->assertSame('call_a', $input[2]['call_id']);
        $this->assertSame('function_call', $input[3]['type']);
        $this->assertSame('call_b', $input[3]['call_id']);
        $this->assertSame('function_call_output', $input[4]['type']);
        $this->assertSame('call_a', $input[4]['call_id']);
        $this->assertSame('function_call_output', $input[5]['type']);
        $this->assertSame('call_b', $input[5]['call_id']);
    }

    // ------------------------------------------------------------------
    // D. reasoning item + function call + folytatás — külön, explicit
    //    tesztként is (a B/C tesztek már bizonyítják, ez összefoglalja).
    // ------------------------------------------------------------------

    public function testD_ReasoningItemPrecedesFunctionCallInReplayedOrder(): void
    {
        $provider = new OpenAiProvider(self::$baseUrl, 'sk-openai-teszt', 'gpt-6-sol', 5);
        $provider->chat([['role' => 'user', 'content' => 'trigger_reasoning_single_tool_call']], []);
        $messages = [
            ['role' => 'user', 'content' => 'trigger_reasoning_single_tool_call'],
            ['role' => 'assistant', 'content' => null, 'tool_calls' => [
                ['id' => 'call_1', 'function' => ['name' => 'get_low_stock_products', 'arguments' => ['filter' => 'low']]],
            ]],
            ['role' => 'tool', 'tool_call_id' => 'call_1', 'name' => 'get_low_stock_products', 'content' => json_encode(['success' => true, 'data' => []])],
        ];
        $provider->chat($messages, []);

        $input = $this->requestBody(2)['input'];
        $reasoningIndex = null;
        $functionCallIndex = null;
        foreach ($input as $i => $item) {
            if (($item['type'] ?? '') === 'reasoning') { $reasoningIndex = $i; }
            if (($item['type'] ?? '') === 'function_call') { $functionCallIndex = $i; }
        }
        $this->assertNotNull($reasoningIndex);
        $this->assertNotNull($functionCallIndex);
        $this->assertLessThan($functionCallIndex, $reasoningIndex, 'A reasoning item-nek a function_call ELŐTT kell állnia.');
    }

    // ------------------------------------------------------------------
    // E. A titkosított reasoning-tartalom VÁLTOZATLANUL megmarad — sose
    //    vizsgáljuk/dekódoljuk, tisztán opak adatként kezeljük.
    // ------------------------------------------------------------------

    public function testE_EncryptedReasoningContentIsPreservedByteForByteAndNeverInspected(): void
    {
        $provider = new OpenAiProvider(self::$baseUrl, 'sk-openai-teszt', 'gpt-6-sol', 5);
        $provider->chat([['role' => 'user', 'content' => 'trigger_reasoning_single_tool_call']], []);
        $messages = [
            ['role' => 'user', 'content' => 'trigger_reasoning_single_tool_call'],
            ['role' => 'assistant', 'content' => null, 'tool_calls' => [
                ['id' => 'call_1', 'function' => ['name' => 'get_low_stock_products', 'arguments' => []]],
            ]],
            ['role' => 'tool', 'tool_call_id' => 'call_1', 'name' => 'get_low_stock_products', 'content' => json_encode(['success' => true, 'data' => []])],
        ];
        $provider->chat($messages, []);

        $input = $this->requestBody(2)['input'];
        $reasoningItem = null;
        foreach ($input as $item) {
            if (($item['type'] ?? '') === 'reasoning') { $reasoningItem = $item; }
        }
        $this->assertNotNull($reasoningItem);
        // Byte-pontosan ugyanaz a string, mint amit a stub adott — nincs
        // se részleges, se módosított, se kicserélt tartalom.
        $this->assertSame('ENC[opaque-reasoning-blob-nem-dekodolhato-XYZ789]', $reasoningItem['encrypted_content']);
        $this->assertSame(['id' => 'rs_abc123', 'type' => 'reasoning', 'summary' => [], 'encrypted_content' => 'ENC[opaque-reasoning-blob-nem-dekodolhato-XYZ789]'], $reasoningItem);
    }

    // ------------------------------------------------------------------
    // F. Ismeretlen/nem támogatott elem-típus function_call mellett — a
    //    válasz-feldolgozás biztonságos marad, ÉS a visszajátszás is
    //    megőrzi (nem dobja el, nem töri el a folyamatot).
    // ------------------------------------------------------------------

    public function testF_UnsupportedOutputItemAlongsideFunctionCallIsSafeAndPreservedOnReplay(): void
    {
        $provider = new OpenAiProvider(self::$baseUrl, 'sk-openai-teszt', 'gpt-6-sol', 5);
        $first = $provider->chat([['role' => 'user', 'content' => 'trigger_unsupported_item_with_tool_call']], []);
        $this->assertTrue($first->hasToolCalls());
        $this->assertCount(1, $first->toolCalls);
        $this->assertNull($first->content);

        $messages = [
            ['role' => 'user', 'content' => 'trigger_unsupported_item_with_tool_call'],
            ['role' => 'assistant', 'content' => null, 'tool_calls' => [
                ['id' => 'call_1', 'function' => ['name' => 'get_low_stock_products', 'arguments' => []]],
            ]],
            ['role' => 'tool', 'tool_call_id' => 'call_1', 'name' => 'get_low_stock_products', 'content' => json_encode(['success' => true, 'data' => []])],
        ];
        $second = $provider->chat($messages, []);
        $this->assertSame('Végleges válasz a folytatás után.', $second->content);

        $input = $this->requestBody(2)['input'];
        $hasUnsupportedItem = false;
        foreach ($input as $item) {
            if (($item['type'] ?? '') === 'web_search_call') { $hasUnsupportedItem = true; }
        }
        $this->assertTrue($hasUnsupportedItem, 'Az ismeretlen elem-típusnak is meg kellett maradnia a visszajátszásban.');
    }

    // ------------------------------------------------------------------
    // G. UGYANAZ az InventoryAgent-mintájú valódi AgentRunner-folyamat
    //    (real ToolRegistry, real AgentRunner) reasoning-gel is működik.
    // ------------------------------------------------------------------

    public function testG_RealAgentRunnerCompletesTwoIterationFlowWithReasoningItemsPresent(): void
    {
        $provider = new OpenAiProvider(self::$baseUrl, 'sk-openai-teszt', 'gpt-6-sol', 5);
        $registry = new ToolRegistry();
        $registry->register($this->tool('step_one', fn (array $a) => ['ok' => true]));
        $runner = new AgentRunner($provider, $registry, 5);

        $result = $runner->run('sys', 'trigger_two_iterations_with_reasoning');

        $this->assertTrue($result->success);
        $this->assertSame(['step_one'], $result->toolsUsed);
        $this->assertSame('Végleges válasz a folytatás után.', $result->answer);

        // A második (folytatás) kérésben ténylegesen ott volt a reasoning
        // item is — az AgentRunner/ConversationManager rétegen áthaladva
        // is megmaradt a visszajátszás.
        $input = $this->requestBody(2)['input'];
        $hasReasoning = false;
        foreach ($input as $item) {
            if (($item['type'] ?? '') === 'reasoning' && ($item['encrypted_content'] ?? '') === 'ENC[iter1]') {
                $hasReasoning = true;
            }
        }
        $this->assertTrue($hasReasoning);
    }

    // ------------------------------------------------------------------
    // Nincs egyező tárolt köteg esetén a régi, egyszerűsített visszaépítés
    // marad a biztonságos alapértelmezés (pl. egy frissen konstruált
    // OpenAiProvider-példány, aminek sose volt korábbi chat()-hívása).
    // ------------------------------------------------------------------

    public function testFallsBackToSimplifiedReconstructionWhenNoMatchingBatchExists(): void
    {
        $provider = new OpenAiProvider(self::$baseUrl, 'sk-openai-teszt', 'gpt-6-sol', 5);
        // KÖZVETLENÜL egy olyan előzményt adunk át, amit ez a
        // providerpéldány SOSE termelt — nincs elmentett nyers köteg.
        $messages = [
            ['role' => 'user', 'content' => 'trigger_reasoning_single_tool_call'],
            ['role' => 'assistant', 'content' => null, 'tool_calls' => [
                ['id' => 'call_idegen', 'function' => ['name' => 'get_low_stock_products', 'arguments' => ['filter' => 'low']]],
            ]],
            ['role' => 'tool', 'tool_call_id' => 'call_idegen', 'name' => 'get_low_stock_products', 'content' => json_encode(['success' => true, 'data' => []])],
        ];
        $response = $provider->chat($messages, []);
        $this->assertSame('Végleges válasz a folytatás után.', $response->content);

        $input = $this->requestBody(1)['input'];
        // Nincs reasoning item — az egyszerűsített, újraépített
        // function_call-t kapta a stub, PONTOSAN, mint a Fázis 3 eredeti
        // viselkedésében.
        $hasReasoning = false;
        foreach ($input as $item) {
            if (($item['type'] ?? '') === 'reasoning') { $hasReasoning = true; }
        }
        $this->assertFalse($hasReasoning);
    }
}
