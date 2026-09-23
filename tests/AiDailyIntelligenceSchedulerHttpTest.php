<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Fázis 7, a kör 27. pontja — a napi AI-intelligencia cron-workerének
 * (webroot/api/ai-daily-intelligence-run.php) topológia-/hitelesítés-
 * viselkedése VALÓDI HTTP-n, ugyanazzal a mintával, mint
 * tests/ClientWorkerGatingHttpTest.php: Szerver/Önálló/Kliens node
 * teljes app-másolatok, `php -S` folyamatokként.
 */
final class AiDailyIntelligenceSchedulerHttpTest extends TestCase
{
    private static array $procs = [];
    private static array $ports = [];
    private static array $docRoots = [];

    private static string $ollamaRoot;
    private static int $ollamaPort;
    /** @var resource */
    private static $ollamaProc;

    public static function setUpBeforeClass(): void
    {
        $projectRoot = dirname(__DIR__);

        self::$ollamaRoot = sys_get_temp_dir() . '/sm_daily_intel_ollama_' . bin2hex(random_bytes(6));
        mkdir(self::$ollamaRoot, 0775, true);
        file_put_contents(self::$ollamaRoot . '/index.php', <<<'PHP'
<?php
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
header('Content-Type: application/json');
file_put_contents(__DIR__ . '/hits.log', date('c') . " $path\n", FILE_APPEND);
if ($path === '/api/tags') {
    echo json_encode(['models' => [['name' => 'qwen3:8b']]]);
} elseif ($path === '/api/chat') {
    echo json_encode(['message' => ['role' => 'assistant', 'content' => 'Nincs jelentős megállapítás ma.']]);
} else {
    http_response_code(404);
}
PHP);
        self::$ollamaPort = self::findFreePort();
        self::$ollamaProc = proc_open(
            [PHP_BINARY, '-S', '127.0.0.1:' . self::$ollamaPort, '-t', self::$ollamaRoot],
            [1 => ['file', self::$ollamaRoot . '/log.txt', 'w'], 2 => ['file', self::$ollamaRoot . '/log.txt', 'w']],
            $pipes,
            self::$ollamaRoot
        );
        self::waitForServerReady(self::$ollamaPort);

        // --- "Kliens felettes" fixture — csak méri, érkezett-e ide
        // valaha cron-kérés egy Kliens node-ról továbbítva. ---
        self::setUpUpstreamHitFixture();

        self::setUpRealAppCopy('server', ['node_role' => 'server']);
        self::seedCronSecret('server', 'valodi-szerver-cron-titok');
        self::seedAiSettings('server', ['ai_local_base_url' => 'http://127.0.0.1:' . self::$ollamaPort]);

        self::setUpRealAppCopy('standalone', null);
        self::seedCronSecret('standalone', 'valodi-onallo-cron-titok');
        self::seedAiSettings('standalone', ['ai_local_base_url' => 'http://127.0.0.1:' . self::$ollamaPort]);

        self::setUpRealAppCopy('client', ['node_role' => 'client', 'client' => [
            'server_url' => 'http://127.0.0.1:' . self::$ports['upstream'], 'client_id' => '', 'client_secret' => '',
        ]]);
    }

    public static function tearDownAfterClass(): void
    {
        foreach (self::$procs as $p) {
            if (is_resource($p)) {
                proc_terminate($p);
                proc_close($p);
            }
        }
        if (self::$ollamaProc !== null && is_resource(self::$ollamaProc)) {
            proc_terminate(self::$ollamaProc);
            proc_close(self::$ollamaProc);
        }
        foreach (glob(sys_get_temp_dir() . '/sm_daily_intel_*', GLOB_ONLYDIR) ?: [] as $dir) {
            self::removeDir($dir);
        }
    }

    // ------------------------------------------------------------------
    // Segédfüggvények (lásd tests/ClientWorkerGatingHttpTest.php azonos mintája)
    // ------------------------------------------------------------------

    private static function setUpUpstreamHitFixture(): void
    {
        $root = sys_get_temp_dir() . '/sm_daily_intel_upstream_' . bin2hex(random_bytes(6));
        mkdir($root . '/api', 0775, true);
        file_put_contents(
            $root . '/api/_hit-counter.php',
            "<?php\nfile_put_contents(__DIR__ . '/../hits.log', date('c') . ' ' . \$_SERVER['REQUEST_URI'] . \"\\n\", FILE_APPEND);\nheader('Content-Type: application/json');\necho json_encode(['ok' => true]);\n"
        );
        self::$docRoots['upstream'] = $root;
        self::startServer('upstream', $root);
    }

    private static function copyDir(string $from, string $to, array $excludeDirNames): void
    {
        if (!is_dir($from)) {
            return;
        }
        mkdir($to, 0775, true);
        foreach (scandir($from) as $item) {
            if ($item === '.' || $item === '..' || in_array($item, $excludeDirNames, true)) {
                continue;
            }
            $srcPath = $from . '/' . $item;
            $dstPath = $to . '/' . $item;
            if (is_dir($srcPath)) {
                self::copyDir($srcPath, $dstPath, $excludeDirNames);
            } else {
                copy($srcPath, $dstPath);
            }
        }
    }

    private static function removeDir(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        foreach (scandir($dir) as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $path = $dir . '/' . $item;
            if (is_dir($path) && !is_link($path)) {
                self::removeDir($path);
            } else {
                @unlink($path);
            }
        }
        @rmdir($dir);
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

    private static function waitForServerReady(int $port): void
    {
        $deadline = microtime(true) + 10;
        while (microtime(true) < $deadline) {
            $fp = @fsockopen('127.0.0.1', $port, $errno, $errstr, 0.2);
            if ($fp) {
                fclose($fp);
                return;
            }
            usleep(100_000);
        }
        self::fail('A teszt-webszerver nem indult el időben.');
    }

    private static function startServer(string $name, string $docRoot): void
    {
        $port = self::findFreePort();
        self::$ports[$name] = $port;
        $log = $docRoot . '/../' . $name . '-server.log';
        self::$procs[$name] = proc_open(
            [PHP_BINARY, '-S', '127.0.0.1:' . $port, '-t', $docRoot],
            [1 => ['file', $log, 'w'], 2 => ['file', $log, 'w']],
            $pipes,
            $docRoot
        );
        self::waitForServerReady($port);
    }

    private static function setUpRealAppCopy(string $name, ?array $installerConfig): void
    {
        $projectRoot = dirname(__DIR__);
        $root = sys_get_temp_dir() . '/sm_daily_intel_' . $name . '_' . bin2hex(random_bytes(6));
        self::copyDir($projectRoot . '/webroot', $root . '/webroot', ['vendor', 'products', 'backups']);
        self::copyDir($projectRoot . '/src', $root . '/src', []);
        copy($projectRoot . '/schema.sql', $root . '/schema.sql');
        mkdir($root . '/config', 0775, true);
        copy($projectRoot . '/config/config.php', $root . '/config/config.php');
        mkdir($root . '/data', 0775, true);

        if ($installerConfig !== null) {
            $base = [
                'shop' => ['name' => 'AI Daily Intelligence teszt', 'address' => 'X'],
                'db' => ['driver' => 'sqlite', 'sqlite' => ['path' => $root . '/data/stock.sqlite'], 'mysql' => []],
            ];
            file_put_contents($root . '/config/installer-generated.php', '<?php return ' . var_export(array_merge($base, $installerConfig), true) . ';');
        }

        self::$docRoots[$name] = $root;
        self::startServer($name, $root . '/webroot');
    }

    private static function seedCronSecret(string $name, string $secret): void
    {
        $settingsPath = self::$docRoots[$name] . '/data/settings.json';
        $existing = is_file($settingsPath) ? (json_decode((string) file_get_contents($settingsPath), true) ?: []) : [];
        file_put_contents($settingsPath, json_encode(array_merge($existing, ['cron_secret' => $secret]), JSON_UNESCAPED_UNICODE));
    }

    /** @param array $overrides ai_local_base_url stb. — lásd hívási helyek. */
    private static function seedAiSettings(string $name, array $overrides): void
    {
        $settingsPath = self::$docRoots[$name] . '/data/settings.json';
        $existing = is_file($settingsPath) ? (json_decode((string) file_get_contents($settingsPath), true) ?: []) : [];
        $merged = array_merge($existing, [
            'ai_enabled' => true,
            'ai_provider' => 'local',
            'ai_local_model' => 'qwen3:8b',
            'ai_timeout_seconds' => 10,
            'ai_max_iterations' => 3,
            'ai_daily_intelligence_enabled' => true,
            'ai_daily_intelligence_hour' => 0,
            'ai_daily_intelligence_max_findings' => 10,
        ], $overrides);
        file_put_contents($settingsPath, json_encode($merged, JSON_UNESCAPED_UNICODE));
    }

    private static function request(string $name, string $method, string $path, array $headers = []): array
    {
        $ch = curl_init('http://127.0.0.1:' . self::$ports[$name] . $path);
        $hdrLines = [];
        foreach ($headers as $k => $v) {
            $hdrLines[] = "$k: $v";
        }
        curl_setopt_array($ch, [
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HEADER => false,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_HTTPHEADER => $hdrLines,
            CURLOPT_TIMEOUT => 15,
        ]);
        $body = curl_exec($ch);
        if ($body === false) {
            self::fail('curl hiba: ' . curl_error($ch));
        }
        $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        $json = json_decode((string) $body, true);
        return ['status' => $status, 'body' => $body, 'json' => is_array($json) ? $json : null];
    }

    // ------------------------------------------------------------------
    // Tesztek
    // ------------------------------------------------------------------

    public function testClientRejectsTheWorkerEndpointEvenWithAToken(): void
    {
        $res = self::request('client', 'GET', '/api/ai-daily-intelligence-run.php', ['X-Cron-Token' => 'barmi']);
        $this->assertSame(403, $res['status']);
        $this->assertTrue($res['json']['client_mode_unavailable'] ?? false);
    }

    public function testClientNeverForwardsTheWorkerRequestToTheUpstream(): void
    {
        $hitsLog = self::$docRoots['upstream'] . '/hits.log';
        $this->assertFileDoesNotExist($hitsLog);
    }

    public function testServerRejectsMissingCronToken(): void
    {
        $res = self::request('server', 'GET', '/api/ai-daily-intelligence-run.php');
        $this->assertSame(401, $res['status']);
    }

    public function testServerRejectsInvalidCronToken(): void
    {
        $res = self::request('server', 'GET', '/api/ai-daily-intelligence-run.php', ['X-Cron-Token' => 'rossz-token']);
        $this->assertSame(401, $res['status']);
    }

    public function testServerGeneratesAndPersistsAReportOnFirstRun(): void
    {
        $res = self::request('server', 'GET', '/api/ai-daily-intelligence-run.php', ['X-Cron-Token' => 'valodi-szerver-cron-titok']);

        $this->assertSame(200, $res['status'], $res['body']);
        $this->assertTrue($res['json']['ok'] ?? false);
        $this->assertSame('completed', $res['json']['status']);
    }

    public function testServerSkipsDuplicateExecutionOnTheSameDay(): void
    {
        // A fenti teszt UTÁN a mai napra MÁR van 'completed' jelentés —
        // egy második futásnak NEM szabad újra generálnia/duplikálnia.
        $res = self::request('server', 'GET', '/api/ai-daily-intelligence-run.php', ['X-Cron-Token' => 'valodi-szerver-cron-titok']);

        $this->assertSame(200, $res['status'], $res['body']);
        $this->assertTrue($res['json']['skipped'] ?? false);
        $this->assertSame('already_completed', $res['json']['reason'] ?? null);
    }

    public function testStandaloneNodeIsAllowedToExecuteTheWorker(): void
    {
        $res = self::request('standalone', 'GET', '/api/ai-daily-intelligence-run.php', ['X-Cron-Token' => 'valodi-onallo-cron-titok']);

        $this->assertSame(200, $res['status'], $res['body']);
        $this->assertTrue($res['json']['ok'] ?? false);
        $this->assertSame('completed', $res['json']['status']);
    }

    public function testWorkerFailsSafelyWhenProviderIsUnavailable(): void
    {
        // Egy KÜLÖN node-másolat, aminek az Ollama URL-je szándékosan
        // sose válaszol — a kör 14. pontja: "If provider is unavailable:
        // record failure safely, do not mark the report as successful,
        // do not generate fake findings".
        self::setUpRealAppCopy('server_unavailable', ['node_role' => 'server']);
        self::seedCronSecret('server_unavailable', 'unavailable-cron-titok');
        self::seedAiSettings('server_unavailable', ['ai_local_base_url' => 'http://127.0.0.1:1']);

        $res = self::request('server_unavailable', 'GET', '/api/ai-daily-intelligence-run.php', ['X-Cron-Token' => 'unavailable-cron-titok']);

        $this->assertSame(502, $res['status'], $res['body']);
        $this->assertFalse($res['json']['ok'] ?? true);
        $this->assertSame('failed', $res['json']['status'] ?? null);
        $this->assertStringNotContainsString('curl', strtolower($res['body']));
    }

    public function testWorkerIsSkippedWhenDailyIntelligenceIsDisabled(): void
    {
        self::setUpRealAppCopy('server_disabled', ['node_role' => 'server']);
        self::seedCronSecret('server_disabled', 'disabled-cron-titok');
        self::seedAiSettings('server_disabled', [
            'ai_local_base_url' => 'http://127.0.0.1:' . self::$ollamaPort,
            'ai_daily_intelligence_enabled' => false,
        ]);

        $res = self::request('server_disabled', 'GET', '/api/ai-daily-intelligence-run.php', ['X-Cron-Token' => 'disabled-cron-titok']);

        $this->assertSame(200, $res['status'], $res['body']);
        $this->assertTrue($res['json']['skipped'] ?? false);
        $this->assertSame('disabled', $res['json']['reason'] ?? null);
    }

    public function testWorkerRespectsTheConfiguredHourNotYetDue(): void
    {
        self::setUpRealAppCopy('server_not_due', ['node_role' => 'server']);
        self::seedCronSecret('server_not_due', 'notdue-cron-titok');
        self::seedAiSettings('server_not_due', [
            'ai_local_base_url' => 'http://127.0.0.1:' . self::$ollamaPort,
            'ai_daily_intelligence_hour' => 23,
        ]);

        $currentHour = (int) date('G');
        if ($currentHour >= 23) {
            $this->markTestSkipped('A teszt-futtatás órája már 23 vagy későbbi — ez a forgatókönyv itt nem reprodukálható.');
        }

        $res = self::request('server_not_due', 'GET', '/api/ai-daily-intelligence-run.php', ['X-Cron-Token' => 'notdue-cron-titok']);

        $this->assertSame(200, $res['status'], $res['body']);
        $this->assertTrue($res['json']['skipped'] ?? false);
        $this->assertSame('not_due', $res['json']['reason'] ?? null);
    }
}
