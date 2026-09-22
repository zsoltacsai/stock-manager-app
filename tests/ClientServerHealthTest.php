<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Fázis 2, Checkpoint 4 — src/ClientServerHealth.php VALÓDI HTTP-hívásokkal
 * bizonyítva, egy EGYETLEN, közös fixture "Szerver" `php -S` folyamattal.
 * Mivel a ClientServerHealth a valódi kódban SOSE fűz query-stringet a
 * server_url-hez (lásd refresh() — pontosan `$serverUrl . '/api/server-ping.php'`,
 * ahogy egy éles Kliens is hívná), a fixture válaszát egy KÖZÖS, a teszt
 * által a hívás ELŐTT felülírt vezérlő-fájl (control.json) szabályozza —
 * ez pontosabban tükrözi a valódi kérés-alakot, mint egy query-stringes
 * trükk lenne. Minden teszt SAJÁT, egyedi client_id-t használ, hogy a
 * ClientServerHealth host-local cache-fájlja (server_url+client_id alapján
 * kulcsolva) tesztenként izolált maradjon, még ugyanazon a fixture-porton is.
 */
final class ClientServerHealthTest extends TestCase
{
    private static string $fixtureRoot;
    private static int $port;
    /** @var resource */
    private static $process;

    public static function setUpBeforeClass(): void
    {
        self::$fixtureRoot = sys_get_temp_dir() . '/sm_health_fixture_' . bin2hex(random_bytes(6));
        mkdir(self::$fixtureRoot . '/api', 0775, true);
        file_put_contents(self::$fixtureRoot . '/api/server-ping.php', self::fixtureSource());
        file_put_contents(self::$fixtureRoot . '/hits.txt', '0');

        self::$port = self::findFreePort();
        self::$process = proc_open(
            [PHP_BINARY, '-S', '127.0.0.1:' . self::$port, '-t', self::$fixtureRoot],
            [1 => ['file', self::$fixtureRoot . '/server.log', 'w'], 2 => ['file', self::$fixtureRoot . '/server.log', 'w']],
            $pipes
        );
        self::waitForServerReady();
    }

    public static function tearDownAfterClass(): void
    {
        if (is_resource(self::$process)) {
            proc_terminate(self::$process);
            proc_close(self::$process);
        }
        self::removeDir(self::$fixtureRoot);
        self::removeDir(sys_get_temp_dir() . '/stockmanager-client-health');
    }

    private static function fixtureSource(): string
    {
        return <<<'PHP'
            <?php
            declare(strict_types=1);
            $hitsFile = __DIR__ . '/../hits.txt';
            file_put_contents($hitsFile, ((int) file_get_contents($hitsFile)) + 1);

            $controlPath = __DIR__ . '/../control.json';
            $control = is_file($controlPath) ? json_decode((string) file_get_contents($controlPath), true) : [];
            $mode = $control['mode'] ?? 'ok';
            $version = $control['version'] ?? '1.4.1';

            header('Content-Type: application/json; charset=utf-8');
            if ($mode === 'ok') {
                echo json_encode(['success' => true, 'app' => 'FountainTrade', 'version' => $version]);
            } elseif ($mode === 'wrong_app') {
                echo json_encode(['success' => true, 'app' => 'SomeOtherApp', 'version' => $version]);
            } elseif ($mode === 'malformed') {
                echo 'not json';
            } elseif ($mode === 'http_error') {
                http_response_code(500);
                echo json_encode(['error' => 'boom']);
            }
            PHP;
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

    private function hitCount(): int
    {
        return (int) file_get_contents(self::$fixtureRoot . '/hits.txt');
    }

    private function setControl(string $mode, string $version = '1.4.1'): void
    {
        file_put_contents(self::$fixtureRoot . '/control.json', json_encode(['mode' => $mode, 'version' => $version]));
    }

    private function clientConfig(string $suffix): array
    {
        return [
            'server_url' => 'http://127.0.0.1:' . self::$port,
            'client_id' => 'cl_health_test_' . $suffix,
            'client_secret' => 'irrelevant',
        ];
    }

    public function testReachableAndSameVersionIsCompatible(): void
    {
        $this->setControl('ok', AppVersion::CURRENT);
        $state = ClientServerHealth::check($this->clientConfig('compat'));
        $this->assertTrue($state['server_reachable']);
        $this->assertTrue($state['api_reachable']);
        $this->assertSame(AppVersion::CURRENT, $state['server_version']);
        $this->assertTrue($state['compatible']);
        $this->assertNotNull($state['last_success']);
        $this->assertNull($state['last_error']);
    }

    public function testDifferentMinorVersionIsIncompatible(): void
    {
        [$maj, $min] = AppVersion::parse(AppVersion::CURRENT);
        $this->setControl('ok', "$maj." . ($min + 1) . '.0');
        $state = ClientServerHealth::check($this->clientConfig('minor'));
        $this->assertTrue($state['server_reachable']);
        $this->assertTrue($state['api_reachable']);
        $this->assertFalse($state['compatible']);
        $this->assertNotNull($state['last_error']);
    }

    public function testDifferentMajorVersionIsIncompatible(): void
    {
        [$maj] = AppVersion::parse(AppVersion::CURRENT);
        $this->setControl('ok', ($maj + 1) . '.0.0');
        $state = ClientServerHealth::check($this->clientConfig('major'));
        $this->assertFalse($state['compatible']);
    }

    public function testSamePatchDifferenceIsStillCompatible(): void
    {
        [$maj, $min, $pat] = AppVersion::parse(AppVersion::CURRENT);
        $this->setControl('ok', "$maj.$min." . ($pat + 5));
        $state = ClientServerHealth::check($this->clientConfig('patch'));
        $this->assertTrue($state['compatible'], 'Egy patch-szintű eltérés önmagában NEM okozhat inkompatibilitást.');
    }

    public function testMalformedVersionStringIsTreatedAsIncompatibleNotAFatalError(): void
    {
        $this->setControl('ok', 'not-a-semver');
        $state = ClientServerHealth::check($this->clientConfig('malformed-version'));
        $this->assertTrue($state['api_reachable']);
        $this->assertFalse($state['compatible']);
    }

    public function testUnreachableServerNeverReportsFalseCompatible(): void
    {
        $config = [
            'server_url' => 'http://127.0.0.1:1', // garantáltan zárt/nem létező cél
            'client_id' => 'cl_health_test_unreachable',
            'client_secret' => 'x',
        ];
        $state = ClientServerHealth::check($config);
        $this->assertFalse($state['server_reachable']);
        $this->assertFalse($state['api_reachable']);
        $this->assertNull($state['compatible'], 'Elérhetetlen Szerver esetén a compatible mezőnek NULL-nak kell maradnia — SOSE hamis true/false.');
    }

    public function testWrongAppNameIsTreatedAsApiUnreachable(): void
    {
        $this->setControl('wrong_app');
        $state = ClientServerHealth::check($this->clientConfig('wrongapp'));
        $this->assertTrue($state['server_reachable']);
        $this->assertFalse($state['api_reachable'], 'Ha a válaszoló nem FountainTrade-nek vallja magát, ne kezeljük valódi API-válaszként.');
        $this->assertNull($state['compatible']);
    }

    public function testMalformedJsonResponseIsTreatedAsApiUnreachable(): void
    {
        $this->setControl('malformed');
        $state = ClientServerHealth::check($this->clientConfig('malformedjson'));
        $this->assertTrue($state['server_reachable']);
        $this->assertFalse($state['api_reachable']);
    }

    public function testHttpErrorStatusIsTreatedAsApiUnreachable(): void
    {
        $this->setControl('http_error');
        $state = ClientServerHealth::check($this->clientConfig('httperror'));
        $this->assertFalse($state['api_reachable']);
    }

    public function testRepeatedChecksWithinTtlDoNotRePing(): void
    {
        $this->setControl('ok', AppVersion::CURRENT);
        $config = $this->clientConfig('ttl');
        $before = $this->hitCount();
        ClientServerHealth::check($config);
        $afterFirst = $this->hitCount();
        ClientServerHealth::check($config);
        ClientServerHealth::check($config);
        $afterMore = $this->hitCount();

        $this->assertSame($before + 1, $afterFirst, 'Az első hívásnak pontosan egy valódi pinget kellett indítania.');
        $this->assertSame($afterFirst, $afterMore, 'A TTL-en belüli ismételt check()-eknek a gyorsítótárból kellett válaszolniuk, ÚJABB ping nélkül.');
    }

    public function testForceRefreshBypassesTheCache(): void
    {
        $this->setControl('ok', AppVersion::CURRENT);
        $config = $this->clientConfig('force');
        ClientServerHealth::check($config);
        $afterFirst = $this->hitCount();
        ClientServerHealth::check($config, true);
        $afterForced = $this->hitCount();
        $this->assertSame($afterFirst + 1, $afterForced, 'A forceRefresh=true-nak MINDIG valódi pinget kell indítania, a TTL-től függetlenül.');
    }

    public function testRecordRequestOutcomeUpdatesAuthenticatedWithoutTouchingReachabilityFields(): void
    {
        $this->setControl('ok', AppVersion::CURRENT);
        $config = $this->clientConfig('authoutcome');
        $afterPing = ClientServerHealth::check($config);
        $this->assertTrue($afterPing['server_reachable']);

        ClientServerHealth::recordRequestOutcome($config, true);
        $afterSuccess = ClientServerHealth::check($config);
        $this->assertTrue($afterSuccess['authenticated']);
        $this->assertTrue($afterSuccess['server_reachable'], 'A recordRequestOutcome() nem módosíthatja a reachability mezőket.');
        $this->assertSame($afterPing['server_version'], $afterSuccess['server_version']);

        ClientServerHealth::recordRequestOutcome($config, false, 'A Szerver elutasította a Kliens gép-szintű hitelesítését.');
        $afterFailure = ClientServerHealth::check($config);
        $this->assertFalse($afterFailure['authenticated']);
        $this->assertSame('A Szerver elutasította a Kliens gép-szintű hitelesítését.', $afterFailure['last_error']);
        $this->assertTrue($afterFailure['server_reachable'], 'Egy hitelesítési kudarc a VALÓDI forgalomban nem jelenti azt, hogy a Szerver elérhetetlen.');
    }

    public function testMissingServerUrlIsHandledGracefullyWithoutException(): void
    {
        $state = ClientServerHealth::check(['server_url' => '', 'client_id' => '', 'client_secret' => '']);
        $this->assertFalse($state['server_reachable']);
        $this->assertNull($state['compatible']);
        $this->assertNotNull($state['last_error']);
    }
}
