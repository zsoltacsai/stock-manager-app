<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * HTTP-szintű tesztek a kasszakezelés (kasszanyitás/kasszazárás) végpontjaira
 * — ugyanaz a teljesen önálló, ideiglenes másolatban futó PHP beépített-
 * szerver minta, mint tests/InvoiceEndpointsHttpTest.php / HttpSecurityTest.php
 * (KÜLÖN példány, hogy ne zavarja meg azok sorba rendezett tesztjeit). SOSE
 * nyúl az éles data/ mappához. Az itt tesztelt üzleti logika (atomikus
 * nyitás/zárás, várható-összeg számítás) MÁR bizonyított egységszinten
 * (DatabaseTest.php) — ez a fájl a VÉGPONTOK bekötését (auth/CSRF/admin-kapu/
 * bemenet-validáció/audit) ellenőrzi.
 */
final class CashSessionEndpointsHttpTest extends TestCase
{
    private static string $root;
    private static int $port;
    /** @var resource|null */
    private static $serverProcess;
    private static string $baseUrl;
    private static string $loggedInJar;
    private static int $locationId;
    private static int $registerId;

    public static function setUpBeforeClass(): void
    {
        self::$root = sys_get_temp_dir() . '/sm_cash_http_test_' . bin2hex(random_bytes(6));
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

        self::request('GET', '/api/auth-status.php');

        require_once $projectRoot . '/src/Database.php';
        $db = new Database(['driver' => 'sqlite', 'sqlite' => ['path' => self::$root . '/data/stock.sqlite']], self::$root);
        self::$locationId = $db->saveLocation(['name' => 'HTTP teszt telephely']);
        self::$registerId = $db->saveCashRegister(['location_id' => self::$locationId, 'name' => 'HTTP teszt kassza', 'code' => 'HTTP1']);

        $jar = self::cookieJar('setup');
        $status = self::request('GET', '/api/auth-status.php', null, [], $jar);
        $csrf = $status['json']['csrf_token'];
        self::request('POST', '/api/security-settings-save.php', [
            'app_password_enabled' => true,
            'new_password' => 'teszt-jelszo-cash-http',
            'new_password_confirm' => 'teszt-jelszo-cash-http',
        ], ['X-CSRF-Token' => $csrf], $jar);

        self::$loggedInJar = self::cookieJar('logged-in');
        self::request('POST', '/api/login.php', ['password' => 'teszt-jelszo-cash-http'], [], self::$loggedInJar);
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
        $json = json_decode($body, true);

        return ['status' => $status, 'body' => $body, 'json' => is_array($json) ? $json : null];
    }

    private function freshCsrf(): string
    {
        $status = self::request('GET', '/api/auth-status.php', null, [], self::$loggedInJar);
        return $status['json']['csrf_token'];
    }

    // -----------------------------------------------------------------
    // Jogosultság
    // -----------------------------------------------------------------

    public function testEndpointsRequireLogin(): void
    {
        $freshJar = self::cookieJar('fresh-' . bin2hex(random_bytes(3)));
        foreach ([
            ['GET', '/api/cash-registers-list.php'],
            ['GET', '/api/cash-session-status.php?cash_register_id=' . self::$registerId],
            ['GET', '/api/cash-session-detail.php?id=1'],
            ['GET', '/api/cash-report-data.php'],
            ['GET', '/api/export-cash-sessions-csv.php'],
            ['POST', '/api/cash-register-save.php'],
            ['POST', '/api/cash-session-open.php'],
            ['POST', '/api/cash-session-close.php'],
            ['POST', '/api/cash-movement.php'],
        ] as [$method, $path]) {
            $res = self::request($method, $path, $method === 'POST' ? [] : null, [], $freshJar);
            $this->assertSame(401, $res['status'], "$method $path 401-et kellett volna adjon bejelentkezés nélkül.");
        }
    }

    public function testPostEndpointsRequireCsrf(): void
    {
        // Bejelentkezve, DE X-CSRF-Token fejléc nélkül.
        foreach ([
            '/api/cash-register-save.php',
            '/api/cash-session-open.php',
            '/api/cash-session-close.php',
            '/api/cash-movement.php',
        ] as $path) {
            $res = self::request('POST', $path, [], [], self::$loggedInJar);
            $this->assertSame(403, $res['status'], "$path 403-at kellett volna adjon CSRF-token nélkül.");
        }
    }

    // -----------------------------------------------------------------
    // Teljes életciklus: nyitás -> pénzmozgás -> zárás -> riport
    // -----------------------------------------------------------------

    public function testFullLifecycleOpenMovementCloseAndReport(): void
    {
        $csrf = $this->freshCsrf();

        // Nyitás.
        $open = self::request('POST', '/api/cash-session-open.php', [
            'cash_register_id' => self::$registerId,
            'opening_amount' => 20000,
        ], ['X-CSRF-Token' => $csrf], self::$loggedInJar);
        $this->assertSame(200, $open['status'], (string) $open['body']);
        $sessionId = $open['json']['id'];
        $this->assertSame('open', $open['json']['session']['status']);

        // Státusz-lekérdezés visszaadja a nyitott műszakot.
        $status = self::request('GET', '/api/cash-session-status.php?cash_register_id=' . self::$registerId, null, [], self::$loggedInJar);
        $this->assertTrue($status['json']['open']);
        $this->assertSame($sessionId, $status['json']['session']['id']);

        // Egy második nyitás ugyanarra a pénztárgépre 409-et ad.
        $csrf = $this->freshCsrf();
        $secondOpen = self::request('POST', '/api/cash-session-open.php', [
            'cash_register_id' => self::$registerId,
            'opening_amount' => 5000,
        ], ['X-CSRF-Token' => $csrf], self::$loggedInJar);
        $this->assertSame(409, $secondOpen['status']);

        // Pénzbevét + pénzkiadás.
        $csrf = $this->freshCsrf();
        $cashIn = self::request('POST', '/api/cash-movement.php', [
            'cash_session_id' => $sessionId, 'type' => 'cash_in', 'amount' => 1000, 'reason' => 'Aprópénz',
        ], ['X-CSRF-Token' => $csrf], self::$loggedInJar);
        $this->assertSame(200, $cashIn['status'], (string) $cashIn['body']);

        $csrf = $this->freshCsrf();
        $cashOut = self::request('POST', '/api/cash-movement.php', [
            'cash_session_id' => $sessionId, 'type' => 'cash_out', 'amount' => 500, 'reason' => 'Futár',
        ], ['X-CSRF-Token' => $csrf], self::$loggedInJar);
        $this->assertSame(200, $cashOut['status'], (string) $cashOut['body']);

        // Negatív/nulla összeg elutasítva.
        $csrf = $this->freshCsrf();
        $badMovement = self::request('POST', '/api/cash-movement.php', [
            'cash_session_id' => $sessionId, 'type' => 'cash_in', 'amount' => 0, 'reason' => 'x',
        ], ['X-CSRF-Token' => $csrf], self::$loggedInJar);
        $this->assertSame(400, $badMovement['status']);

        // Bontás záráshoz — 20000 nyitó + 0 készpénzes eladás - 0 visszatérítés + 1000 - 500 = 20500.
        $detail = self::request('GET', '/api/cash-session-detail.php?id=' . $sessionId, null, [], self::$loggedInJar);
        $this->assertSame(200, $detail['status']);
        $this->assertEqualsWithDelta(20500.0, $detail['json']['expected_amount'], 0.001);

        // Zárás eltéréssel: 20000 megszámolt a 20500 várható helyett -> variance -500.
        $csrf = $this->freshCsrf();
        $close = self::request('POST', '/api/cash-session-close.php', [
            'id' => $sessionId, 'counted_amount' => 20000,
        ], ['X-CSRF-Token' => $csrf], self::$loggedInJar);
        $this->assertSame(200, $close['status'], (string) $close['body']);
        $this->assertEqualsWithDelta(-500.0, $close['json']['variance'], 0.001);

        // Ugyanazzal az összeggel megismételt zárás (hálózati újrapróbálkozás
        // szimulációja) a MÁR meglévő eredményt adja vissza sikeresen.
        $csrf = $this->freshCsrf();
        $replay = self::request('POST', '/api/cash-session-close.php', [
            'id' => $sessionId, 'counted_amount' => 20000,
        ], ['X-CSRF-Token' => $csrf], self::$loggedInJar);
        $this->assertSame(200, $replay['status']);
        $this->assertEqualsWithDelta(-500.0, $replay['json']['variance'], 0.001);

        // Eltérő összeggel megismételt zárás MÁR ténylegesen 409-et ad (nem replay).
        $csrf = $this->freshCsrf();
        $conflictingClose = self::request('POST', '/api/cash-session-close.php', [
            'id' => $sessionId, 'counted_amount' => 99999,
        ], ['X-CSRF-Token' => $csrf], self::$loggedInJar);
        $this->assertSame(409, $conflictingClose['status']);

        // A riport tartalmazza a lezárt műszakot.
        $report = self::request('GET', '/api/cash-report-data.php?cash_register_id=' . self::$registerId, null, [], self::$loggedInJar);
        $ids = array_column($report['json']['sessions'], 'id');
        $this->assertContains($sessionId, $ids);

        // CSV export sikeresen visszaadja a fejlécet.
        $csv = self::request('GET', '/api/export-cash-sessions-csv.php?cash_register_id=' . self::$registerId, null, [], self::$loggedInJar);
        $this->assertSame(200, $csv['status']);
        $this->assertStringContainsString('Azonosító', $csv['body']);

        // Nyitás újra lehetséges a lezárt műszak után.
        $csrf = $this->freshCsrf();
        $reopen = self::request('POST', '/api/cash-session-open.php', [
            'cash_register_id' => self::$registerId, 'opening_amount' => 10000,
        ], ['X-CSRF-Token' => $csrf], self::$loggedInJar);
        $this->assertSame(200, $reopen['status']);
    }

    public function testOpenRejectsNegativeOpeningAmount(): void
    {
        $csrf = $this->freshCsrf();
        $res = self::request('POST', '/api/cash-session-open.php', [
            'cash_register_id' => self::$registerId + 999, 'opening_amount' => -1,
        ], ['X-CSRF-Token' => $csrf], self::$loggedInJar);
        $this->assertSame(400, $res['status']);
    }

    // -----------------------------------------------------------------
    // Admin-kapu a pénztárgép-kezelésen (cash-register-save.php) — a
    // nyitás/zárás/pénzmozgás SZÁNDÉKOSAN NEM admin-kapus (lásd a
    // kasszazárás jogosultsági döntést a tervben), csak maga a
    // pénztárgép-létrehozás/szerkesztés. A require_admin() csak akkor
    // kényszerít, ha MÁR van legalább egy dolgozó felvéve — ezért ezt a
    // tesztet a saját, dedikált dolgozó-felvétellel együtt kell futtatni.
    // -----------------------------------------------------------------

    public function testCashRegisterSaveRequiresAdminOnceStaffExists(): void
    {
        $projectRoot = dirname(__DIR__);
        require_once $projectRoot . '/src/Database.php';
        $db = new Database(['driver' => 'sqlite', 'sqlite' => ['path' => self::$root . '/data/stock.sqlite']], self::$root);
        $db->saveStaff(['name' => 'Nem-admin teszt', 'pin' => '135790', 'role' => 'cashier']);
        $db->saveStaff(['name' => 'Admin teszt', 'pin' => '246801', 'role' => 'admin']);

        $cashierJar = self::cookieJar('cashier-' . bin2hex(random_bytes(3)));
        self::request('POST', '/api/login.php', ['password' => 'teszt-jelszo-cash-http'], [], $cashierJar);
        $status = self::request('GET', '/api/auth-status.php', null, [], $cashierJar);
        $csrf = $status['json']['csrf_token'];
        self::request('POST', '/api/staff-login.php', ['pin' => '135790'], ['X-CSRF-Token' => $csrf], $cashierJar);

        $csrf = self::request('GET', '/api/auth-status.php', null, [], $cashierJar)['json']['csrf_token'];
        $asCashier = self::request('POST', '/api/cash-register-save.php', [
            'location_id' => self::$locationId, 'name' => 'Kassza 2', 'code' => 'HTTP2', 'is_active' => 1,
        ], ['X-CSRF-Token' => $csrf], $cashierJar);
        $this->assertSame(403, $asCashier['status'], 'Nem-admin dolgozó nem hozhat létre új pénztárgépet.');

        $adminJar = self::cookieJar('admin-' . bin2hex(random_bytes(3)));
        self::request('POST', '/api/login.php', ['password' => 'teszt-jelszo-cash-http'], [], $adminJar);
        $status = self::request('GET', '/api/auth-status.php', null, [], $adminJar);
        $csrf = $status['json']['csrf_token'];
        self::request('POST', '/api/staff-login.php', ['pin' => '246801'], ['X-CSRF-Token' => $csrf], $adminJar);

        $csrf = self::request('GET', '/api/auth-status.php', null, [], $adminJar)['json']['csrf_token'];
        $asAdmin = self::request('POST', '/api/cash-register-save.php', [
            'location_id' => self::$locationId, 'name' => 'Kassza 2', 'code' => 'HTTP2', 'is_active' => 1,
        ], ['X-CSRF-Token' => $csrf], $adminJar);
        $this->assertSame(200, $asAdmin['status'], (string) $asAdmin['body']);

        // Nyitás/pénzmozgás továbbra is elérhető egy nem-admin dolgozónak.
        $csrf = self::request('GET', '/api/auth-status.php', null, [], $cashierJar)['json']['csrf_token'];
        $openAsCashier = self::request('POST', '/api/cash-session-open.php', [
            'cash_register_id' => $asAdmin['json']['id'], 'opening_amount' => 1000,
        ], ['X-CSRF-Token' => $csrf], $cashierJar);
        $this->assertSame(200, $openAsCashier['status'], 'Nem-admin dolgozónak is nyithat kasszaműszakot.');
    }
}
