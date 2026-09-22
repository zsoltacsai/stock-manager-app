<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Fázis 2 — HMAC kliens-hitelesítés + kliens-regisztráció + dolgozói
 * munkamenet/CSRF-híd VALÓDI HTTP-szintű bizonyítása. Egyetlen, teljesen
 * önálló, ideiglenes másolatban futó "Szerver" PHP beépített-szerver
 * folyamat (ugyanaz a minta, mint tests/CashSessionEndpointsHttpTest.php/
 * tests/HttpSecurityTest.php) — SOSE nyúl az éles data/ mappához.
 *
 * A "Kliens" oldalt NEM egy második ClientProxy-folyamat játssza itt (azt
 * már tests/ClientProxyTest.php + tests/ClientProxyHttpTest.php bizonyítja
 * a szállítási rétegre) — itt maga a teszt-osztály állítja elő a valódi
 * X-Client-Id/X-Client-Timestamp/X-Client-Nonce/X-Client-Signature
 * fejléceket (ugyanazzal a ClientHmac osztállyal, amit a valódi ClientProxy
 * is használ), és tényleges curl-kéréssel küldi a Szervernek — ez pontosan
 * a Szerver-oldali ClientAuthenticator/_bootstrap.php valódi HTTP-
 * kódútvonalát futtatja le, éles fejlécekkel, éles adatbázissal.
 *
 * A tesztmetódusok SZÁMOZOTTAK és egymásra épülnek (ugyanaz a minta, mint
 * tests/HttpSecurityTest.php test52_... sorozata) — a PHPUnit alapértelmezett
 * végrehajtási sorrendje az osztályban deklarált sorrend, tehát a számozás
 * garantálja az egymásra épülő állapotot (regisztrált kliensek, aktív
 * munkamenetek stb.) egyetlen, megosztott Szerver-folyamaton keresztül.
 */
final class ClientHttpAuthTest extends TestCase
{
    private static string $root;
    private static int $port;
    /** @var resource|null */
    private static $serverProcess;
    private static string $baseUrl;
    private static string $serverLog;

    private static string $adminJar;
    private static string $cashierJar;
    private static int $adminStaffId;

    /** @var array{id:int,client_id:string,client_secret:string} */
    private static array $clientA;
    /** @var array{id:int,client_id:string,client_secret:string} */
    private static array $clientB;
    /** @var array{id:int,client_id:string,client_secret:string} */
    private static array $clientC;

    private static string $sessionA;
    private static string $csrfA;
    private static string $sessionB;
    private static string $csrfB;
    private static string $sessionC;
    private static string $csrfC;

    /** @var string[] minden, a teszt során valaha kiadott nyers client_secret — a log-regresszióhoz. */
    private static array $allRawSecrets = [];
    /** @var string[] minden, a teszt során valaha kiszámolt HMAC-aláírás — a log-regresszióhoz. */
    private static array $allSignatures = [];
    /** @var string[] minden, a teszt során valaha kiadott nyers CSRF-token — a DB-regresszióhoz. */
    private static array $allRawCsrfTokens = [];

    public static function setUpBeforeClass(): void
    {
        self::$root = sys_get_temp_dir() . '/sm_client_auth_http_' . bin2hex(random_bytes(6));
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
        self::$serverLog = self::$root . '/server.log';

        self::$serverProcess = proc_open(
            [PHP_BINARY, '-S', '127.0.0.1:' . self::$port, '-t', self::$root . '/webroot'],
            [1 => ['file', self::$serverLog, 'w'], 2 => ['file', self::$serverLog, 'w']],
            $pipes,
            self::$root
        );
        if (self::$serverProcess === false) {
            self::fail('Nem sikerült elindítani a teszt-"Szerver" php -S folyamatot.');
        }
        self::waitForServerReady();

        // Az első kérés hozza létre/migrálja az adatbázist.
        self::request('GET', '/api/auth-status.php');
    }

    public static function tearDownAfterClass(): void
    {
        if (self::$serverProcess !== null && is_resource(self::$serverProcess)) {
            proc_terminate(self::$serverProcess);
            proc_close(self::$serverProcess);
        }
        self::removeDir(self::$root);
    }

    // ------------------------------------------------------------------
    // Segédfüggvények
    // ------------------------------------------------------------------

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

    /**
     * @param array<string,string> $extraHeaders
     */
    private static function request(string $method, string $path, $body = null, array $extraHeaders = [], ?string $cookieJarPath = null): array
    {
        $ch = curl_init(self::$baseUrl . $path);
        $headers = [];
        foreach ($extraHeaders as $k => $v) {
            $headers[] = "$k: $v";
        }
        $rawBody = null;
        if (is_array($body)) {
            $rawBody = json_encode($body);
            $headers[] = 'Content-Type: application/json';
        } elseif (is_string($body)) {
            $rawBody = $body;
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
        if ($rawBody !== null) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, $rawBody);
        }

        $raw = curl_exec($ch);
        if ($raw === false) {
            self::fail('curl hiba: ' . curl_error($ch));
        }
        $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $headerSize = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
        curl_close($ch);

        $rawHeaders = substr($raw, 0, $headerSize);
        $bodyOut = substr($raw, $headerSize);
        $json = json_decode($bodyOut, true);

        $respHeaders = [];
        foreach (explode("\r\n", $rawHeaders) as $line) {
            if (str_contains($line, ':')) {
                [$k, $v] = explode(':', $line, 2);
                $respHeaders[strtolower(trim($k))] = trim($v);
            }
        }

        return [
            'status' => $status,
            'body' => $bodyOut,
            'json' => is_array($json) ? $json : null,
            'headers' => $respHeaders,
            'rawHeaders' => $rawHeaders,
        ];
    }

    /**
     * Aláírt X-Client-* fejlécek előállítása — UGYANAZZAL a ClientHmac
     * osztállyal, amit a valódi ClientProxy is használ. Alapesetben minden
     * mező önmagával konzisztens (az aláírás a ténylegesen elküldött
     * method/path+query/timestamp/nonce/body felett készül) — a "hamisítási"
     * teszteknél a HÍVÓ mutálja a visszakapott fejléc-tömb EGY mezőjét (vagy
     * a ténylegesen elküldött body/query-t), MIUTÁN ez a függvény már
     * lefutott, hogy pontosan egyetlen, önmagában izolált eltérés legyen a
     * "mit írtunk alá" és a "mit küldtünk ténylegesen" között.
     */
    private static function signHeaders(array $client, string $method, string $scriptName, string $query, string $body, ?string $timestamp = null, ?string $nonce = null): array
    {
        $timestamp ??= (string) time();
        $nonce ??= bin2hex(random_bytes(16));
        $pathAndQuery = '/api/' . $scriptName . ($query !== '' ? '?' . $query : '');
        $signingKey = ClientHmac::deriveSigningKey($client['client_secret']);
        $canonical = ClientHmac::canonicalString($method, $pathAndQuery, $timestamp, $nonce, $body);
        $signature = ClientHmac::sign($canonical, $signingKey);
        self::$allSignatures[] = $signature;

        return [
            'X-Client-Id' => $client['client_id'],
            'X-Client-Timestamp' => $timestamp,
            'X-Client-Nonce' => $nonce,
            'X-Client-Signature' => $signature,
        ];
    }

    private static function dbInstance(): Database
    {
        return new Database(['driver' => 'sqlite', 'sqlite' => ['path' => self::$root . '/data/stock.sqlite']], self::$root);
    }

    private static function rawPdo(Database $db): PDO
    {
        $pdoProp = new ReflectionProperty(Database::class, 'pdo');
        $pdoProp->setAccessible(true);
        return $pdoProp->getValue($db);
    }

    // ------------------------------------------------------------------
    // 01 — Alapállapot: app-jelszó, admin + pénztáros dolgozó, két böngésző-session
    // ------------------------------------------------------------------

    public function test01_SetupAdminAndCashierStaffAndBrowserSessions(): void
    {
        self::$adminJar = self::cookieJar('admin');
        $status = self::request('GET', '/api/auth-status.php', null, [], self::$adminJar);
        $csrf = $status['json']['csrf_token'];

        $enable = self::request('POST', '/api/security-settings-save.php', [
            'app_password_enabled' => true,
            'new_password' => 'teszt-jelszo-client-http',
            'new_password_confirm' => 'teszt-jelszo-client-http',
        ], ['X-CSRF-Token' => $csrf], self::$adminJar);
        $this->assertSame(200, $enable['status'], 'Az app-jelszó bekapcsolásának sikeresnek kell lennie: ' . $enable['body']);

        $login = self::request('POST', '/api/login.php', ['password' => 'teszt-jelszo-client-http'], [], self::$adminJar);
        $this->assertSame(200, $login['status'], 'Az app-jelszavas belépésnek sikeresnek kell lennie: ' . $login['body']);

        $csrf2 = self::request('GET', '/api/auth-status.php', null, [], self::$adminJar)['json']['csrf_token'];
        $admin = self::request('POST', '/api/staff-save.php', [
            'name' => 'HTTP Teszt Admin', 'pin' => '19283', 'role' => 'admin',
        ], ['X-CSRF-Token' => $csrf2], self::$adminJar);
        $this->assertSame(200, $admin['status'], 'Az első (admin) dolgozó felvételének sikeresnek kell lennie: ' . $admin['body']);

        $adminStaffLogin = self::request('POST', '/api/staff-login.php', ['pin' => '19283'], ['X-CSRF-Token' => $csrf2], self::$adminJar);
        $this->assertSame(200, $adminStaffLogin['status'], 'Az admin PIN-bejelentkezésének sikeresnek kell lennie: ' . $adminStaffLogin['body']);
        self::$adminStaffId = (int) $adminStaffLogin['json']['staff']['id'];

        $csrf3 = self::request('GET', '/api/auth-status.php', null, [], self::$adminJar)['json']['csrf_token'];
        $cashier = self::request('POST', '/api/staff-save.php', [
            'name' => 'HTTP Teszt Pénztáros', 'pin' => '57463', 'role' => 'cashier',
        ], ['X-CSRF-Token' => $csrf3], self::$adminJar);
        $this->assertSame(200, $cashier['status'], 'A második (pénztáros) dolgozó felvételének admin-jogosultsággal sikeresnek kell lennie: ' . $cashier['body']);

        // Külön böngésző-session, ami app-jelszóval belép, DE a
        // staff-login.php-t a pénztáros PIN-jével hívja — ez a "nem-admin
        // dolgozó" azonosságot adja a regisztráció admin-kapu teszteknek.
        self::$cashierJar = self::cookieJar('cashier');
        $cashierStatus = self::request('GET', '/api/auth-status.php', null, [], self::$cashierJar);
        self::request('POST', '/api/login.php', ['password' => 'teszt-jelszo-client-http'], [], self::$cashierJar);
        $cashierCsrf = self::request('GET', '/api/auth-status.php', null, [], self::$cashierJar)['json']['csrf_token'];
        $cashierStaffLogin = self::request('POST', '/api/staff-login.php', ['pin' => '57463'], ['X-CSRF-Token' => $cashierCsrf], self::$cashierJar);
        $this->assertSame(200, $cashierStaffLogin['status'], 'A pénztáros PIN-bejelentkezésének sikeresnek kell lennie: ' . $cashierStaffLogin['body']);
    }

    // ------------------------------------------------------------------
    // 02-06 — Kliens-regisztráció: admin-kapu, CSRF-kapu, létrehozás, listázás
    // ------------------------------------------------------------------

    public function test02_ClientRegistrationRequiresLogin(): void
    {
        $res = self::request('POST', '/api/client-register.php', ['label' => 'Nem bejelentkezett próba']);
        $this->assertSame(401, $res['status']);
        $this->assertTrue($res['json']['auth_required'] ?? false);
    }

    public function test03_ClientRegistrationDeniedForNonAdminStaff(): void
    {
        $csrf = self::request('GET', '/api/auth-status.php', null, [], self::$cashierJar)['json']['csrf_token'];
        $res = self::request('POST', '/api/client-register.php', ['label' => 'Pénztáros próba'], ['X-CSRF-Token' => $csrf], self::$cashierJar);
        $this->assertSame(403, $res['status']);
        $this->assertStringContainsString('vezetői jogszint', $res['json']['error'] ?? '');
    }

    public function test04_ClientRegistrationDeniedWithoutCsrf(): void
    {
        $res = self::request('POST', '/api/client-register.php', ['label' => 'Hiányzó CSRF próba'], [], self::$adminJar);
        $this->assertSame(403, $res['status']);
        $this->assertTrue($res['json']['csrf_required'] ?? false);
    }

    public function test05_RegisterClientsAandBandC(): void
    {
        foreach (['A', 'B', 'C'] as $label) {
            $csrf = self::request('GET', '/api/auth-status.php', null, [], self::$adminJar)['json']['csrf_token'];
            $res = self::request('POST', '/api/client-register.php', ['label' => "HTTP teszt Kliens $label"], ['X-CSRF-Token' => $csrf], self::$adminJar);
            $this->assertSame(200, $res['status'], "A(z) $label kliens regisztrációjának sikeresnek kell lennie: " . $res['body']);
            $this->assertNotEmpty($res['json']['client_id']);
            $this->assertNotEmpty($res['json']['client_secret']);
            $this->assertTrue($res['json']['secret_shown_once'] ?? false);
            $this->assertMatchesRegularExpression('/^cl_[0-9a-f]{24}$/', $res['json']['client_id']);

            $client = ['id' => (int) $res['json']['id'], 'client_id' => $res['json']['client_id'], 'client_secret' => $res['json']['client_secret']];
            self::$allRawSecrets[] = $client['client_secret'];
            if ($label === 'A') {
                self::$clientA = $client;
            } elseif ($label === 'B') {
                self::$clientB = $client;
            } else {
                self::$clientC = $client;
            }
        }
        $this->assertNotSame(self::$clientA['client_id'], self::$clientB['client_id']);
        $this->assertNotSame(self::$clientA['client_secret'], self::$clientB['client_secret']);
    }

    public function test06_ClientsListNeverExposesSecretAndRequiresAdmin(): void
    {
        $cashierCsrf = self::request('GET', '/api/auth-status.php', null, [], self::$cashierJar)['json']['csrf_token'];
        $denied = self::request('GET', '/api/clients-list.php', null, ['X-CSRF-Token' => $cashierCsrf], self::$cashierJar);
        $this->assertSame(403, $denied['status'], 'A kliens-lista is admin-kapus végpont.');

        $res = self::request('GET', '/api/clients-list.php', null, [], self::$adminJar);
        $this->assertSame(200, $res['status']);
        $clients = $res['json']['clients'];
        $this->assertCount(3, $clients);
        foreach ($clients as $c) {
            $this->assertArrayNotHasKey('secret_hash', $c);
            $this->assertArrayNotHasKey('client_secret', $c);
        }
        foreach (self::$allRawSecrets as $secret) {
            $this->assertStringNotContainsString($secret, $res['body'], 'Egy nyers client_secret SOSE jelenhet meg a listázó végpont válaszában.');
        }
    }

    // ------------------------------------------------------------------
    // 07-14 — Gépszintű HMAC-hitelesítés (auth-status.php-n keresztül,
    // ami nem igényel dolgozói munkamenetet — tisztán a HMAC-réteget
    // bizonyítja)
    // ------------------------------------------------------------------

    public function test07_HmacRejectsUnknownClientId(): void
    {
        $fake = ['client_id' => 'cl_' . bin2hex(random_bytes(12)), 'client_secret' => bin2hex(random_bytes(32))];
        $headers = self::signHeaders($fake, 'GET', 'auth-status.php', '', '');
        $res = self::request('GET', '/api/auth-status.php', null, $headers);
        $this->assertSame(401, $res['status']);
        $this->assertSame('Hitelesítés sikertelen.', $res['json']['error'] ?? null);
    }

    public function test08_HmacRejectsInvalidSignature(): void
    {
        $headers = self::signHeaders(self::$clientA, 'GET', 'auth-status.php', '', '');
        $headers['X-Client-Signature'] = strrev($headers['X-Client-Signature']);
        $res = self::request('GET', '/api/auth-status.php', null, $headers);
        $this->assertSame(401, $res['status']);
        $this->assertSame('Hitelesítés sikertelen.', $res['json']['error'] ?? null);
    }

    public function test09_HmacRejectsTamperedBody(): void
    {
        $signedBody = json_encode(['probe' => 'eredeti']);
        $headers = self::signHeaders(self::$clientA, 'POST', 'auth-status.php', '', $signedBody);
        // Ugyanazokkal a fejlécekkel, de MÁS törzzsel küldjük ténylegesen.
        $res = self::request('POST', '/api/auth-status.php', json_encode(['probe' => 'módosított']), $headers);
        $this->assertSame(401, $res['status']);
    }

    public function test10_HmacRejectsTamperedQueryString(): void
    {
        $headers = self::signHeaders(self::$clientA, 'GET', 'auth-status.php', 'x=1', '');
        $res = self::request('GET', '/api/auth-status.php?x=2', null, $headers);
        $this->assertSame(401, $res['status']);
    }

    public function test11_HmacRejectsTamperedTimestampHeader(): void
    {
        $headers = self::signHeaders(self::$clientA, 'GET', 'auth-status.php', '', '');
        $headers['X-Client-Timestamp'] = (string) (((int) $headers['X-Client-Timestamp']) + 5);
        $res = self::request('GET', '/api/auth-status.php', null, $headers);
        $this->assertSame(401, $res['status']);
    }

    public function test12_HmacRejectsExpiredTimestamp(): void
    {
        $oldTimestamp = (string) (time() - 300);
        $headers = self::signHeaders(self::$clientA, 'GET', 'auth-status.php', '', '', $oldTimestamp);
        $res = self::request('GET', '/api/auth-status.php', null, $headers);
        $this->assertSame(401, $res['status']);
    }

    public function test13_HmacRejectsFutureTimestampOutsideWindow(): void
    {
        $futureTimestamp = (string) (time() + 300);
        $headers = self::signHeaders(self::$clientA, 'GET', 'auth-status.php', '', '', $futureTimestamp);
        $res = self::request('GET', '/api/auth-status.php', null, $headers);
        $this->assertSame(401, $res['status']);
    }

    public function test14_HmacNonceReplayRejectedThenNewNonceAccepted(): void
    {
        $timestamp = (string) time();
        $nonce = bin2hex(random_bytes(16));

        // "request A + nonce X" — elfogadva.
        $headers1 = self::signHeaders(self::$clientA, 'GET', 'auth-status.php', '', '', $timestamp, $nonce);
        $res1 = self::request('GET', '/api/auth-status.php', null, $headers1);
        $this->assertSame(200, $res1['status'], 'Az első, friss nonce-szal küldött kérésnek sikeresnek kell lennie.');

        // "request A + nonce X" (ismételve, byte-pontosan ugyanazokkal a
        // fejlécekkel) — elutasítva.
        $res2 = self::request('GET', '/api/auth-status.php', null, $headers1);
        $this->assertSame(401, $res2['status'], 'Ugyanaz a nonce másodszor nem fogadható el.');

        // "request B + nonce Y" — friss nonce-szal ismét elfogadva.
        $headers3 = self::signHeaders(self::$clientA, 'GET', 'auth-status.php', '', '');
        $res3 = self::request('GET', '/api/auth-status.php', null, $headers3);
        $this->assertSame(200, $res3['status'], 'Egy ÚJ nonce-szal küldött kérésnek a replay-elutasítás UTÁN is sikeresnek kell lennie.');
    }

    // ------------------------------------------------------------------
    // 15-19 — Dolgozói munkamenet (staff-login híd) + CSRF-mentes GET/POST
    // ------------------------------------------------------------------

    public function test15_StaffLoginWrongPinRejected(): void
    {
        $headers = self::signHeaders(self::$clientA, 'POST', 'staff-login.php', '', json_encode(['pin' => '00000']));
        $res = self::request('POST', '/api/staff-login.php', json_encode(['pin' => '00000']), $headers);
        $this->assertSame(401, $res['status']);
        $this->assertFalse($res['json']['ok'] ?? true);
    }

    public function test16_StaffLoginEstablishesClientSessionForClientA(): void
    {
        $body = json_encode(['pin' => '19283']);
        $headers = self::signHeaders(self::$clientA, 'POST', 'staff-login.php', '', $body);
        $res = self::request('POST', '/api/staff-login.php', $body, $headers);
        $this->assertSame(200, $res['status'], $res['body']);
        $this->assertTrue($res['json']['ok'] ?? false);
        $this->assertSame('admin', $res['json']['staff']['role']);
        $this->assertNotEmpty($res['headers']['x-client-session-id'] ?? '');
        $this->assertNotEmpty($res['headers']['x-client-csrf-token'] ?? '');

        self::$sessionA = $res['headers']['x-client-session-id'];
        self::$csrfA = $res['headers']['x-client-csrf-token'];
        self::$allRawCsrfTokens[] = self::$csrfA;
    }

    public function test17_StaffLoginEstablishesClientSessionForClientBWithSameAdminPin(): void
    {
        // Ugyanaz a PIN (tehát ugyanaz a dolgozó), egy MÁSIK kliens-gépről —
        // a jelenlegi auth-modell ezt megengedi (lásd Auth::currentStaffId()
        // / client_sessions.staff_id — nincs "egy dolgozó = egy aktív
        // munkamenet" kikényszerítés).
        $body = json_encode(['pin' => '19283']);
        $headers = self::signHeaders(self::$clientB, 'POST', 'staff-login.php', '', $body);
        $res = self::request('POST', '/api/staff-login.php', $body, $headers);
        $this->assertSame(200, $res['status'], $res['body']);

        self::$sessionB = $res['headers']['x-client-session-id'];
        self::$csrfB = $res['headers']['x-client-csrf-token'];
        self::$allRawCsrfTokens[] = self::$csrfB;

        $this->assertNotSame(self::$sessionA, self::$sessionB, 'Két külön kliens-gépnek két külön munkamenet-azonosítót kell kapnia.');
        $this->assertNotSame(self::$csrfA, self::$csrfB);
    }

    public function test18_SimultaneousSessionsOnBothClientsWork(): void
    {
        $headersA = self::signHeaders(self::$clientA, 'GET', 'locations-list.php', '', '');
        $headersA['X-Client-Session-Id'] = self::$sessionA;
        $resA = self::request('GET', '/api/locations-list.php', null, $headersA);
        $this->assertSame(200, $resA['status'], 'A Kliens A munkamenetének egyidejűleg is működnie kell: ' . $resA['body']);

        $headersB = self::signHeaders(self::$clientB, 'GET', 'locations-list.php', '', '');
        $headersB['X-Client-Session-Id'] = self::$sessionB;
        $resB = self::request('GET', '/api/locations-list.php', null, $headersB);
        $this->assertSame(200, $resB['status'], 'A Kliens B munkamenetének egyidejűleg is működnie kell: ' . $resB['body']);
    }

    public function test19_AuthenticatedPostWithValidSessionAndCsrfSucceeds(): void
    {
        $body = json_encode(['name' => 'HMAC HTTP teszt telephely']);
        $headers = self::signHeaders(self::$clientA, 'POST', 'location-save.php', '', $body);
        $headers['X-Client-Session-Id'] = self::$sessionA;
        $headers['X-Client-Csrf-Token'] = self::$csrfA;
        $res = self::request('POST', '/api/location-save.php', $body, $headers);
        $this->assertSame(200, $res['status'], 'Proxyzott admin-munkamenettel a require_admin()-nek is át kell engednie: ' . $res['body']);
        $this->assertNotEmpty($res['json']['id'] ?? null);
    }

    public function test20_CrossClientSessionIsRejected(): void
    {
        // Kliens B saját, ÉRVÉNYES HMAC-azonosságával, de Kliens A
        // munkamenet-azonosítójával — a Szervernek ezt EL KELL utasítania,
        // mert a client_sessions sor registered_client_id-je Klienshez A
        // tartozik, nem B-hez.
        $headers = self::signHeaders(self::$clientB, 'GET', 'locations-list.php', '', '');
        $headers['X-Client-Session-Id'] = self::$sessionA;
        $res = self::request('GET', '/api/locations-list.php', null, $headers);
        $this->assertSame(401, $res['status']);
        $this->assertTrue($res['json']['auth_required'] ?? false);
    }

    public function test21_UnknownClientSessionIdIsRejected(): void
    {
        $headers = self::signHeaders(self::$clientA, 'GET', 'locations-list.php', '', '');
        $headers['X-Client-Session-Id'] = bin2hex(random_bytes(32));
        $res = self::request('GET', '/api/locations-list.php', null, $headers);
        $this->assertSame(401, $res['status']);
        $this->assertTrue($res['json']['auth_required'] ?? false);
    }

    // ------------------------------------------------------------------
    // 22-24 — Letiltás (ideiglenes) / engedélyezés — izoláció Kliens A-tól
    // ------------------------------------------------------------------

    public function test22_DisableClientBRejectsFurtherRequestsAndClearsItsSessionsButClientAUnaffected(): void
    {
        $csrf = self::request('GET', '/api/auth-status.php', null, [], self::$adminJar)['json']['csrf_token'];
        $disable = self::request('POST', '/api/client-disable.php', ['id' => self::$clientB['id']], ['X-CSRF-Token' => $csrf], self::$adminJar);
        $this->assertSame(200, $disable['status'], $disable['body']);
        $this->assertFalse($disable['json']['is_active']);

        $headers = self::signHeaders(self::$clientB, 'GET', 'auth-status.php', '', '');
        $res = self::request('GET', '/api/auth-status.php', null, $headers);
        $this->assertSame(401, $res['status'], 'Egy letiltott kliens MINDEN HMAC-kérését el kell utasítani.');

        $db = self::dbInstance();
        $pdo = self::rawPdo($db);
        $remaining = $pdo->prepare('SELECT COUNT(*) FROM client_sessions WHERE registered_client_id = ?');
        $remaining->execute([self::$clientB['id']]);
        $this->assertSame(0, (int) $remaining->fetchColumn(), 'A letiltás azonnal törli az érintett kliens munkameneteit.');

        // Kliens A teljesen érintetlen marad.
        $headersA = self::signHeaders(self::$clientA, 'GET', 'locations-list.php', '', '');
        $headersA['X-Client-Session-Id'] = self::$sessionA;
        $resA = self::request('GET', '/api/locations-list.php', null, $headersA);
        $this->assertSame(200, $resA['status'], 'Kliens B letiltása nem érintheti Kliens A-t.');
    }

    public function test23_EnableClientBAllowsFreshLoginButOldSessionGone(): void
    {
        $csrf = self::request('GET', '/api/auth-status.php', null, [], self::$adminJar)['json']['csrf_token'];
        $enable = self::request('POST', '/api/client-enable.php', ['id' => self::$clientB['id']], ['X-CSRF-Token' => $csrf], self::$adminJar);
        $this->assertSame(200, $enable['status'], $enable['body']);
        $this->assertTrue($enable['json']['is_active']);

        $headers = self::signHeaders(self::$clientB, 'GET', 'auth-status.php', '', '');
        $res = self::request('GET', '/api/auth-status.php', null, $headers);
        $this->assertSame(200, $res['status'], 'Engedélyezés után a kliens HMAC-hitelesítésének ismét sikeresnek kell lennie.');

        $staleHeaders = self::signHeaders(self::$clientB, 'GET', 'locations-list.php', '', '');
        $staleHeaders['X-Client-Session-Id'] = self::$sessionB;
        $staleRes = self::request('GET', '/api/locations-list.php', null, $staleHeaders);
        $this->assertSame(401, $staleRes['status'], 'A letiltáskor törölt régi munkamenet-azonosító engedélyezés után sem éled újra.');

        $body = json_encode(['pin' => '57463']);
        $loginHeaders = self::signHeaders(self::$clientB, 'POST', 'staff-login.php', '', $body);
        $loginRes = self::request('POST', '/api/staff-login.php', $body, $loginHeaders);
        $this->assertSame(200, $loginRes['status'], $loginRes['body']);
        self::$sessionB = $loginRes['headers']['x-client-session-id'];
        self::$csrfB = $loginRes['headers']['x-client-csrf-token'];
        self::$allRawCsrfTokens[] = self::$csrfB;

        $freshHeaders = self::signHeaders(self::$clientB, 'GET', 'locations-list.php', '', '');
        $freshHeaders['X-Client-Session-Id'] = self::$sessionB;
        $freshRes = self::request('GET', '/api/locations-list.php', null, $freshHeaders);
        $this->assertSame(200, $freshRes['status'], 'Egy friss staff-login utáni munkamenetnek működnie kell.');
    }

    // ------------------------------------------------------------------
    // 24 — Visszavonás (végleges) — izoláció, és a disable/enable/rotate
    // ezután véglegesen elutasítva
    // ------------------------------------------------------------------

    public function test24_RevokeClientAIsPermanentAndClearsSessionsButClientBUnaffected(): void
    {
        $csrf = self::request('GET', '/api/auth-status.php', null, [], self::$adminJar)['json']['csrf_token'];
        $revoke = self::request('POST', '/api/client-revoke.php', ['id' => self::$clientA['id']], ['X-CSRF-Token' => $csrf], self::$adminJar);
        $this->assertSame(200, $revoke['status'], $revoke['body']);
        $this->assertTrue($revoke['json']['revoked'] ?? false);

        $headers = self::signHeaders(self::$clientA, 'GET', 'auth-status.php', '', '');
        $res = self::request('GET', '/api/auth-status.php', null, $headers);
        $this->assertSame(401, $res['status'], 'Egy visszavont kliens MINDEN HMAC-kérését véglegesen el kell utasítani.');

        $db = self::dbInstance();
        $pdo = self::rawPdo($db);
        $remaining = $pdo->prepare('SELECT COUNT(*) FROM client_sessions WHERE registered_client_id = ?');
        $remaining->execute([self::$clientA['id']]);
        $this->assertSame(0, (int) $remaining->fetchColumn());

        // Kliens B (még aktív munkamenettel) teljesen érintetlen marad.
        $headersB = self::signHeaders(self::$clientB, 'GET', 'locations-list.php', '', '');
        $headersB['X-Client-Session-Id'] = self::$sessionB;
        $resB = self::request('GET', '/api/locations-list.php', null, $headersB);
        $this->assertSame(200, $resB['status'], 'Kliens A visszavonása nem érintheti Kliens B-t.');

        // "Visszavonva marad" — disable/enable/rotate mind elutasítva.
        $csrf2 = self::request('GET', '/api/auth-status.php', null, [], self::$adminJar)['json']['csrf_token'];
        $disableAttempt = self::request('POST', '/api/client-disable.php', ['id' => self::$clientA['id']], ['X-CSRF-Token' => $csrf2], self::$adminJar);
        $this->assertSame(409, $disableAttempt['status']);

        $csrf3 = self::request('GET', '/api/auth-status.php', null, [], self::$adminJar)['json']['csrf_token'];
        $enableAttempt = self::request('POST', '/api/client-enable.php', ['id' => self::$clientA['id']], ['X-CSRF-Token' => $csrf3], self::$adminJar);
        $this->assertSame(409, $enableAttempt['status']);

        $csrf4 = self::request('GET', '/api/auth-status.php', null, [], self::$adminJar)['json']['csrf_token'];
        $rotateAttempt = self::request('POST', '/api/client-rotate.php', ['id' => self::$clientA['id']], ['X-CSRF-Token' => $csrf4], self::$adminJar);
        $this->assertSame(409, $rotateAttempt['status']);
    }

    // ------------------------------------------------------------------
    // 25 — Kijelentkezés — CSAK az adott kliens-munkamenetet érvényteleníti
    // ------------------------------------------------------------------

    public function test25_LogoutInvalidatesOnlyThatClientsSession(): void
    {
        // Kliens C-n is bejelentkezik (admin PIN) — ez lesz a "másik, még
        // élő munkamenet", amivel bizonyítjuk, hogy Kliens B kijelentkezése
        // NEM globális "mindenkit kijelentkeztet" mechanizmus.
        $body = json_encode(['pin' => '19283']);
        $loginHeaders = self::signHeaders(self::$clientC, 'POST', 'staff-login.php', '', $body);
        $loginRes = self::request('POST', '/api/staff-login.php', $body, $loginHeaders);
        $this->assertSame(200, $loginRes['status'], $loginRes['body']);
        self::$sessionC = $loginRes['headers']['x-client-session-id'];
        self::$csrfC = $loginRes['headers']['x-client-csrf-token'];
        self::$allRawCsrfTokens[] = self::$csrfC;

        $logoutHeaders = self::signHeaders(self::$clientB, 'POST', 'staff-logout.php', '', '');
        $logoutHeaders['X-Client-Session-Id'] = self::$sessionB;
        $logoutHeaders['X-Client-Csrf-Token'] = self::$csrfB;
        $logoutRes = self::request('POST', '/api/staff-logout.php', '', $logoutHeaders);
        $this->assertSame(200, $logoutRes['status'], $logoutRes['body']);
        $this->assertSame('1', $logoutRes['headers']['x-client-session-cleared'] ?? null);

        $afterLogoutHeaders = self::signHeaders(self::$clientB, 'GET', 'locations-list.php', '', '');
        $afterLogoutHeaders['X-Client-Session-Id'] = self::$sessionB;
        $afterLogoutRes = self::request('GET', '/api/locations-list.php', null, $afterLogoutHeaders);
        $this->assertSame(401, $afterLogoutRes['status'], 'Kijelentkezés után a régi munkamenet-azonosító nem használható tovább.');

        // Kliens C érintetlen.
        $stillWorksHeaders = self::signHeaders(self::$clientC, 'GET', 'locations-list.php', '', '');
        $stillWorksHeaders['X-Client-Session-Id'] = self::$sessionC;
        $stillWorksRes = self::request('GET', '/api/locations-list.php', null, $stillWorksHeaders);
        $this->assertSame(200, $stillWorksRes['status'], 'Kliens B kijelentkezése nem érintheti Kliens C munkamenetét.');
    }

    // ------------------------------------------------------------------
    // 26 — Sikeres titok-csere (rotate) egy AKTÍV klienshez
    // ------------------------------------------------------------------

    public function test26_RotateActiveClientCReplacesSecretButKeepsExistingSession(): void
    {
        $csrf = self::request('GET', '/api/auth-status.php', null, [], self::$adminJar)['json']['csrf_token'];
        $rotate = self::request('POST', '/api/client-rotate.php', ['id' => self::$clientC['id']], ['X-CSRF-Token' => $csrf], self::$adminJar);
        $this->assertSame(200, $rotate['status'], $rotate['body']);
        $newSecret = $rotate['json']['client_secret'];
        $this->assertNotSame(self::$clientC['client_secret'], $newSecret);
        self::$allRawSecrets[] = $newSecret;

        // A RÉGI titokkal aláírt kérés innentől érvénytelen.
        $oldSecretHeaders = self::signHeaders(self::$clientC, 'GET', 'auth-status.php', '', '');
        $oldRes = self::request('GET', '/api/auth-status.php', null, $oldSecretHeaders);
        $this->assertSame(401, $oldRes['status'], 'A lecserélt (régi) titokkal aláírt kérésnek el kell buknia.');

        $rotatedClient = ['id' => self::$clientC['id'], 'client_id' => self::$clientC['client_id'], 'client_secret' => $newSecret];
        self::$clientC = $rotatedClient;

        // Az ÚJ titokkal aláírt kérés működik.
        $newSecretHeaders = self::signHeaders(self::$clientC, 'GET', 'auth-status.php', '', '');
        $newRes = self::request('GET', '/api/auth-status.php', null, $newSecretHeaders);
        $this->assertSame(200, $newRes['status']);

        // A MÁR meglévő dolgozói munkamenet (sessionC/csrfC) a titok-csere
        // ELLENÉRE is tovább él — a rotate NEM törli a client_sessions
        // sorokat (csak a jövőbeli aláírás-ellenőrzéshez szükséges kulcsot
        // cseréli).
        $body = json_encode(['name' => 'Rotate utáni HTTP teszt telephely']);
        $postHeaders = self::signHeaders(self::$clientC, 'POST', 'location-save.php', '', $body);
        $postHeaders['X-Client-Session-Id'] = self::$sessionC;
        $postHeaders['X-Client-Csrf-Token'] = self::$csrfC;
        $postRes = self::request('POST', '/api/location-save.php', $body, $postHeaders);
        $this->assertSame(200, $postRes['status'], 'A titok-csere nem érvényteleníti a már meglévő dolgozói munkamenetet: ' . $postRes['body']);
    }

    // ------------------------------------------------------------------
    // 27-31 — CSRF: hiányzó / érvénytelen / rossz munkamenethez tartozó /
    // lejárt munkamenetű / érvényes token
    // ------------------------------------------------------------------

    public function test27_CsrfMissingRejected(): void
    {
        $body = json_encode(['name' => 'CSRF hiányzó próba']);
        $headers = self::signHeaders(self::$clientC, 'POST', 'location-save.php', '', $body);
        $headers['X-Client-Session-Id'] = self::$sessionC;
        $res = self::request('POST', '/api/location-save.php', $body, $headers);
        $this->assertSame(403, $res['status']);
        $this->assertTrue($res['json']['csrf_required'] ?? false);
    }

    public function test28_CsrfInvalidRejected(): void
    {
        $body = json_encode(['name' => 'CSRF érvénytelen próba']);
        $headers = self::signHeaders(self::$clientC, 'POST', 'location-save.php', '', $body);
        $headers['X-Client-Session-Id'] = self::$sessionC;
        $headers['X-Client-Csrf-Token'] = 'nem-ez-a-token';
        $res = self::request('POST', '/api/location-save.php', $body, $headers);
        $this->assertSame(403, $res['status']);
        $this->assertTrue($res['json']['csrf_required'] ?? false);
    }

    public function test29_CsrfWrongSessionRejected(): void
    {
        // Kliens B-n friss munkamenetet nyitunk (admin PIN), csak azért,
        // hogy legyen egy MÁSIK, VALÓS, érvényes csrf-token, ami viszont
        // NEM a sessionC-hez tartozik.
        $body = json_encode(['pin' => '19283']);
        $loginHeaders = self::signHeaders(self::$clientB, 'POST', 'staff-login.php', '', $body);
        $loginRes = self::request('POST', '/api/staff-login.php', $body, $loginHeaders);
        $this->assertSame(200, $loginRes['status'], $loginRes['body']);
        $otherCsrf = $loginRes['headers']['x-client-csrf-token'];
        self::$allRawCsrfTokens[] = $otherCsrf;
        $this->assertNotSame(self::$csrfC, $otherCsrf);

        $postBody = json_encode(['name' => 'CSRF rossz munkamenet próba']);
        $headers = self::signHeaders(self::$clientC, 'POST', 'location-save.php', '', $postBody);
        $headers['X-Client-Session-Id'] = self::$sessionC;
        $headers['X-Client-Csrf-Token'] = $otherCsrf;
        $res = self::request('POST', '/api/location-save.php', $postBody, $headers);
        $this->assertSame(403, $res['status'], 'Egy MÁSIK munkamenethez tartozó, önmagában érvényes csrf-token sem fogadható el.');
    }

    public function test30_CsrfValidAccepted(): void
    {
        $body = json_encode(['name' => 'CSRF érvényes próba']);
        $headers = self::signHeaders(self::$clientC, 'POST', 'location-save.php', '', $body);
        $headers['X-Client-Session-Id'] = self::$sessionC;
        $headers['X-Client-Csrf-Token'] = self::$csrfC;
        $res = self::request('POST', '/api/location-save.php', $body, $headers);
        $this->assertSame(200, $res['status'], $res['body']);
    }

    public function test31_CsrfExpiredSessionRejected(): void
    {
        $db = self::dbInstance();
        $pdo = self::rawPdo($db);
        $pdo->prepare('UPDATE client_sessions SET expires_at = ? WHERE client_session_id = ?')
            ->execute([date('Y-m-d H:i:s', time() - 3600), self::$sessionC]);

        $body = json_encode(['name' => 'CSRF lejárt munkamenet próba']);
        $headers = self::signHeaders(self::$clientC, 'POST', 'location-save.php', '', $body);
        $headers['X-Client-Session-Id'] = self::$sessionC;
        $headers['X-Client-Csrf-Token'] = self::$csrfC;
        $res = self::request('POST', '/api/location-save.php', $body, $headers);
        // A munkamenet-frissesség ellenőrzése a CSRF-ellenőrzés ELŐTT fut le
        // (lásd _bootstrap.php) — egy lejárt munkamenet emiatt "nincs
        // feloldott dolgozói azonosság"-ként jelenik meg (401 auth_required),
        // sose jut el a CSRF-ellenőrzésig.
        $this->assertSame(401, $res['status']);
        $this->assertTrue($res['json']['auth_required'] ?? false);
    }

    // ------------------------------------------------------------------
    // 32 — A kliens által küldött adat SOSE határozhatja meg a dolgozói
    // azonosságot — a staff_id KIZÁRÓLAG a Szerveren feloldott
    // client_sessions sorból jöhet, sose egy kliens-oldali fejlécből.
    // ------------------------------------------------------------------

    public function test32_ClientSuppliedStaffIdHeaderIsIgnored(): void
    {
        // Friss, pénztáros (NEM admin) munkamenet Kliens B-n.
        $body = json_encode(['pin' => '57463']);
        $loginHeaders = self::signHeaders(self::$clientB, 'POST', 'staff-login.php', '', $body);
        $loginRes = self::request('POST', '/api/staff-login.php', $body, $loginHeaders);
        $this->assertSame(200, $loginRes['status'], $loginRes['body']);
        $cashierSessionB = $loginRes['headers']['x-client-session-id'];
        $cashierCsrfB = $loginRes['headers']['x-client-csrf-token'];
        self::$allRawCsrfTokens[] = $cashierCsrfB;

        // A protokoll nem is definiál "kliens-oldali staff_id" fejlécet —
        // ez a teszt egy ilyet MÉGIS megpróbál becsempészni, hogy
        // bizonyítsa: nincs hatása. Az admin-kapunak a VALÓS (pénztáros)
        // azonosságot kell látnia, nem a hamisított fejlécet.
        $postBody = json_encode(['name' => 'Hamisított staff_id próba']);
        $headers = self::signHeaders(self::$clientB, 'POST', 'location-save.php', '', $postBody);
        $headers['X-Client-Session-Id'] = $cashierSessionB;
        $headers['X-Client-Csrf-Token'] = $cashierCsrfB;
        $headers['X-Staff-Id'] = (string) self::$adminStaffId;
        $res = self::request('POST', '/api/location-save.php', $postBody, $headers);
        $this->assertSame(403, $res['status'], 'Egy kliens-oldali, hamisított "staff_id" fejlécnek NULLA hatása lehet — a valódi (pénztáros) azonosságot kell alkalmazni, ami a require_admin()-en elbukik.');
        $this->assertStringContainsString('vezetői jogszint', $res['json']['error'] ?? '');
    }

    // ------------------------------------------------------------------
    // 33 — Biztonsági regresszió: titok/aláírás/csrf-plaintext SOSE
    // naplózva/DB-be írva; a közvetlen (nem proxyzott) forgalom változatlan
    // ------------------------------------------------------------------

    public function test33_SecurityRegressionAssertions(): void
    {
        $log = @file_get_contents(self::$serverLog) ?: '';

        foreach (self::$allRawSecrets as $secret) {
            $this->assertStringNotContainsString($secret, $log, 'Egy nyers client_secret SOSE kerülhet a szerver naplójába.');
        }
        foreach (self::$allSignatures as $signature) {
            $this->assertStringNotContainsString($signature, $log, 'Egy HMAC-aláírás SOSE kerülhet a szerver naplójába.');
        }
        foreach (self::$allRawCsrfTokens as $token) {
            $this->assertStringNotContainsString($token, $log, 'Egy nyers CSRF-token SOSE kerülhet a szerver naplójába.');
        }

        $db = self::dbInstance();
        $pdo = self::rawPdo($db);

        $sqliteBytes = @file_get_contents(self::$root . '/data/stock.sqlite') ?: '';
        foreach (self::$allRawSecrets as $secret) {
            $this->assertStringNotContainsString($secret, $sqliteBytes, 'Egy nyers client_secret SOSE kerülhet az adatbázis-fájlba — csak a levezetett secret_hash.');
        }
        foreach (self::$allRawCsrfTokens as $token) {
            $this->assertStringNotContainsString($token, $sqliteBytes, 'Egy nyers CSRF-token SOSE kerülhet az adatbázis-fájlba — csak a levezetett csrf_token_hash.');
        }

        // registered_clients — kizárólag a levezetett secret_hash tárolódik.
        $rows = $pdo->query('SELECT * FROM registered_clients')->fetchAll(PDO::FETCH_ASSOC);
        $this->assertNotEmpty($rows);
        foreach ($rows as $row) {
            foreach (self::$allRawSecrets as $secret) {
                $this->assertNotSame($secret, $row['secret_hash'], 'A tárolt secret_hash sose egyezhet meg a nyers titokkal.');
            }
            $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $row['secret_hash']);
        }

        // client_sessions — kizárólag a levezetett csrf_token_hash tárolódik.
        $sessionRows = $pdo->query('SELECT * FROM client_sessions')->fetchAll(PDO::FETCH_ASSOC);
        foreach ($sessionRows as $row) {
            foreach (self::$allRawCsrfTokens as $token) {
                $this->assertNotSame($token, $row['csrf_token_hash']);
            }
        }

        // audit_log — a client_register/rotate/revoke/disable/enable
        // bejegyzések a VALÓDI (bejelentkezett) admin staff_id-jét
        // hordozzák, és sose tartalmaznak nyers titkot a leírásban.
        $auditRows = $pdo->prepare("SELECT * FROM audit_log WHERE action LIKE 'client_%'");
        $auditRows->execute();
        $auditRows = $auditRows->fetchAll(PDO::FETCH_ASSOC);
        $this->assertNotEmpty($auditRows);
        foreach ($auditRows as $row) {
            $this->assertSame(self::$adminStaffId, (int) $row['staff_id'], 'Minden kliens-admin audit-bejegyzésnek a valódi, bejelentkezett admin dolgozóhoz kell tartoznia.');
            foreach (self::$allRawSecrets as $secret) {
                $this->assertStringNotContainsString($secret, (string) $row['details'], 'Az audit-napló leírása SOSE tartalmazhat nyers titkot.');
            }
        }

        // A közvetlen (nem proxyzott) böngésző-forgalom teljesen
        // változatlan maradt — sima app-jelszavas session, semmilyen
        // X-Client-* fejléc nélkül.
        $direct = self::request('GET', '/api/auth-status.php', null, [], self::$adminJar);
        $this->assertSame(200, $direct['status']);
        $this->assertTrue($direct['json']['logged_in'] ?? false);
    }
}
