<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * HTTP-szintű tesztek a `cash_session_required` beállításra (opcionális
 * kasszanyitás/-zárás kényszer) — ugyanaz a teljesen önálló, ideiglenes
 * másolatban futó PHP beépített-szerver minta, mint
 * tests/CashSessionEndpointsHttpTest.php / CashSessionSaleEnforcementHttpTest.php
 * (KÜLÖN példány, hogy ne zavarja meg azok sorba rendezett tesztjeit).
 *
 * A beállítás a `data/settings.json` fájlba közvetlenül íródik a legtöbb
 * teszt előfeltételeként (nem a /api/settings.php végponton keresztül) —
 * ez a Settings::read() valódi, minden kérésnél friss beolvasását
 * teszteli, admin-bejelentkezés nélkül; a beállítás MENTÉSÉNEK
 * jogosultság-védelmét (require_admin) külön, a tényleges HTTP végponton
 * keresztül teszteli testSavingTheSettingRequiresAdminOnceStaffExists().
 *
 * Az `api/sale.php` egyetlen érintett ága (UX-02, Phase 7 audit) a
 * `cash_register_id !== null && $cashSessionRequired && !nyitott műszak`
 * esetet utasítja el — a "nincs megadva pénztárgép" útvonal (a
 * kasszakezelést egyáltalán nem használó boltok visszafelé kompatibilis
 * útja) a beállítástól FÜGGETLENÜL mindig változatlan marad, ezt
 * testSaleWithoutAnyCashRegisterIdUnaffectedEitherWay() bizonyítja.
 */
final class CashSessionOptionalSettingHttpTest extends TestCase
{
    private static string $root;
    private static int $port;
    /** @var resource|null */
    private static $serverProcess;
    private static string $baseUrl;
    private static Database $db;
    private static int $productId;
    private static int $locationId;
    private static int $registerNeverOpened;

    public static function setUpBeforeClass(): void
    {
        self::$root = sys_get_temp_dir() . '/sm_cash_optional_http_' . bin2hex(random_bytes(6));
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
        self::$db = new Database(['driver' => 'sqlite', 'sqlite' => ['path' => self::$root . '/data/stock.sqlite']], self::$root);
        self::$productId = self::$db->saveProduct(['name' => 'Opcionális-kassza teszt', 'barcode' => 'OPTCASH-1', 'price' => 1000, 'net_price' => 787.4, 'vat_rate' => '27']);
        self::$db->setStock(self::$productId, 1000);
        self::$locationId = self::$db->saveLocation(['name' => 'Opcionális-kassza bolt']);
        self::$registerNeverOpened = self::$db->saveCashRegister(['location_id' => self::$locationId, 'name' => 'Sosem nyitott kassza', 'code' => 'OPTCASH-R1']);
    }

    public static function tearDownAfterClass(): void
    {
        if (self::$serverProcess !== null && is_resource(self::$serverProcess)) {
            proc_terminate(self::$serverProcess);
            proc_close(self::$serverProcess);
        }
        self::removeDir(self::$root);
    }

    // -- Segédfüggvények (tests/CashSessionEndpointsHttpTest.php pontos mintája) --

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

    /** Közvetlenül írja a data/settings.json-t — a Settings::read() minden
     * kérésnél frissen olvassa, szerver-újraindítás nélkül érvényesül. */
    private static function setCashSessionRequired(bool $value): void
    {
        file_put_contents(self::$root . '/data/settings.json', json_encode(['cash_session_required' => $value]));
    }

    private static function sale(?int $cashRegisterId, string $idempotencyKey): array
    {
        $payload = [
            'items' => [['product_id' => self::$productId, 'qty' => 1]],
            'payment_method' => 'Készpénz',
            'idempotency_key' => $idempotencyKey,
        ];
        if ($cashRegisterId !== null) {
            $payload['cash_register_id'] = $cashRegisterId;
        }
        // Közös cookie jar a GET+POST között — a CSRF-token a munkamenethez
        // kötött, jar nélkül a POST egy teljesen friss (session-cookie
        // nélküli) kérésnek látszana, és érvénytelen tokent kapna.
        $jar = self::cookieJar('sale');
        $csrf = self::request('GET', '/api/auth-status.php', null, [], $jar)['json']['csrf_token'];
        return self::request('POST', '/api/sale.php', $payload, ['X-CSRF-Token' => $csrf], $jar);
    }

    // ------------------------------------------------------------------
    // 1) Mode B — kikapcsolva: eladás sikeres nyitott műszak nélkül is
    // ------------------------------------------------------------------
    public function testSaleSucceedsWithoutOpenSessionWhenSettingDisabled(): void
    {
        self::setCashSessionRequired(false);

        $res = self::sale(self::$registerNeverOpened, 'optcash-disabled-' . bin2hex(random_bytes(4)));
        $this->assertSame(200, $res['status'], $res['body']);
        $this->assertArrayHasKey('sale_id', $res['json']);

        $row = self::$db->pdo()->prepare('SELECT cash_session_id FROM sales WHERE id = ?');
        $row->execute([$res['json']['sale_id']]);
        $this->assertNull($row->fetchColumn(), 'Kikapcsolt beállításnál a sale cash_session_id-ja NULL marad, nem keletkezik mesterséges session.');
    }

    // ------------------------------------------------------------------
    // 2) Mode A — bekapcsolva (ugyanazon a szerveren, csak a beállítás
    //    változik): a korábbi, Phase 7 UX-02 elutasítás visszaáll
    // ------------------------------------------------------------------
    public function testSaleStillRejectedWithoutOpenSessionWhenSettingEnabled(): void
    {
        self::setCashSessionRequired(true);

        $before = (int) self::$db->pdo()->query('SELECT COUNT(*) FROM sales')->fetchColumn();
        $res = self::sale(self::$registerNeverOpened, 'optcash-enabled-' . bin2hex(random_bytes(4)));
        $this->assertSame(409, $res['status'], $res['body']);
        $this->assertSame('cash_session_required', $res['json']['code'] ?? null);
        $after = (int) self::$db->pdo()->query('SELECT COUNT(*) FROM sales')->fetchColumn();
        $this->assertSame($before, $after, 'Elutasított eladásnál egyetlen sales-sor sem jöhet létre.');
    }

    // ------------------------------------------------------------------
    // 3) A "nincs pénztárgép megadva" útvonal a beállítástól FÜGGETLENÜL
    //    mindig változatlan — mindkét módban lefuttatva
    // ------------------------------------------------------------------
    public function testSaleWithoutAnyCashRegisterIdUnaffectedEitherWay(): void
    {
        foreach ([false, true] as $required) {
            self::setCashSessionRequired($required);
            $res = self::sale(null, 'optcash-noreg-' . ($required ? 'on' : 'off') . '-' . bin2hex(random_bytes(4)));
            $this->assertSame(200, $res['status'], $res['body']);
            $row = self::$db->pdo()->prepare('SELECT cash_session_id FROM sales WHERE id = ?');
            $row->execute([$res['json']['sale_id']]);
            $this->assertNull($row->fetchColumn());
        }
    }

    // ------------------------------------------------------------------
    // 4) A pénzmozgás-rögzítés és a kasszazárás a beállítástól FÜGGETLENÜL
    //    továbbra is VALÓDI, nyitott műszakot igényel — ezeket a terv
    //    szerint szándékosan nem érinti a `cash_session_required` kapcsoló.
    // ------------------------------------------------------------------
    public function testCashMovementAndCloseStillRequireARealOpenSessionRegardlessOfSetting(): void
    {
        foreach ([false, true] as $required) {
            self::setCashSessionRequired($required);
            $jar = self::cookieJar('movement-' . ($required ? 'on' : 'off'));
            $csrf = self::request('GET', '/api/auth-status.php', null, [], $jar)['json']['csrf_token'];

            $movement = self::request('POST', '/api/cash-movement.php', [
                'cash_session_id' => 999999,
                'type' => 'cash_in',
                'amount' => 1000,
                'reason' => 'teszt',
            ], ['X-CSRF-Token' => $csrf], $jar);
            $this->assertSame(409, $movement['status'], $movement['body']);

            $close = self::request('POST', '/api/cash-session-close.php', [
                'id' => 999999,
                'counted_amount' => 0,
            ], ['X-CSRF-Token' => $csrf], $jar);
            $this->assertSame(409, $close['status'], $close['body']);
        }
    }

    // ------------------------------------------------------------------
    // 5) A beállítás MENTÉSE admin-jogszinthez kötött, mihelyt van
    //    felvett dolgozó — ugyanaz a minta, mint
    //    CashSessionEndpointsHttpTest::testCashRegisterSaveRequiresAdminOnceStaffExists()
    // ------------------------------------------------------------------
    public function testSavingTheSettingRequiresAdminOnceStaffExists(): void
    {
        self::$db->saveStaff(['name' => 'Opcionális-kassza nem-admin', 'pin' => '246801', 'role' => 'cashier']);
        self::$db->saveStaff(['name' => 'Opcionális-kassza admin', 'pin' => '135790', 'role' => 'admin']);

        $jar = self::cookieJar('setup-optcash');
        $status = self::request('GET', '/api/auth-status.php', null, [], $jar);
        $csrf = $status['json']['csrf_token'];
        self::request('POST', '/api/security-settings-save.php', [
            'app_password_enabled' => true,
            'new_password' => 'teszt-jelszo-optcash-http',
            'new_password_confirm' => 'teszt-jelszo-optcash-http',
        ], ['X-CSRF-Token' => $csrf], $jar);

        $cashierJar = self::cookieJar('cashier-' . bin2hex(random_bytes(3)));
        self::request('POST', '/api/login.php', ['password' => 'teszt-jelszo-optcash-http'], [], $cashierJar);
        $csrf = self::request('GET', '/api/auth-status.php', null, [], $cashierJar)['json']['csrf_token'];
        self::request('POST', '/api/staff-login.php', ['pin' => '246801'], ['X-CSRF-Token' => $csrf], $cashierJar);

        $csrf = self::request('GET', '/api/auth-status.php', null, [], $cashierJar)['json']['csrf_token'];
        $asCashier = self::request('POST', '/api/settings.php', ['cash_session_required' => false], ['X-CSRF-Token' => $csrf], $cashierJar);
        $this->assertSame(403, $asCashier['status'], 'Nem-admin dolgozó nem módosíthatja a kasszanyitás/-zárás beállítást.');

        $adminJar = self::cookieJar('admin-' . bin2hex(random_bytes(3)));
        self::request('POST', '/api/login.php', ['password' => 'teszt-jelszo-optcash-http'], [], $adminJar);
        $csrf = self::request('GET', '/api/auth-status.php', null, [], $adminJar)['json']['csrf_token'];
        self::request('POST', '/api/staff-login.php', ['pin' => '135790'], ['X-CSRF-Token' => $csrf], $adminJar);

        $csrf = self::request('GET', '/api/auth-status.php', null, [], $adminJar)['json']['csrf_token'];
        $asAdmin = self::request('POST', '/api/settings.php', ['cash_session_required' => false], ['X-CSRF-Token' => $csrf], $adminJar);
        $this->assertSame(200, $asAdmin['status'], (string) $asAdmin['body']);
        $this->assertFalse($asAdmin['json']['cash_session_required'] ?? null);
    }
}
