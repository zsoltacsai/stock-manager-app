<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * HTTP-szintű biztonsági integrációs tesztek.
 *
 * A tests/DatabaseTest.php a `Database` osztályt hívja KÖZVETLENÜL — sose
 * megy át a _bootstrap.php-n, tehát semmit nem mond arról, hogy a
 * bejelentkezés-kényszer, a CSRF-ellenőrzés, a cron-token-ellenőrzés vagy a
 * HTTP-státuszkódok TÉNYLEGESEN helyesen viselkednek-e valódi kérés/válasz
 * úton. Ez a fájl azt a hiányt fedi le: egy teljesen KÜLÖN, ideiglenes
 * másolatban felállított, saját (üres SQLite + saját settings.json) példány
 * PHP beépített szerverén keresztül, valódi HTTP-kéréseket küldve.
 *
 * FONTOS: ez a teszt-osztály SOSE nyúl az éles `data/` mappához — minden
 * kérés egy `sys_get_temp_dir()` alá másolt, teljesen önálló másolat ellen
 * megy, amit a legvégén (tearDownAfterClass) töröl.
 *
 * Amit ez a fájl SZÁNDÉKOSAN NEM fedez le (dokumentált korlát, nem hiba):
 *   - Teljes eladás-idempotencia HTTP-n át (sale.php) — ehhez termék-,
 *     dolgozó- és fizetési-adatok teljes felállítása szükséges, ami ennek a
 *     tesztfájlnak az egyszerű, gyors HTTP-smoke-teszt céljához képest
 *     aránytalanul nagy súlyt jelentene. A tényleges UNIQUE-constraint-alapú
 *     atomicitást és a `tryClaimInvoiceIssuance()` állapotgépet a
 *     tests/DatabaseTest.php már közvetlenül, DB-szinten leellenőrzi
 *     (`testInsertSaleEnforcesUniqueIdempotencyKey`,
 *     `testTryClaimInvoiceIssuanceIsExclusiveUntilReleased` stb.).
 *   - SSRF: DNS-rebinding és "biztonságosnak validált URL átirányít belső
 *     célra" forgatókönyv — ehhez egy valódi, külső, átirányítást küldő
 *     szerver kellene; ehelyett tests/UrlSafetyTest.php közvetlenül
 *     ellenőrzi a `pinnedCurlOptions()`-t (CURLOPT_FOLLOWLOCATION=false),
 *     ami architekturálisan kizárja az átirányítás-követést.
 */
final class HttpSecurityTest extends TestCase
{
    private static string $root;
    private static int $port;
    /** @var resource|null */
    private static $serverProcess;
    private static string $baseUrl;
    private static string $installToken = '';
    private static ?string $lastTestBackupFilename = null;

    public static function setUpBeforeClass(): void
    {
        self::$root = sys_get_temp_dir() . '/sm_http_test_' . bin2hex(random_bytes(6));
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
    }

    public static function tearDownAfterClass(): void
    {
        if (self::$serverProcess !== null && is_resource(self::$serverProcess)) {
            proc_terminate(self::$serverProcess);
            proc_close(self::$serverProcess);
        }
        self::removeDir(self::$root);
    }

    // -----------------------------------------------------------------
    // Segédfüggvények
    // -----------------------------------------------------------------

    private static function copyDir(string $from, string $to, array $excludeDirNames): void
    {
        if (!is_dir($from)) {
            return;
        }
        mkdir($to, 0775, true);
        $items = scandir($from);
        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            if (in_array($item, $excludeDirNames, true)) {
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
        $items = scandir($dir);
        foreach ($items as $item) {
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

    /**
     * @return array{status:int, headers:array<string,string>, body:string, json:?array}
     */
    private static function request(
        string $method,
        string $path,
        ?array $jsonBody = null,
        array $extraHeaders = [],
        ?string $cookieJarPath = null
    ): array {
        $ch = curl_init(self::$baseUrl . $path);
        $headers = [];
        foreach ($extraHeaders as $k => $v) {
            $headers[] = "$k: $v";
        }
        curl_setopt_array($ch, [
            CURLOPT_CUSTOMREQUEST  => $method,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HEADER         => true,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_HTTPHEADER     => $headers,
            CURLOPT_TIMEOUT        => 10,
        ]);
        if ($cookieJarPath !== null) {
            curl_setopt($ch, CURLOPT_COOKIEJAR, $cookieJarPath);
            curl_setopt($ch, CURLOPT_COOKIEFILE, $cookieJarPath);
        }
        if ($jsonBody !== null) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($jsonBody));
            $headers[] = 'Content-Type: application/json';
            curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        } elseif ($method === 'POST') {
            curl_setopt($ch, CURLOPT_POSTFIELDS, '');
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

        return [
            'status'  => $status,
            'headers' => $respHeaders,
            'body'    => $body,
            'json'    => is_array($json) ? $json : null,
        ];
    }

    private static function cookieJar(string $name): string
    {
        return self::$root . '/cookies-' . $name . '.txt';
    }

    /**
     * A request()-tel ellentétben form-urlencoded törzset küld (nem JSON-t)
     * — a $_POST-ot közvetlenül olvasó végpontokhoz (pl. backup-restore.php,
     * install.php), amik sose json_input()-ot hívnak.
     *
     * @return array{status:int, headers:array<string,string>, body:string, json:?array}
     */
    private static function requestForm(
        string $path,
        array $fields,
        array $extraHeaders = [],
        ?string $cookieJarPath = null
    ): array {
        $ch = curl_init(self::$baseUrl . $path);
        $headers = [];
        foreach ($extraHeaders as $k => $v) {
            $headers[] = "$k: $v";
        }
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => http_build_query($fields),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HEADER         => true,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_HTTPHEADER     => $headers,
            CURLOPT_TIMEOUT        => 10,
        ]);
        if ($cookieJarPath !== null) {
            curl_setopt($ch, CURLOPT_COOKIEJAR, $cookieJarPath);
            curl_setopt($ch, CURLOPT_COOKIEFILE, $cookieJarPath);
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

        return [
            'status'  => $status,
            'headers' => $respHeaders,
            'body'    => $body,
            'json'    => is_array($json) ? $json : null,
        ];
    }

    // -----------------------------------------------------------------
    // 1) Telepítő (install.php) — hiányzó/érvénytelen/érvényes token,
    //    érvénytelen DB-azonosító, telepítés utáni token-viselkedés.
    // -----------------------------------------------------------------

    public function test01_InstallRejectsMissingToken(): void
    {
        $res = self::request('GET', '/install.php');
        $this->assertSame(403, $res['status']);
        $this->assertStringContainsString('token', $res['body']);
        $this->assertFileExists(self::$root . '/data/.install-token', 'Az install.php-nak első híváskor létre kell hoznia a token-fájlt.');

        self::$installToken = trim((string) file_get_contents(self::$root . '/data/.install-token'));
        $this->assertNotSame('', self::$installToken);
    }

    public function test02_InstallRejectsInvalidToken(): void
    {
        $res = self::request('GET', '/install.php?token=' . urlencode('nyilvanvaloan-rossz-token'));
        $this->assertSame(403, $res['status']);
    }

    public function test03_InstallRejectsInvalidMysqlIdentifier(): void
    {
        // A mysql_database mezőt közvetlenül, azonosítóként illeszti be egy
        // CREATE DATABASE utasításba — ha ez a validáció hiányozna, egy
        // backtick-et/pontosvesszőt tartalmazó név SQL-injekciót engedne.
        // Form-urlencoded body-t küldünk, nem JSON-t, mivel install.php a
        // sima $_POST-ot olvassa, nem json_input()-ot.
        $ch = curl_init(self::$baseUrl . '/install.php?token=' . urlencode(self::$installToken));
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => http_build_query([
                'shop_name'      => 'Teszt Bolt',
                'driver'         => 'mysql',
                'mysql_host'     => '127.0.0.1',
                'mysql_port'     => '3306',
                'mysql_database' => 'rossz-nev; DROP TABLE x;',
                'mysql_username' => 'root',
                'mysql_password' => '',
            ]),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => false,
        ]);
        $body = curl_exec($ch);
        $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        $this->assertSame(200, $status, 'Hibás bemenetre a telepítő az űrlapot jeleníti újra, nem irányít át.');
        $this->assertStringContainsString('csak betűket, számokat és aláhúzást', $body);
        $this->assertFileDoesNotExist(self::$root . '/data/.installed', 'Érvénytelen bemenet mellett a telepítés nem fejeződhet be.');
    }

    public function test04_InstallCompletesWithSkip(): void
    {
        $ch = curl_init(self::$baseUrl . '/install.php?token=' . urlencode(self::$installToken));
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => http_build_query(['skip' => '1']),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HEADER         => true,
            CURLOPT_FOLLOWLOCATION => false,
        ]);
        $raw = curl_exec($ch);
        $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        $this->assertSame(302, $status);
        $this->assertStringContainsString('Location: index.php', $raw);
        $this->assertFileExists(self::$root . '/data/.installed');
    }

    public function test05_InstallPostInstallIgnoresToken(): void
    {
        // Telepítés után install.php-t akár token nélkül, akár hibás
        // tokennel is meghívva egyszerűen átirányít — nem ad ki 403-at, de
        // funkciót sem enged újra futtatni, mert a marker-ellenőrzés az
        // egész token-logika ELŐTT tér vissza.
        $res = self::request('GET', '/install.php?token=akarhogyanroszabb');
        $this->assertSame(302, $res['status']);
        $this->assertStringContainsString('index.php', $res['headers']['location'] ?? '');
    }

    // -----------------------------------------------------------------
    // 2) Bejelentkezés-mentes CSRF-token kiosztás (auth-status.php)
    // -----------------------------------------------------------------

    public function test10_AuthStatusReturnsCsrfTokenWithoutLogin(): void
    {
        $jar = self::cookieJar('main');
        $res = self::request('GET', '/api/auth-status.php', null, [], $jar);
        $this->assertSame(200, $res['status']);
        $this->assertFalse($res['json']['enabled']);
        $this->assertTrue($res['json']['logged_in']);
        $this->assertNotEmpty($res['json']['csrf_token']);
    }

    // -----------------------------------------------------------------
    // 3) Metódus-korlátozás állapotváltoztató végpontokon
    // -----------------------------------------------------------------

    public function test11_StaffLoginRejectsGet(): void
    {
        $res = self::request('GET', '/api/staff-login.php');
        $this->assertSame(405, $res['status']);
    }

    public function test12_SyncPullRejectsGet(): void
    {
        $res = self::request('GET', '/api/sync-pull.php');
        $this->assertSame(405, $res['status']);
    }

    // -----------------------------------------------------------------
    // 4) CSRF-ellenőrzés — GET rejected már fentebb (405), most a
    //    POST + hiányzó/érvénytelen/érvényes CSRF-token esetek.
    // -----------------------------------------------------------------

    public function test13_PostWithoutCsrfTokenRejected(): void
    {
        $jar = self::cookieJar('main');
        // A jar-nak már van session-sütije a test10-ből, de csrf fejlécet
        // most szándékosan nem küldünk.
        $res = self::request('POST', '/api/security-settings-save.php', ['session_timeout_minutes' => 240], [], $jar);
        $this->assertSame(403, $res['status']);
        $this->assertTrue($res['json']['csrf_required'] ?? false);
    }

    public function test14_PostWithInvalidCsrfTokenRejected(): void
    {
        $jar = self::cookieJar('main');
        $res = self::request('POST', '/api/security-settings-save.php', ['session_timeout_minutes' => 240], ['X-CSRF-Token' => 'nyilvan-hamis-token'], $jar);
        $this->assertSame(403, $res['status']);
    }

    public function test15_PostWithValidCsrfTokenAccepted(): void
    {
        $jar = self::cookieJar('main');
        $status = self::request('GET', '/api/auth-status.php', null, [], $jar);
        $csrf = $status['json']['csrf_token'];

        $res = self::request('POST', '/api/security-settings-save.php', ['session_timeout_minutes' => 240], ['X-CSRF-Token' => $csrf], $jar);
        $this->assertSame(200, $res['status'], 'Érvényes CSRF-tokennel a mentésnek sikeresnek kell lennie: ' . $res['body']);
    }

    public function test16_LogoutRequiresCsrf(): void
    {
        $jar = self::cookieJar('logout-test');
        self::request('GET', '/api/auth-status.php', null, [], $jar); // session-cookie beállítása

        $withoutCsrf = self::request('POST', '/api/logout.php', [], [], $jar);
        $this->assertSame(403, $withoutCsrf['status'], 'A logout.php mostantól CSRF-tokent igényel.');

        $status = self::request('GET', '/api/auth-status.php', null, [], $jar);
        $csrf = $status['json']['csrf_token'];
        $withCsrf = self::request('POST', '/api/logout.php', [], ['X-CSRF-Token' => $csrf], $jar);
        $this->assertSame(200, $withCsrf['status']);
    }

    // -----------------------------------------------------------------
    // 5) Cron-hitelesítés — hiányzó/érvénytelen/érvényes token fejlécben,
    //    és hogy egy böngésző-session SOSE helyettesítheti.
    // -----------------------------------------------------------------

    public function test20_SetCronSecretForCronTests(): void
    {
        $jar = self::cookieJar('main');
        $status = self::request('GET', '/api/auth-status.php', null, [], $jar);
        $csrf = $status['json']['csrf_token'];

        $res = self::request('POST', '/api/settings.php', ['cron_secret' => 'test-cron-secret-abc123'], ['X-CSRF-Token' => $csrf], $jar);
        $this->assertSame(200, $res['status'], 'A cron_secret beállításának sikeresnek kell lennie: ' . $res['body']);
    }

    public function test21_CronScriptRejectsMissingToken(): void
    {
        $res = self::request('GET', '/api/auto-backup-run.php');
        $this->assertSame(401, $res['status']);
    }

    public function test22_CronScriptRejectsInvalidToken(): void
    {
        $res = self::request('GET', '/api/auto-backup-run.php', null, ['X-Cron-Token' => 'nyilvan-rossz-titok']);
        $this->assertSame(401, $res['status']);
    }

    public function test23_CronScriptAcceptsValidToken(): void
    {
        $res = self::request('GET', '/api/auto-backup-run.php', null, ['X-Cron-Token' => 'test-cron-secret-abc123']);
        $this->assertSame(200, $res['status'], (string) json_encode($res['json']));
        // Friss telepítésen a mentés nincs bekapcsolva — a helyes válasz
        // "skipped", de a lényeg a 401 HIÁNYA: a token-ellenőrzésen átjutott.
        $this->assertTrue($res['json']['skipped'] ?? false);
    }

    public function test24_CronScriptRejectsQueryStringToken(): void
    {
        // A token KIZÁRÓLAG az X-Cron-Token fejlécben fogadható el — ha egy
        // korábbi/elavult dokumentáció vagy szkript még ?token=-t próbálna
        // küldeni, azt is el kell utasítani, mert egy URL-be írt titok
        // szerver-/proxy-naplókba kerülhet.
        $res = self::request('GET', '/api/auto-backup-run.php?token=test-cron-secret-abc123');
        $this->assertSame(401, $res['status']);
    }

    public function test25_BrowserSessionCannotAuthorizeCronScript(): void
    {
        // Egy érvényes, bejelentkezett böngésző-session (sőt, akár CSRF-
        // tokennel is) sose helyettesítheti a dedikált cron-tokent — ez zárja
        // ki, hogy egy ellopott böngésző-session ezt az admin-jogszint
        // nélküli, teljes katalógus-felülírásra/mentésre képes utat is
        // felhasználhassa.
        $jar = self::cookieJar('main');
        $res = self::request('GET', '/api/auto-backup-run.php', null, [], $jar);
        $this->assertSame(401, $res['status'], 'Egy böngésző-session önmagában sose engedhet cron-végpontot.');
    }

    // -----------------------------------------------------------------
    // 6) Alkalmazás-jelszó bekapcsolása után: hitelesítés kikényszerítve,
    //    admin-jogszint kényszerítése "network" módban, majd fail-closed
    //    viselkedés sérült settings.json esetén.
    // -----------------------------------------------------------------

    public function test30_ProtectedEndpointRejectsUnauthenticatedAfterPasswordEnabled(): void
    {
        $jar = self::cookieJar('main');
        $status = self::request('GET', '/api/auth-status.php', null, [], $jar);
        $csrf = $status['json']['csrf_token'];

        $enable = self::request('POST', '/api/security-settings-save.php', [
            'app_password_enabled'  => true,
            'new_password'          => 'nagyon-titkos-jelszo-123',
            'new_password_confirm'  => 'nagyon-titkos-jelszo-123',
        ], ['X-CSRF-Token' => $csrf], $jar);
        $this->assertSame(200, $enable['status'], 'A jelszavas védelem bekapcsolásának sikeresnek kell lennie: ' . $enable['body']);

        // Egy ÚJ, session-mentes klienssel (friss cookie jar) most már
        // 401-et kell kapnunk egy védett végponton.
        $freshJar = self::cookieJar('fresh-no-session');
        $res = self::request('GET', '/api/settings.php', null, [], $freshJar);
        $this->assertSame(401, $res['status']);
        $this->assertTrue($res['json']['auth_required'] ?? false);
    }

    public function test31_LoginRejectsWrongPassword(): void
    {
        $jar = self::cookieJar('login-attempt');
        $res = self::request('POST', '/api/login.php', ['password' => 'nyilvan-rossz-jelszo'], [], $jar);
        $this->assertSame(401, $res['status']);
    }

    public function test32_LoginAcceptsCorrectPasswordThenGrantsAccess(): void
    {
        $jar = self::cookieJar('login-success');
        $login = self::request('POST', '/api/login.php', ['password' => 'nagyon-titkos-jelszo-123'], [], $jar);
        $this->assertSame(200, $login['status']);
        $this->assertTrue($login['json']['ok'] ?? false);
        $this->assertNotEmpty($login['json']['csrf_token'] ?? '');

        $res = self::request('GET', '/api/settings.php', null, [], $jar);
        $this->assertSame(200, $res['status'], 'Sikeres bejelentkezés után a védett végpontnak elérhetőnek kell lennie.');
    }

    public function test33_CorruptSettingsFileFailsClosed(): void
    {
        $settingsPath = self::$root . '/data/settings.json';
        $goodContent = file_get_contents($settingsPath);
        $this->assertNotFalse($goodContent);

        file_put_contents($settingsPath, '{ez nem ervenyes json');
        try {
            $res = self::request('GET', '/api/auth-status.php');
            $this->assertSame(500, $res['status'], 'Sérült settings.json esetén MINDEN kérést el kell utasítani, nem szabad DEFAULTS-ra visszaesni.');
        } finally {
            // Vissza az érvényes tartalomra, hogy a további tesztek (és a
            // tearDown-ban törölt mappa) ne maradjanak sérült állapotban.
            file_put_contents($settingsPath, $goodContent);
        }

        $recovered = self::request('GET', '/api/auth-status.php');
        $this->assertSame(200, $recovered['status'], 'A jó tartalom visszaírása után a rendszernek újra működnie kell.');
    }

    // -----------------------------------------------------------------
    // 7) "Nyilvános" (network) üzemmód kényszerítése
    // -----------------------------------------------------------------

    public function test40_NetworkModeCannotBeEnabledWithoutPassword(): void
    {
        // Ehhez a teszthez egy MÁSIK, jelszó nélküli állapot kellene — mivel
        // a megosztott szerverpéldányon a 30-as teszt már bekapcsolta a
        // jelszót, itt azt teszteljük, hogy 'network' módban a jelszó
        // KIKAPCSOLÁSA van megtagadva (ami ugyanazt az invariánst fedi le:
        // 'network' + jelszó-nélküliség sose engedhető meg egyszerre).
        $jar = self::cookieJar('login-success'); // már bejelentkezett session a 32-es tesztből
        $status = self::request('GET', '/api/auth-status.php', null, [], $jar);
        $csrf = $status['json']['csrf_token'];

        $switchToNetwork = self::request('POST', '/api/security-settings-save.php', [
            'deployment_mode' => 'network',
        ], ['X-CSRF-Token' => $csrf], $jar);
        $this->assertSame(200, $switchToNetwork['status'], '"network" módra váltás jelszóval már beállítva sikeresnek kell lennie.');

        $disablePassword = self::request('POST', '/api/security-settings-save.php', [
            'app_password_enabled' => false,
        ], ['X-CSRF-Token' => $csrf], $jar);
        $this->assertSame(400, $disablePassword['status'], '"network" módban a jelszó nem kapcsolható ki.');
    }

    // Regresszió (1.3.1): security-settings-save.php korábban a
    // Settings::save() TELJES, maszkolatlan eredményét küldte vissza —
    // egy, a titkokhoz semmilyen kapcsolatban nem álló mező mentése (itt:
    // session_timeout_minutes) is nyers szövegben visszaadta az ÖSSZES
    // konfigurált titkot, köztük a test20-ban beállított cron_secret-et.
    public function test41_SecuritySettingsSaveResponseNeverLeaksRawSecrets(): void
    {
        $jar = self::cookieJar('login-success'); // már bejelentkezett session a 32-es tesztből
        $status = self::request('GET', '/api/auth-status.php', null, [], $jar);
        $csrf = $status['json']['csrf_token'];

        $res = self::request('POST', '/api/security-settings-save.php', [
            'session_timeout_minutes' => 120,
        ], ['X-CSRF-Token' => $csrf], $jar);
        $this->assertSame(200, $res['status'], (string) $res['body']);

        $this->assertStringNotContainsString(
            'test-cron-secret-abc123',
            $res['body'],
            'A security-settings-save.php válasza SOSE tartalmazhatja a nyers cron_secret értéket, még akkor sem, ha a mentett mező ehhez semmilyen kapcsolatban nincs.'
        );
        $this->assertSame('', $res['json']['cron_secret'] ?? null, 'A cron_secret mezőnek üresen kell visszajönnie.');
        $this->assertTrue($res['json']['cron_secret_set'] ?? false, 'A cron_secret_set jelzőnek igaznak kell lennie, jelezve, hogy VAN elmentett érték.');
    }

    // -----------------------------------------------------------------
    // 7) sale.php — a hűségpont-jóváírás és az élettartam-elköltés
    //    frissítése MOSTANTÓL az eladás tranzakcióján BELÜL fut (C3
    //    javítás). Ez a teszt egy valódi HTTP-n át indított eladást követ
    //    végig, és azt bizonyítja, hogy a válaszban visszaadott új
    //    pontegyenleg AZONNAL, a sale.php válaszával egyidejűleg
    //    lekérdezhető egy FÜGGETLEN, külön kéréssel is — vagyis a
    //    jóváírás ténylegesen véglegesítve (commit-olva) van, mire a
    //    kliens a választ megkapja, nem egy később, esetleg soha le nem
    //    futó lépésre van bízva.
    // -----------------------------------------------------------------

    public function test45_SaleCreditsLoyaltyPointsAndSpendAtomicallyWithinTransaction(): void
    {
        $jar = self::cookieJar('login-success');
        $status = self::request('GET', '/api/auth-status.php', null, [], $jar);
        $csrf = $status['json']['csrf_token'];

        $enableLoyalty = self::request('POST', '/api/settings.php', [
            'loyalty_enabled' => true,
            'loyalty_huf_per_point' => 100,
        ], ['X-CSRF-Token' => $csrf], $jar);
        $this->assertSame(200, $enableLoyalty['status'], 'A hűségpont-rendszer bekapcsolásának sikeresnek kell lennie: ' . $enableLoyalty['body']);

        $product = self::request('POST', '/api/product-save.php', [
            'name' => 'Hűségteszt termék', 'gross_price' => 1000, 'vat_rate' => '27',
        ], ['X-CSRF-Token' => $csrf], $jar);
        $this->assertSame(200, $product['status'], 'A teszttermék létrehozásának sikeresnek kell lennie: ' . $product['body']);
        $productId = $product['json']['product']['id'] ?? null;
        $this->assertNotEmpty($productId);

        $customer = self::request('POST', '/api/customer-save.php', [
            'name' => 'Hűségteszt vásárló',
        ], ['X-CSRF-Token' => $csrf], $jar);
        $this->assertSame(200, $customer['status'], 'A tesztvásárló létrehozásának sikeresnek kell lennie: ' . $customer['body']);
        $customerId = $customer['json']['id'] ?? null;
        $this->assertNotEmpty($customerId);

        $before = self::request('GET', '/api/customer-detail.php?id=' . $customerId, null, [], $jar);
        $this->assertSame(0, (int) ($before['json']['customer']['loyalty_points'] ?? -1), 'A vásárlónak 0 ponttal kell indulnia.');

        $sale = self::request('POST', '/api/sale.php', [
            'items' => [['product_id' => $productId, 'qty' => 1]],
            'customer_id' => $customerId,
            'payment_method' => 'Készpénz',
            'idempotency_key' => bin2hex(random_bytes(16)),
        ], ['X-CSRF-Token' => $csrf], $jar);
        $this->assertSame(200, $sale['status'], 'Az eladásnak sikeresnek kell lennie: ' . $sale['body']);

        $expectedPoints = intdiv(1000, 100); // loyalty_huf_per_point = 100, lásd fentebb
        $this->assertSame($expectedPoints, $sale['json']['loyalty']['points_earned'] ?? null);
        $this->assertSame($expectedPoints, $sale['json']['loyalty']['new_balance'] ?? null, 'A válasznak már a ténylegesen jóváírt (nem egy később esedékes) egyenleget kell tartalmaznia.');

        // FÜGGETLEN, külön kérés — ha a jóváírás a sale.php válaszának
        // visszaküldése után, egy külön (esetleg sose lefutó) lépésben
        // történne, ez az azonnali, második kérés még a régi (0) értéket
        // látná. Mivel MOST már a sale.php saját tranzakcióján belül fut,
        // itt is a már véglegesített, helyes értéknek kell megjelennie.
        $after = self::request('GET', '/api/customer-detail.php?id=' . $customerId, null, [], $jar);
        $this->assertSame($expectedPoints, (int) ($after['json']['customer']['loyalty_points'] ?? -1), 'A jóváírásnak azonnal, egy független lekérdezéssel is láthatónak kell lennie.');
        $this->assertGreaterThan(0.0, (float) ($after['json']['customer']['total_spent'] ?? 0), 'Az élettartam-elköltésnek is frissülnie kellett ugyanabban a tranzakcióban.');
    }

    /**
     * C4 javítás regressziós tesztje: a low_stock_notify_webhook — mivel a
     * legtöbb Slack/Discord/Teams-webhook URL saját magában hordozza a
     * hitelesítést — mostantól ugyanúgy maszkolva jön vissza, mint a
     * wc_consumer_secret/cron_secret stb. Ellenőrizzük mind a GET, mind a
     * mentés utáni POST-válaszban, hogy a nyers URL SOSE megy ki, DE a
     * ténylegesen elmentett érték (üresen hagyott mezővel újramentve)
     * változatlan marad.
     */
    public function test46_LowStockWebhookUrlIsMaskedInSettingsResponses(): void
    {
        $jar = self::cookieJar('login-success');
        $status = self::request('GET', '/api/auth-status.php', null, [], $jar);
        $csrf = $status['json']['csrf_token'];

        $secretUrl = 'https://hooks.slack.com/services/T00/B00/' . bin2hex(random_bytes(8));
        $save = self::request('POST', '/api/settings.php', [
            'low_stock_notify_webhook' => $secretUrl,
        ], ['X-CSRF-Token' => $csrf], $jar);
        $this->assertSame(200, $save['status'], 'A webhook-URL mentésének sikeresnek kell lennie: ' . $save['body']);
        $this->assertSame('', $save['json']['low_stock_notify_webhook'] ?? null, 'A mentés válasza se tartalmazza a nyers URL-t.');
        $this->assertTrue($save['json']['low_stock_notify_webhook_set'] ?? false);
        $this->assertStringNotContainsString($secretUrl, $save['body'], 'A nyers webhook-URL sehol se jelenhet meg a válasz törzsében.');

        $get = self::request('GET', '/api/settings.php', null, [], $jar);
        $this->assertSame(200, $get['status']);
        $this->assertSame('', $get['json']['low_stock_notify_webhook'] ?? null, 'Egy sima GET /api/settings.php se adja vissza a nyers URL-t egy hitelesített, nem-admin munkamenetnek sem.');
        $this->assertTrue($get['json']['low_stock_notify_webhook_set'] ?? false);
        $this->assertStringNotContainsString($secretUrl, $get['body']);

        // Üresen hagyott mezővel újramentve a KORÁBBAN elmentett érték nem
        // törlődhet (ugyanaz a "blank = nincs változás" szabály, mint a
        // többi titkos mezőnél) — ezt a _set jelző továbbra is igaz
        // értékén keresztül ellenőrizzük (a nyers érték úgyis maszkolt).
        $resaveBlank = self::request('POST', '/api/settings.php', [
            'low_stock_notify_webhook' => '',
        ], ['X-CSRF-Token' => $csrf], $jar);
        $this->assertSame(200, $resaveBlank['status']);
        $this->assertTrue($resaveBlank['json']['low_stock_notify_webhook_set'] ?? false, 'Üresen hagyott mezővel újramentve a korábbi webhook-URL-nek MEGMARADÓ (nem törölt) állapotban kell maradnia.');
    }

    // -----------------------------------------------------------------
    // 7b) sale.php — idempotencia-kulcs ÉS ujjlenyomat együttes ellenőrzése
    //    (C7 javítás). Minden itteni teszt saját, egyedi idempotencia-
    //    kulcsot használ, hogy a tesztek egymástól függetlenek maradjanak.
    // -----------------------------------------------------------------

    private static ?int $fpProductId = null;
    private static ?int $fpCustomerId = null;

    private function ensureFingerprintTestFixtures(): array
    {
        $jar = self::cookieJar('login-success');
        $status = self::request('GET', '/api/auth-status.php', null, [], $jar);
        $csrf = $status['json']['csrf_token'];

        if (self::$fpProductId === null) {
            $product = self::request('POST', '/api/product-save.php', [
                'name' => 'Ujjlenyomat teszt termék', 'gross_price' => 500, 'vat_rate' => '27',
            ], ['X-CSRF-Token' => $csrf], $jar);
            $this->assertSame(200, $product['status'], $product['body']);
            self::$fpProductId = $product['json']['product']['id'];
        }
        if (self::$fpCustomerId === null) {
            $customer = self::request('POST', '/api/customer-save.php', [
                'name' => 'Ujjlenyomat teszt vásárló',
            ], ['X-CSRF-Token' => $csrf], $jar);
            $this->assertSame(200, $customer['status'], $customer['body']);
            self::$fpCustomerId = $customer['json']['id'];
        }

        return [$jar, $csrf];
    }

    public function testFp1_SameKeySameRequestReplaysOriginalSale(): void
    {
        [$jar, $csrf] = $this->ensureFingerprintTestFixtures();
        $key = bin2hex(random_bytes(16));
        $payload = [
            'items' => [['product_id' => self::$fpProductId, 'qty' => 1]],
            'payment_method' => 'Készpénz',
            'idempotency_key' => $key,
        ];

        $first = self::request('POST', '/api/sale.php', $payload, ['X-CSRF-Token' => $csrf], $jar);
        $this->assertSame(200, $first['status'], $first['body']);
        $this->assertArrayNotHasKey('replayed', $first['json']);

        $second = self::request('POST', '/api/sale.php', $payload, ['X-CSRF-Token' => $csrf], $jar);
        $this->assertSame(200, $second['status'], $second['body']);
        $this->assertTrue($second['json']['replayed'] ?? false, 'Azonos kulcs + azonos kérés esetén visszajátszásnak kell történnie.');
        $this->assertSame($first['json']['sale_id'], $second['json']['sale_id']);
        $this->assertSame($first['json']['receipt_token'], $second['json']['receipt_token']);
    }

    public function testFp2_SameKeyDifferentCartQuantityIsRejectedWith409(): void
    {
        [$jar, $csrf] = $this->ensureFingerprintTestFixtures();
        $key = bin2hex(random_bytes(16));

        $first = self::request('POST', '/api/sale.php', [
            'items' => [['product_id' => self::$fpProductId, 'qty' => 1]],
            'payment_method' => 'Készpénz',
            'idempotency_key' => $key,
        ], ['X-CSRF-Token' => $csrf], $jar);
        $this->assertSame(200, $first['status'], $first['body']);

        // UGYANAZ a kulcs, de eltérő mennyiség — ez egy MÁSIK logikai kérés.
        $second = self::request('POST', '/api/sale.php', [
            'items' => [['product_id' => self::$fpProductId, 'qty' => 2]],
            'payment_method' => 'Készpénz',
            'idempotency_key' => $key,
        ], ['X-CSRF-Token' => $csrf], $jar);
        $this->assertSame(409, $second['status'], 'Azonos kulcs, de eltérő kosártartalom esetén 409-et kell kapni, nem az első eladás visszajátszását.');
    }

    public function testFp3_SameKeyDifferentManualItemPriceIsRejectedWith409(): void
    {
        [$jar, $csrf] = $this->ensureFingerprintTestFixtures();
        $key = bin2hex(random_bytes(16));

        $first = self::request('POST', '/api/sale.php', [
            'items' => [['manual' => true, 'name' => 'Egyedi szolgáltatás', 'qty' => 1, 'unit_price' => 100, 'vat_rate' => '27']],
            'payment_method' => 'Készpénz',
            'idempotency_key' => $key,
        ], ['X-CSRF-Token' => $csrf], $jar);
        $this->assertSame(200, $first['status'], $first['body']);

        // Ugyanaz a kulcs, de a kézi tétel ára eltér — a kézi tételeknél az
        // ár TÉNYLEGESEN a kliens által meghatározott, tehát ez is eltérő
        // logikai kérésnek számít.
        $second = self::request('POST', '/api/sale.php', [
            'items' => [['manual' => true, 'name' => 'Egyedi szolgáltatás', 'qty' => 1, 'unit_price' => 200, 'vat_rate' => '27']],
            'payment_method' => 'Készpénz',
            'idempotency_key' => $key,
        ], ['X-CSRF-Token' => $csrf], $jar);
        $this->assertSame(409, $second['status'], 'Azonos kulcs, de eltérő kézi tétel-ár esetén 409-et kell kapni.');
    }

    public function testFp4_SameKeyDifferentCustomerOrPaymentMethodIsRejectedWith409(): void
    {
        [$jar, $csrf] = $this->ensureFingerprintTestFixtures();

        $keyCustomer = bin2hex(random_bytes(16));
        $base = ['items' => [['product_id' => self::$fpProductId, 'qty' => 1]], 'payment_method' => 'Készpénz'];

        $first = self::request('POST', '/api/sale.php', $base + ['idempotency_key' => $keyCustomer], ['X-CSRF-Token' => $csrf], $jar);
        $this->assertSame(200, $first['status'], $first['body']);

        $secondCustomer = self::request('POST', '/api/sale.php', $base + [
            'idempotency_key' => $keyCustomer, 'customer_id' => self::$fpCustomerId,
        ], ['X-CSRF-Token' => $csrf], $jar);
        $this->assertSame(409, $secondCustomer['status'], 'Azonos kulcs, de eltérő vásárló esetén 409-et kell kapni.');

        $keyPayment = bin2hex(random_bytes(16));
        $firstPayment = self::request('POST', '/api/sale.php', $base + ['idempotency_key' => $keyPayment], ['X-CSRF-Token' => $csrf], $jar);
        $this->assertSame(200, $firstPayment['status'], $firstPayment['body']);

        $secondPayment = self::request('POST', '/api/sale.php', [
            'items' => [['product_id' => self::$fpProductId, 'qty' => 1]],
            'payment_method' => 'Bankkártya',
            'idempotency_key' => $keyPayment,
        ], ['X-CSRF-Token' => $csrf], $jar);
        $this->assertSame(409, $secondPayment['status'], 'Azonos kulcs, de eltérő fizetési mód esetén 409-et kell kapni.');
    }

    public function testFp5_DifferentKeysCreateIndependentSalesEvenWithIdenticalCart(): void
    {
        [$jar, $csrf] = $this->ensureFingerprintTestFixtures();
        $payloadA = [
            'items' => [['product_id' => self::$fpProductId, 'qty' => 1]],
            'payment_method' => 'Készpénz',
            'idempotency_key' => bin2hex(random_bytes(16)),
        ];
        $payloadB = $payloadA;
        $payloadB['idempotency_key'] = bin2hex(random_bytes(16));

        $saleA = self::request('POST', '/api/sale.php', $payloadA, ['X-CSRF-Token' => $csrf], $jar);
        $saleB = self::request('POST', '/api/sale.php', $payloadB, ['X-CSRF-Token' => $csrf], $jar);

        $this->assertSame(200, $saleA['status'], $saleA['body']);
        $this->assertSame(200, $saleB['status'], $saleB['body']);
        $this->assertNotSame($saleA['json']['sale_id'], $saleB['json']['sale_id'], 'Két KÜLÖNBÖZŐ kulcs — akár azonos kosártartalommal is — két FÜGGETLEN eladást kell, hogy létrehozzon (pl. két külön vásárló ugyanazt veszi).');
    }

    // -----------------------------------------------------------------
    // 7c) purchase-save.php — idempotencia-kulcs ÉS ujjlenyomat (1.1.1) —
    //    PONTOSAN a fenti 7b) sale.php Fp1-Fp5 mintáját követi.
    // -----------------------------------------------------------------

    private function ensurePurchaseFingerprintTestFixtures(): array
    {
        [$jar, $csrf] = $this->ensureFingerprintTestFixtures(); // ugyanaz a bejelentkezett session + self::$fpProductId
        return [$jar, $csrf];
    }

    public function testPp1_SameKeySameRequestReplaysOriginalPurchase(): void
    {
        [$jar, $csrf] = $this->ensurePurchaseFingerprintTestFixtures();
        $key = bin2hex(random_bytes(16));
        $payload = [
            'items' => [['product_id' => self::$fpProductId, 'qty' => 3, 'unit_cost_net' => 100]],
            'idempotency_key' => $key,
        ];

        $first = self::request('POST', '/api/purchase-save.php', $payload, ['X-CSRF-Token' => $csrf], $jar);
        $this->assertSame(200, $first['status'], $first['body']);
        $this->assertArrayNotHasKey('replayed', $first['json']);

        $second = self::request('POST', '/api/purchase-save.php', $payload, ['X-CSRF-Token' => $csrf], $jar);
        $this->assertSame(200, $second['status'], $second['body']);
        $this->assertTrue($second['json']['replayed'] ?? false, 'Azonos kulcs + azonos kérés esetén visszajátszásnak kell történnie.');
        $this->assertSame($first['json']['purchase_id'], $second['json']['purchase_id']);
        $this->assertSame($first['json']['total_gross'], $second['json']['total_gross']);
    }

    public function testPp2_SameKeyDifferentQuantityIsRejectedWith409(): void
    {
        [$jar, $csrf] = $this->ensurePurchaseFingerprintTestFixtures();
        $key = bin2hex(random_bytes(16));

        $first = self::request('POST', '/api/purchase-save.php', [
            'items' => [['product_id' => self::$fpProductId, 'qty' => 1, 'unit_cost_net' => 100]],
            'idempotency_key' => $key,
        ], ['X-CSRF-Token' => $csrf], $jar);
        $this->assertSame(200, $first['status'], $first['body']);

        // UGYANAZ a kulcs, de eltérő mennyiség — ez egy MÁSIK logikai kérés.
        $second = self::request('POST', '/api/purchase-save.php', [
            'items' => [['product_id' => self::$fpProductId, 'qty' => 5, 'unit_cost_net' => 100]],
            'idempotency_key' => $key,
        ], ['X-CSRF-Token' => $csrf], $jar);
        $this->assertSame(409, $second['status'], 'Azonos kulcs, de eltérő tétel-mennyiség esetén 409-et kell kapni, nem az első beszerzés visszajátszását.');
    }

    public function testPp3_SameKeyDifferentUnitCostIsRejectedWith409(): void
    {
        [$jar, $csrf] = $this->ensurePurchaseFingerprintTestFixtures();
        $key = bin2hex(random_bytes(16));

        $first = self::request('POST', '/api/purchase-save.php', [
            'items' => [['product_id' => self::$fpProductId, 'qty' => 1, 'unit_cost_net' => 100]],
            'idempotency_key' => $key,
        ], ['X-CSRF-Token' => $csrf], $jar);
        $this->assertSame(200, $first['status'], $first['body']);

        $second = self::request('POST', '/api/purchase-save.php', [
            'items' => [['product_id' => self::$fpProductId, 'qty' => 1, 'unit_cost_net' => 250]],
            'idempotency_key' => $key,
        ], ['X-CSRF-Token' => $csrf], $jar);
        $this->assertSame(409, $second['status'], 'Azonos kulcs, de eltérő beszerzési egységár esetén 409-et kell kapni.');
    }

    public function testPp4_DifferentKeysCreateIndependentPurchasesEvenWithIdenticalCart(): void
    {
        [$jar, $csrf] = $this->ensurePurchaseFingerprintTestFixtures();
        $payloadA = [
            'items' => [['product_id' => self::$fpProductId, 'qty' => 2, 'unit_cost_net' => 100]],
            'idempotency_key' => bin2hex(random_bytes(16)),
        ];
        $payloadB = $payloadA;
        $payloadB['idempotency_key'] = bin2hex(random_bytes(16));

        $purchaseA = self::request('POST', '/api/purchase-save.php', $payloadA, ['X-CSRF-Token' => $csrf], $jar);
        $purchaseB = self::request('POST', '/api/purchase-save.php', $payloadB, ['X-CSRF-Token' => $csrf], $jar);

        $this->assertSame(200, $purchaseA['status'], $purchaseA['body']);
        $this->assertSame(200, $purchaseB['status'], $purchaseB['body']);
        $this->assertNotSame($purchaseA['json']['purchase_id'], $purchaseB['json']['purchase_id'], 'Két KÜLÖNBÖZŐ kulcs — akár azonos kosártartalommal is — két FÜGGETLEN beszerzést kell, hogy létrehozzon.');
    }

    public function testPp5_NegativeUnitCostIsRejectedBeforeAnyPersistence(): void
    {
        [$jar, $csrf] = $this->ensurePurchaseFingerprintTestFixtures();

        $res = self::request('POST', '/api/purchase-save.php', [
            'items' => [['product_id' => self::$fpProductId, 'qty' => 1, 'unit_cost_net' => -50]],
        ], ['X-CSRF-Token' => $csrf], $jar);
        $this->assertSame(400, $res['status'], 'Negatív beszerzési egységárral a kérésnek el kell buknia, mielőtt bármi rögzülne.');
    }

    // -----------------------------------------------------------------
    // 8) backup-restore.php — require_admin() kényszerítése (C1 javítás
    //    regressziós tesztjei). Ettől a ponttól a megosztott szerverpéldány
    //    már jelszóval védett + "network" módban van (lásd 30-as/40-es
    //    teszt) — az itt létrehozott dolgozói PIN-ek pedig MOSTANTÓL
    //    megváltoztatják a listStaff(true) eredményét minden KÉSŐBBI
    //    tesztre nézve, ezért ez a blokk szándékosan a legvégén fut.
    // -----------------------------------------------------------------

    public function test50_BackupRestoreRejectsUnauthenticated(): void
    {
        $freshJar = self::cookieJar('backup-restore-no-session');
        $res = self::requestForm('/api/backup-restore.php', ['filename' => 'nemletezo.sqlite.enc'], [], $freshJar);
        $this->assertSame(401, $res['status'], 'Bejelentkezés nélkül a visszaállítás ne legyen elérhető.');
    }

    /**
     * Amíg egyáltalán nincs dolgozói PIN-rendszer beállítva (listStaff(true)
     * üres), a require_admin() — ugyanúgy, mint minden más admin-kapus
     * végponton az appban — no-op. Ez a teszt azt bizonyítja, hogy a C1
     * javítás EZT a dokumentált, szándékos viselkedést nem törte el: egy
     * egyszerű bejelentkezett munkamenet még mindig eljut az üzleti
     * logikáig (itt: a hiányzó fájlnév miatti 400-as hibáig), NEM 403-at
     * kap a require_admin()-től.
     */
    public function test51_BackupRestoreReachesBusinessLogicWhenNoStaffConfigured(): void
    {
        $jar = self::cookieJar('login-success'); // már bejelentkezett a 32-es tesztből, még nincs dolgozói PIN
        $status = self::request('GET', '/api/auth-status.php', null, [], $jar);
        $csrf = $status['json']['csrf_token'];

        $res = self::requestForm('/api/backup-restore.php', [], ['X-CSRF-Token' => $csrf], $jar);
        $this->assertSame(400, $res['status'], 'Dolgozói PIN-rendszer nélkül a require_admin() no-op — a hívásnak a fájlnév-hiány miatt kell elbuknia, nem jogosultság miatt.');
    }

    /**
     * Ez a teszt hozza létre az ELSŐ dolgozót (admin) — innentől a
     * listStaff(true) többé nem üres, tehát minden KÉSŐBBI teszt (ebben
     * és a jövőbeli tesztfájlokban, ha ugyanezt a szerverpéldányt bővítik)
     * már a "van dolgozói PIN-rendszer" ágat futtatja.
     *
     * A MÁSODIK (cashier) dolgozó felvétele előtt a session PIN-nel
     * bejelentkezik a frissen létrehozott adminként — ez a P1-4 javítás
     * ÓTA szükséges: amint van már felvett dolgozó, ÚJ dolgozó
     * létrehozása (nem csak admin-szerepkör adása/meglévő szerkesztése)
     * is vezetői jogszintet kér, lásd staff-save.php. (Korábban ez a
     * lépés kihagyható volt — pontosan ez volt a P1-4 alatt javított
     * hiba: bármelyik bejelentkezett, nem-admin session is felvehetett
     * új dolgozót.)
     */
    public function test52_CreateAdminAndCashierStaffForAdminGateTests(): void
    {
        $jar = self::cookieJar('login-success');
        $status = self::request('GET', '/api/auth-status.php', null, [], $jar);
        $csrf = $status['json']['csrf_token'];

        $admin = self::request('POST', '/api/staff-save.php', [
            'name' => 'Teszt Admin', 'pin' => '13579', 'role' => 'admin',
        ], ['X-CSRF-Token' => $csrf], $jar);
        $this->assertSame(200, $admin['status'], 'Az első (admin) dolgozó felvételének sikeresnek kell lennie: ' . $admin['body']);

        $staffLogin = self::request('POST', '/api/staff-login.php', ['pin' => '13579'], ['X-CSRF-Token' => $csrf], $jar);
        $this->assertSame(200, $staffLogin['status'], 'A frissen létrehozott admin PIN-bejelentkezésének sikeresnek kell lennie: ' . $staffLogin['body']);

        $cashier = self::request('POST', '/api/staff-save.php', [
            'name' => 'Teszt Pénztáros', 'pin' => '24680', 'role' => 'cashier',
        ], ['X-CSRF-Token' => $csrf], $jar);
        $this->assertSame(200, $cashier['status'], 'A második (nem-admin) dolgozó felvételének admin-bejelentkezéssel sikeresnek kell lennie: ' . $cashier['body']);
    }

    /**
     * P1-4 regresszió: a staff-save.php korábban CSAK admin-szerepkör
     * adásakor vagy MEGLÉVŐ dolgozó szerkesztésekor kért vezetői
     * jogszintet — egy ÚJ, sima "cashier" szerepkörű dolgozó létrehozása
     * (a leggyakoribb eset) kimaradt a feltételből, tehát bármelyik
     * bejelentkezett (nem-admin) session korlátlanul fabrikálhatott új
     * dolgozó-azonosítókat, amint a PIN-rendszer már működésben volt.
     */
    public function test52b_StaffSaveRejectsNewStaffCreationFromAuthenticatedNonAdminSession(): void
    {
        // Alkalmazás-jelszóval belépve, MAJD a (test52-ben létrehozott)
        // pénztáros PIN-jével is — tehát bejelentkezett, DE NEM admin.
        $jar = self::cookieJar('staff-save-cashier-tries-to-create-staff');
        $login = self::request('POST', '/api/login.php', ['password' => 'nagyon-titkos-jelszo-123'], [], $jar);
        $this->assertSame(200, $login['status']);

        $staffLogin = self::request('POST', '/api/staff-login.php', ['pin' => '24680'], ['X-CSRF-Token' => $login['json']['csrf_token']], $jar);
        $this->assertSame(200, $staffLogin['status']);
        $this->assertSame('cashier', $staffLogin['json']['staff']['role'] ?? null);

        $status = self::request('GET', '/api/auth-status.php', null, [], $jar);
        $csrf = $status['json']['csrf_token'];

        $res = self::request('POST', '/api/staff-save.php', [
            'name' => 'Csalárd Új Dolgozó', 'pin' => '11223', 'role' => 'cashier',
        ], ['X-CSRF-Token' => $csrf], $jar);
        $this->assertSame(403, $res['status'], 'Nem-admin dolgozói session ne tudjon ÚJ dolgozót létrehozni.');
        $this->assertStringContainsString('vezetői jogszint', $res['json']['error'] ?? '');
    }

    public function test52c_StaffSaveRejectsUnauthenticatedNewStaffCreation(): void
    {
        $freshJar = self::cookieJar('staff-save-unauthenticated');
        $res = self::request('POST', '/api/staff-save.php', [
            'name' => 'Ismeretlen Dolgozó', 'pin' => '99887', 'role' => 'cashier',
        ], [], $freshJar);
        $this->assertSame(401, $res['status'], 'Bejelentkezés nélkül a dolgozó-létrehozás ne legyen elérhető.');
    }

    public function test52d_StaffSaveAllowsNewStaffCreationFromAdminSession(): void
    {
        $jar = self::cookieJar('staff-save-admin-creates-staff');
        $login = self::request('POST', '/api/login.php', ['password' => 'nagyon-titkos-jelszo-123'], [], $jar);
        $this->assertSame(200, $login['status']);

        $staffLogin = self::request('POST', '/api/staff-login.php', ['pin' => '13579'], ['X-CSRF-Token' => $login['json']['csrf_token']], $jar);
        $this->assertSame(200, $staffLogin['status']);
        $this->assertSame('admin', $staffLogin['json']['staff']['role'] ?? null);

        $status = self::request('GET', '/api/auth-status.php', null, [], $jar);
        $csrf = $status['json']['csrf_token'];

        $res = self::request('POST', '/api/staff-save.php', [
            'name' => 'Admin Által Felvett Dolgozó', 'pin' => '55443', 'role' => 'cashier',
        ], ['X-CSRF-Token' => $csrf], $jar);
        $this->assertSame(200, $res['status'], 'Admin session-nek új dolgozót kell tudnia felvennie: ' . $res['body']);
    }

    /**
     * "Editing existing staff follows existing intended rules" — ez a
     * viselkedés NEM változott a P1-4 javítással (a meglévő dolgozó
     * szerkesztése már korábban is vezetői jogszintet kért), csak
     * explicit regresszióként rögzítjük.
     */
    public function test52e_StaffSaveStillRejectsEditingExistingStaffFromNonAdminSession(): void
    {
        $jar = self::cookieJar('staff-save-cashier-tries-to-edit');
        $login = self::request('POST', '/api/login.php', ['password' => 'nagyon-titkos-jelszo-123'], [], $jar);
        $this->assertSame(200, $login['status']);

        $staffLogin = self::request('POST', '/api/staff-login.php', ['pin' => '24680'], ['X-CSRF-Token' => $login['json']['csrf_token']], $jar);
        $this->assertSame(200, $staffLogin['status']);
        $cashierId = $staffLogin['json']['staff']['id'];

        $status = self::request('GET', '/api/auth-status.php', null, [], $jar);
        $csrf = $status['json']['csrf_token'];

        $res = self::request('POST', '/api/staff-save.php', [
            'id' => $cashierId, 'name' => 'Átnevezett Saját Magam',
        ], ['X-CSRF-Token' => $csrf], $jar);
        $this->assertSame(403, $res['status'], 'Nem-admin session ne tudjon MEGLÉVŐ dolgozó-rekordot szerkeszteni (ez már a javítás előtt is védve volt).');
    }

    public function test53_BackupRestoreRejectsAuthenticatedNonAdminStaff(): void
    {
        // Külön munkamenet: alkalmazás-jelszóval belépve, MAJD a pénztáros
        // PIN-jével is bejelentkezve (Auth::currentStaffId() a pénztárosra
        // mutat) — ez a require_admin() valódi célesete: nem "van-e
        // egyáltalán érvényes session", hanem "admin szerepkörű-e".
        $jar = self::cookieJar('backup-restore-cashier');
        $login = self::request('POST', '/api/login.php', ['password' => 'nagyon-titkos-jelszo-123'], [], $jar);
        $this->assertSame(200, $login['status']);

        $staffLogin = self::request('POST', '/api/staff-login.php', ['pin' => '24680'], ['X-CSRF-Token' => $login['json']['csrf_token']], $jar);
        $this->assertSame(200, $staffLogin['status'], 'A pénztáros PIN-bejelentkezésének sikeresnek kell lennie: ' . $staffLogin['body']);
        $this->assertSame('cashier', $staffLogin['json']['staff']['role'] ?? null);

        $status = self::request('GET', '/api/auth-status.php', null, [], $jar);
        $csrf = $status['json']['csrf_token'];

        $res = self::requestForm('/api/backup-restore.php', ['filename' => 'akarmi.sqlite.enc'], ['X-CSRF-Token' => $csrf], $jar);
        $this->assertSame(403, $res['status'], 'Nem-admin dolgozói session ne juthasson túl a require_admin()-en.');
        $this->assertStringContainsString('vezetői jogszint', $res['json']['error'] ?? '', 'A require_admin() üzenetének kell megjelennie, nem a fájlnév-hibának.');
    }

    public function test54_BackupRestoreStillRequiresFreshAdminPinEvenForAdminSession(): void
    {
        // Admin session, de a KÉRÉSBEN nincs friss PIN — ez bizonyítja, hogy
        // a require_admin() hozzáadása NEM helyettesítette a friss-PIN
        // ellenőrzést: a modellnek AUTH -> ADMIN AUTHZ -> FRISS ADMIN
        // ÚJRA-HITELESÍTÉS -> RESTORE-nak kell lennie, nem csak az első kettőnek.
        // Egy VALÓS, létező mentés-fájlnevet kell megadni, különben a
        // fájlnév-ellenőrzés (ami a PIN-ellenőrzés ELŐTT fut) 404-re futna,
        // elfedve a tényleges PIN-ellenőrzést.
        $jar = self::cookieJar('backup-restore-admin');
        $login = self::request('POST', '/api/login.php', ['password' => 'nagyon-titkos-jelszo-123'], [], $jar);
        $this->assertSame(200, $login['status']);

        $staffLogin = self::request('POST', '/api/staff-login.php', ['pin' => '13579'], ['X-CSRF-Token' => $login['json']['csrf_token']], $jar);
        $this->assertSame(200, $staffLogin['status']);
        $this->assertSame('admin', $staffLogin['json']['staff']['role'] ?? null);

        $status = self::request('GET', '/api/auth-status.php', null, [], $jar);
        $csrf = $status['json']['csrf_token'];

        $backupNow = self::request('POST', '/api/backup-now.php', [], ['X-CSRF-Token' => $csrf], $jar);
        $this->assertSame(200, $backupNow['status'], 'Az admin session-nek friss mentést kell tudnia készíteni a teszthez: ' . $backupNow['body']);
        $realFilename = $backupNow['json']['result']['filename'] ?? null;
        $this->assertNotEmpty($realFilename, 'A backup-now.php válaszának tartalmaznia kell a létrejött fájl nevét.');

        $withoutPin = self::requestForm('/api/backup-restore.php', ['filename' => $realFilename], ['X-CSRF-Token' => $csrf], $jar);
        $this->assertSame(403, $withoutPin['status'], 'Admin session, de friss PIN nélkül a visszaállítás továbbra is elutasítandó.');
        $this->assertStringContainsString('PIN', $withoutPin['json']['error'] ?? '', 'A friss-PIN hibaüzenetnek kell megjelennie, nem a require_admin() üzenetének (az admin session túljutott rajta).');

        self::$lastTestBackupFilename = $realFilename;
    }

    public function test55_BackupRestoreReachesRestoreFlowWithValidAdminPinAndCsrf(): void
    {
        // Ugyanaz az admin session, most a helyes friss PIN-nel, ÉS a 54-es
        // tesztben ténylegesen létrehozott, valódi mentés-fájlnévvel — a
        // require_admin() ÉS a PIN-frissesség ellenőrzés is átengedi, tehát
        // a hívásnak a TÉNYLEGES visszaállítási logikáig (sikeres restore,
        // 200) kell eljutnia, nem 403-ig.
        $this->assertNotEmpty(self::$lastTestBackupFilename, 'A 054-es tesztnek kellett létrehoznia egy valódi mentés-fájlt.');

        $jar = self::cookieJar('backup-restore-admin');
        $status = self::request('GET', '/api/auth-status.php', null, [], $jar);
        $csrf = $status['json']['csrf_token'];

        $res = self::requestForm('/api/backup-restore.php', ['filename' => self::$lastTestBackupFilename, 'pin' => '13579'], ['X-CSRF-Token' => $csrf], $jar);
        $this->assertSame(200, $res['status'], 'Érvényes admin session + friss PIN esetén a visszaállításnak sikeresen le kell futnia: ' . $res['body']);
        $this->assertTrue($res['json']['success'] ?? false);
    }

    /**
     * Regresszió (1.3.1): a security-audit során kiderült, hogy 7 végpont
     * (tevékenységnapló, vásárlók tömeges exportja, telephely mentés, és a
     * 2 logó feltöltő/törlő pár) korábban BÁRMELYIK bejelentkezett
     * dolgozó számára elérhető volt, require_admin() nélkül — egy sima
     * pénztáros láthatta a teljes tevékenységnaplót, letölthette az összes
     * vásárló PII-adatát, vagy módosíthatta a boltban mindenki által
     * látott branding-et. Ez a teszt mind a 7 végpontot lefedi, ugyanazzal
     * a cashier-sessionnel, mint a 053-as teszt.
     */
    public function test56_PreviouslyUngatedAdminEndpointsNowRejectNonAdminStaff(): void
    {
        $jar = self::cookieJar('admin-gate-regression-cashier');
        $login = self::request('POST', '/api/login.php', ['password' => 'nagyon-titkos-jelszo-123'], [], $jar);
        $this->assertSame(200, $login['status']);

        $staffLogin = self::request('POST', '/api/staff-login.php', ['pin' => '24680'], ['X-CSRF-Token' => $login['json']['csrf_token']], $jar);
        $this->assertSame(200, $staffLogin['status'], 'A pénztáros PIN-bejelentkezésének sikeresnek kell lennie: ' . $staffLogin['body']);
        $this->assertSame('cashier', $staffLogin['json']['staff']['role'] ?? null);

        $status = self::request('GET', '/api/auth-status.php', null, [], $jar);
        $csrf = $status['json']['csrf_token'];

        $getEndpoints = ['/api/audit-log.php', '/api/export-customers.php'];
        foreach ($getEndpoints as $endpoint) {
            $res = self::request('GET', $endpoint, null, [], $jar);
            $this->assertSame(403, $res['status'], "$endpoint ne engedjen át egy nem-admin dolgozói sessiont.");
        }

        $postEndpoints = ['/api/location-save.php', '/api/logo-upload.php', '/api/logo-reset.php', '/api/print-logo-upload.php', '/api/print-logo-reset.php'];
        foreach ($postEndpoints as $endpoint) {
            $res = self::requestForm($endpoint, [], ['X-CSRF-Token' => $csrf], $jar);
            $this->assertSame(403, $res['status'], "$endpoint ne engedjen át egy nem-admin dolgozói sessiont.");
        }
    }

    // -----------------------------------------------------------------
    // 1.2.0 — Dashboard/riportok HTTP-szintű coverage (lásd a kör 25.
    // pontja: "ne csak Database metódusokat tesztelj"). A Database-szintű
    // (aggregáció-, visszáru-nettósítás-, forecast-) helyesség a
    // tests/DashboardReportsTest.php-ban van bizonyítva — itt kizárólag a
    // HTTP-réteg (auth, validáció, státuszkódok, CSV fejlécek, admin-kapu)
    // a tárgy.
    // -----------------------------------------------------------------

    public function testDash1_ReportEndpointsRequireAuthentication(): void
    {
        foreach ([
            '/api/dashboard-summary.php', '/api/sales-report.php', '/api/inventory-report.php',
            '/api/low-stock-report.php', '/api/stock-movements-report.php', '/api/top-products-report.php',
            '/api/woocommerce-sync-status.php',
        ] as $path) {
            $res = self::request('GET', $path);
            $this->assertSame(401, $res['status'], "$path bejelentkezés nélkül 401-et kell adjon.");
        }
    }

    public function testDash2_InvalidPeriodReturns400WithBackendAuthoritativeValidation(): void
    {
        $jar = self::cookieJar('login-success');
        $res = self::request('GET', '/api/sales-report.php?period=holnaputan', null, [], $jar);
        $this->assertSame(400, $res['status'], 'Egy nem létező period értéknek 400-at kell adnia, nem csendben visszaesnie egy alapértelmezettre.');
        $this->assertNotEmpty($res['json']['error'] ?? '');
    }

    public function testDash3_CustomPeriodMissingDatesReturns400(): void
    {
        $jar = self::cookieJar('login-success');
        $res = self::request('GET', '/api/stock-movements-report.php?period=custom', null, [], $jar);
        $this->assertSame(400, $res['status']);
    }

    public function testDash4_CustomPeriodOversizedRangeRejected(): void
    {
        $jar = self::cookieJar('login-success');
        $res = self::request('GET', '/api/sales-report.php?period=custom&date_from=1990-01-01&date_to=2026-01-01', null, [], $jar);
        $this->assertSame(400, $res['status'], 'Egy tíz éves egyedi tartományt (a kör 28. pontja: "oversized requests") el kell utasítani.');
    }

    public function testDash5_StockMovementsInvalidTypeReturns400(): void
    {
        $jar = self::cookieJar('login-success');
        $res = self::request('GET', '/api/stock-movements-report.php?period=today&type=nem-letezo-tipus', null, [], $jar);
        $this->assertSame(400, $res['status']);
    }

    public function testDash6_DashboardSummaryReturnsExpectedJsonShape(): void
    {
        $jar = self::cookieJar('login-success');
        $res = self::request('GET', '/api/dashboard-summary.php?period=today', null, [], $jar);
        $this->assertSame(200, $res['status'], $res['body']);
        foreach (['period', 'today', 'period_summary', 'inventory', 'woocommerce', 'nav_invoices'] as $key) {
            $this->assertArrayHasKey($key, $res['json'], "A dashboard-summary.php válaszának tartalmaznia kell a '$key' kulcsot.");
        }
        $this->assertArrayHasKey('revenue_gross', $res['json']['today']);
        $this->assertArrayHasKey('low_stock', $res['json']['inventory']);
    }

    public function testDash7_SalesReportCsvExportHasCorrectHeadersAndBom(): void
    {
        $jar = self::cookieJar('login-success');
        $res = self::request('GET', '/api/export-sales-report-csv.php?period=today', null, [], $jar);
        $this->assertSame(200, $res['status']);
        $this->assertStringContainsString('text/csv', $res['headers']['content-type'] ?? '');
        $this->assertStringContainsString('attachment', $res['headers']['content-disposition'] ?? '');
        $this->assertStringStartsWith("\xEF\xBB\xBF", $res['body'], 'A CSV export-nak UTF-8 BOM-mal kell kezdődnie, hogy Excelben helyesen jelenjenek meg az ékezetes karakterek.');
    }

    public function testDash8_LowStockCsvExportAppliesFormulaInjectionProtection(): void
    {
        $jar = self::cookieJar('login-success');
        $status = self::request('GET', '/api/auth-status.php', null, [], $jar);
        $csrf = $status['json']['csrf_token'];

        // Egy `=`-jellel kezdődő terméknév, konkrét CSV-formula-injekciós
        // kísérlet szimulálása (CWE-1236, lásd _bootstrap.php csv_safe()).
        $product = self::request('POST', '/api/product-save.php', [
            'name' => '=HYPERLINK("http://evil.example/steal")', 'gross_price' => 100, 'vat_rate' => '27',
            'low_stock_threshold' => 1000,
        ], ['X-CSRF-Token' => $csrf], $jar);
        $this->assertSame(200, $product['status'], $product['body']);

        $csv = self::request('GET', '/api/export-low-stock-csv.php?filter=low', null, [], $jar);
        $this->assertSame(200, $csv['status']);
        $this->assertStringNotContainsString(
            "\n=HYPERLINK",
            $csv['body'],
            'Egy `=`-jellel kezdődő terméknév a CSV-ben SOSE jelenhet meg vezető aposztróf nélkül (formula-injekció).'
        );
        $this->assertStringContainsString("'=HYPERLINK", $csv['body'], 'A csv_safe() védelemnek vezető aposztróffal kell ellátnia az ilyen mezőt.');
    }

    public function testDash9_WooCommerceSyncRetryRequiresAdminAndOnlyAllowsTerminalRows(): void
    {
        $adminJar = self::cookieJar('login-success');
        $status = self::request('GET', '/api/auth-status.php', null, [], $adminJar);
        $csrf = $status['json']['csrf_token'];

        // Egy valódi wc_push_queue sor létrehozása — a HTTP-rétegen ehhez
        // nincs dedikált végpont (a queue MINDIG egy eladás/beszerzés/
        // leltár-lezárás tranzakciójának belső mellékhatásaként jön létre,
        // lásd Database::migrateV24WcPushQueue() docblockja), a Database-
        // szintű enqueue/claim/retry helyesség pedig már bizonyítva van
        // (tests/WcPushQueueWorkerTest.php,
        // tests/PurchaseAndWcPushConcurrencyTest.php) — itt KIZÁRÓLAG a
        // HTTP admin-kapu a tárgy, ezért egy közvetlen SQLite-sor beszúrás
        // elegendő és arányos (a teszt-gyökér saját, elszigetelt
        // adatbázisába, lásd setUpBeforeClass()).
        $product = self::request('POST', '/api/product-save.php', [
            'name' => 'WC retry teszt termék', 'gross_price' => 100, 'vat_rate' => '27',
        ], ['X-CSRF-Token' => $csrf], $adminJar);
        $this->assertSame(200, $product['status'], $product['body']);
        $productId = $product['json']['product']['id'];

        $pdo = new PDO('sqlite:' . self::$root . '/data/stock.sqlite');
        $now = date('Y-m-d H:i:s');
        $pdo->prepare("
            INSERT INTO wc_push_queue (product_id, wc_product_id, trigger_type, trigger_id, operation_key, status, attempts, created_at, updated_at)
            VALUES (?, 999, 'sale', 1, ?, 'failed', 3, ?, ?)
        ")->execute([$productId, 'test-retry-op-key-' . bin2hex(random_bytes(4)), $now, $now]);
        $queueId = (int) $pdo->lastInsertId();

        $pdo->prepare("
            INSERT INTO wc_push_queue (product_id, wc_product_id, trigger_type, trigger_id, operation_key, status, attempts, created_at, updated_at)
            VALUES (?, 999, 'sale', 2, ?, 'queued', 0, ?, ?)
        ")->execute([$productId, 'test-retry-op-key-queued-' . bin2hex(random_bytes(4)), $now, $now]);
        $queuedNonTerminalId = (int) $pdo->lastInsertId();

        // 1) Nem-admin (cashier) session -> 403.
        $cashierJar = self::cookieJar('wc-retry-cashier');
        $cashierLogin = self::request('POST', '/api/login.php', ['password' => 'nagyon-titkos-jelszo-123'], [], $cashierJar);
        $this->assertSame(200, $cashierLogin['status']);
        $cashierStaffLogin = self::request('POST', '/api/staff-login.php', ['pin' => '24680'], ['X-CSRF-Token' => $cashierLogin['json']['csrf_token']], $cashierJar);
        $this->assertSame(200, $cashierStaffLogin['status'], $cashierStaffLogin['body']);
        $forbidden = self::request('POST', '/api/woocommerce-sync-retry.php', ['id' => $queueId], ['X-CSRF-Token' => $cashierLogin['json']['csrf_token']], $cashierJar);
        $this->assertSame(403, $forbidden['status'], 'Egy nem-admin (cashier) session nem próbálhatja meg újraütemezni a push-t.');

        // 2) Admin, de nem létező id -> 404.
        $adminStaffLogin = self::request('POST', '/api/staff-login.php', ['pin' => '13579'], ['X-CSRF-Token' => $csrf], $adminJar);
        $this->assertSame(200, $adminStaffLogin['status'], $adminStaffLogin['body']);
        $notFound = self::request('POST', '/api/woocommerce-sync-retry.php', ['id' => 999999], ['X-CSRF-Token' => $csrf], $adminJar);
        $this->assertSame(404, $notFound['status']);

        // 3) Admin, de a sor NEM terminális (queued) állapotú -> 409.
        $conflict = self::request('POST', '/api/woocommerce-sync-retry.php', ['id' => $queuedNonTerminalId], ['X-CSRF-Token' => $csrf], $adminJar);
        $this->assertSame(409, $conflict['status'], 'Csak terminális (failed/dead_letter) sorra engedélyezett a kézi újrapróbálkozás.');

        // 4) Admin, terminális (failed) sor -> 200, a sor visszakerül queued-ba.
        $ok = self::request('POST', '/api/woocommerce-sync-retry.php', ['id' => $queueId], ['X-CSRF-Token' => $csrf], $adminJar);
        $this->assertSame(200, $ok['status'], $ok['body']);
        $this->assertSame('queued', $ok['json']['row']['status'] ?? null);
        $this->assertSame(0, $ok['json']['row']['attempts'] ?? null, 'Az újraütemezésnek nulláznia kell a próbálkozás-számlálót.');
    }

    public function testDash10_ProductScopedReportEndpointsReturn404ForNonexistentProduct(): void
    {
        $jar = self::cookieJar('login-success');
        foreach (['/api/stock-forecast.php', '/api/product-stock-movements.php'] as $path) {
            $res = self::request('GET', $path . '?product_id=999999', null, [], $jar);
            $this->assertSame(404, $res['status'], "$path egy nem létező product_id-ra 404-et adjon, ne hamis \"nincs fogyás\" adatot.");
        }
    }

    /**
     * A kör 23. pontja külön kéri a CSV exportok "magyar ékezetek"
     * lefedettségét — a UTF-8 BOM-ot már testDash7 ellenőrzi, ez a teszt a
     * TARTALOM tényleges, helyes UTF-8 kódolását bizonyítja ékezetes
     * terméknévvel, VALÓDI HTTP-n át (nem csak Database-szinten).
     */
    public function testDash11_LowStockCsvExportPreservesHungarianAccentsAsUtf8(): void
    {
        $jar = self::cookieJar('login-success');
        $status = self::request('GET', '/api/auth-status.php', null, [], $jar);
        $csrf = $status['json']['csrf_token'];

        $accentedName = 'Ékezetes Termékteszt Árvíztűrő Tükörfúrógép';
        $product = self::request('POST', '/api/product-save.php', [
            'name' => $accentedName, 'gross_price' => 100, 'vat_rate' => '27',
            'low_stock_threshold' => 1000,
        ], ['X-CSRF-Token' => $csrf], $jar);
        $this->assertSame(200, $product['status'], $product['body']);

        $csv = self::request('GET', '/api/export-low-stock-csv.php?filter=low', null, [], $jar);
        $this->assertSame(200, $csv['status']);
        $bodyWithoutBom = substr($csv['body'], 3);
        $this->assertTrue(mb_check_encoding($bodyWithoutBom, 'UTF-8'), 'A CSV tartalmának érvényes UTF-8-nak kell lennie.');
        $this->assertStringContainsString($accentedName, $csv['body'], 'Az ékezetes terméknévnek sérülés nélkül kell megjelennie a CSV-ben.');
    }

    // -----------------------------------------------------------------
    // Navigáció-fix (a Dashboard mostantól a kezdőoldal, nincs külön
    // sidebar-menüpontja, a logó vezet rá) — kis UI-only változtatás egy
    // KÉSŐBBI release-hez, lásd README/CHANGELOG "Nem kiadott módosítás"
    // szakaszát. Valódi renderelt HTML-en ellenőriz, nem csak a PHP
    // sablon forráskódján.
    // -----------------------------------------------------------------

    public function testNav1_SidebarHasNoDedicatedDashboardMenuItemButLogoLinksToIt(): void
    {
        $jar = self::cookieJar('login-success');
        $res = self::request('GET', '/index.php', null, [], $jar);
        $this->assertSame(200, $res['status']);
        $this->assertStringNotContainsString(
            'title="Dashboard"><svg',
            $res['body'],
            'Ne legyen KÜLÖN, ikonos sidebar-menüpont "Dashboard" címkével.'
        );
        $this->assertMatchesRegularExpression(
            '/<a href="dashboard\.php"[^>]*><img[^>]*id="sidebar-logo"/',
            $res['body'],
            'A logónak a Dashboardra kell mutatnia.'
        );
    }

    public function testNav2_LoginDefaultRedirectTargetIsDashboard(): void
    {
        // login.html statikus fájl — a _bootstrap.php auth-kapuja csak az
        // api/*.php végpontokra vonatkozik, ez a fájl közvetlenül
        // kiszolgálva olvasható (ugyanaz, mint minden más statikus oldal).
        $res = self::request('GET', '/login.html');
        $this->assertSame(200, $res['status']);
        $this->assertStringContainsString(
            "return 'dashboard.php';",
            $res['body'],
            'A bejelentkezés utáni (és a "már bejelentkezve" auto-redirect) alapértelmezett célja a Dashboard legyen.'
        );
    }

    // -----------------------------------------------------------------
    // Dashboard-újratervezés (dátum/névnap fejléc, rendszerállapot,
    // KPI-összehasonlítás, "Figyelmet igényel" blokk, mai top termékek/
    // fizetési módok) — a kör 10. pontja szerinti HTTP-szintű ellenőrzés.
    // A forecast-/visszáru-nettósítás DB-szintű helyessége már bizonyítva
    // van a DashboardReportsTest.php-ban — itt a válasz-alak és a
    // ténylegesen ÖSSZETETT (endpoint-szintű) logika a tárgy.
    // -----------------------------------------------------------------

    public function testDashNav1_SummaryIncludesDateNameDaySystemStatusAndAttentionShape(): void
    {
        $jar = self::cookieJar('login-success');
        $res = self::request('GET', '/api/dashboard-summary.php?period=today', null, [], $jar);
        $this->assertSame(200, $res['status'], $res['body']);
        $json = $res['json'];

        $this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}$/', $json['date']['iso']);
        $this->assertNotEmpty($json['date']['formatted']);
        $this->assertArrayHasKey('name_day', $json['date']); // lehet null (pl. jan. 23-24.), de a kulcsnak léteznie kell

        $this->assertContains($json['system_status']['level'], ['ok', 'warning', 'error']);
        $this->assertNotEmpty($json['system_status']['label']);

        foreach (['revenue_change_pct', 'sales_count_change_pct', 'avg_sale_change_pct'] as $key) {
            $val = $json['today_vs_yesterday'][$key];
            $this->assertTrue($val === null || is_numeric($val), "today_vs_yesterday.$key null vagy szám legyen.");
        }

        $this->assertIsArray($json['attention']);
        foreach ($json['attention'] as $item) {
            foreach (['type', 'count', 'label', 'link'] as $key) {
                $this->assertArrayHasKey($key, $item);
            }
            $this->assertGreaterThan(0, $item['count'], 'A "Figyelmet igényel" blokk SOSE tartalmazhat 0 darabszámú (fiktív) tételt.');
        }

        $this->assertArrayHasKey('closing_done', $json['today_status']);
        $this->assertIsBool($json['today_status']['closing_done']);
        $this->assertIsArray($json['today_top_products']);
        $this->assertLessThanOrEqual(5, count($json['today_top_products']));
        $this->assertIsArray($json['today_payment_methods']);
    }

    public function testDashNav2_AttentionBlockReflectsARealZeroStockProductAndLinksToPurchaseSuggestions(): void
    {
        $jar = self::cookieJar('login-success');
        $status = self::request('GET', '/api/auth-status.php', null, [], $jar);
        $csrf = $status['json']['csrf_token'];

        // Valódi, 0 készletű termék létrehozása — a "Figyelmet igényel"
        // blokknak ezt fel KELL vennie (nem fiktív állapot, lásd a kör
        // 1. pontja). 1.3.0 óta ez a MEGLÉVő getPurchaseRecommendations()-en
        // (PurchaseDecisionService::classifyUrgency()) keresztül fut —
        // egy 0 készletű termék mindig "urgent" besorolást kap.
        $product = self::request('POST', '/api/product-save.php', [
            'name' => 'Dashboard figyelmeztetés teszt termék', 'gross_price' => 100, 'vat_rate' => '27',
        ], ['X-CSRF-Token' => $csrf], $jar);
        $this->assertSame(200, $product['status'], $product['body']);

        $res = self::request('GET', '/api/dashboard-summary.php?period=today', null, [], $jar);
        $this->assertSame(200, $res['status']);
        $urgentItem = null;
        foreach ($res['json']['attention'] as $item) {
            if ($item['type'] === 'purchase_urgent') {
                $urgentItem = $item;
                break;
            }
        }
        $this->assertNotNull($urgentItem, 'A frissen létrehozott 0 készletű terméknek meg kell jelennie a "Figyelmet igényel" blokkban.');
        $this->assertGreaterThan(0, $urgentItem['count']);
        $this->assertSame('beszerzesi-javaslat.php?urgency=urgent', $urgentItem['link']);
    }

    public function testDashNav3_NoExternalHttpDependencyForNameDayData(): void
    {
        // A névnap-adat KIZÁRÓLAG a lokális HungarianNameDays.php-ból jön
        // — ez a teszt azt bizonyítja, hogy a válasz egy szinkron, helyi
        // PHP-hívásból ered (nincs számottevő extra késleltetés, ami egy
        // külső HTTP-hívásra utalna), lásd a kör 9. pontja.
        $jar = self::cookieJar('login-success');
        $start = microtime(true);
        $res = self::request('GET', '/api/dashboard-summary.php?period=today', null, [], $jar);
        $elapsed = microtime(true) - $start;
        $this->assertSame(200, $res['status']);
        $this->assertLessThan(2.0, $elapsed, 'A dashboard-summary.php válasznak gyorsnak kell lennie — külső API-függőség esetén ez jóval lassabb lenne.');
    }

    public function testDashNav4_BareRootRedirectsToDashboardButExplicitIndexPhpStillServesKassza(): void
    {
        $jar = self::cookieJar('login-success');

        $root = self::request('GET', '/', null, [], $jar);
        $this->assertSame(302, $root['status'], 'A csupasz gyökér-URL-nek átirányítania kell, nem közvetlenül a Kasszát kiszolgálnia.');
        $this->assertSame('dashboard.php', $root['headers']['location'] ?? null);

        $explicitIndex = self::request('GET', '/index.php', null, [], $jar);
        $this->assertSame(200, $explicitIndex['status'], 'Az explicit /index.php-nek (pl. a sidebar "Kassza" linkjének) változatlanul a Kasszát kell megnyitnia, nem átirányítania.');
        $this->assertStringContainsString('Kassza', $explicitIndex['body']);
    }

    // -----------------------------------------------------------------
    // 1.3.0 — Beszerzési döntéstámogatás / árrés / készletérték HTTP-
    // szintű coverage (lásd a kör 12. pontja). A számítás-helyesség már
    // bizonyítva van a PurchaseDecisionServiceTest.php/PurchaseDecisionDbTest.php-ban
    // — itt kizárólag a HTTP-réteg (auth, validáció, státuszkódok, JSON-alak).
    // -----------------------------------------------------------------

    public function testPurchase1_NewEndpointsRequireAuthentication(): void
    {
        foreach ([
            '/api/purchase-suggestions.php', '/api/product-insights.php?product_id=1',
        ] as $path) {
            $res = self::request('GET', $path);
            $this->assertSame(401, $res['status'], "$path bejelentkezés nélkül 401-et kell adjon.");
        }
    }

    public function testPurchase2_PurchaseSuggestionsRejectsInvalidUrgencyAndReturnsValidShape(): void
    {
        $jar = self::cookieJar('login-success');

        $invalid = self::request('GET', '/api/purchase-suggestions.php?urgency=nemletezo', null, [], $jar);
        $this->assertSame(400, $invalid['status']);

        $res = self::request('GET', '/api/purchase-suggestions.php', null, [], $jar);
        $this->assertSame(200, $res['status'], $res['body']);
        $this->assertIsArray($res['json']['recommendations']);
        foreach (['all', 'urgent', 'soon', 'low'] as $key) {
            $this->assertArrayHasKey($key, $res['json']['counts']);
        }
        foreach ($res['json']['recommendations'] as $row) {
            foreach (['id', 'name', 'stock_qty', 'avg_daily_consumption', 'estimated_days_remaining', 'safety_stock', 'reorder_point', 'recommended_qty', 'urgency', 'reason', 'workflow_status'] as $key) {
                $this->assertArrayHasKey($key, $row, "Minden javaslat-sornak tartalmaznia kell a '$key' mezőt.");
            }
            $this->assertContains($row['urgency'], ['urgent', 'soon', 'low']);
        }
    }

    public function testPurchase3_UrgencyFilterOnlyReturnsMatchingRows(): void
    {
        $jar = self::cookieJar('login-success');
        $status = self::request('GET', '/api/auth-status.php', null, [], $jar);
        $csrf = $status['json']['csrf_token'];

        // Valódi 0 készletű (urgent) termék.
        $product = self::request('POST', '/api/product-save.php', [
            'name' => 'Beszerzési teszt — sürgős termék', 'gross_price' => 100, 'vat_rate' => '27',
        ], ['X-CSRF-Token' => $csrf], $jar);
        $this->assertSame(200, $product['status'], $product['body']);

        $res = self::request('GET', '/api/purchase-suggestions.php?urgency=urgent', null, [], $jar);
        $this->assertSame(200, $res['status']);
        foreach ($res['json']['recommendations'] as $row) {
            $this->assertSame('urgent', $row['urgency']);
        }
        $this->assertGreaterThan(0, count($res['json']['recommendations']));

        $resSoon = self::request('GET', '/api/purchase-suggestions.php?urgency=soon', null, [], $jar);
        foreach ($resSoon['json']['recommendations'] as $row) {
            $this->assertSame('soon', $row['urgency']);
        }
    }

    public function testPurchase4_ProductInsightsReturns404ForNonexistentProductAndValidShapeForReal(): void
    {
        $jar = self::cookieJar('login-success');
        $status = self::request('GET', '/api/auth-status.php', null, [], $jar);
        $csrf = $status['json']['csrf_token'];

        $notFound = self::request('GET', '/api/product-insights.php?product_id=999999', null, [], $jar);
        $this->assertSame(404, $notFound['status']);

        $invalid = self::request('GET', '/api/product-insights.php?product_id=0', null, [], $jar);
        $this->assertSame(400, $invalid['status']);

        $product = self::request('POST', '/api/product-save.php', [
            'name' => 'Insights teszt termék', 'gross_price' => 1270, 'vat_rate' => '27',
        ], ['X-CSRF-Token' => $csrf], $jar);
        $this->assertSame(200, $product['status'], $product['body']);
        $productId = $product['json']['product']['id'];

        $res = self::request('GET', '/api/product-insights.php?product_id=' . $productId, null, [], $jar);
        $this->assertSame(200, $res['status'], $res['body']);
        $json = $res['json'];
        foreach (['stock_qty', 'stock_value_net', 'price', 'net_price', 'purchase_price_net', 'has_cost_history', 'margin_ft', 'margin_pct'] as $key) {
            $this->assertArrayHasKey($key, $json['status'], "status.$key hiányzik.");
        }
        $this->assertArrayHasKey('last_30_days', $json['sales']);
        $this->assertArrayHasKey('last_90_days', $json['sales']);
        $this->assertIsArray($json['recent_purchases']);
        $this->assertIsArray($json['price_trend']);
        $this->assertNull($json['status']['margin_ft'], 'Frissen létrehozott, sose beszerzett termékhez NE legyen (hamis) árrés.');
    }

    public function testPurchase5_InventoryReportIncludesValuationSummary(): void
    {
        $jar = self::cookieJar('login-success');
        $res = self::request('GET', '/api/inventory-report.php', null, [], $jar);
        $this->assertSame(200, $res['status']);
        foreach (['cost_value_net', 'retail_value_net', 'potential_margin_value_net', 'products_with_reliable_cost', 'products_total'] as $key) {
            $this->assertArrayHasKey($key, $res['json']['valuation'], "valuation.$key hiányzik.");
        }
    }

    public function testPurchase6_SalesReportIncludesMarginSummary(): void
    {
        $jar = self::cookieJar('login-success');
        $res = self::request('GET', '/api/sales-report.php?period=today', null, [], $jar);
        $this->assertSame(200, $res['status']);
        foreach (['revenue_net', 'estimated_cost_net', 'margin_net', 'margin_pct', 'products_with_margin', 'products_without_margin'] as $key) {
            $this->assertArrayHasKey($key, $res['json']['margin'], "margin.$key hiányzik.");
        }
    }

    public function testPurchase7_TopProductsReportIncludesMarginFields(): void
    {
        $jar = self::cookieJar('login-success');
        $res = self::request('GET', '/api/top-products-report.php?period=last_30_days', null, [], $jar);
        $this->assertSame(200, $res['status']);
        foreach ($res['json']['products'] as $row) {
            $this->assertArrayHasKey('margin_net', $row);
            $this->assertArrayHasKey('margin_pct', $row);
            $this->assertArrayHasKey('revenue_net', $row);
        }
    }

    /**
     * XSS-védelem: egy `<script>`-et tartalmazó terméknév a beszerzési
     * javaslat JSON-válaszában NYERS szövegként kerül vissza (a védelem a
     * frontend escapeHtml()-jénél van, lásd beszerzesi-javaslat.js), de a
     * JSON-válasz maga sose tartalmazzon kiszökő, nem escapelt HTML-t
     * (ami egy `Content-Type: application/json` válasznál amúgy sem
     * futna le böngészőben, de a JSON encodingnak akkor is helyesnek
     * kell lennie).
     */
    public function testPurchase8_ProductNameWithScriptTagIsSafelyJsonEncoded(): void
    {
        $jar = self::cookieJar('login-success');
        $status = self::request('GET', '/api/auth-status.php', null, [], $jar);
        $csrf = $status['json']['csrf_token'];

        $xssName = '<script>alert(1)</script>';
        $product = self::request('POST', '/api/product-save.php', [
            'name' => $xssName, 'gross_price' => 100, 'vat_rate' => '27',
        ], ['X-CSRF-Token' => $csrf], $jar);
        $this->assertSame(200, $product['status'], $product['body']);

        $res = self::request('GET', '/api/purchase-suggestions.php', null, [], $jar);
        $this->assertSame(200, $res['status']);
        $found = null;
        foreach ($res['json']['recommendations'] as $row) {
            if ($row['name'] === $xssName) { $found = $row; break; }
        }
        $this->assertNotNull($found, 'A terméknek meg kell jelennie a javaslatlistában (0 készlet).');
        $this->assertStringContainsString('application/json', $res['headers']['content-type'] ?? '');
    }

    // -----------------------------------------------------------------
    // 1.3.1 FINAL RELEASE GATE — a release-gate audit során kiderült,
    // hogy több végpont a nyers $e->getMessage()-t adta vissza a
    // kliensnek, ami konkrétan, reprodukálhatóan SQL/séma-töredéket
    // tartalmazhatott (pl. "UNIQUE constraint failed: coupons.code").
    // Ezek a tesztek bizonyítják: (a) a technikai részlet többé nem
    // szivárog ki, (b) a valódi, hasznos üzleti hibaüzenetek (pl. "már
    // le van zárva", "időközben már csak N db vihető vissza") VÁLTOZATLANUL
    // eljutnak a felhasználóhoz — a javítás nem generikusította túl a
    // legitim visszajelzéseket.
    // -----------------------------------------------------------------

    public function testGate1_DuplicateCouponCodeDoesNotLeakRawSqlFragment(): void
    {
        $jar = self::cookieJar('login-success');
        $status = self::request('GET', '/api/auth-status.php', null, [], $jar);
        $csrf = $status['json']['csrf_token'];

        $code = 'GATE-DUP-' . bin2hex(random_bytes(4));
        $first = self::request('POST', '/api/coupon-save.php', [
            'code' => $code, 'type' => 'fixed', 'value' => 10,
        ], ['X-CSRF-Token' => $csrf], $jar);
        $this->assertSame(200, $first['status'], $first['body']);

        $duplicate = self::request('POST', '/api/coupon-save.php', [
            'code' => $code, 'type' => 'fixed', 'value' => 20,
        ], ['X-CSRF-Token' => $csrf], $jar);
        $this->assertSame(400, $duplicate['status']);
        $this->assertStringContainsString('már létezik', $duplicate['json']['error'] ?? '', 'A hasznos, felhasználó-orientált gyanúnak meg kell maradnia.');
        $this->assertStringNotContainsString('UNIQUE', $duplicate['body'], 'A nyers SQL/séma-töredék (UNIQUE constraint ...) nem szivároghat ki.');
        $this->assertStringNotContainsString('coupons.code', $duplicate['body'], 'A tábla/oszlopnév nem szivároghat ki.');
        $this->assertStringNotContainsString('SQLSTATE', $duplicate['body'], 'A nyers PDO-hibakód nem szivároghat ki.');
    }

    public function testGate2_DuplicateGiftCardCodeDoesNotLeakRawSqlFragment(): void
    {
        $jar = self::cookieJar('login-success');
        $status = self::request('GET', '/api/auth-status.php', null, [], $jar);
        $csrf = $status['json']['csrf_token'];

        $code = 'GATEDUP' . bin2hex(random_bytes(4));
        $first = self::request('POST', '/api/gift-card-save.php', [
            'code' => $code, 'balance' => 1000,
        ], ['X-CSRF-Token' => $csrf], $jar);
        $this->assertSame(200, $first['status'], $first['body']);

        $duplicate = self::request('POST', '/api/gift-card-save.php', [
            'code' => $code, 'balance' => 2000,
        ], ['X-CSRF-Token' => $csrf], $jar);
        $this->assertSame(400, $duplicate['status']);
        $this->assertStringContainsString('már létezik', $duplicate['json']['error'] ?? '', 'A hasznos, felhasználó-orientált gyanúnak meg kell maradnia.');
        $this->assertStringNotContainsString('UNIQUE', $duplicate['body'], 'A nyers SQL/séma-töredék nem szivároghat ki.');
        $this->assertStringNotContainsString('gift_cards.code', $duplicate['body'], 'A tábla/oszlopnév nem szivároghat ki.');
        $this->assertStringNotContainsString('SQLSTATE', $duplicate['body'], 'A nyers PDO-hibakód nem szivároghat ki.');
    }

    public function testGate3_StockTakeDoubleCompletionStillShowsRealUserFacingMessageNotGenericError(): void
    {
        $jar = self::cookieJar('login-success');
        $status = self::request('GET', '/api/auth-status.php', null, [], $jar);
        $csrf = $status['json']['csrf_token'];

        $start = self::request('POST', '/api/stock-take-start.php', [], ['X-CSRF-Token' => $csrf], $jar);
        $this->assertSame(200, $start['status'], $start['body']);
        $takeId = $start['json']['id'] ?? $start['json']['stock_take_id'] ?? null;
        $this->assertNotEmpty($takeId, 'A leltár indításának valódi id-t kell visszaadnia: ' . $start['body']);

        $firstClose = self::request('POST', '/api/stock-take-complete.php', [
            'id' => $takeId, 'apply_corrections' => false,
        ], ['X-CSRF-Token' => $csrf], $jar);
        $this->assertSame(200, $firstClose['status'], $firstClose['body']);

        $secondClose = self::request('POST', '/api/stock-take-complete.php', [
            'id' => $takeId, 'apply_corrections' => false,
        ], ['X-CSRF-Token' => $csrf], $jar);
        $this->assertSame(409, $secondClose['status']);
        $this->assertSame(
            'Ez a leltár már le van zárva.',
            $secondClose['json']['error'] ?? null,
            'A JAVÍTÁS (RuntimeException külön catch-elése) UTÁN is a valódi, konkrét üzenetnek kell megjelennie — nem egy generikus "váratlan szerverhiba"-nak.'
        );
    }
}
