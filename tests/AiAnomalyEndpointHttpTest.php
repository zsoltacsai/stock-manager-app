<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * A POST /api/ai-anomaly.php végpont VALÓDI HTTP-n, VALÓDI (de
 * kontrollált, saját stub-) Ollamával bizonyított tesztje (Fázis 5) —
 * ugyanaz a minta, mint tests/AiSalesEndpointHttpTest.php. Nincs valódi
 * Ollama-függőség.
 */
final class AiAnomalyEndpointHttpTest extends TestCase
{
    private static string $root;
    private static int $port;
    /** @var resource|null */
    private static $serverProcess;
    private static string $baseUrl;

    private static string $ollamaRoot;
    private static int $ollamaPort;
    /** @var resource|null */
    private static $ollamaProcess;

    private static string $adminJar;
    private static string $staffJar;

    public static function setUpBeforeClass(): void
    {
        self::$root = sys_get_temp_dir() . '/sm_ai_anomaly_endpoint_test_' . bin2hex(random_bytes(6));
        mkdir(self::$root, 0775, true);

        $projectRoot = dirname(__DIR__);
        self::copyDir($projectRoot . '/webroot', self::$root . '/webroot', ['vendor', 'products', 'backups']);
        self::copyDir($projectRoot . '/src', self::$root . '/src', []);
        copy($projectRoot . '/schema.sql', self::$root . '/schema.sql');
        mkdir(self::$root . '/config', 0775, true);
        copy($projectRoot . '/config/config.php', self::$root . '/config/config.php');
        mkdir(self::$root . '/data', 0775, true);

        self::$ollamaRoot = sys_get_temp_dir() . '/sm_ai_anomaly_ollama_stub_' . bin2hex(random_bytes(6));
        mkdir(self::$ollamaRoot, 0775, true);
        file_put_contents(self::$ollamaRoot . '/index.php', <<<'PHP'
<?php
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
header('Content-Type: application/json');
$controlFile = __DIR__ . '/control.json';
$control = is_file($controlFile) ? json_decode(file_get_contents($controlFile), true) : ['mode' => 'final'];
if ($path === '/api/tags') {
    echo json_encode(['models' => [['name' => 'qwen3:8b']]]);
    exit;
}
if ($path !== '/api/chat') {
    http_response_code(404);
    echo json_encode(['error' => 'unknown']);
    exit;
}
$body = json_decode(file_get_contents('php://input'), true);
$hasToolResult = false;
foreach ($body['messages'] ?? [] as $m) {
    if (($m['role'] ?? '') === 'tool') { $hasToolResult = true; }
}
if ($hasToolResult) {
    echo json_encode(['message' => ['role' => 'assistant', 'content' => 'Találtam egy jelentős eladás-visszaesést.']]);
    exit;
}
if (($control['mode'] ?? 'final') === 'tool_call') {
    echo json_encode(['message' => ['role' => 'assistant', 'content' => '', 'tool_calls' => [
        ['function' => ['name' => 'get_sales_anomalies', 'arguments' => ['period' => 'last_30_days']]],
    ]]]);
} elseif (($control['mode'] ?? '') === 'unknown_tool_call') {
    echo json_encode(['message' => ['role' => 'assistant', 'content' => '', 'tool_calls' => [
        ['function' => ['name' => 'run_arbitrary_sql', 'arguments' => ['sql' => 'DROP TABLE sales']]],
    ]]]);
} else {
    echo json_encode(['message' => ['role' => 'assistant', 'content' => 'Nincs szokatlan minta ma.']]);
}
PHP);
        self::$ollamaPort = self::findFreePort();
        self::$ollamaProcess = proc_open(
            [PHP_BINARY, '-S', '127.0.0.1:' . self::$ollamaPort, '-t', self::$ollamaRoot],
            [1 => ['file', self::$ollamaRoot . '/log.txt', 'w'], 2 => ['file', self::$ollamaRoot . '/log.txt', 'w']],
            $pipes,
            self::$ollamaRoot
        );
        self::waitForReady(self::$ollamaPort);
        self::setOllamaMode('final');

        self::$port = self::findFreePort();
        self::$baseUrl = 'http://127.0.0.1:' . self::$port;
        self::$serverProcess = proc_open(
            [PHP_BINARY, '-S', '127.0.0.1:' . self::$port, '-t', self::$root . '/webroot'],
            [1 => ['file', self::$root . '/server.log', 'w'], 2 => ['file', self::$root . '/server.log', 'w']],
            $pipes,
            self::$root
        );
        self::waitForReady(self::$port);
        self::request('GET', '/api/auth-status.php');

        require_once $projectRoot . '/src/Database.php';
        $db = new Database(['driver' => 'sqlite', 'sqlite' => ['path' => self::$root . '/data/stock.sqlite']], self::$root);
        $db->saveStaff(['name' => 'Anomaly Teszt Admin', 'pin' => '91831', 'role' => 'admin']);
        $db->saveStaff(['name' => 'Anomaly Teszt Pénztáros', 'pin' => '19287', 'role' => 'staff']);
        $pdo = $db->pdo();
        $stmt = $pdo->prepare('INSERT INTO products (name, unit, price, net_price, stock_qty, group_name, vat_rate) VALUES (?, "db", 1000, 787, 500, "Italok", "27")');
        $stmt->execute(['Anomaly HTTP teszt termék']);
        $pid = (int) $pdo->lastInsertId();
        $pdo->prepare("INSERT INTO sales (total, payment_method, created_at) VALUES (20000, 'Készpénz', ?)")
            ->execute([date('Y-m-d H:i:s', strtotime('-40 days'))]);
        $saleId1 = (int) $pdo->lastInsertId();
        $pdo->prepare('INSERT INTO sale_items (sale_id, product_id, name, qty, unit_price, vat_rate) VALUES (?, ?, "teszt", 20, 1000, "27")')
            ->execute([$saleId1, $pid]);
        $pdo->prepare("INSERT INTO sales (total, payment_method, created_at) VALUES (2000, 'Készpénz', ?)")
            ->execute([date('Y-m-d H:i:s', strtotime('-5 days'))]);
        $saleId2 = (int) $pdo->lastInsertId();
        $pdo->prepare('INSERT INTO sale_items (sale_id, product_id, name, qty, unit_price, vat_rate) VALUES (?, ?, "teszt", 2, 1000, "27")')
            ->execute([$saleId2, $pid]);
        unset($db);

        $jar = self::cookieJar('setup');
        $status = self::request('GET', '/api/auth-status.php', null, [], $jar);
        $csrf = $status['json']['csrf_token'];
        self::request('POST', '/api/security-settings-save.php', [
            'app_password_enabled' => true,
            'new_password' => 'ai-anomaly-http-teszt-jelszo',
            'new_password_confirm' => 'ai-anomaly-http-teszt-jelszo',
        ], ['X-CSRF-Token' => $csrf], $jar);

        self::$adminJar = self::cookieJar('admin');
        self::request('POST', '/api/login.php', ['password' => 'ai-anomaly-http-teszt-jelszo'], [], self::$adminJar);
        $adminLoginCsrf = self::request('GET', '/api/auth-status.php', null, [], self::$adminJar)['json']['csrf_token'];
        $adminStaffLogin = self::request('POST', '/api/staff-login.php', ['pin' => '91831'], ['X-CSRF-Token' => $adminLoginCsrf], self::$adminJar);
        if (!($adminStaffLogin['json']['ok'] ?? false)) {
            self::fail('Admin staff-login setup sikertelen: ' . $adminStaffLogin['body']);
        }

        self::$staffJar = self::cookieJar('staff');
        self::request('POST', '/api/login.php', ['password' => 'ai-anomaly-http-teszt-jelszo'], [], self::$staffJar);
        $staffLoginCsrf = self::request('GET', '/api/auth-status.php', null, [], self::$staffJar)['json']['csrf_token'];
        $staffStaffLogin = self::request('POST', '/api/staff-login.php', ['pin' => '19287'], ['X-CSRF-Token' => $staffLoginCsrf], self::$staffJar);
        if (!($staffStaffLogin['json']['ok'] ?? false)) {
            self::fail('Nem-admin staff-login setup sikertelen: ' . $staffStaffLogin['body']);
        }

        $adminCsrf = self::request('GET', '/api/auth-status.php', null, [], self::$adminJar)['json']['csrf_token'];
        self::request('POST', '/api/settings.php', [
            'ai_enabled' => true,
            'ai_local_base_url' => 'http://127.0.0.1:' . self::$ollamaPort,
            'ai_local_model' => 'qwen3:8b',
            'ai_timeout_seconds' => 10,
            'ai_max_iterations' => 5,
        ], ['X-CSRF-Token' => $adminCsrf], self::$adminJar);
    }

    public static function tearDownAfterClass(): void
    {
        foreach ([self::$serverProcess, self::$ollamaProcess] as $p) {
            if ($p !== null && is_resource($p)) {
                proc_terminate($p);
                proc_close($p);
            }
        }
        self::removeDir(self::$root);
        self::removeDir(self::$ollamaRoot);
    }

    private static function setOllamaMode(string $mode): void
    {
        file_put_contents(self::$ollamaRoot . '/control.json', json_encode(['mode' => $mode]));
    }

    // -- Segédfüggvények --

    private static function copyDir(string $from, string $to, array $exclude): void
    {
        if (!is_dir($from)) return;
        mkdir($to, 0775, true);
        foreach (scandir($from) as $item) {
            if ($item === '.' || $item === '..' || in_array($item, $exclude, true)) continue;
            $s = "$from/$item"; $d = "$to/$item";
            is_dir($s) ? self::copyDir($s, $d, $exclude) : copy($s, $d);
        }
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
        self::fail('A teszt-szerver nem indult el időben.');
    }

    private static function cookieJar(string $name): string
    {
        return self::$root . '/cookies-' . $name . '.txt';
    }

    private static function request(string $method, string $path, ?array $jsonBody = null, array $extraHeaders = [], ?string $cookieJarPath = null): array
    {
        $ch = curl_init(self::$baseUrl . $path);
        $headers = [];
        foreach ($extraHeaders as $k => $v) { $headers[] = "$k: $v"; }
        curl_setopt_array($ch, [
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HEADER => false,
            CURLOPT_TIMEOUT => 15,
        ]);
        if ($cookieJarPath !== null) {
            curl_setopt($ch, CURLOPT_COOKIEJAR, $cookieJarPath);
            curl_setopt($ch, CURLOPT_COOKIEFILE, $cookieJarPath);
        }
        if ($jsonBody !== null) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($jsonBody));
            $headers[] = 'Content-Type: application/json';
        }
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        $body = curl_exec($ch);
        if ($body === false) { self::fail('curl hiba: ' . curl_error($ch)); }
        $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        $json = json_decode((string) $body, true);
        return ['status' => $status, 'json' => is_array($json) ? $json : null, 'body' => $body];
    }

    private function freshCsrf(string $jar): string
    {
        return self::request('GET', '/api/auth-status.php', null, [], $jar)['json']['csrf_token'];
    }

    // ------------------------------------------------------------------
    // Biztonság
    // ------------------------------------------------------------------

    public function testUnauthenticatedRequestIsRejected(): void
    {
        $freshJar = self::cookieJar('fresh-unauth-' . bin2hex(random_bytes(3)));
        $res = self::request('POST', '/api/ai-anomaly.php', ['message' => 'Van valami szokatlan?'], [], $freshJar);
        $this->assertContains($res['status'], [401, 403]);
    }

    public function testNonAdminStaffIsRejected(): void
    {
        $csrf = $this->freshCsrf(self::$staffJar);
        $res = self::request('POST', '/api/ai-anomaly.php', ['message' => 'Van valami szokatlan?'], ['X-CSRF-Token' => $csrf], self::$staffJar);
        $this->assertSame(403, $res['status']);
    }

    public function testMissingCsrfTokenIsRejected(): void
    {
        $res = self::request('POST', '/api/ai-anomaly.php', ['message' => 'Van valami szokatlan?'], [], self::$adminJar);
        $this->assertSame(403, $res['status']);
        $this->assertTrue($res['json']['csrf_required'] ?? false);
    }

    public function testEmptyMessageIsRejected(): void
    {
        $csrf = $this->freshCsrf(self::$adminJar);
        $res = self::request('POST', '/api/ai-anomaly.php', ['message' => '   '], ['X-CSRF-Token' => $csrf], self::$adminJar);
        $this->assertSame(400, $res['status']);
    }

    public function testOversizedMessageIsRejected(): void
    {
        $csrf = $this->freshCsrf(self::$adminJar);
        $res = self::request('POST', '/api/ai-anomaly.php', ['message' => str_repeat('a', 2001)], ['X-CSRF-Token' => $csrf], self::$adminJar);
        $this->assertSame(400, $res['status']);
    }

    public function testGetMethodIsRejected(): void
    {
        $res = self::request('GET', '/api/ai-anomaly.php', null, [], self::$adminJar);
        $this->assertSame(405, $res['status']);
    }

    public function testUnknownToolRequestedByModelIsRejectedNotExecuted(): void
    {
        self::setOllamaMode('unknown_tool_call');
        $csrf = $this->freshCsrf(self::$adminJar);
        $res = self::request('POST', '/api/ai-anomaly.php', ['message' => 'próbálj meg valami furát'], ['X-CSRF-Token' => $csrf], self::$adminJar);

        $this->assertContains($res['status'], [200, 502]);
        if ($res['status'] === 200) {
            $this->assertTrue($res['json']['ok']);
        }
        $this->assertStringNotContainsString('DROP TABLE', $res['body']);
        $this->assertStringNotContainsString('SQLSTATE', $res['body']);

        require_once dirname(__DIR__) . '/src/Database.php';
        $db = new Database(['driver' => 'sqlite', 'sqlite' => ['path' => self::$root . '/data/stock.sqlite']], self::$root);
        $stmt = $db->pdo()->query('SELECT COUNT(*) FROM sales');
        $this->assertGreaterThanOrEqual(1, (int) $stmt->fetchColumn());
        self::setOllamaMode('final');
    }

    public function testProviderUnavailableNeverLeaksRawDetails(): void
    {
        $csrf = $this->freshCsrf(self::$adminJar);
        $adminCsrfForSettings = $this->freshCsrf(self::$adminJar);
        self::request('POST', '/api/settings.php', ['ai_local_base_url' => 'http://127.0.0.1:1'], ['X-CSRF-Token' => $adminCsrfForSettings], self::$adminJar);

        $res = self::request('POST', '/api/ai-anomaly.php', ['message' => 'kérdés amíg az Ollama nem elérhető'], ['X-CSRF-Token' => $csrf], self::$adminJar);

        $this->assertSame(502, $res['status']);
        $this->assertSame('Az AI-modell jelenleg nem érhető el.', $res['json']['error']);
        $this->assertStringNotContainsString('curl', strtolower($res['body']));
        $this->assertStringNotContainsString(self::$root, $res['body']);

        $adminCsrfRestore = $this->freshCsrf(self::$adminJar);
        self::request('POST', '/api/settings.php', ['ai_local_base_url' => 'http://127.0.0.1:' . self::$ollamaPort], ['X-CSRF-Token' => $adminCsrfRestore], self::$adminJar);
    }

    // ------------------------------------------------------------------
    // Teljes, valódi végponti lefolyás
    // ------------------------------------------------------------------

    public function testSuccessfulEndToEndRunWithoutToolCall(): void
    {
        self::setOllamaMode('final');
        $csrf = $this->freshCsrf(self::$adminJar);
        $res = self::request('POST', '/api/ai-anomaly.php', ['message' => 'Volt ma valami rendkívüli?'], ['X-CSRF-Token' => $csrf], self::$adminJar);

        $this->assertSame(200, $res['status'], $res['body']);
        $this->assertTrue($res['json']['ok']);
        $this->assertSame('anomaly', $res['json']['agent']);
        $this->assertSame('Nincs szokatlan minta ma.', $res['json']['answer']);
        $this->assertSame([], $res['json']['tools_used']);
    }

    public function testSuccessfulEndToEndRunWithRealToolCall(): void
    {
        self::setOllamaMode('tool_call');
        $csrf = $this->freshCsrf(self::$adminJar);
        $res = self::request('POST', '/api/ai-anomaly.php', ['message' => 'Van valami szokatlan a forgalomban?'], ['X-CSRF-Token' => $csrf], self::$adminJar);

        $this->assertSame(200, $res['status'], $res['body']);
        $this->assertTrue($res['json']['ok']);
        $this->assertSame(['get_sales_anomalies'], $res['json']['tools_used']);

        self::setOllamaMode('final');
    }

    public function testAiDisabledReturnsClearError(): void
    {
        $adminCsrf = $this->freshCsrf(self::$adminJar);
        self::request('POST', '/api/settings.php', ['ai_enabled' => false], ['X-CSRF-Token' => $adminCsrf], self::$adminJar);

        $csrf = $this->freshCsrf(self::$adminJar);
        $res = self::request('POST', '/api/ai-anomaly.php', ['message' => 'kérdés'], ['X-CSRF-Token' => $csrf], self::$adminJar);
        $this->assertSame(503, $res['status']);

        $adminCsrf2 = $this->freshCsrf(self::$adminJar);
        self::request('POST', '/api/settings.php', ['ai_enabled' => true], ['X-CSRF-Token' => $adminCsrf2], self::$adminJar);
    }

    public function testOtherAgentEndpointsStillWorkUnchangedAlongsideAnomalyAgent(): void
    {
        // Fázis 5 kritikus elfogadási kritérium: a MEGLÉVŐ Inventory-/
        // SalesAgent végpontok VÁLTOZATLANUL működnek, ugyanazon a
        // szerveren, az AnomalyAgent bevezetése után is.
        self::setOllamaMode('final');
        $csrf = $this->freshCsrf(self::$adminJar);
        $resInv = self::request('POST', '/api/ai-inventory.php', ['message' => 'Minden rendben van a készlettel?'], ['X-CSRF-Token' => $csrf], self::$adminJar);
        $this->assertSame(200, $resInv['status'], $resInv['body']);
        $this->assertSame('inventory', $resInv['json']['agent']);

        $csrf2 = $this->freshCsrf(self::$adminJar);
        $resSales = self::request('POST', '/api/ai-sales.php', ['message' => 'Mennyi volt a forgalom?'], ['X-CSRF-Token' => $csrf2], self::$adminJar);
        $this->assertSame(200, $resSales['status'], $resSales['body']);
        $this->assertSame('sales', $resSales['json']['agent']);
    }
}
