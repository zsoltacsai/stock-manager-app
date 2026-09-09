<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * HTTP-szintű tesztek a "Kimenő számlák" új végpontjaira
 * (invoices-list.php, invoice-detail.php, invoice-pdf.php,
 * export-invoices-csv.php) — ugyanaz a teljesen önálló, ideiglenes
 * másolatban futó PHP beépített-szerver minta, mint tests/HttpSecurityTest.php
 * (KÜLÖN példány, hogy ne zavarja meg annak gondosan sorba rendezett
 * tesztjeit). SOSE nyúl az éles data/ mappához.
 */
final class InvoiceEndpointsHttpTest extends TestCase
{
    private static string $root;
    private static int $port;
    /** @var resource|null */
    private static $serverProcess;
    private static string $baseUrl;
    private static string $loggedInJar;

    private static int $szamlazzInvoiceId;
    private static int $navInvoiceId;
    private static int $traversalInvoiceId;
    private static string $realPdfPath;
    private static string $outsidePdfPath;

    public static function setUpBeforeClass(): void
    {
        self::$root = sys_get_temp_dir() . '/sm_invoice_http_test_' . bin2hex(random_bytes(6));
        mkdir(self::$root, 0775, true);

        $projectRoot = dirname(__DIR__);
        self::copyDir($projectRoot . '/webroot', self::$root . '/webroot', ['vendor', 'products', 'backups']);
        self::copyDir($projectRoot . '/src', self::$root . '/src', []);
        copy($projectRoot . '/schema.sql', self::$root . '/schema.sql');
        mkdir(self::$root . '/config', 0775, true);
        copy($projectRoot . '/config/config.php', self::$root . '/config/config.php');
        mkdir(self::$root . '/data', 0775, true);
        mkdir(self::$root . '/invoices', 0775, true);

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

        // Egy ártalmatlan kérés, hogy a _bootstrap.php inicializálja (létrehozza,
        // sémával feltöltse) a saját, ideiglenes SQLite fájlt, mielőtt mi magunk
        // közvetlenül is megnyitnánk ugyanazt a fájlt a teszt-adatok beszúrásához.
        self::request('GET', '/api/auth-status.php');

        require_once $projectRoot . '/src/Database.php';
        $db = new Database(['driver' => 'sqlite', 'sqlite' => ['path' => self::$root . '/data/stock.sqlite']], self::$root);

        // Valódi PDF-fájl a helyes invoices/ könyvtárban.
        self::$realPdfPath = self::$root . '/invoices/invoice_TEST-SZ-1.pdf';
        file_put_contents(self::$realPdfPath, '%PDF-1.4 teszt tartalom');

        // Egy fájl a KÖNYVTÁRON KÍVÜL — ide próbál majd "kitörni" egy
        // path-traversal-szerű pdf_path.
        self::$outsidePdfPath = self::$root . '/config/config.php';

        $saleA = $db->insertSale(1270.0, 'Készpénz');
        $db->pdo()->prepare('UPDATE sales SET buyer_name = ? WHERE id = ?')->execute(['Teszt Vevő Kft', $saleA]);
        $db->pdo()->prepare("
            INSERT INTO invoices (sale_id, provider, status, invoice_number, net_total, vat_total, gross_total, currency, issued_at, pdf_path, created_at, updated_at)
            VALUES (?, 'szamlazz', 'done', 'SZ-2026-TEST-1', 1000, 270, 1270, 'HUF', datetime('now'), ?, datetime('now'), datetime('now'))
        ")->execute([$saleA, self::$realPdfPath]);
        self::$szamlazzInvoiceId = (int) $db->pdo()->lastInsertId();

        $saleB = $db->insertSale(2540.0, 'Bankkártya');
        $db->pdo()->prepare('UPDATE sales SET buyer_name = ? WHERE id = ?')->execute(['NAV Teszt Vevő', $saleB]);
        $db->pdo()->prepare("
            INSERT INTO invoices (sale_id, provider, status, invoice_number, provider_ref, net_total, vat_total, gross_total, currency, attempts, created_at, updated_at)
            VALUES (?, 'nav', 'submitted', 'SM-NAV-2026-TEST-2', 'TXN-TEST-ABC', 2000, 540, 2540, 'HUF', 1, datetime('now'), datetime('now'))
        ")->execute([$saleB]);
        self::$navInvoiceId = (int) $db->pdo()->lastInsertId();

        $saleC = $db->insertSale(500.0, 'Készpénz');
        $db->pdo()->prepare("
            INSERT INTO invoices (sale_id, provider, status, invoice_number, net_total, vat_total, gross_total, currency, pdf_path, created_at, updated_at)
            VALUES (?, 'szamlazz', 'done', 'SZ-2026-TRAVERSAL', 394, 106, 500, 'HUF', ?, datetime('now'), datetime('now'))
        ")->execute([$saleC, self::$outsidePdfPath]);
        self::$traversalInvoiceId = (int) $db->pdo()->lastInsertId();

        // Jelszavas védelem bekapcsolása + bejelentkezés — ugyanaz a
        // minta, mint HttpSecurityTest::test30/32 — hogy legyen érvényes,
        // bejelentkezett session a "sikeres elérés" tesztekhez, és egy
        // FRISS (session nélküli) jar-ral tesztelhető legyen a 401.
        $jar = self::cookieJar('setup');
        $status = self::request('GET', '/api/auth-status.php', null, [], $jar);
        $csrf = $status['json']['csrf_token'];
        self::request('POST', '/api/security-settings-save.php', [
            'app_password_enabled' => true,
            'new_password' => 'teszt-jelszo-invoice-http',
            'new_password_confirm' => 'teszt-jelszo-invoice-http',
        ], ['X-CSRF-Token' => $csrf], $jar);

        self::$loggedInJar = self::cookieJar('logged-in');
        self::request('POST', '/api/login.php', ['password' => 'teszt-jelszo-invoice-http'], [], self::$loggedInJar);
    }

    public static function tearDownAfterClass(): void
    {
        if (self::$serverProcess !== null && is_resource(self::$serverProcess)) {
            proc_terminate(self::$serverProcess);
            proc_close(self::$serverProcess);
        }
        self::removeDir(self::$root);
    }

    // -- Segédfüggvények (tests/HttpSecurityTest.php pontos mintája) --

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
    // Jogosultság
    // -----------------------------------------------------------------

    public function testListRequiresLogin(): void
    {
        $freshJar = self::cookieJar('fresh-list');
        $res = self::request('GET', '/api/invoices-list.php', null, [], $freshJar);
        $this->assertSame(401, $res['status']);
    }

    public function testExportCsvRequiresLogin(): void
    {
        $freshJar = self::cookieJar('fresh-export');
        $res = self::request('GET', '/api/export-invoices-csv.php', null, [], $freshJar);
        $this->assertSame(401, $res['status']);
    }

    public function testDetailRequiresLogin(): void
    {
        $freshJar = self::cookieJar('fresh-detail');
        $res = self::request('GET', '/api/invoice-detail.php?id=' . self::$szamlazzInvoiceId, null, [], $freshJar);
        $this->assertSame(401, $res['status']);
    }

    public function testPdfRequiresLogin(): void
    {
        $freshJar = self::cookieJar('fresh-pdf');
        $res = self::request('GET', '/api/invoice-pdf.php?id=' . self::$szamlazzInvoiceId, null, [], $freshJar);
        $this->assertSame(401, $res['status']);
    }

    // -----------------------------------------------------------------
    // Lista — NAV és Számlázz.hu megjelenése, státusz
    // -----------------------------------------------------------------

    public function testListReturnsBothProvidersForLoggedInUser(): void
    {
        $res = self::request('GET', '/api/invoices-list.php', null, [], self::$loggedInJar);
        $this->assertSame(200, $res['status']);
        $invoices = $res['json']['invoices'];

        $numbers = array_column($invoices, 'invoice_number');
        $this->assertContains('SZ-2026-TEST-1', $numbers, 'A Számlázz.hu-s számlának meg kell jelennie.');
        $this->assertContains('SM-NAV-2026-TEST-2', $numbers, 'A NAV-számlának meg kell jelennie.');

        $navRow = current(array_filter($invoices, fn ($i) => $i['invoice_number'] === 'SM-NAV-2026-TEST-2'));
        $this->assertSame('nav', $navRow['provider']);
        $this->assertSame('TXN-TEST-ABC', $navRow['provider_ref']);
        $this->assertSame('NAV Teszt Vevő', $navRow['sale_buyer_name']);

        $szamlazzRow = current(array_filter($invoices, fn ($i) => $i['invoice_number'] === 'SZ-2026-TEST-1'));
        $this->assertSame('szamlazz', $szamlazzRow['provider']);
        $this->assertNull($szamlazzRow['provider_ref'], 'Számlázz.hu sornak sose lehet provider_ref-je.');
    }

    public function testListFiltersByProvider(): void
    {
        $res = self::request('GET', '/api/invoices-list.php?provider=nav', null, [], self::$loggedInJar);
        $numbers = array_column($res['json']['invoices'], 'invoice_number');
        $this->assertContains('SM-NAV-2026-TEST-2', $numbers);
        $this->assertNotContains('SZ-2026-TEST-1', $numbers);
    }

    public function testListFiltersByStatusBucket(): void
    {
        // 'submitted' a 'pending' bucket-be tartozik (lásd Database::INVOICE_STATUS_BUCKETS).
        $res = self::request('GET', '/api/invoices-list.php?status=pending', null, [], self::$loggedInJar);
        $numbers = array_column($res['json']['invoices'], 'invoice_number');
        $this->assertContains('SM-NAV-2026-TEST-2', $numbers, "'submitted' a 'pending' bucket-be tartozik.");

        $doneRes = self::request('GET', '/api/invoices-list.php?status=done', null, [], self::$loggedInJar);
        $doneNumbers = array_column($doneRes['json']['invoices'], 'invoice_number');
        $this->assertContains('SZ-2026-TEST-1', $doneNumbers);
        $this->assertNotContains('SM-NAV-2026-TEST-2', $doneNumbers, "'submitted' NEM 'done'.");
    }

    // -----------------------------------------------------------------
    // Részletnézet
    // -----------------------------------------------------------------

    public function testDetailReturnsInvoiceAndRelatedSale(): void
    {
        $res = self::request('GET', '/api/invoice-detail.php?id=' . self::$szamlazzInvoiceId, null, [], self::$loggedInJar);
        $this->assertSame(200, $res['status']);
        $this->assertSame('SZ-2026-TEST-1', $res['json']['invoice']['invoice_number']);
        $this->assertSame('Teszt Vevő Kft', $res['json']['sale']['buyer_name']);
        $this->assertArrayNotHasKey('receipt_token', $res['json']['sale'], 'A receipt_token titkos, sose mehet ki.');
    }

    public function testDetailForNonexistentInvoiceReturns404(): void
    {
        $res = self::request('GET', '/api/invoice-detail.php?id=9999999', null, [], self::$loggedInJar);
        $this->assertSame(404, $res['status']);
        $this->assertNotEmpty($res['json']['error'] ?? null);
    }

    // -----------------------------------------------------------------
    // PDF — path traversal védelem
    // -----------------------------------------------------------------

    public function testPdfServesRealFileForSzamlazzInvoice(): void
    {
        $res = self::request('GET', '/api/invoice-pdf.php?id=' . self::$szamlazzInvoiceId, null, [], self::$loggedInJar);
        $this->assertSame(200, $res['status']);
        $this->assertSame('application/pdf', $res['headers']['content-type'] ?? null);
        $this->assertStringContainsString('%PDF', $res['body']);
    }

    public function testPdfReturns404ForNavInvoiceWithNoPdf(): void
    {
        $res = self::request('GET', '/api/invoice-pdf.php?id=' . self::$navInvoiceId, null, [], self::$loggedInJar);
        $this->assertSame(404, $res['status'], 'NAV-számlához sose lehet PDF (a NAV API-nak nincs PDF-fogalma).');
    }

    public function testPdfReturns404ForNonexistentInvoice(): void
    {
        $res = self::request('GET', '/api/invoice-pdf.php?id=9999999', null, [], self::$loggedInJar);
        $this->assertSame(404, $res['status']);
    }

    /**
     * A KULCS-teszt: egy invoices sor pdf_path-ja a `invoices/` könyvtáron
     * KÍVÜLRE mutat (jelen esetben egyenesen a config.php-ra) — a
     * végpontnak ezt EL KELL UTASÍTANIA, SOSE szabad kiszolgálnia a
     * config.php tartalmát (ami hitelesítő adatokat is tartalmazhatna
     * éles beállításnál).
     */
    public function testPdfRejectsPathOutsideInvoicesDirectory(): void
    {
        $res = self::request('GET', '/api/invoice-pdf.php?id=' . self::$traversalInvoiceId, null, [], self::$loggedInJar);
        $this->assertSame(404, $res['status'], 'A invoices/ könyvtáron kívülre mutató pdf_path-ot el kell utasítani.');
        $this->assertStringNotContainsString('<?php', $res['body'], 'A config.php tartalma SOSE szivároghat ki.');
    }

    // -----------------------------------------------------------------
    // CSV export
    // -----------------------------------------------------------------

    public function testExportCsvReturnsDataForLoggedInUser(): void
    {
        $res = self::request('GET', '/api/export-invoices-csv.php', null, [], self::$loggedInJar);
        $this->assertSame(200, $res['status']);
        $this->assertStringContainsString('text/csv', $res['headers']['content-type'] ?? '');
        $this->assertStringContainsString('SZ-2026-TEST-1', $res['body']);
        $this->assertStringContainsString('SM-NAV-2026-TEST-2', $res['body']);
    }
}
