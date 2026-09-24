<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Fázis 8A — a kör 32. pontja: az ai-action-proposals-list.php/
 * ai-action-proposal-detail.php/ai-action-proposal-approve.php/
 * ai-action-proposal-reject.php végpontok VALÓDI HTTP-n bizonyított
 * tesztje. UGYANAZ a minta, mint tests/AiHistoryEndpointHttpTest.php —
 * nincs szükség AI-providerre, ezek a végpontok LLM-hívás nélkül
 * dolgoznak (a javaslatok a setUpBeforeClass()-ban közvetlenül,
 * ActionProposalService-en keresztül kerülnek beszúrásra).
 */
final class ActionProposalEndpointHttpTest extends TestCase
{
    private static string $root;
    private static int $port;
    /** @var resource|null */
    private static $serverProcess;
    private static string $baseUrl;

    private static string $adminJar;
    private static string $staffJar;

    private static int $pendingProposalId;
    private static int $secondPendingProposalId;
    private static int $productId;

    public static function setUpBeforeClass(): void
    {
        self::$root = sys_get_temp_dir() . '/sm_action_proposal_endpoint_test_' . bin2hex(random_bytes(6));
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
        $db = new Database(['driver' => 'sqlite', 'sqlite' => ['path' => self::$root . '/data/stock.sqlite']], self::$root);
        $db->saveStaff(['name' => 'Proposal Teszt Admin', 'pin' => '73412', 'role' => 'admin']);
        $db->saveStaff(['name' => 'Proposal Teszt Pénztáros', 'pin' => '28193', 'role' => 'staff']);

        self::$productId = $db->saveProduct(['name' => 'Végpont teszt termék', 'barcode' => 'EP-' . bin2hex(random_bytes(4)), 'price' => 1000, 'net_price' => 787, 'vat_rate' => 27]);
        $db->setStock(self::$productId, 8);

        $service = new ActionProposalService($db, []);
        $finding = static function (int $productId, string $reasonCode) {
            return [
                'type' => 'low_stock_elevated_sales', 'severity' => 'high', 'entity_type' => 'product',
                'entity_id' => $productId, 'entity_name' => 'Végpont teszt termék', 'metric' => 'qty',
                'current_value' => 12.0, 'baseline_value' => 6.0, 'change_percent' => 100.0,
                'reason_code' => $reasonCode,
            ];
        };
        $row1 = $service->createFromFinding($finding(self::$productId, 'low_stock_with_sales_uplift'), 'daily_intelligence', 'local', 'qwen3:8b', null);
        self::$pendingProposalId = (int) $row1['id'];

        $productId2 = $db->saveProduct(['name' => 'Második végpont teszt termék', 'barcode' => 'EP2-' . bin2hex(random_bytes(4)), 'price' => 1000, 'net_price' => 787, 'vat_rate' => 27]);
        $db->setStock($productId2, 3);
        $row2 = $service->createFromFinding($finding($productId2, 'low_stock_with_sales_uplift_2'), 'daily_intelligence', 'local', 'qwen3:8b', null);
        self::$secondPendingProposalId = (int) $row2['id'];
        unset($db);

        $jar = self::cookieJar('setup');
        $status = self::request('GET', '/api/auth-status.php', null, [], $jar);
        $csrf = $status['json']['csrf_token'];
        self::request('POST', '/api/security-settings-save.php', [
            'app_password_enabled' => true,
            'new_password' => 'proposal-http-teszt-jelszo',
            'new_password_confirm' => 'proposal-http-teszt-jelszo',
        ], ['X-CSRF-Token' => $csrf], $jar);

        self::$adminJar = self::cookieJar('admin');
        self::request('POST', '/api/login.php', ['password' => 'proposal-http-teszt-jelszo'], [], self::$adminJar);
        $adminLoginCsrf = self::request('GET', '/api/auth-status.php', null, [], self::$adminJar)['json']['csrf_token'];
        $adminStaffLogin = self::request('POST', '/api/staff-login.php', ['pin' => '73412'], ['X-CSRF-Token' => $adminLoginCsrf], self::$adminJar);
        if (!($adminStaffLogin['json']['ok'] ?? false)) {
            self::fail('Admin staff-login setup sikertelen: ' . $adminStaffLogin['body']);
        }

        self::$staffJar = self::cookieJar('staff');
        self::request('POST', '/api/login.php', ['password' => 'proposal-http-teszt-jelszo'], [], self::$staffJar);
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

    // ------------------------------------------------------------------
    // ai-action-proposals-list.php
    // ------------------------------------------------------------------

    public function testListRequiresAuthentication(): void
    {
        $freshJar = self::cookieJar('fresh-' . bin2hex(random_bytes(3)));
        $res = self::request('GET', '/api/ai-action-proposals-list.php', null, [], $freshJar);
        $this->assertContains($res['status'], [401, 403]);
    }

    public function testListRejectsNonAdminStaff(): void
    {
        $res = self::request('GET', '/api/ai-action-proposals-list.php', null, [], self::$staffJar);
        $this->assertSame(403, $res['status']);
    }

    public function testListReturnsSeededPendingProposals(): void
    {
        $res = self::request('GET', '/api/ai-action-proposals-list.php', null, [], self::$adminJar);

        $this->assertSame(200, $res['status'], $res['body']);
        $this->assertTrue($res['json']['ok']);
        $this->assertSame(2, $res['json']['total']);
        $this->assertCount(2, $res['json']['proposals']);
    }

    public function testListFiltersByStatus(): void
    {
        $res = self::request('GET', '/api/ai-action-proposals-list.php?status=pending', null, [], self::$adminJar);
        $this->assertSame(200, $res['status']);
        $this->assertSame(2, $res['json']['total']);

        $res2 = self::request('GET', '/api/ai-action-proposals-list.php?status=rejected', null, [], self::$adminJar);
        $this->assertSame(200, $res2['status']);
        $this->assertSame(0, $res2['json']['total']);
    }

    public function testListRejectsUnknownStatusFilterSilentlyIgnoringIt(): void
    {
        // Fázis 8B — a kör 4. pontja bővítette a whitelistet (executing/
        // executed/execution_failed), ezért ez a teszt egy TÉNYLEGESEN,
        // örökre ismeretlen státusz-értéket használ, nem 'executed'-et.
        $res = self::request('GET', '/api/ai-action-proposals-list.php?status=nem_letezo_statusz_vagy_sql_injekcio', null, [], self::$adminJar);
        $this->assertSame(200, $res['status'], $res['body']);
        $this->assertSame(2, $res['json']['total']);
    }

    public function testListNeverLeaksSecretLikeFields(): void
    {
        $res = self::request('GET', '/api/ai-action-proposals-list.php', null, [], self::$adminJar);
        $this->assertStringNotContainsString('api_key', $res['body']);
        $this->assertStringNotContainsString('csrf', strtolower($res['body']));
    }

    public function testListNeverShowsExecutedLanguage(): void
    {
        $res = self::request('GET', '/api/ai-action-proposals-list.php', null, [], self::$adminJar);
        $this->assertStringNotContainsString('"executed"', $res['body']);
    }

    // ------------------------------------------------------------------
    // ai-action-proposal-detail.php
    // ------------------------------------------------------------------

    public function testDetailRequiresAuthentication(): void
    {
        $freshJar = self::cookieJar('fresh2-' . bin2hex(random_bytes(3)));
        $res = self::request('GET', '/api/ai-action-proposal-detail.php?id=' . self::$pendingProposalId, null, [], $freshJar);
        $this->assertContains($res['status'], [401, 403]);
    }

    public function testDetailReturnsProposalForValidId(): void
    {
        $res = self::request('GET', '/api/ai-action-proposal-detail.php?id=' . self::$pendingProposalId, null, [], self::$adminJar);
        $this->assertSame(200, $res['status'], $res['body']);
        $this->assertSame(self::$pendingProposalId, $res['json']['proposal']['id']);
        $this->assertSame('reorder_draft', $res['json']['proposal']['proposal_type']);
        $this->assertArrayHasKey('evidence', $res['json']['proposal']);
        $this->assertArrayHasKey('proposed_action', $res['json']['proposal']);
    }

    public function testDetailReturns404ForMissingId(): void
    {
        $res = self::request('GET', '/api/ai-action-proposal-detail.php?id=999999', null, [], self::$adminJar);
        $this->assertSame(404, $res['status']);
    }

    public function testDetailRejectsInvalidId(): void
    {
        $res = self::request('GET', '/api/ai-action-proposal-detail.php?id=0', null, [], self::$adminJar);
        $this->assertSame(400, $res['status']);
    }

    // ------------------------------------------------------------------
    // ai-action-proposal-approve.php / ai-action-proposal-reject.php
    // ------------------------------------------------------------------

    public function testApproveRequiresAuthentication(): void
    {
        $freshJar = self::cookieJar('fresh3-' . bin2hex(random_bytes(3)));
        $res = self::request('POST', '/api/ai-action-proposal-approve.php', ['id' => self::$pendingProposalId], [], $freshJar);
        $this->assertContains($res['status'], [401, 403]);
    }

    public function testApproveRejectsNonAdminStaff(): void
    {
        $csrf = self::request('GET', '/api/auth-status.php', null, [], self::$staffJar)['json']['csrf_token'];
        $res = self::request('POST', '/api/ai-action-proposal-approve.php', ['id' => self::$pendingProposalId], ['X-CSRF-Token' => $csrf], self::$staffJar);
        $this->assertSame(403, $res['status']);
    }

    public function testApproveRequiresCsrfToken(): void
    {
        $res = self::request('POST', '/api/ai-action-proposal-approve.php', ['id' => self::$pendingProposalId], [], self::$adminJar);
        $this->assertSame(403, $res['status']);
    }

    public function testApproveRejectsInvalidId(): void
    {
        $res = self::request('POST', '/api/ai-action-proposal-approve.php', ['id' => 0], ['X-CSRF-Token' => $this->adminCsrf()], self::$adminJar);
        $this->assertSame(400, $res['status']);
    }

    public function testApproveNotFoundReturns409WithSafeMessage(): void
    {
        $res = self::request('POST', '/api/ai-action-proposal-approve.php', ['id' => 999999], ['X-CSRF-Token' => $this->adminCsrf()], self::$adminJar);
        $this->assertSame(409, $res['status']);
        $this->assertSame('not_found', $res['json']['reason']);
    }

    public function testApproveSucceedsForPendingProposalAndNeverClaimsExecution(): void
    {
        $res = self::request('POST', '/api/ai-action-proposal-approve.php', ['id' => self::$pendingProposalId], ['X-CSRF-Token' => $this->adminCsrf()], self::$adminJar);

        $this->assertSame(200, $res['status'], $res['body']);
        $this->assertTrue($res['json']['ok']);
        $this->assertSame('approved', $res['json']['status']);
        $this->assertStringNotContainsString('készlet módosítva', strtolower($res['json']['note'] ?? ''));

        $detail = self::request('GET', '/api/ai-action-proposal-detail.php?id=' . self::$pendingProposalId, null, [], self::$adminJar);
        $this->assertSame('approved', $detail['json']['proposal']['status']);
        $this->assertNotNull($detail['json']['proposal']['reviewed_at']);
    }

    public function testApproveAlreadyApprovedReturns409(): void
    {
        $res = self::request('POST', '/api/ai-action-proposal-approve.php', ['id' => self::$pendingProposalId], ['X-CSRF-Token' => $this->adminCsrf()], self::$adminJar);
        $this->assertSame(409, $res['status']);
        $this->assertSame('approved', $res['json']['reason']);
    }

    public function testApprovalDidNotMutateActualProductStock(): void
    {
        // A kör "I. NO business mutation occurs" kritériuma — a fenti
        // jóváhagyás UTÁN a termék készlete VÁLTOZATLAN kell maradjon.
        $projectRoot = dirname(__DIR__);
        require_once $projectRoot . '/src/Database.php';
        $db = new Database(['driver' => 'sqlite', 'sqlite' => ['path' => self::$root . '/data/stock.sqlite']], self::$root);
        $product = $db->findProductById(self::$productId);
        $this->assertSame(8, (int) $product['stock_qty']);
    }

    public function testRejectRequiresCsrfToken(): void
    {
        $res = self::request('POST', '/api/ai-action-proposal-reject.php', ['id' => self::$secondPendingProposalId], [], self::$adminJar);
        $this->assertSame(403, $res['status']);
    }

    public function testRejectSucceedsWithReason(): void
    {
        $res = self::request('POST', '/api/ai-action-proposal-reject.php', [
            'id' => self::$secondPendingProposalId,
            'reason' => 'Nem releváns a szezon miatt.',
        ], ['X-CSRF-Token' => $this->adminCsrf()], self::$adminJar);

        $this->assertSame(200, $res['status'], $res['body']);
        $this->assertTrue($res['json']['ok']);
        $this->assertSame('rejected', $res['json']['status']);

        $detail = self::request('GET', '/api/ai-action-proposal-detail.php?id=' . self::$secondPendingProposalId, null, [], self::$adminJar);
        $this->assertSame('rejected', $detail['json']['proposal']['status']);
        $this->assertSame('Nem releváns a szezon miatt.', $detail['json']['proposal']['rejection_reason']);
    }

    public function testRejectAlreadyRejectedReturns409(): void
    {
        $res = self::request('POST', '/api/ai-action-proposal-reject.php', ['id' => self::$secondPendingProposalId], ['X-CSRF-Token' => $this->adminCsrf()], self::$adminJar);
        $this->assertSame(409, $res['status']);
        $this->assertSame('rejected', $res['json']['reason']);
    }

    public function testRejectionReasonTooLongIsRejected(): void
    {
        $freshJar = self::cookieJar('reason-too-long');
        self::request('POST', '/api/login.php', ['password' => 'proposal-http-teszt-jelszo'], [], $freshJar);
        $csrf = self::request('GET', '/api/auth-status.php', null, [], $freshJar)['json']['csrf_token'];
        self::request('POST', '/api/staff-login.php', ['pin' => '73412'], ['X-CSRF-Token' => $csrf], $freshJar);
        $csrf2 = self::request('GET', '/api/auth-status.php', null, [], $freshJar)['json']['csrf_token'];

        $res = self::request('POST', '/api/ai-action-proposal-reject.php', [
            'id' => self::$pendingProposalId,
            'reason' => str_repeat('x', 600),
        ], ['X-CSRF-Token' => $csrf2], $freshJar);

        $this->assertSame(400, $res['status']);
    }
}
