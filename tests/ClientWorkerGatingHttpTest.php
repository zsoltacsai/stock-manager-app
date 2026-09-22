<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Fázis 2, Checkpoint 3 — worker/cron-végpontok topológia-kapuja, VALÓDI
 * HTTP-szinten bizonyítva. Három teljes app-másolat, `php -S` folyamatként:
 * egy "Kliens" node (node_role='client'), egy "Szerver" node
 * (node_role='server', VALÓDI cron_secret-tel) és egy "Önálló" node
 * (node_role alapértelmezett — meglévő 1.4.x telepítés-kompatibilitás).
 *
 * A cél annak bizonyítása, hogy:
 *   1) egy Kliens node a saját cron-végpontjait MINDIG elutasítja — MÉG
 *      egy (elméletileg) érvényes X-Cron-Token birtokában is —, és ez a
 *      döntés a ClientProxy-továbbítás ELŐTT dől el (a "Szerver" fixture
 *      SOSE kap ilyen kérést, lásd testClientNeverForwardsCronRequests);
 *   2) a Szerver/Önálló node-ok meglévő, token-alapú cron-viselkedése
 *      BIT-PONTOSAN változatlan (ezt a HttpSecurityTest.php már
 *      részletesen bizonyítja Standalone-ra — itt csak a topológia-kapu
 *      NEM-hatását ellenőrizzük, nem duplikáljuk a teljes token-logikát).
 */
final class ClientWorkerGatingHttpTest extends TestCase
{
    private static array $procs = [];
    private static array $ports = [];
    private static string $sharedFixtureRoot;

    public static function setUpBeforeClass(): void
    {
        $projectRoot = dirname(__DIR__);

        // --- "Szerver" fixture — csak azt méri, ÉRKEZETT-e valaha ide
        // cron-kérés egy Kliens node-ról továbbítva. NEM a valódi app. ---
        self::$sharedFixtureRoot = sys_get_temp_dir() . '/sm_workergate_upstream_' . bin2hex(random_bytes(6));
        mkdir(self::$sharedFixtureRoot . '/api', 0775, true);
        file_put_contents(
            self::$sharedFixtureRoot . '/api/_hit-counter.php',
            "<?php\nfile_put_contents(__DIR__ . '/../hits.log', date('c') . ' ' . \$_SERVER['REQUEST_URI'] . \"\\n\", FILE_APPEND);\nheader('Content-Type: application/json');\necho json_encode(['ok' => true]);\n"
        );
        self::startServer('upstream', self::$sharedFixtureRoot);

        // --- "Kliens" node — VALÓDI app-másolat, node_role='client'. ---
        self::setUpRealAppCopy('client', ['node_role' => 'client', 'client' => [
            'server_url' => 'http://127.0.0.1:' . self::$ports['upstream'], 'client_id' => '', 'client_secret' => '',
        ]]);

        // --- "Szerver" node — VALÓDI app-másolat, node_role='server', valós cron_secret. ---
        self::setUpRealAppCopy('server', ['node_role' => 'server']);
        self::request('server', 'GET', '/api/auth-status.php');
        self::seedCronSecret('server', 'valodi-szerver-cron-titok');

        // --- "Önálló" node — VALÓDI app-másolat, node_role hiányzik a
        // configból (régi, Fázis 2 előtti telepítés-szimuláció, lásd a
        // kör 12. pontja: "no node_role -> automatikusan standalone"). ---
        self::setUpRealAppCopy('standalone', null);
        self::request('standalone', 'GET', '/api/auth-status.php');
        self::seedCronSecret('standalone', 'valodi-onallo-cron-titok');
    }

    public static function tearDownAfterClass(): void
    {
        foreach (self::$procs as $p) {
            if (is_resource($p)) {
                proc_terminate($p);
                proc_close($p);
            }
        }
        foreach (glob(sys_get_temp_dir() . '/sm_workergate_*', GLOB_ONLYDIR) ?: [] as $dir) {
            self::removeDir($dir);
        }
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

    private static function startServer(string $name, string $docRoot): void
    {
        $port = self::findFreePort();
        self::$ports[$name] = $port;
        $log = $docRoot . '/../' . $name . '-server.log';
        self::$procs[$name] = proc_open(
            [PHP_BINARY, '-S', '127.0.0.1:' . $port, '-t', $docRoot],
            [1 => ['file', $log, 'w'], 2 => ['file', $log, 'w']],
            $pipes,
            $docRoot
        );
        self::waitForServerReady($port);
    }

    private static function setUpRealAppCopy(string $name, ?array $installerConfig): void
    {
        $projectRoot = dirname(__DIR__);
        $root = sys_get_temp_dir() . '/sm_workergate_' . $name . '_' . bin2hex(random_bytes(6));
        self::copyDir($projectRoot . '/webroot', $root . '/webroot', ['vendor', 'products', 'backups']);
        self::copyDir($projectRoot . '/src', $root . '/src', []);
        copy($projectRoot . '/schema.sql', $root . '/schema.sql');
        mkdir($root . '/config', 0775, true);
        copy($projectRoot . '/config/config.php', $root . '/config/config.php');
        mkdir($root . '/data', 0775, true);

        if ($installerConfig !== null) {
            $base = [
                'shop' => ['name' => 'Worker gating teszt', 'address' => 'X'],
                'db' => ['driver' => 'sqlite', 'sqlite' => ['path' => 'unused'], 'mysql' => []],
            ];
            file_put_contents($root . '/config/installer-generated.php', '<?php return ' . var_export(array_merge($base, $installerConfig), true) . ';');
        }
        // Ha $installerConfig === null: SZÁNDÉKOSAN NINCS installer-generated.php
        // — ez a "meglévő, Fázis 2 előtti telepítés" pontos szimulációja
        // (lásd config.php: hiányzó fájl esetén minden alapértelmezésre esik,
        // 'node_role' => 'standalone').

        self::$docRoots[$name] = $root;
        self::startServer($name, $root . '/webroot');
    }

    private static array $docRoots = [];

    private static function seedCronSecret(string $name, string $secret): void
    {
        $settingsPath = self::$docRoots[$name] . '/data/settings.json';
        file_put_contents($settingsPath, json_encode(['cron_secret' => $secret], JSON_UNESCAPED_UNICODE));
    }

    private static function request(string $name, string $method, string $path, array $headers = []): array
    {
        $ch = curl_init('http://127.0.0.1:' . self::$ports[$name] . $path);
        $hdrLines = [];
        foreach ($headers as $k => $v) {
            $hdrLines[] = "$k: $v";
        }
        curl_setopt_array($ch, [
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HEADER => false,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_HTTPHEADER => $hdrLines,
            CURLOPT_TIMEOUT => 10,
        ]);
        $body = curl_exec($ch);
        if ($body === false) {
            self::fail('curl hiba: ' . curl_error($ch));
        }
        $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        $json = json_decode((string) $body, true);
        return ['status' => $status, 'body' => $body, 'json' => is_array($json) ? $json : null];
    }

    // ------------------------------------------------------------------
    // Tesztek
    // ------------------------------------------------------------------

    public function testClientRejectsCronEndpointEvenWithoutAnyToken(): void
    {
        $res = self::request('client', 'GET', '/api/auto-sync-run.php');
        $this->assertSame(403, $res['status']);
        $this->assertTrue($res['json']['client_mode_unavailable'] ?? false);
    }

    public function testClientRejectsCronEndpointEvenWithAPlausibleLookingToken(): void
    {
        // A topológia-kapunak MÉG egy szintaktikailag hihető tokennel
        // érkező kérést is el kell utasítania — ez NEM hitelesítési döntés,
        // a token TARTALMA itt teljesen irreleváns.
        $res = self::request('client', 'GET', '/api/auto-backup-run.php', ['X-Cron-Token' => 'barmilyen-token-ertek']);
        $this->assertSame(403, $res['status']);
        $this->assertTrue($res['json']['client_mode_unavailable'] ?? false);
    }

    public function testClientRejectsAllSixCronEndpoints(): void
    {
        foreach (['auto-backup-run.php', 'auto-sync-run.php', 'nav-queue-run.php', 'nav-incoming-sync-run.php', 'update-check-run.php', 'wc-queue-run.php'] as $endpoint) {
            $res = self::request('client', 'GET', "/api/$endpoint");
            $this->assertSame(403, $res['status'], "$endpoint-nak elutasítva kellene lennie Kliens node-on.");
            $this->assertTrue($res['json']['client_mode_unavailable'] ?? false, "$endpoint válasza nem jelzi a client_mode_unavailable okot.");
        }
    }

    public function testClientNeverForwardsCronRequestsToTheUpstreamServer(): void
    {
        // Több kérés is elment a Kliens saját cron-végpontjaira a fenti
        // teszteknél — a "Szerver" fixture-nek EGYETLEN kérést sem
        // szabadott kapnia belőlük (a topológia-kapu a ClientProxy-
        // továbbítás ELŐTT dönt el mindent).
        $hitsLog = self::$sharedFixtureRoot . '/hits.log';
        $this->assertFileDoesNotExist($hitsLog, 'A Kliens node cron-kérése SOSE juthat el a Szerverig — a hit-számláló fixture-nek üresnek kell maradnia.');
    }

    public function testClientNonCronEndpointsAreUnaffectedByTheGate(): void
    {
        // A topológia-kapu KIZÁRÓLAG a 6 cron-végpontra vonatkozik — egy
        // normál (nem-cron) végpontnak továbbra is a ClientProxy-n keresztül
        // kell viselkednie (itt egyszerűen HMAC hiányában 401-et kapunk,
        // NEM 403 client_mode_unavailable-t — ez bizonyítja, hogy a kérés
        // ténylegesen eljutott a ClientProxy hitelesítési rétegéig, nem a
        // topológia-kapunál akadt el).
        $res = self::request('client', 'GET', '/api/auth-status.php');
        $this->assertNotSame(403, $res['status']);
    }

    public function testServerModeCronBehaviourUnaffectedByTheGateWithValidToken(): void
    {
        $res = self::request('server', 'GET', '/api/auto-backup-run.php', ['X-Cron-Token' => 'valodi-szerver-cron-titok']);
        $this->assertNotSame(403, $res['status'], 'A Szerver node-nak SOSE szabadna 403 client_mode_unavailable-t adnia.');
        $this->assertSame(200, $res['status'], $res['body']);
    }

    public function testServerModeCronBehaviourUnaffectedByTheGateWithInvalidToken(): void
    {
        $res = self::request('server', 'GET', '/api/auto-backup-run.php', ['X-Cron-Token' => 'nyilvan-rossz-token']);
        $this->assertNotSame(403, $res['status'], 'Egy érvénytelen tokennek 401-et kell adnia, NEM a topológia-kapu 403-át.');
        $this->assertSame(401, $res['status']);
    }

    public function testStandaloneModeCronBehaviourUnaffectedByTheGate(): void
    {
        // Régi (Fázis 2 előtti) telepítés-szimuláció — nincs
        // installer-generated.php egyáltalán, tehát node_role a config.php
        // saját alapértelmezése szerint 'standalone'.
        $valid = self::request('standalone', 'GET', '/api/auto-backup-run.php', ['X-Cron-Token' => 'valodi-onallo-cron-titok']);
        $this->assertSame(200, $valid['status'], $valid['body']);

        $invalid = self::request('standalone', 'GET', '/api/auto-backup-run.php', ['X-Cron-Token' => 'rossz-token']);
        $this->assertSame(401, $invalid['status']);
    }
}
