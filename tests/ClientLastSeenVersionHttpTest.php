<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Fázis 2, Checkpoint 4 — a Kliens SAJÁT AppVersion::CURRENT-je (lásd
 * ClientProxy::buildOutboundHeaders() X-Client-App-Version fejléce) VALÓDI,
 * teljes Kliens<->Szerver HTTP-láncon keresztül landol a Szerver
 * registered_clients.last_seen_version oszlopában — ez a fájl a valódi
 * ClientProxy-t (NEM kézzel írt HMAC-fejléceket) használja a Kliens
 * oldalán, ellentétben tests/ClientHttpAuthTest.php-vel, ami szándékosan a
 * fejlécek KÉZI felépítését teszteli.
 */
final class ClientLastSeenVersionHttpTest extends TestCase
{
    private static string $serverRoot;
    private static int $serverPort;
    /** @var resource */
    private static $serverProcess;

    private static string $clientRoot;
    private static int $clientPort;
    /** @var resource */
    private static $clientProcess;

    private static array $client;

    public static function setUpBeforeClass(): void
    {
        $projectRoot = dirname(__DIR__);

        self::$serverRoot = sys_get_temp_dir() . '/sm_lastseenver_server_' . bin2hex(random_bytes(6));
        self::copyDir($projectRoot . '/webroot', self::$serverRoot . '/webroot', ['vendor', 'products', 'backups']);
        self::copyDir($projectRoot . '/src', self::$serverRoot . '/src', []);
        copy($projectRoot . '/schema.sql', self::$serverRoot . '/schema.sql');
        mkdir(self::$serverRoot . '/config', 0775, true);
        copy($projectRoot . '/config/config.php', self::$serverRoot . '/config/config.php');
        file_put_contents(self::$serverRoot . '/config/installer-generated.php', '<?php return ' . var_export([
            'shop' => ['name' => 'X', 'address' => 'X'],
            'db' => ['driver' => 'sqlite', 'sqlite' => ['path' => self::$serverRoot . '/data/stock.sqlite'], 'mysql' => []],
            'node_role' => 'server',
        ], true) . ';');
        mkdir(self::$serverRoot . '/data', 0775, true);

        self::$serverPort = self::findFreePort();
        self::$serverProcess = proc_open(
            [PHP_BINARY, '-S', '127.0.0.1:' . self::$serverPort, '-t', self::$serverRoot . '/webroot'],
            [1 => ['file', self::$serverRoot . '/server.log', 'w'], 2 => ['file', self::$serverRoot . '/server.log', 'w']],
            $pipes
        );
        self::waitForServerReady(self::$serverPort);
        self::request(self::$serverPort, 'GET', '/api/auth-status.php'); // DB inicializálás

        $db = new Database(['driver' => 'sqlite', 'sqlite' => ['path' => self::$serverRoot . '/data/stock.sqlite']], self::$serverRoot);
        self::$client = $db->registerClient('HTTP teszt — last_seen_version');

        self::$clientRoot = sys_get_temp_dir() . '/sm_lastseenver_client_' . bin2hex(random_bytes(6));
        self::copyDir($projectRoot . '/webroot', self::$clientRoot . '/webroot', ['vendor', 'products', 'backups']);
        self::copyDir($projectRoot . '/src', self::$clientRoot . '/src', []);
        mkdir(self::$clientRoot . '/config', 0775, true);
        copy($projectRoot . '/config/config.php', self::$clientRoot . '/config/config.php');
        file_put_contents(self::$clientRoot . '/config/installer-generated.php', '<?php return ' . var_export([
            'shop' => ['name' => 'X', 'address' => 'X'],
            'db' => ['driver' => 'sqlite', 'sqlite' => ['path' => 'unused'], 'mysql' => []],
            'node_role' => 'client',
            'client' => ['server_url' => 'http://127.0.0.1:' . self::$serverPort, 'client_id' => self::$client['client_id'], 'client_secret' => self::$client['client_secret']],
        ], true) . ';');

        self::$clientPort = self::findFreePort();
        self::$clientProcess = proc_open(
            [PHP_BINARY, '-S', '127.0.0.1:' . self::$clientPort, '-t', self::$clientRoot . '/webroot'],
            [1 => ['file', self::$clientRoot . '/client.log', 'w'], 2 => ['file', self::$clientRoot . '/client.log', 'w']],
            $pipes2
        );
        self::waitForServerReady(self::$clientPort);
    }

    public static function tearDownAfterClass(): void
    {
        foreach ([self::$serverProcess, self::$clientProcess] as $p) {
            if (is_resource($p)) {
                proc_terminate($p);
                proc_close($p);
            }
        }
        self::removeDir(self::$serverRoot);
        self::removeDir(self::$clientRoot);
        self::removeDir(sys_get_temp_dir() . '/stockmanager-client-health');
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
            is_dir($srcPath) ? self::copyDir($srcPath, $dstPath, $excludeDirNames) : copy($srcPath, $dstPath);
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
            is_dir($path) && !is_link($path) ? self::removeDir($path) : @unlink($path);
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

    private static function waitForServerReady(int $port): void
    {
        $deadline = microtime(true) + 10;
        while (microtime(true) < $deadline) {
            $fp = @fsockopen('127.0.0.1', $port, $errno, $errstr, 0.2);
            if ($fp) {
                fclose($fp);
                return;
            }
            usleep(100_000);
        }
        self::fail('A teszt-webszerver nem indult el időben.');
    }

    private static function request(int $port, string $method, string $path): array
    {
        $ch = curl_init('http://127.0.0.1:' . $port . $path);
        curl_setopt_array($ch, [CURLOPT_CUSTOMREQUEST => $method, CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 10]);
        $body = curl_exec($ch);
        $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        $json = json_decode((string) $body, true);
        return ['status' => $status, 'json' => is_array($json) ? $json : null, 'body' => $body];
    }

    public function testRealProxiedRequestRecordsTheClientsOwnAppVersionOnTheServer(): void
    {
        // Induláskor még nincs last_seen_version (a kliens sose jelentkezett).
        $db = new Database(['driver' => 'sqlite', 'sqlite' => ['path' => self::$serverRoot . '/data/stock.sqlite']], self::$serverRoot);
        $before = $db->findRegisteredClientById((int) self::$client['id']);
        $this->assertNull($before['last_seen_version']);

        // Valódi Kliens -> ClientProxy -> Szerver kérés (auth-status.php nem
        // igényel dolgozói munkamenetet) — ez a VALÓDI ClientProxy-t
        // futtatja, ami a X-Client-App-Version fejlécet automatikusan
        // hozzáadja (lásd buildOutboundHeaders()).
        $res = self::request(self::$clientPort, 'GET', '/api/auth-status.php');
        $this->assertSame(200, $res['status'], $res['body']);

        $after = $db->findRegisteredClientById((int) self::$client['id']);
        $this->assertSame(AppVersion::CURRENT, $after['last_seen_version'], 'A Szervernek a Kliens SAJÁT (proxyzott kérésben küldött) verzióját kell eltárolnia.');
        $this->assertNotNull($after['last_seen_at']);
    }

    public function testLastSeenVersionAppearsInTheAdminClientsListResponse(): void
    {
        $jarPath = sys_get_temp_dir() . '/sm_lastseenver_admin_' . bin2hex(random_bytes(6)) . '.txt';
        $status = $this->requestWithJar('GET', '/api/auth-status.php', null, [], $jarPath);
        $csrf = $status['json']['csrf_token'];
        $this->requestWithJar('POST', '/api/security-settings-save.php', [
            'app_password_enabled' => true, 'new_password' => 'lastseenver-teszt-jelszo', 'new_password_confirm' => 'lastseenver-teszt-jelszo',
        ], ['X-CSRF-Token' => $csrf], $jarPath);
        $this->requestWithJar('POST', '/api/login.php', ['password' => 'lastseenver-teszt-jelszo'], [], $jarPath);

        $res = $this->requestWithJar('GET', '/api/clients-list.php', null, [], $jarPath);
        $this->assertSame(200, $res['status'], $res['body']);
        $found = null;
        foreach ($res['json']['clients'] as $c) {
            if ($c['id'] === self::$client['id']) {
                $found = $c;
            }
        }
        $this->assertNotNull($found);
        $this->assertSame(AppVersion::CURRENT, $found['last_seen_version']);
        $this->assertArrayNotHasKey('secret_hash', $found);
    }

    private function requestWithJar(string $method, string $path, $body, array $headers, string $jarPath): array
    {
        $ch = curl_init('http://127.0.0.1:' . self::$serverPort . $path);
        $hdrLines = [];
        foreach ($headers as $k => $v) {
            $hdrLines[] = "$k: $v";
        }
        curl_setopt_array($ch, [
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_COOKIEJAR => $jarPath,
            CURLOPT_COOKIEFILE => $jarPath,
            CURLOPT_TIMEOUT => 10,
        ]);
        if ($body !== null) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body));
            $hdrLines[] = 'Content-Type: application/json';
        }
        curl_setopt($ch, CURLOPT_HTTPHEADER, $hdrLines);
        $raw = curl_exec($ch);
        $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        $json = json_decode((string) $raw, true);
        return ['status' => $status, 'json' => is_array($json) ? $json : null, 'body' => $raw];
    }
}
