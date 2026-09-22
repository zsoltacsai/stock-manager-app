<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * ClientProxy valódi, HTTP-szintű, két-folyamatos bizonyítása — egy "Szerver"
 * (minimális, kontrollált teszt-fixture) és egy "Kliens" (a valódi webroot/
 * src egy másolata, node_role='client'-tel), mindkettő tényleges `php -S`
 * folyamatként. Ugyanaz a teljesen önálló, ideiglenes-másolatban futó minta,
 * mint tests/InvoiceEndpointsHttpTest.php — KÜLÖN példány, hogy ne zavarja
 * meg egyetlen másik teszt-szerver folyamatát sem. Ebben a körben SZÁNDÉKOSAN
 * nincs HMAC/CSRF-fejléc a kérésekben — a szállítási réteget bizonyítja,
 * nem a (később készülő) hitelesítést.
 */
final class ClientProxyHttpTest extends TestCase
{
    private static string $serverRoot;
    private static string $clientRoot;
    private static int $serverPort;
    private static int $clientPort;
    /** @var resource|null */
    private static $serverProcess;
    /** @var resource|null */
    private static $clientProcess;
    private static string $clientBaseUrl;

    public static function setUpBeforeClass(): void
    {
        $projectRoot = dirname(__DIR__);

        // --- "Szerver" oldal: minimális, kontrollált fixture — NEM a valódi app. ---
        self::$serverRoot = sys_get_temp_dir() . '/sm_proxy_server_' . bin2hex(random_bytes(6));
        mkdir(self::$serverRoot . '/api', 0775, true);
        file_put_contents(self::$serverRoot . '/api/_test-fixture.php', self::fixtureSource());

        self::$serverPort = self::findFreePort();
        self::$serverProcess = proc_open(
            [PHP_BINARY, '-S', '127.0.0.1:' . self::$serverPort, '-t', self::$serverRoot],
            [1 => ['file', self::$serverRoot . '/server.log', 'w'], 2 => ['file', self::$serverRoot . '/server.log', 'w']],
            $pipes,
            self::$serverRoot
        );
        if (self::$serverProcess === false) {
            self::fail('Nem sikerült elindítani a teszt-"Szerver" php -S folyamatot.');
        }
        self::waitForServerReady(self::$serverPort);

        // --- "Kliens" oldal: a valódi webroot/src másolata, node_role='client'. ---
        self::$clientRoot = sys_get_temp_dir() . '/sm_proxy_client_' . bin2hex(random_bytes(6));
        self::copyDir($projectRoot . '/webroot', self::$clientRoot . '/webroot', ['vendor', 'products', 'backups']);
        self::copyDir($projectRoot . '/src', self::$clientRoot . '/src', []);
        mkdir(self::$clientRoot . '/config', 0775, true);
        copy($projectRoot . '/config/config.php', self::$clientRoot . '/config/config.php');
        file_put_contents(
            self::$clientRoot . '/config/installer-generated.php',
            '<?php return ' . var_export([
                'shop' => ['name' => 'Proxy teszt', 'address' => 'X'],
                'db'   => ['driver' => 'sqlite', 'sqlite' => ['path' => 'unused'], 'mysql' => []],
                'node_role' => 'client',
                'client' => ['server_url' => 'http://127.0.0.1:' . self::$serverPort, 'client_id' => '', 'client_secret' => ''],
            ], true) . ';'
        );
        // A routing-hoz kell, hogy a fájl FIZIKAILAG létezzen a Kliens saját
        // webroot-jában is — a tartalma a valódi végpont-fájlok pontos
        // mintája (require _bootstrap.php, semmi más), a ClientProxy-ágat
        // maga a _bootstrap.php futtatja le.
        file_put_contents(self::$clientRoot . '/webroot/api/_test-fixture.php', "<?php\ndeclare(strict_types=1);\nrequire __DIR__ . '/_bootstrap.php';\n");

        self::$clientPort = self::findFreePort();
        self::$clientBaseUrl = 'http://127.0.0.1:' . self::$clientPort;
        self::$clientProcess = proc_open(
            [PHP_BINARY, '-S', '127.0.0.1:' . self::$clientPort, '-t', self::$clientRoot . '/webroot'],
            [1 => ['file', self::$clientRoot . '/client.log', 'w'], 2 => ['file', self::$clientRoot . '/client.log', 'w']],
            $pipes2,
            self::$clientRoot
        );
        if (self::$clientProcess === false) {
            self::fail('Nem sikerült elindítani a teszt-"Kliens" php -S folyamatot.');
        }
        self::waitForServerReady(self::$clientPort);
    }

    public static function tearDownAfterClass(): void
    {
        foreach ([self::$serverProcess, self::$clientProcess] as $p) {
            if ($p !== null && is_resource($p)) {
                proc_terminate($p);
                proc_close($p);
            }
        }
        self::removeDir(self::$serverRoot);
        self::removeDir(self::$clientRoot);
    }

    private static function fixtureSource(): string
    {
        return <<<'PHP'
            <?php
            declare(strict_types=1);
            $mode = $_GET['mode'] ?? 'json';

            if ($mode === 'json') {
                header('Content-Type: application/json; charset=utf-8');
                echo json_encode([
                    'method' => $_SERVER['REQUEST_METHOD'],
                    'query'  => $_GET,
                    'body'   => file_get_contents('php://input'),
                    'content_type' => $_SERVER['CONTENT_TYPE'] ?? null,
                    'got_host' => $_SERVER['HTTP_HOST'] ?? null,
                ], JSON_UNESCAPED_UNICODE);
                exit;
            }

            if ($mode === 'binary') {
                header('Content-Type: image/png');
                header('Content-Disposition: attachment; filename="test.png"');
                echo "\x89PNG\r\n\x1a\n" . random_bytes(64);
                exit;
            }

            if ($mode === 'cookie') {
                header('Content-Type: application/json; charset=utf-8');
                header('Set-Cookie: server_side_secret=should-never-reach-browser; Path=/; HttpOnly');
                echo json_encode(['ok' => true]);
                exit;
            }

            http_response_code(404);
            echo json_encode(['error' => 'unknown mode']);
            PHP;
    }

    // -- Segédfüggvények (InvoiceEndpointsHttpTest.php pontos mintája) --

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

    private static function request(string $method, string $url, ?string $body = null, array $extraHeaders = []): array
    {
        $ch = curl_init($url);
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
        if ($body !== null) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
        }
        $raw = curl_exec($ch);
        if ($raw === false) {
            self::fail('curl hiba: ' . curl_error($ch));
        }
        $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $headerSize = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
        curl_close($ch);

        $rawHeaders = substr($raw, 0, $headerSize);
        $rawBody = substr($raw, $headerSize);
        $respHeaders = [];
        foreach (explode("\r\n", $rawHeaders) as $line) {
            if (str_contains($line, ':')) {
                [$k, $v] = explode(':', $line, 2);
                $respHeaders[strtolower(trim($k))] = trim($v);
            }
        }
        return ['status' => $status, 'headers' => $respHeaders, 'body' => $rawBody];
    }

    // -----------------------------------------------------------------
    // Valódi kérés/válasz test-átlátszóság a teljes proxy-láncon keresztül
    // -----------------------------------------------------------------

    public function testJsonGetRequestQueryStringPassesThrough(): void
    {
        $res = self::request('GET', self::$clientBaseUrl . '/api/_test-fixture.php?mode=json&foo=bar&baz=qux');
        $this->assertSame(200, $res['status']);
        $data = json_decode($res['body'], true);
        $this->assertSame('GET', $data['method']);
        $this->assertSame(['mode' => 'json', 'foo' => 'bar', 'baz' => 'qux'], $data['query']);
    }

    public function testJsonPostBodyAndContentTypePassThroughUnchanged(): void
    {
        $payload = json_encode(['a' => 1, 'ékezet' => 'próba']);
        $res = self::request('POST', self::$clientBaseUrl . '/api/_test-fixture.php?mode=json', $payload, ['Content-Type' => 'application/json']);
        $this->assertSame(200, $res['status']);
        $data = json_decode($res['body'], true);
        $this->assertSame($payload, $data['body'], 'A POST törzsnek byte-pontosan változatlanul kell megérkeznie a Szerverre.');
        $this->assertSame('application/json', $data['content_type']);
    }

    public function testHostHeaderSeenByServerIsTheServerItselfNotTheClient(): void
    {
        // A Kliens saját (böngésző felé mutatott) Host fejlécét SOSE
        // szabad vakon átküldeni — a Szerver a SAJÁT host:port-ját kell,
        // hogy lássa (127.0.0.1:$serverPort), sose a Kliens localhost:
        // $clientPort-ját.
        $res = self::request('GET', self::$clientBaseUrl . '/api/_test-fixture.php?mode=json');
        $data = json_decode($res['body'], true);
        $this->assertSame('127.0.0.1:' . self::$serverPort, $data['got_host']);
    }

    public function testBinaryResponseBytesAndContentDispositionPreserved(): void
    {
        $res = self::request('GET', self::$clientBaseUrl . '/api/_test-fixture.php?mode=binary');
        $this->assertSame(200, $res['status']);
        $this->assertSame('image/png', $res['headers']['content-type']);
        $this->assertSame('attachment; filename="test.png"', $res['headers']['content-disposition']);
        $this->assertStringStartsWith("\x89PNG\r\n\x1a\n", $res['body']);
        $this->assertSame(64 + 8, strlen($res['body']), 'A bináris törzsnek byte-pontosan változatlanul kell átérnie.');
    }

    public function testServerSetCookieNeverReachesTheBrowserResponse(): void
    {
        $res = self::request('GET', self::$clientBaseUrl . '/api/_test-fixture.php?mode=cookie');
        $this->assertSame(200, $res['status']);
        $this->assertArrayNotHasKey('set-cookie', $res['headers'], 'A Szerver saját Set-Cookie fejléce sose juthat el a böngészőig.');
    }

    public function testUnreachableServerProducesGracefulServerUnavailableResponse(): void
    {
        // Egy MÁSODIK, saját Kliens-másolatot indítunk, aminek a
        // server_url-je egy garantáltan zárt portra mutat — a valódi
        // "Szerver nem elérhető" hibaágat bizonyítja, nem csak a boldog utat.
        $deadClientRoot = sys_get_temp_dir() . '/sm_proxy_deadclient_' . bin2hex(random_bytes(6));
        self::copyDir(dirname(__DIR__) . '/webroot', $deadClientRoot . '/webroot', ['vendor', 'products', 'backups']);
        self::copyDir(dirname(__DIR__) . '/src', $deadClientRoot . '/src', []);
        mkdir($deadClientRoot . '/config', 0775, true);
        copy(dirname(__DIR__) . '/config/config.php', $deadClientRoot . '/config/config.php');
        $deadPort = self::findFreePort(); // szabad port, amin NEM fut semmi
        file_put_contents(
            $deadClientRoot . '/config/installer-generated.php',
            '<?php return ' . var_export([
                'shop' => ['name' => 'X', 'address' => 'X'],
                'db'   => ['driver' => 'sqlite', 'sqlite' => ['path' => 'unused'], 'mysql' => []],
                'node_role' => 'client',
                'client' => ['server_url' => 'http://127.0.0.1:' . $deadPort, 'client_id' => '', 'client_secret' => ''],
            ], true) . ';'
        );
        file_put_contents($deadClientRoot . '/webroot/api/_test-fixture.php', "<?php\ndeclare(strict_types=1);\nrequire __DIR__ . '/_bootstrap.php';\n");

        $port = self::findFreePort();
        $proc = proc_open(
            [PHP_BINARY, '-S', '127.0.0.1:' . $port, '-t', $deadClientRoot . '/webroot'],
            [1 => ['file', $deadClientRoot . '/log', 'w'], 2 => ['file', $deadClientRoot . '/log', 'w']],
            $pipes,
            $deadClientRoot
        );
        self::waitForServerReady($port);

        try {
            $res = self::request('GET', 'http://127.0.0.1:' . $port . '/api/_test-fixture.php?mode=json');
            $this->assertSame(503, $res['status']);
            $data = json_decode($res['body'], true);
            $this->assertTrue($data['server_unreachable'] ?? false);
            $this->assertSame('Szerver nem elérhető.', $data['error']);
        } finally {
            if (is_resource($proc)) {
                proc_terminate($proc);
                proc_close($proc);
            }
            self::removeDir($deadClientRoot);
        }
    }
}
