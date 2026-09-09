<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * HTTP-szintű tesztek a "Beérkezett számlák" (NAV bejövő sync) új
 * végpontjaira (incoming-invoices-list.php, incoming-invoice-detail.php,
 * nav-incoming-sync-status.php, nav-incoming-sync-trigger.php,
 * nav-incoming-sync-run.php) — a tests/InvoiceEndpointsHttpTest.php pontos
 * mintája (teljesen önálló, ideiglenes másolatban futó PHP beépített-
 * szerver). SOSE nyúl az éles data/ mappához.
 */
final class IncomingInvoiceEndpointsHttpTest extends TestCase
{
    private static string $root;
    private static int $port;
    /** @var resource|null */
    private static $serverProcess;
    private static string $baseUrl;
    private static string $loggedInJar;

    private static int $createInvoiceId;
    private static int $modifyInvoiceId;

    public static function setUpBeforeClass(): void
    {
        self::$root = sys_get_temp_dir() . '/sm_incoming_http_test_' . bin2hex(random_bytes(6));
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

        $logFile = self::$root . '/server.log';
        self::$serverProcess = proc_open(
            [PHP_BINARY, '-S', '127.0.0.1:' . self::$port, '-t', self::$root . '/webroot'],
            [1 => ['file', $logFile, 'w'], 2 => ['file', $logFile, 'w']],
            $pipes,
            self::$root
        );
        if (self::$serverProcess === false) {
            self::fail('Nem sikerült elindítani a PHP beépített szervert a teszthez.');
        }
        self::waitForServerReady();

        self::request('GET', '/api/auth-status.php');

        require_once $projectRoot . '/src/Database.php';
        $db = new Database(['driver' => 'sqlite', 'sqlite' => ['path' => self::$root . '/data/stock.sqlite']], self::$root);

        $create = $db->insertIncomingInvoiceDigestEntry([
            'nav_transaction_id' => 'TX-1', 'invoice_number' => 'HTTP-TEST-001', 'batch_index' => 0,
            'supplier_tax_number' => '87654321', 'supplier_name' => 'HTTP Teszt Beszállító Kft',
            'invoice_operation' => 'CREATE', 'invoice_category' => 'NORMAL',
            'invoice_issue_date' => '2026-09-01', 'currency' => 'HUF',
            'net_total' => 10000.0, 'vat_total' => 2700.0, 'nav_ins_date' => '2026-09-01T10:00:00.000Z',
        ]);
        self::$createInvoiceId = (int) $create['id'];

        $modify = $db->insertIncomingInvoiceDigestEntry([
            'nav_transaction_id' => 'TX-2', 'invoice_number' => 'HTTP-TEST-001-M1', 'batch_index' => 0,
            'supplier_tax_number' => '87654321', 'supplier_name' => 'HTTP Teszt Beszállító Kft',
            'invoice_operation' => 'MODIFY', 'invoice_category' => 'NORMAL',
            'original_invoice_number' => 'HTTP-TEST-001', 'modification_index' => '1',
            'invoice_issue_date' => '2026-09-05', 'currency' => 'HUF',
            'net_total' => 1000.0, 'vat_total' => 270.0, 'nav_ins_date' => '2026-09-05T10:00:00.000Z',
        ]);
        self::$modifyInvoiceId = (int) $modify['id'];

        $jar = self::cookieJar('setup');
        $status = self::request('GET', '/api/auth-status.php', null, [], $jar);
        $csrf = $status['json']['csrf_token'];
        self::request('POST', '/api/security-settings-save.php', [
            'app_password_enabled' => true,
            'new_password' => 'teszt-jelszo-incoming-http',
            'new_password_confirm' => 'teszt-jelszo-incoming-http',
        ], ['X-CSRF-Token' => $csrf], $jar);

        self::$loggedInJar = self::cookieJar('logged-in');
        self::request('POST', '/api/login.php', ['password' => 'teszt-jelszo-incoming-http'], [], self::$loggedInJar);
    }

    public static function tearDownAfterClass(): void
    {
        if (self::$serverProcess !== null && is_resource(self::$serverProcess)) {
            proc_terminate(self::$serverProcess);
            proc_close(self::$serverProcess);
        }
        self::removeDir(self::$root);
    }

    // -- Segédfüggvények (tests/InvoiceEndpointsHttpTest.php pontos mintája) --

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

    private static function waitForServerReady(): void
    {
        $deadline = microtime(true) + 10;
        while (microtime(true) < $deadline) {
            $fp = @fsockopen('127.0.0.1', self::$port, $errno, $errstr, 0.2);
            if ($fp) {
                fclose($fp);
                return;
            }
            usleep(100_000);
        }
        self::fail('A teszt-webszerver nem indult el időben.');
    }

    private static function cookieJar(string $name): string
    {
        return self::$root . '/cookies-' . $name . '.txt';
    }

    private static function request(string $method, string $path, ?array $jsonBody = null, array $extraHeaders = [], ?string $cookieJarPath = null): array
    {
        $ch = curl_init(self::$baseUrl . $path);
        $headers = [];
        foreach ($extraHeaders as $k => $v) {
            $headers[] = "$k: $v";
        }
        curl_setopt_array($ch, [
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HEADER => true,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_TIMEOUT => 10,
        ]);
        if ($cookieJarPath !== null) {
            curl_setopt($ch, CURLOPT_COOKIEJAR, $cookieJarPath);
            curl_setopt($ch, CURLOPT_COOKIEFILE, $cookieJarPath);
        }
        if ($jsonBody !== null) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($jsonBody));
            $headers[] = 'Content-Type: application/json';
            curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        }

        $raw = curl_exec($ch);
        if ($raw === false) {
            self::fail('curl hiba: ' . curl_error($ch));
        }
        $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $headerSize = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
        curl_close($ch);

        $rawHeaders = substr($raw, 0, $headerSize);
        $body = substr($raw, $headerSize);
        $respHeaders = [];
        foreach (explode("\r\n", $rawHeaders) as $line) {
            if (str_contains($line, ':')) {
                [$k, $v] = explode(':', $line, 2);
                $respHeaders[strtolower(trim($k))] = trim($v);
            }
        }
        $json = json_decode($body, true);

        return ['status' => $status, 'headers' => $respHeaders, 'body' => $body, 'json' => is_array($json) ? $json : null];
    }

    // -----------------------------------------------------------------
    // Jogosultság — bejelentkezés szükséges
    // -----------------------------------------------------------------

    public function testListRequiresLogin(): void
    {
        $res = self::request('GET', '/api/incoming-invoices-list.php', null, [], self::cookieJar('fresh-list'));
        $this->assertSame(401, $res['status']);
    }

    public function testDetailRequiresLogin(): void
    {
        $res = self::request('GET', '/api/incoming-invoice-detail.php?id=' . self::$createInvoiceId, null, [], self::cookieJar('fresh-detail'));
        $this->assertSame(401, $res['status']);
    }

    public function testSyncStatusRequiresLogin(): void
    {
        $res = self::request('GET', '/api/nav-incoming-sync-status.php', null, [], self::cookieJar('fresh-status'));
        $this->assertSame(401, $res['status']);
    }

    public function testSyncTriggerRequiresLogin(): void
    {
        $res = self::request('POST', '/api/nav-incoming-sync-trigger.php', ['period' => '7d'], [], self::cookieJar('fresh-trigger'));
        $this->assertSame(401, $res['status']);
    }

    public function testSyncRunRequiresCronToken(): void
    {
        $res = self::request('POST', '/api/nav-incoming-sync-run.php', null, [], self::cookieJar('fresh-run'));
        $this->assertSame(401, $res['status']);
    }

    public function testSyncRunRejectsWrongCronToken(): void
    {
        $res = self::request('POST', '/api/nav-incoming-sync-run.php', null, ['X-Cron-Token' => 'nyilvan-rossz-token'], self::cookieJar('wrong-cron'));
        $this->assertSame(401, $res['status']);
    }

    // -----------------------------------------------------------------
    // CSRF — állapotváltoztató (POST) végpont
    // -----------------------------------------------------------------

    public function testSyncTriggerRejectsMissingCsrfToken(): void
    {
        $res = self::request('POST', '/api/nav-incoming-sync-trigger.php', ['period' => '7d'], [], self::$loggedInJar);
        $this->assertSame(403, $res['status']);
        $this->assertTrue(!empty($res['json']['csrf_required']));
    }

    // -----------------------------------------------------------------
    // Lista
    // -----------------------------------------------------------------

    public function testListReturnsSeededInvoices(): void
    {
        $res = self::request('GET', '/api/incoming-invoices-list.php', null, [], self::$loggedInJar);
        $this->assertSame(200, $res['status']);
        $numbers = array_column($res['json']['invoices'], 'invoice_number');
        $this->assertContains('HTTP-TEST-001', $numbers);
        $this->assertContains('HTTP-TEST-001-M1', $numbers);
    }

    public function testListFiltersByOperation(): void
    {
        $res = self::request('GET', '/api/incoming-invoices-list.php?operation=MODIFY', null, [], self::$loggedInJar);
        $numbers = array_column($res['json']['invoices'], 'invoice_number');
        $this->assertContains('HTTP-TEST-001-M1', $numbers);
        $this->assertNotContains('HTTP-TEST-001', $numbers);
    }

    public function testListFiltersBySupplier(): void
    {
        $res = self::request('GET', '/api/incoming-invoices-list.php?' . http_build_query(['supplier' => 'HTTP Teszt Beszállító']), null, [], self::$loggedInJar);
        $this->assertGreaterThanOrEqual(2, count($res['json']['invoices']));
    }

    public function testListFiltersByCurrency(): void
    {
        $res = self::request('GET', '/api/incoming-invoices-list.php?currency=EUR', null, [], self::$loggedInJar);
        $this->assertSame([], $res['json']['invoices'], 'Nincs EUR-s tesztadat, üres listát kell adjon.');
    }

    /**
     * SQL-injekció elleni védelem — a szűrő paraméterekbe ártó SQL-
     * metakaraktereket tartalmazó értéket küldünk; a végpontnak ezt
     * ÁRTALMATLANUL (paraméterezett query, lásd Database::listIncomingInvoices())
     * kell kezelnie — SOSE 500-as szerverhibával, SOSE adatvesztéssel.
     */
    public function testListIsResilientToSqlInjectionAttempt(): void
    {
        $malicious = "'; DROP TABLE incoming_invoices; --";
        $res = self::request('GET', '/api/incoming-invoices-list.php?' . http_build_query(['supplier' => $malicious, 'invoice_number' => $malicious]), null, [], self::$loggedInJar);
        $this->assertSame(200, $res['status']);
        $this->assertSame([], $res['json']['invoices']);

        // A tábla ténylegesen NEM törlődött — a korábbi tesztadat továbbra is lekérdezhető.
        $followUp = self::request('GET', '/api/incoming-invoices-list.php', null, [], self::$loggedInJar);
        $this->assertSame(200, $followUp['status']);
        $this->assertContains('HTTP-TEST-001', array_column($followUp['json']['invoices'], 'invoice_number'));
    }

    // -----------------------------------------------------------------
    // Részletnézet
    // -----------------------------------------------------------------

    public function testDetailReturnsInvoiceWithoutNavConfiguredShowsConfigError(): void
    {
        // Ebben a teszt-környezetben a NAV integráció NINCS beállítva
        // (üres nav_login/nav_password) — a LAZY queryInvoiceData hívás
        // emiatt NEM indulhat el, de a MÁR ismert digest-adatoknak (a
        // válaszban) továbbra is meg kell jelenniük, egy magyarázó
        // detail_error mellett.
        $res = self::request('GET', '/api/incoming-invoice-detail.php?id=' . self::$createInvoiceId, null, [], self::$loggedInJar);
        $this->assertSame(200, $res['status']);
        $this->assertSame('HTTP-TEST-001', $res['json']['invoice']['invoice_number']);
        $this->assertNotEmpty($res['json']['detail_error']);
        $this->assertSame([], $res['json']['items']);
    }

    public function testDetailForNonexistentInvoiceReturns404(): void
    {
        $res = self::request('GET', '/api/incoming-invoice-detail.php?id=9999999', null, [], self::$loggedInJar);
        $this->assertSame(404, $res['status']);
    }

    public function testDetailShowsModificationLinkToOriginal(): void
    {
        $res = self::request('GET', '/api/incoming-invoice-detail.php?id=' . self::$modifyInvoiceId, null, [], self::$loggedInJar);
        $this->assertSame(200, $res['status']);
        $this->assertSame('HTTP-TEST-001', $res['json']['invoice']['original_invoice_number']);
    }

    // -----------------------------------------------------------------
    // Sync állapot + manuális trigger
    // -----------------------------------------------------------------

    public function testSyncStatusReturnsSeededRow(): void
    {
        $res = self::request('GET', '/api/nav-incoming-sync-status.php', null, [], self::$loggedInJar);
        $this->assertSame(200, $res['status']);
        $this->assertSame('nav', $res['json']['sync']['provider']);
    }

    private function csrfFor(string $jar): string
    {
        $status = self::request('GET', '/api/auth-status.php', null, [], $jar);
        return $status['json']['csrf_token'];
    }

    public function testSyncTriggerRejectsInvalidPeriod(): void
    {
        $csrf = $this->csrfFor(self::$loggedInJar);
        $res = self::request('POST', '/api/nav-incoming-sync-trigger.php', ['period' => 'not-a-real-period'], ['X-CSRF-Token' => $csrf], self::$loggedInJar);
        $this->assertSame(400, $res['status']);
    }

    public function testSyncTriggerRejectsCustomPeriodWithoutDateFrom(): void
    {
        $csrf = $this->csrfFor(self::$loggedInJar);
        $res = self::request('POST', '/api/nav-incoming-sync-trigger.php', ['period' => 'custom'], ['X-CSRF-Token' => $csrf], self::$loggedInJar);
        $this->assertSame(400, $res['status']);
    }

    public function testSyncTriggerFailsGracefullyWhenNavNotConfigured(): void
    {
        // Ebben a teszt-környezetben a NAV nincs beállítva — a trigger
        // végpontnak egyértelmű 400-at kell adnia, NEM 500-at/kivételt.
        $csrf = $this->csrfFor(self::$loggedInJar);
        $res = self::request('POST', '/api/nav-incoming-sync-trigger.php', ['period' => '7d'], ['X-CSRF-Token' => $csrf], self::$loggedInJar);
        $this->assertSame(400, $res['status']);
        $this->assertStringContainsString('NAV', $res['json']['error'] ?? '');
    }

    public function testSyncRunSkipsWhenDisabledOrNotConfigured(): void
    {
        // A cron-token útvonal a session/CSRF-védelmet teljesen megkerüli
        // (lásd _bootstrap.php) — a hívás sikeres X-Cron-Token mellett is
        // csak "skipped"-et ad, mert nav_incoming_sync_enabled alapból
        // KIKAPCSOLT (és a NAV sincs beállítva ebben a teszt-környezetben).
        $projectRoot = dirname(__DIR__);
        require_once $projectRoot . '/src/Settings.php';
        $settings = new Settings(self::$root . '/data/settings.json');
        $cronSecret = 'teszt-cron-secret-incoming';
        $settings->save(['cron_secret' => $cronSecret]);

        $res = self::request('POST', '/api/nav-incoming-sync-run.php', null, ['X-Cron-Token' => $cronSecret], self::cookieJar('cron-ok'));
        $this->assertSame(200, $res['status']);
        $this->assertTrue($res['json']['skipped'] ?? false);
    }
}
