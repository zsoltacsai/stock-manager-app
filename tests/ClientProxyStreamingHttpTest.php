<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Fázis 9, kör 8/26. pontja — `ClientProxy::forwardStreaming()` valódi,
 * két-folyamatos, HTTP-szintű bizonyítása: egy "Szerver" (minimális,
 * kontrollált SSE-fixture, `ai-agent-stream.php` néven — a ClientProxy
 * SZÁNDÉKOSAN fájlnév-fehérlista alapján dönt a streamelő útvonalról, lásd
 * `ClientProxy::STREAMING_SCRIPTS`) és egy "Kliens" (a valódi webroot/src
 * másolata, `node_role='client'`), mindkettő tényleges `php -S` folyamatként
 * — pontosan a tests/ClientProxyHttpTest.php mintája, KÜLÖN folyamat-
 * példányokkal, hogy ne zavarjon egyetlen másik teszt-szervert se.
 *
 * A LEGFONTOSABB bizonyítandó tulajdonság: a Kliens NEM pufferli a teljes
 * Szerver-választ, mielőtt bármit visszaküldene a böngészőnek (ami a RÉGI,
 * `CURLOPT_RETURNTRANSFER => true`-s forward()-dal történt volna) — ezt
 * IDŐZÍTÉSSEL bizonyítjuk: a szerver-fixture két, mesterséges késleltetéssel
 * elválasztott SSE-eseményt küld, és a teszt megméri, hogy az ELSŐ esemény a
 * böngésző-oldali curl-hez majdnem azonnal, a MÁSODIK pedig csak a
 * késleltetés UTÁN érkezik-e meg — két teljes hop-on (teszt→Kliens→Szerver)
 * keresztül.
 */
final class ClientProxyStreamingHttpTest extends TestCase
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

    private const CHUNK_DELAY_SECONDS = 0.3;

    public static function setUpBeforeClass(): void
    {
        $projectRoot = dirname(__DIR__);

        // --- "Szerver" oldal: minimális, kontrollált SSE-fixture — NEM a
        // valódi ai-agent-stream.php (annak admin/DB/agent-függőségei
        // lennének, ez a kör KIZÁRÓLAG a szállítási réteget bizonyítja,
        // lásd ClientProxyHttpTest.php azonos indoklását). A server-ping.php
        // VALÓDI másolata kell, mert ClientProxy::forward()/forwardStreaming()
        // MINDEN továbbítás előtt health/verzió-ellenőrzést végez. ---
        self::$serverRoot = sys_get_temp_dir() . '/sm_proxy_stream_server_' . bin2hex(random_bytes(6));
        mkdir(self::$serverRoot . '/webroot/api', 0775, true);
        file_put_contents(self::$serverRoot . '/webroot/api/ai-agent-stream.php', self::fixtureSource());
        copy($projectRoot . '/webroot/api/server-ping.php', self::$serverRoot . '/webroot/api/server-ping.php');
        mkdir(self::$serverRoot . '/src', 0775, true);
        copy($projectRoot . '/src/AppVersion.php', self::$serverRoot . '/src/AppVersion.php');

        self::$serverPort = self::findFreePort();
        self::$serverProcess = proc_open(
            [PHP_BINARY, '-S', '127.0.0.1:' . self::$serverPort, '-t', self::$serverRoot . '/webroot'],
            [1 => ['file', self::$serverRoot . '/server.log', 'w'], 2 => ['file', self::$serverRoot . '/server.log', 'w']],
            $pipes,
            self::$serverRoot
        );
        if (self::$serverProcess === false) {
            self::fail('Nem sikerült elindítani a teszt-"Szerver" php -S folyamatot.');
        }
        self::waitForServerReady(self::$serverPort);

        // --- "Kliens" oldal: a valódi webroot/src másolata, node_role='client'. ---
        self::$clientRoot = sys_get_temp_dir() . '/sm_proxy_stream_client_' . bin2hex(random_bytes(6));
        self::copyDir($projectRoot . '/webroot', self::$clientRoot . '/webroot', ['vendor', 'products', 'backups']);
        self::copyDir($projectRoot . '/src', self::$clientRoot . '/src', []);
        mkdir(self::$clientRoot . '/config', 0775, true);
        copy($projectRoot . '/config/config.php', self::$clientRoot . '/config/config.php');
        file_put_contents(
            self::$clientRoot . '/config/installer-generated.php',
            '<?php return ' . var_export([
                'shop' => ['name' => 'Proxy stream teszt', 'address' => 'X'],
                'db'   => ['driver' => 'sqlite', 'sqlite' => ['path' => 'unused'], 'mysql' => []],
                'node_role' => 'client',
                'client' => ['server_url' => 'http://127.0.0.1:' . self::$serverPort, 'client_id' => '', 'client_secret' => ''],
            ], true) . ';'
        );
        // A Kliens saját webroot-jában is FIZIKAILAG léteznie kell az
        // ai-agent-stream.php fájlnak — a ClientProxy a KÉRÉS SCRIPT_NAME-jét
        // (ami ehhez a fizikailag létező fájlhoz tartozik) nézi a
        // STREAMING_SCRIPTS fehérlistával szemben, a tényleges tartalma
        // (csak a _bootstrap.php-t hívja) irreleváns.
        file_put_contents(self::$clientRoot . '/webroot/api/ai-agent-stream.php', "<?php\ndeclare(strict_types=1);\nrequire __DIR__ . '/_bootstrap.php';\n");

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
        $delayMicros = (int) (self::CHUNK_DELAY_SECONDS * 1_000_000);
        return <<<PHP
            <?php
            declare(strict_types=1);
            \$mode = \$_GET['mode'] ?? 'stream';

            if (\$mode === 'unauthorized') {
                http_response_code(401);
                header('Content-Type: application/json; charset=utf-8');
                echo json_encode(['error' => 'Hitelesítés sikertelen.'], JSON_UNESCAPED_UNICODE);
                exit;
            }

            header('Content-Type: text/event-stream; charset=utf-8');
            header('Cache-Control: no-cache');
            header('X-Accel-Buffering: no');
            while (ob_get_level() > 0) { @ob_end_flush(); }

            echo 'data: ' . json_encode(['type' => 'agent_started', 'payload' => ['agent' => 'copilot']]) . "\\n\\n";
            @flush();
            usleep($delayMicros);
            echo 'data: ' . json_encode(['type' => 'text_delta', 'payload' => ['text' => 'Szia']]) . "\\n\\n";
            @flush();
            usleep($delayMicros);
            echo 'data: ' . json_encode(['type' => 'done', 'payload' => ['success' => true]]) . "\\n\\n";
            @flush();
            PHP;
    }

    // -- Segédfüggvények (ClientProxyHttpTest.php pontos mintája) --

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

    /**
     * `CURLOPT_WRITEFUNCTION`-alapú kliens — DARABONKÉNT rögzíti, mikor
     * (a hívás kezdetéhez képest hány másodperccel) érkezett meg minden
     * egyes darab, hogy a progresszív (nem pufferelt) szállítás
     * IDŐZÍTÉSSEL is bizonyítható legyen, nem csak a végleges tartalommal.
     *
     * @return array{status:int, body:string, chunk_times:float[], headers:array<string,string>}
     */
    private static function streamingRequest(string $url): array
    {
        $chunks = [];
        $timestamps = [];
        $headerLines = [];
        $start = microtime(true);

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_CUSTOMREQUEST => 'GET',
            CURLOPT_RETURNTRANSFER => false,
            CURLOPT_HEADER => false,
            CURLOPT_TIMEOUT => 10,
            CURLOPT_HEADERFUNCTION => function ($curlHandle, string $line) use (&$headerLines): int {
                $trimmed = trim($line);
                if ($trimmed !== '' && str_contains($trimmed, ':')) {
                    [$k, $v] = explode(':', $trimmed, 2);
                    $headerLines[strtolower(trim($k))] = trim($v);
                }
                return strlen($line);
            },
            CURLOPT_WRITEFUNCTION => function ($curlHandle, string $chunk) use (&$chunks, &$timestamps, $start): int {
                $chunks[] = $chunk;
                $timestamps[] = microtime(true) - $start;
                return strlen($chunk);
            },
        ]);
        curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        return ['status' => $status, 'body' => implode('', $chunks), 'chunk_times' => $timestamps, 'headers' => $headerLines];
    }

    // -----------------------------------------------------------------
    // A tényleges bizonyítás
    // -----------------------------------------------------------------

    public function testStreamedResponseArrivesProgressivelyThroughBothHopsNotBuffered(): void
    {
        $res = self::streamingRequest(self::$clientBaseUrl . '/api/ai-agent-stream.php');

        $this->assertSame(200, $res['status']);
        $this->assertGreaterThanOrEqual(
            2,
            count($res['chunk_times']),
            'Legalább 2 külön darabban kellett megérkeznie a válasznak — egyetlen darab azt jelentené, hogy a Kliens a teljes választ pufferelte, mielőtt bármit visszaküldött volna.'
        );

        $firstArrival = $res['chunk_times'][0];
        $lastArrival = end($res['chunk_times']);

        $this->assertLessThan(
            self::CHUNK_DELAY_SECONDS,
            $firstArrival,
            'Az ELSŐ SSE-eseménynek a szerver-oldali késleltetés ELŐTT meg kellett érkeznie — ez bizonyítja, hogy a Kliens NEM várja meg a teljes Szerver-választ, mielőtt elkezdene relézni.'
        );
        $this->assertGreaterThan(
            self::CHUNK_DELAY_SECONDS * 1.5,
            $lastArrival,
            'Az UTOLSÓ darabnak a két mesterséges szerver-oldali késleltetés UTÁN kellett megérkeznie — ha a Kliens pufferelt volna, az első és utolsó darab érkezési ideje gyakorlatilag megegyezne.'
        );
    }

    public function testStreamedResponseBodyAndSseHeadersFullyPreserved(): void
    {
        $res = self::streamingRequest(self::$clientBaseUrl . '/api/ai-agent-stream.php');

        $this->assertSame(200, $res['status']);
        $this->assertSame('text/event-stream; charset=utf-8', $res['headers']['content-type'] ?? null);
        $this->assertSame('no-cache', $res['headers']['cache-control'] ?? null);
        $this->assertSame('no', $res['headers']['x-accel-buffering'] ?? null);

        $frames = array_values(array_filter(explode("\n\n", trim($res['body']))));
        $this->assertCount(3, $frames, 'A 3 SSE-eseménynek (agent_started/text_delta/done) hiánytalanul, sorrendhelyesen kell átérnie a két hop-on keresztül.');

        $decoded = array_map(static function (string $frame): array {
            return json_decode(trim(substr($frame, strlen('data:'))), true);
        }, $frames);

        $this->assertSame('agent_started', $decoded[0]['type']);
        $this->assertSame('text_delta', $decoded[1]['type']);
        $this->assertSame('Szia', $decoded[1]['payload']['text']);
        $this->assertSame('done', $decoded[2]['type']);
        $this->assertTrue($decoded[2]['payload']['success']);
    }

    public function testMachineAuthRejectionStatusAndBodyRelayedThroughStreamingPath(): void
    {
        $res = self::streamingRequest(self::$clientBaseUrl . '/api/ai-agent-stream.php?mode=unauthorized');

        $this->assertSame(401, $res['status']);
        $this->assertStringContainsString('Hitelesítés sikertelen.', $res['body']);
        $this->assertSame(
            1,
            count($res['chunk_times']),
            'Egy rövid, nem-streamelt hibaválasznak EGYETLEN darabban kell megérkeznie (a forwardStreaming() puffereli, amíg biztosan nem streamelt válaszról van szó).'
        );
    }
}
