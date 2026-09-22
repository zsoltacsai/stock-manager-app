<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Fázis 2, Checkpoint 4 — a ClientProxy verzió-kompatibilitási kapuja
 * (lásd src/ClientProxy.php forward() eleje), VALÓDI HTTP-n keresztül, egy
 * VALÓDI Kliens app-másolattal és egy vezérelhető válaszú "Szerver"
 * fixture-rel bizonyítva. A négy, egymástól élesen elkülönített kimenetet
 * teszteli: kompatibilis kérés (normál proxy), verzió-eltérés (409
 * version_mismatch, a fixture ÜZLETI végpontja SOSE kap kérést), a Szerver
 * teljes elérhetetlensége (a MEGLÉVŐ "Szerver nem elérhető" 503), és a
 * helyreállás, miután a Szerver ismét elérhetővé/kompatibilissé válik.
 */
final class ClientProxyVersionGateHttpTest extends TestCase
{
    private static string $fixtureRoot;
    private static int $fixturePort;
    /** @var resource */
    private static $fixtureProcess;

    private static string $clientRoot;
    private static int $clientPort;
    private static string $clientBaseUrl;
    /** @var resource */
    private static $clientProcess;

    public static function setUpBeforeClass(): void
    {
        $projectRoot = dirname(__DIR__);

        // --- "Szerver" fixture: vezérelhető server-ping.php + egy üzleti
        // végpont, ami saját hit-számlálóval bizonyítja, ELÉRTE-e egyáltalán
        // a kérés (verzió-eltérésnél SOSE szabadna). ---
        self::$fixtureRoot = sys_get_temp_dir() . '/sm_versiongate_server_' . bin2hex(random_bytes(6));
        mkdir(self::$fixtureRoot . '/api', 0775, true);
        file_put_contents(self::$fixtureRoot . '/api/server-ping.php', self::pingFixtureSource());
        file_put_contents(self::$fixtureRoot . '/api/business-endpoint.php', self::businessFixtureSource());
        file_put_contents(self::$fixtureRoot . '/business-hits.txt', '0');
        self::setControl('ok', AppVersion::CURRENT);

        self::$fixturePort = self::findFreePort();
        self::$fixtureProcess = proc_open(
            [PHP_BINARY, '-S', '127.0.0.1:' . self::$fixturePort, '-t', self::$fixtureRoot],
            [1 => ['file', self::$fixtureRoot . '/server.log', 'w'], 2 => ['file', self::$fixtureRoot . '/server.log', 'w']],
            $pipes
        );
        self::waitForServerReady(self::$fixturePort);

        // --- "Kliens" oldal: VALÓDI app-másolat, node_role='client'. ---
        self::$clientRoot = sys_get_temp_dir() . '/sm_versiongate_client_' . bin2hex(random_bytes(6));
        self::copyDir($projectRoot . '/webroot', self::$clientRoot . '/webroot', ['vendor', 'products', 'backups']);
        self::copyDir($projectRoot . '/src', self::$clientRoot . '/src', []);
        mkdir(self::$clientRoot . '/config', 0775, true);
        copy($projectRoot . '/config/config.php', self::$clientRoot . '/config/config.php');
        file_put_contents(
            self::$clientRoot . '/config/installer-generated.php',
            '<?php return ' . var_export([
                'shop' => ['name' => 'Version gate teszt', 'address' => 'X'],
                'db' => ['driver' => 'sqlite', 'sqlite' => ['path' => 'unused'], 'mysql' => []],
                'node_role' => 'client',
                'client' => ['server_url' => 'http://127.0.0.1:' . self::$fixturePort, 'client_id' => 'cl_versiongate_test', 'client_secret' => 'irrelevant'],
            ], true) . ';'
        );
        file_put_contents(self::$clientRoot . '/webroot/api/business-endpoint.php', "<?php\ndeclare(strict_types=1);\nrequire __DIR__ . '/_bootstrap.php';\n");

        self::$clientPort = self::findFreePort();
        self::$clientBaseUrl = 'http://127.0.0.1:' . self::$clientPort;
        self::$clientProcess = proc_open(
            [PHP_BINARY, '-S', '127.0.0.1:' . self::$clientPort, '-t', self::$clientRoot . '/webroot'],
            [1 => ['file', self::$clientRoot . '/client.log', 'w'], 2 => ['file', self::$clientRoot . '/client.log', 'w']],
            $pipes2
        );
        self::waitForServerReady(self::$clientPort);
    }

    public static function tearDownAfterClass(): void
    {
        foreach ([self::$fixtureProcess, self::$clientProcess] as $p) {
            if (is_resource($p)) {
                proc_terminate($p);
                proc_close($p);
            }
        }
        self::removeDir(self::$fixtureRoot);
        self::removeDir(self::$clientRoot);
        self::removeDir(sys_get_temp_dir() . '/stockmanager-client-health');
    }

    private static function pingFixtureSource(): string
    {
        return <<<'PHP'
            <?php
            declare(strict_types=1);
            $control = json_decode((string) @file_get_contents(__DIR__ . '/../control.json'), true) ?: [];
            if (($control['down'] ?? false) === true) {
                // Kapcsolat-szintű elérhetetlenség szimulálása — a socketet
                // egyszerűen nem nyitjuk meg egyáltalán (lásd lentebb: ilyenkor
                // a teszt egy garantáltan zárt portra állítja a Kliens
                // server_url-jét, nem ezt a fixture-t hívja).
                exit;
            }
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode(['success' => true, 'app' => 'FountainTrade', 'version' => $control['version'] ?? '1.4.1']);
            PHP;
    }

    private static function businessFixtureSource(): string
    {
        return <<<'PHP'
            <?php
            declare(strict_types=1);
            $hitsFile = __DIR__ . '/../business-hits.txt';
            file_put_contents($hitsFile, ((int) file_get_contents($hitsFile)) + 1);
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode(['ok' => true]);
            PHP;
    }

    private static function setControl(string $mode, string $version): void
    {
        file_put_contents(self::$fixtureRoot . '/control.json', json_encode(['mode' => $mode, 'version' => $version]));
    }

    private static function businessHits(): int
    {
        return (int) file_get_contents(self::$fixtureRoot . '/business-hits.txt');
    }

    /** A ClientServerHealth cache-fájlját közvetlenül "lejártként" preparáljuk — lásd a teszt-osztály docblokkja: ez a valódi 30s TTL kivárása helyett gyors, determinisztikus "recovery" bizonyítékot ad. */
    private static function expireHealthCache(): void
    {
        $key = hash('sha256', 'http://127.0.0.1:' . self::$fixturePort . '|cl_versiongate_test');
        $file = sys_get_temp_dir() . '/stockmanager-client-health/' . $key . '.json';
        if (is_file($file)) {
            $data = json_decode((string) file_get_contents($file), true) ?: [];
            $data['_checked_at_unix'] = time() - 3600;
            file_put_contents($file, json_encode($data));
        }
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

    private static function request(string $path): array
    {
        $ch = curl_init(self::$clientBaseUrl . $path);
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 10]);
        $body = curl_exec($ch);
        $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        $json = json_decode((string) $body, true);
        return ['status' => $status, 'json' => is_array($json) ? $json : null, 'body' => $body];
    }

    // ------------------------------------------------------------------

    public function test01_CompatibleVersionProceedsWithNormalProxying(): void
    {
        self::setControl('ok', AppVersion::CURRENT);
        self::expireHealthCache();
        $before = self::businessHits();

        $res = self::request('/api/business-endpoint.php');
        $this->assertSame(200, $res['status'], $res['body']);
        $this->assertTrue($res['json']['ok'] ?? false);
        $this->assertSame($before + 1, self::businessHits(), 'Kompatibilis verziónál a kérésnek ténylegesen el kellett jutnia az üzleti végpontig.');
    }

    public function test02_IncompatibleVersionIsRejectedBeforeReachingTheBusinessEndpoint(): void
    {
        [$maj, $min] = AppVersion::parse(AppVersion::CURRENT);
        self::setControl('ok', "$maj." . ($min + 1) . '.0');
        self::expireHealthCache();
        $before = self::businessHits();

        $res = self::request('/api/business-endpoint.php');
        $this->assertSame(409, $res['status'], $res['body']);
        $this->assertTrue($res['json']['version_mismatch'] ?? false);
        $this->assertSame('A kliens frissítése szükséges.', $res['json']['error'] ?? null);
        $this->assertSame($before, self::businessHits(), 'Verzió-eltérésnél a kérés SOSE juthat el az üzleti végpontig.');
    }

    public function test03_UnreachableServerStillProducesTheExistingGracefulResponse(): void
    {
        // Egy MÁSODIK, saját Kliens-másolatot indítunk, aminek a
        // server_url-je egy garantáltan zárt portra mutat — a health-kapu
        // ÉS a meglévő "Szerver nem elérhető" válasz együttes bizonyítéka.
        $projectRoot = dirname(__DIR__);
        $deadRoot = sys_get_temp_dir() . '/sm_versiongate_dead_' . bin2hex(random_bytes(6));
        self::copyDir($projectRoot . '/webroot', $deadRoot . '/webroot', ['vendor', 'products', 'backups']);
        self::copyDir($projectRoot . '/src', $deadRoot . '/src', []);
        mkdir($deadRoot . '/config', 0775, true);
        copy($projectRoot . '/config/config.php', $deadRoot . '/config/config.php');
        $deadPort = self::findFreePort();
        file_put_contents(
            $deadRoot . '/config/installer-generated.php',
            '<?php return ' . var_export([
                'shop' => ['name' => 'X', 'address' => 'X'],
                'db' => ['driver' => 'sqlite', 'sqlite' => ['path' => 'unused'], 'mysql' => []],
                'node_role' => 'client',
                'client' => ['server_url' => 'http://127.0.0.1:' . $deadPort, 'client_id' => 'cl_versiongate_dead', 'client_secret' => 'x'],
            ], true) . ';'
        );
        file_put_contents($deadRoot . '/webroot/api/business-endpoint.php', "<?php\ndeclare(strict_types=1);\nrequire __DIR__ . '/_bootstrap.php';\n");

        $port = self::findFreePort();
        $proc = proc_open(
            [PHP_BINARY, '-S', '127.0.0.1:' . $port, '-t', $deadRoot . '/webroot'],
            [1 => ['file', $deadRoot . '/log', 'w'], 2 => ['file', $deadRoot . '/log', 'w']],
            $pipes
        );
        self::waitForServerReady($port);

        try {
            $ch = curl_init('http://127.0.0.1:' . $port . '/api/business-endpoint.php');
            curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 10]);
            $body = curl_exec($ch);
            $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);
            $json = json_decode((string) $body, true);

            $this->assertSame(503, $status);
            $this->assertTrue($json['server_unreachable'] ?? false);
            $this->assertSame('Szerver nem elérhető.', $json['error'] ?? null);
        } finally {
            if (is_resource($proc)) {
                proc_terminate($proc);
                proc_close($proc);
            }
            self::removeDir($deadRoot);
        }
    }

    public function test04_RecoveryAfterServerBecomesCompatibleAgainIsReflectedOnNextCheck(): void
    {
        // Az előző teszt (02) inkompatibilis állapotot cache-elt EBBEN a
        // Kliens-példányban (cl_versiongate_test kulcs alatt) — most a
        // Szerver "helyreáll" (kompatibilis verziót jelez), a cache-t
        // lejártként preparáljuk (a valódi 30s TTL kivárása helyett), és
        // bizonyítjuk, hogy a KÖVETKEZŐ kérés MÁR sikeresen átmegy.
        self::setControl('ok', AppVersion::CURRENT);
        self::expireHealthCache();

        $before = self::businessHits();
        $res = self::request('/api/business-endpoint.php');
        $this->assertSame(200, $res['status'], $res['body']);
        $this->assertSame($before + 1, self::businessHits());
    }
}
