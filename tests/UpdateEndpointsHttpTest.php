<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * HTTP-szintű tesztek az önfrissítő rendszer végpontjaira — a
 * tests/PrinterEmailEndpointsHttpTest.php pontos mintája (önálló, teljesen
 * elkülönített PHP beépített-szerveren futó másolat). SZÁNDÉKOSAN NEM hív
 * valódi GitHub API-t: az auth/CSRF/admin-gate/method HATÁROKAT teszteli
 * (amik a GitHubReleaseClient-hívás ELŐTT elbuknak, ha elbuknak) — magának
 * a teljes letöltés→ellenőrzés→telepítés folyamatnak a valódi (hálózat
 * nélküli, fixture-alapú) bizonyítása a tests/UpdateInstallerTest.php-ban
 * található.
 */
final class UpdateEndpointsHttpTest extends TestCase
{
    private static string $root;
    private static int $port;
    /** @var resource|null */
    private static $serverProcess;
    private static string $baseUrl;
    private static string $loggedInJar;

    public static function setUpBeforeClass(): void
    {
        self::$root = sys_get_temp_dir() . '/ft_update_http_test_' . bin2hex(random_bytes(6));
        mkdir(self::$root, 0775, true);

        $projectRoot = dirname(__DIR__);
        self::copyDir($projectRoot . '/webroot', self::$root . '/webroot', ['products', 'backups']);
        self::copyDir($projectRoot . '/src', self::$root . '/src', []);
        if (is_dir($projectRoot . '/vendor')) {
            self::copyDir($projectRoot . '/vendor', self::$root . '/vendor', []);
        }
        copy($projectRoot . '/schema.sql', self::$root . '/schema.sql');
        copy($projectRoot . '/schema.mysql.sql', self::$root . '/schema.mysql.sql');
        mkdir(self::$root . '/config', 0775, true);
        copy($projectRoot . '/config/config.php', self::$root . '/config/config.php');
        mkdir(self::$root . '/data', 0775, true);
        mkdir(self::$root . '/tools', 0775, true);
        copy($projectRoot . '/tools/update-post-deploy-check.php', self::$root . '/tools/update-post-deploy-check.php');

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

        // A "requires authentication" tesztek csak akkor értelmesek, ha a
        // jelszavas védelem TÉNYLEGESEN be van kapcsolva — alap (jelszó
        // nélküli, "local" üzemmódú) telepítésen MINDENKI bejelentkezettnek
        // számít, lásd Auth::isLoggedIn() — pontosan a
        // PrinterEmailEndpointsHttpTest::setUpBeforeClass() mintája.
        self::request('GET', '/api/auth-status.php');
        $setupJar = self::cookieJar('setup');
        $status = self::request('GET', '/api/auth-status.php', null, [], $setupJar);
        $csrf = $status['json']['csrf_token'];
        self::request('POST', '/api/security-settings-save.php', [
            'app_password_enabled' => true,
            'new_password' => 'ft-update-http-teszt-jelszo',
            'new_password_confirm' => 'ft-update-http-teszt-jelszo',
        ], ['X-CSRF-Token' => $csrf], $setupJar);

        self::$loggedInJar = self::cookieJar('logged-in');
        self::request('POST', '/api/login.php', ['password' => 'ft-update-http-teszt-jelszo'], [], self::$loggedInJar);
    }

    public static function tearDownAfterClass(): void
    {
        if (self::$serverProcess !== null && is_resource(self::$serverProcess)) {
            proc_terminate(self::$serverProcess);
            proc_close(self::$serverProcess);
        }
        self::removeDir(self::$root);
    }

    // ---- Bejelentkezés nélkül ----

    public function testUpdateStatusRequiresAuthentication(): void
    {
        $res = self::request('GET', '/api/update-status.php');
        $this->assertSame(401, $res['status']);
    }

    public function testUpdateCheckRequiresAuthentication(): void
    {
        $res = self::request('POST', '/api/update-check.php');
        $this->assertSame(401, $res['status']);
    }

    public function testUpdateInstallRequiresAuthentication(): void
    {
        $res = self::request('POST', '/api/update-install.php');
        $this->assertSame(401, $res['status']);
    }

    public function testUpdateHistoryRequiresAuthentication(): void
    {
        $res = self::request('GET', '/api/update-history.php');
        $this->assertSame(401, $res['status']);
    }

    // ---- Cron-hitelesítés ----

    public function testUpdateCheckRunRejectsMissingCronToken(): void
    {
        $res = self::request('GET', '/api/update-check-run.php');
        $this->assertSame(401, $res['status']);
    }

    public function testUpdateCheckRunRejectsWrongCronToken(): void
    {
        $res = self::request('GET', '/api/update-check-run.php', null, ['X-Cron-Token' => 'wrong-token']);
        $this->assertSame(401, $res['status']);
    }

    // ---- Módszer-ellenőrzés ----

    public function testUpdateCheckRejectsGetMethod(): void
    {
        $jar = $this->loggedInJar();
        $res = self::request('GET', '/api/update-check.php', null, [], $jar);
        $this->assertSame(405, $res['status']);
    }

    public function testUpdateInstallRejectsGetMethod(): void
    {
        $jar = $this->loggedInJar();
        $res = self::request('GET', '/api/update-install.php', null, [], $jar);
        $this->assertSame(405, $res['status']);
    }

    // ---- CSRF ----

    public function testUpdateCheckRejectsMissingCsrfToken(): void
    {
        $jar = $this->loggedInJar();
        $res = self::request('POST', '/api/update-check.php', null, [], $jar);
        $this->assertSame(403, $res['status']);
        $this->assertTrue($res['json']['csrf_required'] ?? false);
    }

    public function testUpdateInstallRejectsMissingCsrfToken(): void
    {
        $jar = $this->loggedInJar();
        $res = self::request('POST', '/api/update-install.php', null, [], $jar);
        $this->assertSame(403, $res['status']);
    }

    // ---- Olvasó végpontok — sikeres, valódi (GitHub-hívás nélküli) válasz ----

    public function testUpdateStatusReturnsExpectedShapeWhenLoggedIn(): void
    {
        $jar = $this->loggedInJar();
        $res = self::request('GET', '/api/update-status.php', null, [], $jar);
        $this->assertSame(200, $res['status']);
        $this->assertSame('FountainTrade', $res['json']['product'] ?? null);
        $this->assertArrayHasKey('current_version', $res['json']);
        $this->assertArrayHasKey('state', $res['json']);
        $this->assertSame('idle', $res['json']['state']);
    }

    public function testUpdateHistoryReturnsEmptyListInitially(): void
    {
        $jar = $this->loggedInJar();
        $res = self::request('GET', '/api/update-history.php', null, [], $jar);
        $this->assertSame(200, $res['status']);
        $this->assertSame([], $res['json']['history']);
    }

    // ---- Karbantartási mód (12. pont) ----

    public function testMaintenanceModeBlocksNonAdminButNotAdmin(): void
    {
        $this->setUpAdminAndCashier();
        // setUpAdminAndCashier() a self::$loggedInJar session-t admin PIN-nel
        // is bejelentkezteti — ez lesz az "admin" oldal.
        $adminJar = self::$loggedInJar;

        $cashierJar = self::cookieJar('maintenance-cashier');
        $loginC = self::request('POST', '/api/login.php', ['password' => 'ft-update-http-teszt-jelszo'], [], $cashierJar);
        $staffLoginC = self::request('POST', '/api/staff-login.php', ['pin' => '90002'], ['X-CSRF-Token' => $loginC['json']['csrf_token']], $cashierJar);
        $this->assertSame('cashier', $staffLoginC['json']['staff']['role'] ?? null);

        // Karbantartási mód bekapcsolása közvetlenül a settings.json-ban —
        // SZÁNDÉKOSAN NEM a settings.php-n keresztül (az explicit NEM
        // fogadja el ezt a mezőt, lásd webroot/api/settings.php), hanem úgy,
        // ahogy azt ténylegesen csak az UpdateInstaller tenné.
        $settingsPath = self::$root . '/data/settings.json';
        $settings = json_decode(file_get_contents($settingsPath), true);
        $settings['maintenance_mode_active'] = true;
        $settings['maintenance_mode_message'] = 'A FountainTrade frissítése folyamatban van.';
        file_put_contents($settingsPath, json_encode($settings, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

        $cashierRes = self::request('GET', '/api/system-status.php', null, [], $cashierJar);
        $this->assertSame(503, $cashierRes['status'], 'Egy nem-admin session-nek karbantartási mód alatt minden nem-fehérlistás végpontot 503-mal kell elutasítania.');
        $this->assertTrue($cashierRes['json']['maintenance'] ?? false);

        $adminRes = self::request('GET', '/api/system-status.php', null, [], $adminJar);
        $this->assertSame(200, $adminRes['status'], 'Egy admin session-nek karbantartási mód alatt is változatlanul elérhetőnek kell maradnia.');

        $adminUpdateStatus = self::request('GET', '/api/update-status.php', null, [], $adminJar);
        $this->assertSame(200, $adminUpdateStatus['status'], 'Az update-status.php-nak admin számára karbantartási mód alatt is elérhetőnek kell maradnia.');

        // Takarítás — a settings.json-t visszaállítjuk, hogy ne szennyezze a további teszteket.
        $settings['maintenance_mode_active'] = false;
        file_put_contents($settingsPath, json_encode($settings, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
    }

    // -- Segédfüggvények --

    private static array $adminCashierSetUp = [];

    private function setUpAdminAndCashier(): void
    {
        if (self::$adminCashierSetUp) {
            return;
        }
        $status = self::request('GET', '/api/auth-status.php', null, [], self::$loggedInJar);
        $csrf = $status['json']['csrf_token'];

        $admin = self::request('POST', '/api/staff-save.php', ['name' => 'Admin', 'pin' => '90001', 'role' => 'admin'], ['X-CSRF-Token' => $csrf], self::$loggedInJar);
        $this->assertSame(200, $admin['status'], $admin['body']);
        $staffLogin = self::request('POST', '/api/staff-login.php', ['pin' => '90001'], ['X-CSRF-Token' => $csrf], self::$loggedInJar);
        $this->assertSame(200, $staffLogin['status'], $staffLogin['body']);

        $cashier = self::request('POST', '/api/staff-save.php', ['name' => 'Cashier', 'pin' => '90002', 'role' => 'cashier'], ['X-CSRF-Token' => $csrf], self::$loggedInJar);
        $this->assertSame(200, $cashier['status'], $cashier['body']);

        self::$adminCashierSetUp = ['done' => true];
    }

    private function loggedInJar(): string
    {
        return self::$loggedInJar;
    }

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
        }

        $raw = curl_exec($ch);
        if ($raw === false) {
            self::fail('curl hiba: ' . curl_error($ch));
        }
        $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $headerSize = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
        curl_close($ch);

        $body = substr($raw, $headerSize);
        $json = json_decode($body, true);

        return ['status' => $status, 'body' => $body, 'json' => is_array($json) ? $json : null];
    }
}
