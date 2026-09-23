<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Fázis 7 — a kör 25. pontja: az AI-előzmények/napi-jelentés VÉGPONTJAI
 * (ai-history-list.php/ai-history-detail.php/ai-daily-report.php) VALÓDI
 * HTTP-n bizonyított tesztje. Nincs szükség stub Ollamára — ezek a
 * végpontok kizárólag a MÁR eltárolt system_events/ai_daily_reports
 * sorokat olvassák, LLM-hívás nélkül.
 */
final class AiHistoryEndpointHttpTest extends TestCase
{
    private static string $root;
    private static int $port;
    /** @var resource|null */
    private static $serverProcess;
    private static string $baseUrl;

    private static string $adminJar;
    private static string $staffJar;

    public static function setUpBeforeClass(): void
    {
        self::$root = sys_get_temp_dir() . '/sm_ai_history_endpoint_test_' . bin2hex(random_bytes(6));
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
        $db = new Database(['driver' => 'sqlite', 'sqlite' => ['path' => self::$root . '/data/stock.sqlite']], self::$root);
        $db->saveStaff(['name' => 'History Teszt Admin', 'pin' => '73412', 'role' => 'admin']);
        $db->saveStaff(['name' => 'History Teszt Pénztáros', 'pin' => '28193', 'role' => 'staff']);

        for ($i = 0; $i < 3; $i++) {
            $db->logSystemEvent(
                'ai', 'agent_run', 'info', 'success', 'AI-agent futás sikeres (sales).',
                json_encode(['agent' => 'sales', 'provider' => 'local', 'model' => 'qwen3:8b', 'tools_used' => ['get_sales_summary'], 'iterations' => 1, 'duration_ms' => 50, 'error' => null], JSON_UNESCAPED_UNICODE),
                365
            );
        }
        $db->logSystemEvent(
            'ai', 'agent_run', 'warning', 'failure', 'AI-agent futás sikertelen (anomaly).',
            json_encode(['agent' => 'anomaly', 'provider' => 'anthropic', 'model' => 'claude-sonnet-5', 'tools_used' => [], 'iterations' => 1, 'duration_ms' => 30, 'error' => 'Az AI-modell jelenleg nem érhető el.'], JSON_UNESCAPED_UNICODE),
            365
        );
        $db->claimAiDailyReportSlot('2026-05-01');
        $db->finalizeAiDailyReport('2026-05-01', [
            'status' => 'completed', 'provider' => 'local', 'model' => 'qwen3:8b',
            'has_significant_findings' => true, 'findings_count' => 1,
            'findings_json' => json_encode([['type' => 'sales_decline', 'severity' => 'critical']]),
            'report_text' => 'Napi összefoglaló teszt szöveg.',
        ]);
        unset($db);

        $jar = self::cookieJar('setup');
        $status = self::request('GET', '/api/auth-status.php', null, [], $jar);
        $csrf = $status['json']['csrf_token'];
        self::request('POST', '/api/security-settings-save.php', [
            'app_password_enabled' => true,
            'new_password' => 'ai-history-http-teszt-jelszo',
            'new_password_confirm' => 'ai-history-http-teszt-jelszo',
        ], ['X-CSRF-Token' => $csrf], $jar);

        self::$adminJar = self::cookieJar('admin');
        self::request('POST', '/api/login.php', ['password' => 'ai-history-http-teszt-jelszo'], [], self::$adminJar);
        $adminLoginCsrf = self::request('GET', '/api/auth-status.php', null, [], self::$adminJar)['json']['csrf_token'];
        $adminStaffLogin = self::request('POST', '/api/staff-login.php', ['pin' => '73412'], ['X-CSRF-Token' => $adminLoginCsrf], self::$adminJar);
        if (!($adminStaffLogin['json']['ok'] ?? false)) {
            self::fail('Admin staff-login setup sikertelen: ' . $adminStaffLogin['body']);
        }

        self::$staffJar = self::cookieJar('staff');
        self::request('POST', '/api/login.php', ['password' => 'ai-history-http-teszt-jelszo'], [], self::$staffJar);
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

    // ------------------------------------------------------------------
    // ai-history-list.php
    // ------------------------------------------------------------------

    public function testHistoryListRequiresAuthentication(): void
    {
        $freshJar = self::cookieJar('fresh-' . bin2hex(random_bytes(3)));
        $res = self::request('GET', '/api/ai-history-list.php', null, [], $freshJar);
        $this->assertContains($res['status'], [401, 403]);
    }

    public function testHistoryListRejectsNonAdminStaff(): void
    {
        $res = self::request('GET', '/api/ai-history-list.php', null, [], self::$staffJar);
        $this->assertSame(403, $res['status']);
    }

    public function testHistoryListReturnsSeededEntriesNewestFirst(): void
    {
        $res = self::request('GET', '/api/ai-history-list.php', null, [], self::$adminJar);

        $this->assertSame(200, $res['status'], $res['body']);
        $this->assertTrue($res['json']['ok']);
        $this->assertSame(4, $res['json']['total']);
        $this->assertCount(4, $res['json']['entries']);
        $this->assertSame('anomaly', $res['json']['entries'][0]['agent']);
    }

    public function testHistoryListPaginationBoundsPageSize(): void
    {
        $res = self::request('GET', '/api/ai-history-list.php?page_size=2&page=1', null, [], self::$adminJar);

        $this->assertSame(200, $res['status']);
        $this->assertCount(2, $res['json']['entries']);
        $this->assertTrue($res['json']['has_more']);
    }

    public function testHistoryListFiltersByAgentAndStatus(): void
    {
        $res = self::request('GET', '/api/ai-history-list.php?agent=anomaly&status=failure', null, [], self::$adminJar);

        $this->assertSame(200, $res['status']);
        $this->assertSame(1, $res['json']['total']);
        $this->assertSame('anomaly', $res['json']['entries'][0]['agent']);
    }

    public function testHistoryListRejectsUnknownAgentFilterSilentlyIgnoringIt(): void
    {
        // Egy ismeretlen agent-érték NEM okoz SQL-hibát/kivételt — a
        // végpont a fehérlistán kívüli értéket egyszerűen figyelmen kívül
        // hagyja (lásd $allowedAgents).
        $res = self::request('GET', '/api/ai-history-list.php?agent=nem-letezo-agent-vagy-sql-injekcio', null, [], self::$adminJar);

        $this->assertSame(200, $res['status'], $res['body']);
        $this->assertSame(4, $res['json']['total']);
    }

    public function testHistoryListNeverLeaksRawTechnicalDetailOrSecrets(): void
    {
        $res = self::request('GET', '/api/ai-history-list.php', null, [], self::$adminJar);

        $this->assertStringNotContainsString('technical_detail', $res['body']);
        $this->assertStringNotContainsString('api_key', $res['body']);
        $this->assertStringNotContainsString('csrf', strtolower($res['body']));
    }

    // ------------------------------------------------------------------
    // ai-history-detail.php
    // ------------------------------------------------------------------

    public function testHistoryDetailRequiresAuthentication(): void
    {
        $freshJar = self::cookieJar('fresh2-' . bin2hex(random_bytes(3)));
        $res = self::request('GET', '/api/ai-history-detail.php?id=1', null, [], $freshJar);
        $this->assertContains($res['status'], [401, 403]);
    }

    public function testHistoryDetailReturnsEntryForValidId(): void
    {
        $list = self::request('GET', '/api/ai-history-list.php', null, [], self::$adminJar);
        $id = $list['json']['entries'][0]['id'];

        $res = self::request('GET', '/api/ai-history-detail.php?id=' . $id, null, [], self::$adminJar);

        $this->assertSame(200, $res['status'], $res['body']);
        $this->assertTrue($res['json']['ok']);
        $this->assertSame((int) $id, $res['json']['entry']['id']);
    }

    public function testHistoryDetailReturns404ForMissingId(): void
    {
        $res = self::request('GET', '/api/ai-history-detail.php?id=999999', null, [], self::$adminJar);
        $this->assertSame(404, $res['status']);
    }

    public function testHistoryDetailRejectsInvalidId(): void
    {
        $res = self::request('GET', '/api/ai-history-detail.php?id=0', null, [], self::$adminJar);
        $this->assertSame(400, $res['status']);
    }

    // ------------------------------------------------------------------
    // ai-daily-report.php
    // ------------------------------------------------------------------

    public function testDailyReportRequiresAuthentication(): void
    {
        $freshJar = self::cookieJar('fresh3-' . bin2hex(random_bytes(3)));
        $res = self::request('GET', '/api/ai-daily-report.php', null, [], $freshJar);
        $this->assertContains($res['status'], [401, 403]);
    }

    public function testDailyReportReturnsLatestCompletedReportByDefault(): void
    {
        $res = self::request('GET', '/api/ai-daily-report.php', null, [], self::$adminJar);

        $this->assertSame(200, $res['status'], $res['body']);
        $this->assertSame('2026-05-01', $res['json']['report']['report_date']);
        $this->assertSame('Napi összefoglaló teszt szöveg.', $res['json']['report']['report_text']);
        $this->assertCount(1, $res['json']['report']['findings']);
    }

    public function testDailyReportReturnsSpecificDate(): void
    {
        $res = self::request('GET', '/api/ai-daily-report.php?date=2026-05-01', null, [], self::$adminJar);

        $this->assertSame(200, $res['status']);
        $this->assertSame('2026-05-01', $res['json']['report']['report_date']);
    }

    public function testDailyReportReturnsNullForDateWithNoReport(): void
    {
        $res = self::request('GET', '/api/ai-daily-report.php?date=2099-01-01', null, [], self::$adminJar);

        $this->assertSame(200, $res['status']);
        $this->assertNull($res['json']['report']);
    }

    public function testDailyReportRejectsMalformedDate(): void
    {
        $res = self::request('GET', '/api/ai-daily-report.php?date=not-a-date', null, [], self::$adminJar);
        $this->assertSame(400, $res['status']);
    }

    public function testDailyReportListModeReturnsBoundedMetadataOnly(): void
    {
        $res = self::request('GET', '/api/ai-daily-report.php?list=1', null, [], self::$adminJar);

        $this->assertSame(200, $res['status'], $res['body']);
        $this->assertCount(1, $res['json']['reports']);
        $this->assertSame('2026-05-01', $res['json']['reports'][0]['report_date']);
        $this->assertArrayNotHasKey('report_text', $res['json']['reports'][0]);
        $this->assertArrayNotHasKey('findings_json', $res['json']['reports'][0]);
    }
}
