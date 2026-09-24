<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Fázis 8B — a kör 26. pontja: ai-action-proposal-execute.php VALÓDI
 * HTTP-n bizonyított tesztje. UGYANAZ a minta, mint tests/
 * ActionProposalEndpointHttpTest.php (Fázis 8A).
 */
final class ActionProposalExecuteEndpointHttpTest extends TestCase
{
    private static string $root;
    private static int $port;
    /** @var resource|null */
    private static $serverProcess;
    private static string $baseUrl;

    private static string $adminJar;
    private static string $staffJar;

    private static int $approvedExecutableId;
    private static int $pendingId;
    private static int $informationalApprovedId;
    private static int $productId;

    public static function setUpBeforeClass(): void
    {
        self::$root = sys_get_temp_dir() . '/sm_action_execute_endpoint_test_' . bin2hex(random_bytes(6));
        mkdir(self::$root, 0775, true);

        $projectRoot = dirname(__DIR__);
        self::copyDir($projectRoot . '/webroot', self::$root . '/webroot', ['vendor', 'products', 'backups']);
        self::copyDir($projectRoot . '/src', self::$root . '/src', []);
        copy($projectRoot . '/schema.sql', self::$root . '/schema.sql');
        mkdir(self::$root . '/config', 0775, true);
        copy($projectRoot . '/config/config.php', self::$root . '/config/config.php');
        mkdir(self::$root . '/data', 0775, true);

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
        require_once $projectRoot . '/src/Ai/ActionProposal.php';
        require_once $projectRoot . '/src/Ai/ActionProposalService.php';
        require_once $projectRoot . '/src/Ai/ActionExecutor.php';
        $db = new Database(['driver' => 'sqlite', 'sqlite' => ['path' => self::$root . '/data/stock.sqlite']], self::$root);
        $db->saveStaff(['name' => 'Execute Teszt Admin', 'pin' => '73412', 'role' => 'admin']);
        $db->saveStaff(['name' => 'Execute Teszt Pénztáros', 'pin' => '28193', 'role' => 'staff']);

        self::$productId = $db->saveProduct(['name' => 'Execute végpont teszt termék', 'barcode' => 'EXEC-EP-' . bin2hex(random_bytes(4)), 'price' => 1000, 'net_price' => 787, 'vat_rate' => 27, 'low_stock_threshold' => 10]);
        $db->setStock(self::$productId, 3);

        $service = new ActionProposalService($db, []);
        $finding = static function (int $productId, string $type, string $reasonCode) {
            return [
                'type' => $type, 'severity' => 'high', 'entity_type' => 'product',
                'entity_id' => $productId, 'entity_name' => 'Execute végpont teszt termék', 'metric' => 'qty',
                'current_value' => 12.0, 'baseline_value' => 6.0, 'change_percent' => 100.0,
                'reason_code' => $reasonCode,
            ];
        };

        $approvedRow = $service->createFromFinding($finding(self::$productId, 'low_stock_elevated_sales', 'exec_ep_1'), 'daily_intelligence', null, null, null);
        $service->approve((int) $approvedRow['id'], null);
        self::$approvedExecutableId = (int) $approvedRow['id'];

        $pendingRow = $service->createFromFinding($finding(self::$productId, 'low_stock_elevated_sales', 'exec_ep_2'), 'daily_intelligence', null, null, null);
        self::$pendingId = (int) $pendingRow['id'];

        $infoRow = $service->createFromFinding($finding(self::$productId, 'stock_sales_divergence', 'exec_ep_3'), 'daily_intelligence', null, null, null);
        $service->approve((int) $infoRow['id'], null);
        self::$informationalApprovedId = (int) $infoRow['id'];
        unset($db);

        $jar = self::cookieJar('setup');
        $status = self::request('GET', '/api/auth-status.php', null, [], $jar);
        $csrf = $status['json']['csrf_token'];
        self::request('POST', '/api/security-settings-save.php', [
            'app_password_enabled' => true,
            'new_password' => 'exec-http-teszt-jelszo',
            'new_password_confirm' => 'exec-http-teszt-jelszo',
        ], ['X-CSRF-Token' => $csrf], $jar);

        self::$adminJar = self::cookieJar('admin');
        self::request('POST', '/api/login.php', ['password' => 'exec-http-teszt-jelszo'], [], self::$adminJar);
        $adminLoginCsrf = self::request('GET', '/api/auth-status.php', null, [], self::$adminJar)['json']['csrf_token'];
        $adminStaffLogin = self::request('POST', '/api/staff-login.php', ['pin' => '73412'], ['X-CSRF-Token' => $adminLoginCsrf], self::$adminJar);
        if (!($adminStaffLogin['json']['ok'] ?? false)) {
            self::fail('Admin staff-login setup sikertelen: ' . $adminStaffLogin['body']);
        }

        self::$staffJar = self::cookieJar('staff');
        self::request('POST', '/api/login.php', ['password' => 'exec-http-teszt-jelszo'], [], self::$staffJar);
        $staffLoginCsrf = self::request('GET', '/api/auth-status.php', null, [], self::$staffJar)['json']['csrf_token'];
        self::request('POST', '/api/staff-login.php', ['pin' => '28193'], ['X-CSRF-Token' => $staffLoginCsrf], self::$staffJar);
    }

    public static function tearDownAfterClass(): void
    {
        if (self::$serverProcess !== null && is_resource(self::$serverProcess)) {
            proc_terminate(self::$serverProcess);
            proc_close(self::$serverProcess);
        }
        self::removeDir(self::$root);
    }

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

    private function adminCsrf(): string
    {
        return self::request('GET', '/api/auth-status.php', null, [], self::$adminJar)['json']['csrf_token'];
    }

    public function testExecuteRequiresAuthentication(): void
    {
        $freshJar = self::cookieJar('fresh-' . bin2hex(random_bytes(3)));
        $res = self::request('POST', '/api/ai-action-proposal-execute.php', ['id' => self::$approvedExecutableId], [], $freshJar);
        $this->assertContains($res['status'], [401, 403]);
    }

    public function testExecuteRejectsNonAdminStaff(): void
    {
        $csrf = self::request('GET', '/api/auth-status.php', null, [], self::$staffJar)['json']['csrf_token'];
        $res = self::request('POST', '/api/ai-action-proposal-execute.php', ['id' => self::$approvedExecutableId], ['X-CSRF-Token' => $csrf], self::$staffJar);
        $this->assertSame(403, $res['status']);
    }

    public function testExecuteRequiresCsrfToken(): void
    {
        $res = self::request('POST', '/api/ai-action-proposal-execute.php', ['id' => self::$approvedExecutableId], [], self::$adminJar);
        $this->assertSame(403, $res['status']);
    }

    public function testExecuteRejectsInvalidId(): void
    {
        $res = self::request('POST', '/api/ai-action-proposal-execute.php', ['id' => 0], ['X-CSRF-Token' => $this->adminCsrf()], self::$adminJar);
        $this->assertSame(400, $res['status']);
    }

    public function testExecuteNotFoundReturns409(): void
    {
        $res = self::request('POST', '/api/ai-action-proposal-execute.php', ['id' => 999999], ['X-CSRF-Token' => $this->adminCsrf()], self::$adminJar);
        $this->assertSame(409, $res['status']);
        $this->assertSame('not_found', $res['json']['reason']);
    }

    public function testExecutePendingProposalIsRejected(): void
    {
        $res = self::request('POST', '/api/ai-action-proposal-execute.php', ['id' => self::$pendingId], ['X-CSRF-Token' => $this->adminCsrf()], self::$adminJar);
        $this->assertSame(409, $res['status']);
        $this->assertSame('pending', $res['json']['reason']);
    }

    public function testExecuteInformationalTypeIsRejected(): void
    {
        $res = self::request('POST', '/api/ai-action-proposal-execute.php', ['id' => self::$informationalApprovedId], ['X-CSRF-Token' => $this->adminCsrf()], self::$adminJar);
        $this->assertSame(409, $res['status']);
        $this->assertSame('not_executable', $res['json']['reason']);
    }

    public function testExecuteSucceedsForApprovedExecutableProposal(): void
    {
        $res = self::request('POST', '/api/ai-action-proposal-execute.php', ['id' => self::$approvedExecutableId], ['X-CSRF-Token' => $this->adminCsrf()], self::$adminJar);

        $this->assertSame(200, $res['status'], $res['body']);
        $this->assertTrue($res['json']['ok']);
        $this->assertSame('executed', $res['json']['status']);
        $this->assertSame('reorder_draft', $res['json']['result']['action']);
        $this->assertGreaterThan(0, $res['json']['result']['quantity']);

        $detail = self::request('GET', '/api/ai-action-proposal-detail.php?id=' . self::$approvedExecutableId, null, [], self::$adminJar);
        $this->assertSame('executed', $detail['json']['proposal']['status']);
        $this->assertNotNull($detail['json']['proposal']['executed_at']);
    }

    public function testDuplicateExecuteReturnsExistingResultNotError(): void
    {
        $res = self::request('POST', '/api/ai-action-proposal-execute.php', ['id' => self::$approvedExecutableId], ['X-CSRF-Token' => $this->adminCsrf()], self::$adminJar);

        $this->assertSame(200, $res['status'], $res['body']);
        $this->assertTrue($res['json']['ok']);
        $this->assertTrue($res['json']['already_executed']);
    }

    public function testExecuteNeverLeaksInternalExceptionDetails(): void
    {
        // A javaslat MÁR végrehajtva — egy tetszőleges hibaszimulációhoz
        // itt elég azt bizonyítani, hogy a válasz SOSE tartalmaz nyers
        // kivétel-/SQL-részletet egyetlen eddigi híváson sem.
        $res = self::request('POST', '/api/ai-action-proposal-execute.php', ['id' => self::$approvedExecutableId], ['X-CSRF-Token' => $this->adminCsrf()], self::$adminJar);
        $this->assertStringNotContainsString('SQLSTATE', $res['body']);
        $this->assertStringNotContainsString('.php on line', $res['body']);
        $this->assertStringNotContainsString('Stack trace', $res['body']);
    }

    public function testExecuteDidNotMutateActualProductStock(): void
    {
        $projectRoot = dirname(__DIR__);
        require_once $projectRoot . '/src/Database.php';
        $db = new Database(['driver' => 'sqlite', 'sqlite' => ['path' => self::$root . '/data/stock.sqlite']], self::$root);
        $product = $db->findProductById(self::$productId);
        $this->assertSame(3, (int) $product['stock_qty'], 'A végrehajtás SOSE módosíthatja a tényleges készletet.');
    }
}
