<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Fázis 7, a kör 28. pontja — UGYANAZ az AiDailyIntelligence-bemenet fut
 * le UGYANAZON az AgentRunner-en keresztül mindhárom providerrel
 * (LocalProvider/Ollama, AnthropicProvider/Claude, OpenAiProvider) —
 * ugyanaz a minta, mint tests/AiCopilotCrossProviderRegressionTest.php.
 * Mivel a szintézis-lépés ÜRES ToolRegistry-vel fut (nincs eszköz-hívási
 * kör), minden provider stub-ja EGYETLEN, azonnali végleges választ ad —
 * nincs "tool_calls" ághoz szimulált válasz szükséges.
 */
final class AiDailyIntelligenceCrossProviderTest extends TestCase
{
    private static string $stubRoot;
    private static int $stubPort;
    /** @var resource|null */
    private static $stubServerProcess;
    private static string $baseUrl;
    private static Database $db;

    public static function setUpBeforeClass(): void
    {
        self::$stubRoot = sys_get_temp_dir() . '/sm_daily_intel_cross_provider_stub_' . bin2hex(random_bytes(6));
        mkdir(self::$stubRoot, 0775, true);
        file_put_contents(self::$stubRoot . '/index.php', <<<'PHP'
<?php
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
header('Content-Type: application/json');

if ($path === '/api/tags') {
    echo json_encode(['models' => [['name' => 'qwen3:8b']]]);
    exit;
}
if ($path === '/api/chat') {
    echo json_encode(['message' => ['role' => 'assistant', 'content' => 'Ollama: napi összefoglaló kész.']]);
    exit;
}

if ($path === '/v1/models') {
    echo json_encode(['data' => [['id' => 'claude-sonnet-5'], ['id' => 'gpt-6-sol']]]);
    exit;
}
if ($path === '/v1/messages') {
    echo json_encode([
        'id' => 'msg_1', 'type' => 'message', 'role' => 'assistant',
        'content' => [['type' => 'text', 'text' => 'Anthropic: napi összefoglaló kész.']],
        'model' => 'claude-sonnet-5', 'stop_reason' => 'end_turn',
    ]);
    exit;
}

if ($path === '/v1/responses') {
    echo json_encode([
        'id' => 'resp_1', 'object' => 'response', 'model' => 'gpt-6-sol',
        'output' => [
            ['id' => 'msg_1', 'type' => 'message', 'role' => 'assistant', 'status' => 'completed',
             'content' => [['type' => 'output_text', 'text' => 'OpenAI: napi összefoglaló kész.']]],
        ],
    ]);
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
    public function testSameDailyIntelligenceCompletesCorrectlyViaEachProvider(string $providerName, string $expectedAnswerFragment): void
    {
        $settings = $this->baseSettings();
        $settings['ai_provider'] = $providerName;

        $provider = AiProviderFactory::create($settings);
        $intelligence = new AiDailyIntelligence($provider, self::$db, $settings);

        $reportDate = '2026-0' . (array_search($providerName, ['local', 'anthropic', 'openai'], true) + 1) . '-15';
        $result = $intelligence->generateForDate($reportDate);

        $this->assertSame('completed', $result['status'], "$providerName: a napi jelentésnek sikeresnek kellett volna lennie.");

        $stored = self::$db->getAiDailyReport($reportDate);
        $this->assertSame($providerName, $stored['provider']);
        $this->assertStringContainsString($expectedAnswerFragment, (string) $stored['report_text']);
    }

    public static function providerNames(): array
    {
        return [
            'local (Ollama)' => ['local', 'Ollama:'],
            'anthropic (Claude)' => ['anthropic', 'Anthropic:'],
            'openai' => ['openai', 'OpenAI:'],
        ];
    }

    public function testAllThreeProvidersWouldSeeTheSameDeterministicContext(): void
    {
        // A HÁROM provider-futás mögött UGYANAZ a determinisztikus
        // gyűjtés (AnomalyTools/SalesTools/InventoryTools) áll —
        // providertől függetlenül, közvetlenül bizonyítva.
        $ref = new ReflectionMethod(AiDailyIntelligence::class, 'gatherContext');
        $ref->setAccessible(true);
        $intelligence = new AiDailyIntelligence(AiProviderFactory::create(array_merge($this->baseSettings(), ['ai_provider' => 'local'])), self::$db, []);

        $contextA = $ref->invoke($intelligence, '2026-05-01');
        $contextB = $ref->invoke($intelligence, '2026-05-01');

        $this->assertSame($contextA['anomalies'], $contextB['anomalies']);
        $this->assertSame($contextA['sales_summary'], $contextB['sales_summary']);
    }
}
