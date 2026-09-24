<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Biztonsági audit remediáció — F-09 (proxyzott kérés sose eshet vissza a
 * Szerver saját PHP-sessionjére), F-05 (inaktivált dolgozó Kliens-munkamenete)
 * és F-07 (érvénytelen HMAC-aláírású kérés sose foglal nonce-ot). Egy
 * VALÓDI, Szerver szerepkörű (node_role='server', beállított app-jelszóval)
 * app-másolat, `php -S` folyamatként; a Kliens-fejléceket a teszt állítja elő
 * UGYANAZZAL a ClientHmac osztállyal, amit a ClientProxy is használ.
 *
 * A tesztmetódusok számozottak és egymásra épülnek (megosztott Szerver).
 */
final class SecurityRemediationClientServerHttpTest extends TestCase
{
    private const APP_PASSWORD = 'szerver-app-jelszo-f09';
    private const ADMIN_PIN = '482193';
    private const CASHIER_PIN = '615274';

    private static string $root;
    private static int $port;
    /** @var resource|null */
    private static $serverProcess;

    /** @var array{id:int,client_id:string,client_secret:string} */
    private static array $client;
    /** @var array{id:int,client_id:string,client_secret:string} */
    private static array $otherClient;
    private static int $cashierStaffId;

    private static string $cashierSession;
    private static string $cashierCsrf;

    public static function setUpBeforeClass(): void
    {
        self::$root = sys_get_temp_dir() . '/sm_remed_cs_' . bin2hex(random_bytes(6));
        $projectRoot = dirname(__DIR__);
        self::copyDir($projectRoot . '/webroot', self::$root . '/webroot', ['vendor', 'products', 'backups']);
        self::copyDir($projectRoot . '/src', self::$root . '/src', []);
        copy($projectRoot . '/schema.sql', self::$root . '/schema.sql');
        mkdir(self::$root . '/config', 0775, true);
        copy($projectRoot . '/config/config.php', self::$root . '/config/config.php');
        mkdir(self::$root . '/data', 0775, true);
        mkdir(self::$root . '/invoices', 0775, true);
        file_put_contents(self::$root . '/config/installer-generated.php', '<?php return ' . var_export([
            'shop' => ['name' => 'X', 'address' => 'X'],
            'db' => ['driver' => 'sqlite', 'sqlite' => ['path' => self::$root . '/data/stock.sqlite'], 'mysql' => []],
            'node_role' => 'server',
        ], true) . ';');
        (new Settings(self::$root . '/data/settings.json'))->save([
            'app_password_hash' => password_hash(self::APP_PASSWORD, PASSWORD_DEFAULT),
            'app_password_enabled' => true,
        ]);

        $db = self::db();
        $db->saveStaff(['name' => 'Remed Admin', 'pin' => self::ADMIN_PIN, 'role' => 'admin']);
        self::$cashierStaffId = $db->saveStaff(['name' => 'Remed Pénztáros', 'pin' => self::CASHIER_PIN, 'role' => 'cashier']);
        self::$client = $db->registerClient('Remediációs kliens');
        self::$otherClient = $db->registerClient('Másik kliens');
        unset($db);

        self::$port = self::findFreePort();
        self::$serverProcess = proc_open(
            [PHP_BINARY, '-S', '127.0.0.1:' . self::$port, '-t', self::$root . '/webroot'],
            [1 => ['file', self::$root . '/server.log', 'w'], 2 => ['file', self::$root . '/server.log', 'w']],
            $pipes,
            self::$root
        );
        self::waitForServerReady();
    }

    public static function tearDownAfterClass(): void
    {
        if (self::$serverProcess !== null && is_resource(self::$serverProcess)) {
            proc_terminate(self::$serverProcess);
            proc_close(self::$serverProcess);
        }
        foreach ([self::$client, self::$otherClient] as $c) {
            @unlink(self::nonceFile($c['client_id']));
        }
        self::removeDir(self::$root);
    }

    // -- Segédfüggvények (tests/ClientHttpAuthTest.php mintája) --

    private static function db(): Database
    {
        return new Database(['driver' => 'sqlite', 'sqlite' => ['path' => self::$root . '/data/stock.sqlite']], self::$root);
    }

    private static function pdo(): PDO
    {
        $prop = new ReflectionProperty(Database::class, 'pdo');
        $prop->setAccessible(true);
        return $prop->getValue(self::db());
    }

    private static function nonceFile(string $clientId): string
    {
        return sys_get_temp_dir() . '/stockmanager-client-nonces/' . preg_replace('/[^a-zA-Z0-9_-]/', '_', $clientId) . '.json';
    }

    private static function nonceCount(string $clientId): int
    {
        $raw = @file_get_contents(self::nonceFile($clientId));
        $decoded = $raw !== false ? json_decode($raw, true) : null;
        return is_array($decoded) ? count($decoded) : 0;
    }

    private static function copyDir(string $from, string $to, array $exclude): void
    {
        if (!is_dir($from)) {
            return;
        }
        mkdir($to, 0775, true);
        foreach (scandir($from) as $item) {
            if ($item === '.' || $item === '..' || in_array($item, $exclude, true)) {
                continue;
            }
            is_dir("$from/$item") ? self::copyDir("$from/$item", "$to/$item", $exclude) : copy("$from/$item", "$to/$item");
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
            $path = "$dir/$item";
            is_dir($path) && !is_link($path) ? self::removeDir($path) : @unlink($path);
        }
        @rmdir($dir);
    }

    private static function findFreePort(): int
    {
        $sock = stream_socket_server('tcp://127.0.0.1:0');
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

    /** @param array<string,string> $headers */
    private static function request(string $method, string $path, ?string $body = null, array $headers = [], ?string $jar = null): array
    {
        $ch = curl_init('http://127.0.0.1:' . self::$port . $path);
        $lines = [];
        foreach ($headers as $k => $v) {
            $lines[] = "$k: $v";
        }
        if ($body !== null) {
            $lines[] = 'Content-Type: application/json';
        }
        curl_setopt_array($ch, [
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HEADER => true,
            CURLOPT_HTTPHEADER => $lines,
            CURLOPT_TIMEOUT => 10,
        ]);
        if ($jar !== null) {
            curl_setopt($ch, CURLOPT_COOKIEJAR, $jar);
            curl_setopt($ch, CURLOPT_COOKIEFILE, $jar);
        }
        if ($body !== null) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
        }
        $raw = (string) curl_exec($ch);
        $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $headerSize = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
        curl_close($ch);
        $respHeaders = [];
        foreach (explode("\r\n", substr($raw, 0, $headerSize)) as $line) {
            if (str_contains($line, ':')) {
                [$k, $v] = explode(':', $line, 2);
                $respHeaders[strtolower(trim($k))] = trim($v);
            }
        }
        $json = json_decode(substr($raw, $headerSize), true);
        return ['status' => $status, 'json' => is_array($json) ? $json : null, 'headers' => $respHeaders, 'body' => substr($raw, $headerSize)];
    }

    /**
     * Egy aláírt, proxyzott kérés — a ClientProxy pontos fejléc-alakjával.
     * @param array<string,string> $extra
     */
    private static function signed(array $client, string $method, string $script, ?array $jsonBody = null, array $extra = [], ?string $jar = null, ?string $signatureOverride = null, ?string $timestamp = null, ?string $nonce = null): array
    {
        $body = $jsonBody !== null ? (string) json_encode($jsonBody) : '';
        $timestamp ??= (string) time();
        $nonce ??= bin2hex(random_bytes(16));
        $canonical = ClientHmac::canonicalString($method, '/api/' . $script, $timestamp, $nonce, $body);
        $headers = [
            'X-Client-Id' => $client['client_id'],
            'X-Client-Timestamp' => $timestamp,
            'X-Client-Nonce' => $nonce,
            'X-Client-Signature' => $signatureOverride ?? ClientHmac::sign($canonical, ClientHmac::deriveSigningKey($client['client_secret'])),
        ] + $extra;
        return self::request($method, '/api/' . $script, $jsonBody !== null ? $body : null, $headers, $jar);
    }

    private function sessionHeaders(string $sessionId, ?string $csrf = null): array
    {
        $h = ['X-Client-Session-Id' => $sessionId];
        if ($csrf !== null) {
            $h['X-Client-Csrf-Token'] = $csrf;
        }
        return $h;
    }

    // ------------------------------------------------------------------
    // 01-03 — a legitim Kliens-út Szerver szerepkörben is működik
    // ------------------------------------------------------------------

    public function test01_DirectUnauthenticatedServerRequestIsRejectedButProxiedAuthStatusIsNotAPasswordLoop(): void
    {
        $direct = self::request('GET', '/api/customers-list.php');
        $this->assertSame(401, $direct['status']);

        // A Kliens böngészője (topbar.js) enabled && !logged_in esetén a
        // login.html-re irányítana — a proxyzott forgalomnál az app-jelszó
        // réteg nem értelmezhető, ezért ez sose okozhat átirányítási hurkot.
        $proxied = self::signed(self::$client, 'GET', 'auth-status.php');
        $this->assertSame(200, $proxied['status']);
        $this->assertFalse($proxied['json']['enabled']);
        // Dolgozói client_sessions nélkül a gép maga nem "bejelentkezett".
        $this->assertFalse($proxied['json']['logged_in']);
    }

    public function test01b_ProxiedReceiptWithoutClientSessionStillNeedsTheReceiptToken(): void
    {
        $db = self::db();
        $saleId = $db->insertSale(1000.0, 'Készpénz');
        $db->insertSaleItem($saleId, ['product_id' => null, 'name' => 'Nyugta-teszt', 'qty' => 1, 'unit_price' => 1000.0, 'vat_rate' => 27]);

        $this->assertSame(401, self::signed(self::$client, 'GET', 'receipt-detail.php?sale_id=' . $saleId)['status'], 'Egy HMAC-kal hitelesített gép dolgozói munkamenet nélkül nem olvashat token nélkül nyugtát.');

        $login = self::signed(self::$client, 'POST', 'staff-login.php', ['pin' => self::CASHIER_PIN]);
        $withSession = self::signed(self::$client, 'GET', 'receipt-detail.php?sale_id=' . $saleId, null, $this->sessionHeaders($login['headers']['x-client-session-id']));
        $this->assertSame(200, $withSession['status'], $withSession['body']);
        $this->assertTrue($withSession['json']['is_staff']);
    }

    public function test02_ProxiedStaffLoginMintsClientSessionForThePinOwner(): void
    {
        $res = self::signed(self::$client, 'POST', 'staff-login.php', ['pin' => self::CASHIER_PIN]);
        $this->assertSame(200, $res['status'], $res['body']);
        $this->assertNotEmpty($res['headers']['x-client-session-id'] ?? '');
        $this->assertNotEmpty($res['headers']['x-client-csrf-token'] ?? '');
        self::$cashierSession = $res['headers']['x-client-session-id'];
        self::$cashierCsrf = $res['headers']['x-client-csrf-token'];

        $row = self::db()->findClientSession(self::$cashierSession);
        $this->assertSame(self::$cashierStaffId, (int) $row['staff_id']);
    }

    public function test03_ValidProxiedClientSessionWorksForReadsAndCsrfBoundWrites(): void
    {
        $read = self::signed(self::$client, 'GET', 'customers-list.php', null, $this->sessionHeaders(self::$cashierSession));
        $this->assertSame(200, $read['status'], $read['body']);

        $write = self::signed(self::$client, 'POST', 'product-save.php', ['name' => 'Proxy remediációs termék', 'gross_price' => 1000, 'vat_rate' => '27'], $this->sessionHeaders(self::$cashierSession, self::$cashierCsrf));
        $this->assertSame(200, $write['status'], $write['body']);

        $noCsrf = self::signed(self::$client, 'POST', 'product-save.php', ['name' => 'CSRF nélkül', 'gross_price' => 1000, 'vat_rate' => '27'], $this->sessionHeaders(self::$cashierSession));
        $this->assertSame(403, $noCsrf['status']);
        $badCsrf = self::signed(self::$client, 'POST', 'product-save.php', ['name' => 'Hamis CSRF', 'gross_price' => 1000, 'vat_rate' => '27'], $this->sessionHeaders(self::$cashierSession, str_repeat('ab', 32)));
        $this->assertSame(403, $badCsrf['status']);
    }

    // ------------------------------------------------------------------
    // 04-07 — F-09: nincs visszaesés a Szerver saját PHP-sessionjére
    // ------------------------------------------------------------------

    public function test04_ProxiedRequestCannotUseAServerPhpSessionHoldingAnAdminIdentity(): void
    {
        // Egy VALÓDI Szerver-oldali böngésző-session, admin dolgozóval.
        $jar = self::$root . '/server-admin-jar.txt';
        self::request('GET', '/api/auth-status.php', null, [], $jar);
        $this->assertSame(200, self::request('POST', '/api/login.php', json_encode(['password' => self::APP_PASSWORD]), [], $jar)['status']);
        $csrf = self::request('GET', '/api/auth-status.php', null, [], $jar)['json']['csrf_token'];
        $this->assertSame(200, self::request('POST', '/api/staff-login.php', json_encode(['pin' => self::ADMIN_PIN]), ['X-CSRF-Token' => $csrf], $jar)['status']);
        $this->assertSame(200, self::request('GET', '/api/clients-list.php', null, [], $jar)['status'], 'Előfeltétel: a direkt Szerver-session admin.');

        // Ugyanez a süti egy érvényesen aláírt, de client_sessions nélküli
        // proxyzott kéréssel — sem olvasás, sem admin, sem CSRF-mentes írás.
        $this->assertSame(401, self::signed(self::$client, 'GET', 'customers-list.php', null, [], $jar)['status']);
        $this->assertSame(401, self::signed(self::$client, 'GET', 'clients-list.php', null, [], $jar)['status']);
        $this->assertSame(401, self::signed(self::$client, 'POST', 'client-register.php', ['label' => 'visszaesés'], [], $jar)['status']);
        $this->assertSame(401, self::signed(self::$client, 'GET', 'clients-list.php', null, $this->sessionHeaders(str_repeat('0', 64)), $jar)['status'], 'Érvénytelen client_session_id + érvényes Szerver-süti.');
    }

    public function test05_ExpiredClientSessionIsRejected(): void
    {
        $res = self::signed(self::$client, 'POST', 'staff-login.php', ['pin' => self::CASHIER_PIN]);
        $session = $res['headers']['x-client-session-id'];
        self::pdo()->prepare('UPDATE client_sessions SET expires_at = ? WHERE client_session_id = ?')->execute([date('Y-m-d H:i:s', time() - 60), $session]);

        $this->assertSame(401, self::signed(self::$client, 'GET', 'customers-list.php', null, $this->sessionHeaders($session))['status']);
    }

    public function test06_ClientSessionOfAnotherRegisteredClientIsRejected(): void
    {
        $this->assertSame(401, self::signed(self::$otherClient, 'GET', 'customers-list.php', null, $this->sessionHeaders(self::$cashierSession))['status']);
    }

    public function test07_RevokedClientIsRejectedEvenWithAValidClientSession(): void
    {
        $db = self::db();
        $revoked = $db->registerClient('Visszavonandó kliens');
        $login = self::signed($revoked, 'POST', 'staff-login.php', ['pin' => self::CASHIER_PIN]);
        $session = $login['headers']['x-client-session-id'];
        $this->assertSame(200, self::signed($revoked, 'GET', 'customers-list.php', null, $this->sessionHeaders($session))['status']);

        self::pdo()->prepare('UPDATE registered_clients SET revoked_at = ? WHERE id = ?')->execute([date('Y-m-d H:i:s'), $revoked['id']]);
        $this->assertSame(401, self::signed($revoked, 'GET', 'customers-list.php', null, $this->sessionHeaders($session))['status']);
        @unlink(self::nonceFile($revoked['client_id']));
    }

    // ------------------------------------------------------------------
    // 08 — F-05: inaktivált dolgozó Kliens-munkamenete
    // ------------------------------------------------------------------

    public function test08_DeactivatedStaffLosesExistingClientSessionAndCannotLogInAgain(): void
    {
        $db = self::db();
        $tempStaffId = $db->saveStaff(['name' => 'Távozó dolgozó', 'pin' => '904417', 'role' => 'admin']);
        $login = self::signed(self::$client, 'POST', 'staff-login.php', ['pin' => '904417']);
        $session = $login['headers']['x-client-session-id'];
        $this->assertSame(200, self::signed(self::$client, 'GET', 'clients-list.php', null, $this->sessionHeaders($session))['status']);

        $db->saveStaff(['id' => $tempStaffId, 'name' => 'Távozó dolgozó', 'role' => 'admin', 'is_active' => 0]);

        $this->assertSame(401, self::signed(self::$client, 'GET', 'clients-list.php', null, $this->sessionHeaders($session))['status']);
        $this->assertSame(401, self::signed(self::$client, 'GET', 'customers-list.php', null, $this->sessionHeaders($session))['status']);
        $this->assertNull($db->findClientSession($session), 'Az inaktivált dolgozó munkamenete a kérés során visszavonódik.');
        $this->assertSame(401, self::signed(self::$client, 'POST', 'staff-login.php', ['pin' => '904417'])['status']);

        // Az aktív pénztáros munkamenete érintetlen.
        $this->assertSame(200, self::signed(self::$client, 'GET', 'customers-list.php', null, $this->sessionHeaders(self::$cashierSession))['status']);
    }

    public function test09_DeactivationThroughStaffSaveRevokesClientSessionsImmediately(): void
    {
        $db = self::db();
        $staffId = $db->saveStaff(['name' => 'Endpointon inaktivált', 'pin' => '731902', 'role' => 'cashier']);
        $session = self::signed(self::$client, 'POST', 'staff-login.php', ['pin' => '731902'])['headers']['x-client-session-id'];
        $this->assertNotNull($db->findClientSession($session));

        $adminLogin = self::signed(self::$client, 'POST', 'staff-login.php', ['pin' => self::ADMIN_PIN]);
        $adminSession = $adminLogin['headers']['x-client-session-id'];
        $adminCsrf = $adminLogin['headers']['x-client-csrf-token'];
        $save = self::signed(self::$client, 'POST', 'staff-save.php', ['id' => $staffId, 'name' => 'Endpointon inaktivált', 'role' => 'cashier', 'is_active' => false], $this->sessionHeaders($adminSession, $adminCsrf));
        $this->assertSame(200, $save['status'], $save['body']);

        $this->assertNull($db->findClientSession($session));
    }

    // ------------------------------------------------------------------
    // 10-13 — F-07: nonce-claim csak érvényes aláírás után
    // ------------------------------------------------------------------

    public function test10_InvalidSignatureNeverConsumesNonceState(): void
    {
        $before = self::nonceCount(self::$client['client_id']);
        for ($i = 0; $i < 25; $i++) {
            $res = self::signed(self::$client, 'GET', 'customers-list.php', null, [], null, str_repeat('ab', 32));
            $this->assertSame(401, $res['status']);
        }
        $this->assertSame($before, self::nonceCount(self::$client['client_id']), '25 érvénytelen aláírású, egyedi nonce-ú kérés sem növelheti a nonce-tárat.');
    }

    public function test11_ValidRequestConsumesItsNonceAndReplayIsRejected(): void
    {
        $nonce = bin2hex(random_bytes(16));
        $timestamp = (string) time();
        $before = self::nonceCount(self::$client['client_id']);

        $first = self::signed(self::$client, 'GET', 'customers-list.php', null, $this->sessionHeaders(self::$cashierSession), null, null, $timestamp, $nonce);
        $this->assertSame(200, $first['status']);
        $this->assertSame($before + 1, self::nonceCount(self::$client['client_id']));

        $replay = self::signed(self::$client, 'GET', 'customers-list.php', null, $this->sessionHeaders(self::$cashierSession), null, null, $timestamp, $nonce);
        $this->assertSame(401, $replay['status'], 'Egy már felhasznált nonce-szal érkező, egyébként érvényes kérés visszajátszás.');
    }

    public function test12_StaleTimestampIsStillRejectedAndDoesNotConsumeNonce(): void
    {
        $before = self::nonceCount(self::$client['client_id']);
        $res = self::signed(self::$client, 'GET', 'customers-list.php', null, $this->sessionHeaders(self::$cashierSession), null, null, (string) (time() - 600));
        $this->assertSame(401, $res['status']);
        $this->assertSame($before, self::nonceCount(self::$client['client_id']));
    }

    public function test13_UnknownClientIdNeverCreatesNonceState(): void
    {
        $fakeClient = ['client_id' => 'cl_nemletezo' . bin2hex(random_bytes(4)), 'client_secret' => 'x'];
        $res = self::signed($fakeClient, 'GET', 'customers-list.php');
        $this->assertSame(401, $res['status']);
        $this->assertFileDoesNotExist(self::nonceFile($fakeClient['client_id']));
    }
}
