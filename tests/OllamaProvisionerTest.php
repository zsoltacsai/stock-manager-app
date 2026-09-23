<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Fázis 6, Rész B — az OllamaProvisioner teszjei kizárólag mockok/
 * stub-szerverek ellen (a kör 26. pontja: "Do not run a real installer
 * during PHPUnit"). SOSE hívjuk a valódi github.com-ot vagy futtatunk
 * egy valódi Ollama-telepítőt — minden hálózati hívás egy saját,
 * loopback stub-szerver ellen megy (ugyanaz a minta, mint
 * tests/AiLocalProviderTest.php / OllamaHealth tesztjei), minden
 * folyamat-indítás a PHP CLI-t magát (PHP_BINARY) használja ártalmatlan,
 * rövid parancsokkal a valódi installer helyett.
 */
final class OllamaProvisionerTest extends TestCase
{
    private static string $root;

    public static function setUpBeforeClass(): void
    {
        self::$root = sys_get_temp_dir() . '/sm_ollama_provisioner_test_' . bin2hex(random_bytes(6));
        mkdir(self::$root, 0775, true);
    }

    public static function tearDownAfterClass(): void
    {
        self::removeDir(self::$root);
    }

    private static function removeDir(string $dir): void
    {
        if (!is_dir($dir)) return;
        foreach (scandir($dir) as $item) {
            if ($item === '.' || $item === '..') continue;
            $path = "$dir/$item";
            is_dir($path) && !is_link($path) ? self::removeDir($path) : @unlink($path);
        }
        @rmdir($dir);
    }

    private static function findFreePort(): int
    {
        $sock = stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);
        if ($sock === false) self::fail('Nem sikerült szabad portot találni: ' . $errstr);
        $name = stream_socket_get_name($sock, false);
        fclose($sock);
        return (int) substr($name, strrpos($name, ':') + 1);
    }

    private static function waitForReady(int $port): void
    {
        $deadline = microtime(true) + 10;
        while (microtime(true) < $deadline) {
            $fp = @fsockopen('127.0.0.1', $port, $errno, $errstr, 0.2);
            if ($fp) { fclose($fp); return; }
            usleep(100_000);
        }
        self::fail('A stub-szerver nem indult el időben.');
    }

    // ------------------------------------------------------------------
    // Telepítettség-detektálás
    // ------------------------------------------------------------------

    public function testAbsentInstallationIsDetectedCorrectly(): void
    {
        $fakeAppData = self::$root . '/appdata-empty-' . bin2hex(random_bytes(4));
        mkdir($fakeAppData, 0775, true);

        $this->assertNull(OllamaProvisioner::installedBinaryPath($fakeAppData));
        $this->assertFalse(OllamaProvisioner::isInstalled($fakeAppData));
    }

    public function testInstalledBinaryIsDetectedCorrectly(): void
    {
        $fakeAppData = self::$root . '/appdata-installed-' . bin2hex(random_bytes(4));
        mkdir($fakeAppData . '/Programs/Ollama', 0775, true);
        file_put_contents($fakeAppData . '/Programs/Ollama/ollama.exe', 'stub-binary');

        $path = OllamaProvisioner::installedBinaryPath($fakeAppData);
        $this->assertNotNull($path);
        $this->assertStringEndsWith('Programs\\Ollama\\ollama.exe', $path);
        $this->assertTrue(OllamaProvisioner::isInstalled($fakeAppData));
    }

    public function testEmptyLocalAppDataIsHandledSafely(): void
    {
        $this->assertNull(OllamaProvisioner::installedBinaryPath(''));
    }

    public function testDetectCliVersionUsesRealButHarmlessProcess(): void
    {
        // A PHP CLI-t magát használjuk "ollama.exe" helyett — a "php
        // --version" kimenete valódi X.Y.Z alakú verziószámot tartalmaz
        // (pl. "PHP 8.3.33"), ez egy VALÓDI, de ártalmatlan folyamat-
        // indítás, nem egy telepítő.
        $version = OllamaProvisioner::detectCliVersion(PHP_BINARY);
        $this->assertNotNull($version);
        $this->assertMatchesRegularExpression('/^\d+\.\d+\.\d+$/', $version);
    }

    public function testDetectCliVersionReturnsNullForMissingBinary(): void
    {
        $this->assertNull(OllamaProvisioner::detectCliVersion(self::$root . '/nincs-ilyen.exe'));
    }

    // ------------------------------------------------------------------
    // Ollama API-alapú detektálás (stub szerver)
    // ------------------------------------------------------------------

    private static function startOllamaStub(): array
    {
        $root = self::$root . '/ollama_stub_' . bin2hex(random_bytes(6));
        mkdir($root, 0775, true);
        file_put_contents($root . '/index.php', <<<'PHP'
<?php
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
header('Content-Type: application/json');
$controlFile = __DIR__ . '/control.json';
$control = is_file($controlFile) ? json_decode(file_get_contents($controlFile), true) : [];
if ($path === '/api/version') {
    if (($control['version_mode'] ?? 'ok') === 'malformed') { echo 'not json'; exit; }
    if (($control['version_mode'] ?? 'ok') === 'down') { http_response_code(500); echo json_encode(['error' => 'down']); exit; }
    echo json_encode(['version' => $control['version'] ?? '0.34.3']);
    exit;
}
if ($path === '/api/tags') {
    echo json_encode(['models' => $control['models'] ?? [['name' => 'qwen3:8b']]]);
    exit;
}
if ($path === '/api/pull') {
    $body = json_decode(file_get_contents('php://input'), true);
    file_put_contents(__DIR__ . '/last_pull.json', json_encode($body));
    $mode = $control['pull_mode'] ?? 'success';
    if ($mode === 'success') { echo json_encode(['status' => 'success']); exit; }
    if ($mode === 'malformed') { echo 'not json'; exit; }
    if ($mode === 'error_status') { echo json_encode(['status' => 'pulling manifest']); exit; }
    http_response_code(500);
    echo json_encode(['error' => 'boom']);
    exit;
}
http_response_code(404);
echo json_encode(['error' => 'unknown']);
PHP);
        $port = self::findFreePort();
        $proc = proc_open(
            [PHP_BINARY, '-S', '127.0.0.1:' . $port, '-t', $root],
            [1 => ['file', $root . '/log.txt', 'w'], 2 => ['file', $root . '/log.txt', 'w']],
            $pipes,
            $root
        );
        self::waitForReady($port);
        return ['root' => $root, 'port' => $port, 'proc' => $proc, 'baseUrl' => 'http://127.0.0.1:' . $port];
    }

    private static function setOllamaStubControl(string $root, array $control): void
    {
        file_put_contents($root . '/control.json', json_encode($control));
    }

    private static function stopStub(array $stub): void
    {
        if (isset($stub['proc']) && is_resource($stub['proc'])) {
            proc_terminate($stub['proc']);
            proc_close($stub['proc']);
        }
    }

    public function testDetectApiVersionAndAvailabilityAgainstRealStubServer(): void
    {
        $stub = self::startOllamaStub();
        self::setOllamaStubControl($stub['root'], ['version' => '0.34.3']);

        $this->assertSame('0.34.3', OllamaProvisioner::detectApiVersion($stub['baseUrl']));
        $this->assertTrue(OllamaProvisioner::isApiAvailable($stub['baseUrl']));

        self::stopStub($stub);
    }

    public function testApiUnavailableIsHandledSafely(): void
    {
        // Nincs is elindítva stub-szerver ezen a porton.
        $port = self::findFreePort();
        $this->assertNull(OllamaProvisioner::detectApiVersion('http://127.0.0.1:' . $port));
        $this->assertFalse(OllamaProvisioner::isApiAvailable('http://127.0.0.1:' . $port));
    }

    public function testMalformedApiResponseIsHandledSafely(): void
    {
        $stub = self::startOllamaStub();
        self::setOllamaStubControl($stub['root'], ['version_mode' => 'malformed']);

        $this->assertNull(OllamaProvisioner::detectApiVersion($stub['baseUrl']));

        self::stopStub($stub);
    }

    public function testModelInstalledDetectionMatchesAndToleratesTagSuffix(): void
    {
        $stub = self::startOllamaStub();
        self::setOllamaStubControl($stub['root'], ['models' => [['name' => 'qwen3:8b']]]);

        $this->assertTrue(OllamaProvisioner::isModelInstalled($stub['baseUrl'], 'qwen3:8b'));
        $this->assertTrue(OllamaProvisioner::isModelInstalled($stub['baseUrl'], 'qwen3'));
        $this->assertFalse(OllamaProvisioner::isModelInstalled($stub['baseUrl'], 'llama3'));

        self::stopStub($stub);
    }

    public function testDetectStatusCombinesAllChecksInOneCall(): void
    {
        $stub = self::startOllamaStub();
        self::setOllamaStubControl($stub['root'], ['version' => '0.34.3', 'models' => [['name' => 'qwen3:8b']]]);
        $fakeAppData = self::$root . '/appdata-status-' . bin2hex(random_bytes(4));
        mkdir($fakeAppData . '/Programs/Ollama', 0775, true);
        file_put_contents($fakeAppData . '/Programs/Ollama/ollama.exe', 'stub-binary');

        $status = OllamaProvisioner::detectStatus($stub['baseUrl'], 'qwen3:8b', $fakeAppData);

        $this->assertTrue($status['installed']);
        $this->assertTrue($status['api_available']);
        $this->assertSame('0.34.3', $status['version']);
        $this->assertSame('qwen3:8b', $status['configured_model']);
        $this->assertTrue($status['model_installed']);

        self::stopStub($stub);
    }

    public function testDetectStatusReflectsNotInstalledNotAvailable(): void
    {
        $port = self::findFreePort();
        $fakeAppData = self::$root . '/appdata-status-empty-' . bin2hex(random_bytes(4));
        mkdir($fakeAppData, 0775, true);

        $status = OllamaProvisioner::detectStatus('http://127.0.0.1:' . $port, 'qwen3:8b', $fakeAppData);

        $this->assertFalse($status['installed']);
        $this->assertFalse($status['api_available']);
        $this->assertNull($status['version']);
        $this->assertFalse($status['model_installed']);
    }

    // ------------------------------------------------------------------
    // Modell-letöltés (pull)
    // ------------------------------------------------------------------

    public function testPullModelSuccess(): void
    {
        $stub = self::startOllamaStub();
        self::setOllamaStubControl($stub['root'], ['pull_mode' => 'success']);

        $result = OllamaProvisioner::pullModel($stub['baseUrl'], 'qwen3:8b', 5);

        $this->assertSame('ok', $result['status']);
        $sentBody = json_decode((string) file_get_contents($stub['root'] . '/last_pull.json'), true);
        $this->assertSame('qwen3:8b', $sentBody['model']);
        $this->assertFalse($sentBody['stream']);

        self::stopStub($stub);
    }

    public function testPullModelNonSuccessStatusIsTreatedAsFailure(): void
    {
        $stub = self::startOllamaStub();
        self::setOllamaStubControl($stub['root'], ['pull_mode' => 'error_status']);

        $result = OllamaProvisioner::pullModel($stub['baseUrl'], 'qwen3:8b', 5);

        $this->assertSame('error', $result['status']);

        self::stopStub($stub);
    }

    public function testPullModelMalformedResponseIsHandledSafely(): void
    {
        $stub = self::startOllamaStub();
        self::setOllamaStubControl($stub['root'], ['pull_mode' => 'malformed']);

        $result = OllamaProvisioner::pullModel($stub['baseUrl'], 'qwen3:8b', 5);

        $this->assertSame('error', $result['status']);

        self::stopStub($stub);
    }

    public function testPullModelNetworkFailureIsHandledSafely(): void
    {
        $port = self::findFreePort();
        $result = OllamaProvisioner::pullModel('http://127.0.0.1:' . $port, 'qwen3:8b', 2);

        $this->assertSame('error', $result['status']);
    }

    public function testPullModelRejectsInvalidModelNameWithoutAnyNetworkCall(): void
    {
        $stub = self::startOllamaStub();

        $result = OllamaProvisioner::pullModel($stub['baseUrl'], '"; DROP TABLE sales; --', 5);

        $this->assertSame('error', $result['status']);
        $this->assertFileDoesNotExist($stub['root'] . '/last_pull.json');

        self::stopStub($stub);
    }

    // ------------------------------------------------------------------
    // Telepítő-parancs összeállítása (tiszta függvény)
    // ------------------------------------------------------------------

    public function testBuildInstallerCommandUsesOfficialSilentSwitches(): void
    {
        $command = OllamaProvisioner::buildInstallerCommand('C:\\temp\\OllamaSetup.exe');

        $this->assertSame([
            'C:\\temp\\OllamaSetup.exe',
            '/VERYSILENT',
            '/SUPPRESSMSGBOXES',
            '/NORESTART',
        ], $command);
    }

    // ------------------------------------------------------------------
    // Folyamat-futtatás (VALÓDI, de ártalmatlan PHP CLI-parancsokkal)
    // ------------------------------------------------------------------

    public function testRunSilentInstallerSuccessWithRealHarmlessProcess(): void
    {
        $result = OllamaProvisioner::runSilentInstaller([PHP_BINARY, '-r', 'exit(0);'], 10);

        $this->assertSame('ok', $result['status']);
        $this->assertSame(0, $result['exit_code']);
    }

    public function testRunSilentInstallerNonZeroExitCodeIsTreatedAsFailure(): void
    {
        $result = OllamaProvisioner::runSilentInstaller([PHP_BINARY, '-r', 'exit(3);'], 10);

        $this->assertSame('error', $result['status']);
        $this->assertSame(3, $result['exit_code']);
    }

    public function testRunSilentInstallerTimeoutIsHandledSafely(): void
    {
        $result = OllamaProvisioner::runSilentInstaller([PHP_BINARY, '-r', 'usleep(5000000);'], 1);

        $this->assertSame('error', $result['status']);
        $this->assertStringContainsString('időkorlát', $result['message']);
    }

    public function testRunSilentInstallerMissingFileIsHandledSafely(): void
    {
        $result = OllamaProvisioner::runSilentInstaller([self::$root . '/nincs-ilyen.exe'], 5);

        $this->assertSame('error', $result['status']);
    }

    // ------------------------------------------------------------------
    // Letöltés + SHA-256 ellenőrzés (kontrollált GitHub-alakú stub)
    // ------------------------------------------------------------------

    private static function startGithubStub(string $assetBytes, ?string $digestOverride = null, bool $omitAsset = false, bool $omitDigest = false, ?string $downloadUrlLiteral = null): array
    {
        $root = self::$root . '/github_stub_' . bin2hex(random_bytes(6));
        mkdir($root . '/dl', 0775, true);
        // FONTOS: a PHP beépített fejlesztői szervere (php -S) ismert,
        // dokumentálatlan korlátja — bizonyos kiterjesztésekkel (pl. .exe)
        // rendelkező, DE a docroot-on ténylegesen NEM létező útvonalaknál
        // NEM esik vissza az index.php router-re (404-et ad közvetlenül),
        // ellentétben a kiterjesztés nélküli/egyéb útvonalakkal (élőben
        // megfigyelt, reprodukált viselkedés). Ezért a "letöltendő" .exe-t
        // VALÓDI, statikus fájlként helyezzük el pontosan a hívott
        // útvonalon (dl/OllamaSetup.exe) — ezt a beépített szerver a
        // router megkerülésével, közvetlenül kiszolgálja, pontosan úgy,
        // ahogy egy valódi GitHub Releases letöltés is egy statikus
        // fájlt szolgál ki.
        file_put_contents($root . '/dl/OllamaSetup.exe', $assetBytes);
        $realDigest = $digestOverride ?? hash('sha256', $assetBytes);

        $downloadUrlExpr = $downloadUrlLiteral !== null
            ? "'" . addslashes($downloadUrlLiteral) . "'"
            : "\$base.'/dl/OllamaSetup.exe'";

        $assetsPhp = $omitAsset ? '[]' : sprintf(
            "[['name'=>'OllamaSetup.exe','browser_download_url'=>%s,%s]]",
            $downloadUrlExpr,
            $omitDigest ? "'digest'=>''" : "'digest'=>'sha256:%s'"
        );

        file_put_contents($root . '/index.php', <<<PHP
<?php
\$path = parse_url(\$_SERVER['REQUEST_URI'], PHP_URL_PATH);
\$base = 'http://127.0.0.1:' . \$_SERVER['SERVER_PORT'];
if (\$path === '/release') {
    header('Content-Type: application/json');
    echo json_encode(['tag_name' => 'v0.34.3', 'assets' => $assetsPhp]);
    exit;
}
if (\$path === '/dl/OllamaSetup.exe') {
    readfile(__DIR__ . '/OllamaSetup.exe');
    exit;
}
http_response_code(404);
PHP);
        // A digest-et külön, string-interpolációs probléma nélkül fűzzük be.
        if (!$omitAsset && !$omitDigest) {
            $content = file_get_contents($root . '/index.php');
            $content = str_replace('%s', $realDigest, $content);
            file_put_contents($root . '/index.php', $content);
        }

        $port = self::findFreePort();
        $proc = proc_open(
            [PHP_BINARY, '-S', '127.0.0.1:' . $port, '-t', $root],
            [1 => ['file', $root . '/log.txt', 'w'], 2 => ['file', $root . '/log.txt', 'w']],
            $pipes,
            $root
        );
        self::waitForReady($port);
        return ['root' => $root, 'port' => $port, 'proc' => $proc, 'baseUrl' => 'http://127.0.0.1:' . $port];
    }

    public function testDownloadAndVerifyInstallerSucceedsWithMatchingChecksum(): void
    {
        $stub = self::startGithubStub('fake-installer-bytes-12345');

        $result = OllamaProvisioner::downloadAndVerifyInstaller(
            self::$root . '/downloads',
            $stub['baseUrl'] . '/release',
            $stub['baseUrl'] . '/'
        );

        $this->assertSame('ok', $result['status'], json_encode($result));
        $this->assertFileExists($result['path']);
        $this->assertSame('fake-installer-bytes-12345', file_get_contents($result['path']));
        @unlink($result['path']);

        self::stopStub($stub);
    }

    public function testDownloadAndVerifyInstallerRejectsChecksumMismatch(): void
    {
        $stub = self::startGithubStub('fake-installer-bytes-12345', 'sha256:' . str_repeat('0', 64));

        $result = OllamaProvisioner::downloadAndVerifyInstaller(
            self::$root . '/downloads',
            $stub['baseUrl'] . '/release',
            $stub['baseUrl'] . '/'
        );

        $this->assertSame('error', $result['status']);
        $this->assertStringContainsString('ellenőrzése sikertelen', $result['message']);

        self::stopStub($stub);
    }

    public function testDownloadAndVerifyInstallerRejectsUntrustedUrlPrefix(): void
    {
        // A kiadási JSON egy HTTPS-es, de NEM a megbízható előtaggal
        // kezdődő letöltési címet ad vissza — a kódnak MÉG A LETÖLTÉS
        // MEGKÍSÉRLÉSE ELŐTT el kell utasítania ezt (sose próbál
        // ténylegesen kapcsolódni az idegen célponthoz).
        $stub = self::startGithubStub('fake-installer-bytes-12345', null, false, false, 'https://evil.example.com/OllamaSetup.exe');

        $result = OllamaProvisioner::downloadAndVerifyInstaller(
            self::$root . '/downloads',
            $stub['baseUrl'] . '/release',
            OllamaProvisioner::TRUSTED_ASSET_URL_PREFIX
        );

        $this->assertSame('error', $result['status']);
        $this->assertStringContainsString('megbízható forrásból', $result['message']);

        self::stopStub($stub);
    }

    public function testDownloadAndVerifyInstallerRejectsMissingDigest(): void
    {
        $stub = self::startGithubStub('fake-installer-bytes-12345', null, false, true);

        $result = OllamaProvisioner::downloadAndVerifyInstaller(
            self::$root . '/downloads',
            $stub['baseUrl'] . '/release',
            $stub['baseUrl'] . '/'
        );

        $this->assertSame('error', $result['status']);
        $this->assertStringContainsString('checksum', $result['message']);

        self::stopStub($stub);
    }

    public function testDownloadAndVerifyInstallerHandlesMissingAsset(): void
    {
        $stub = self::startGithubStub('fake-installer-bytes-12345', null, true);

        $result = OllamaProvisioner::downloadAndVerifyInstaller(
            self::$root . '/downloads',
            $stub['baseUrl'] . '/release',
            $stub['baseUrl'] . '/'
        );

        $this->assertSame('error', $result['status']);

        self::stopStub($stub);
    }

    public function testDownloadAndVerifyInstallerHandlesNetworkFailure(): void
    {
        $port = self::findFreePort();
        $result = OllamaProvisioner::downloadAndVerifyInstaller(
            self::$root . '/downloads',
            'http://127.0.0.1:' . $port . '/release',
            'http://127.0.0.1:' . $port . '/'
        );

        $this->assertSame('error', $result['status']);
    }

    public function testDownloadAndVerifyInstallerHandlesMalformedReleaseJson(): void
    {
        $root = self::$root . '/malformed_github_stub_' . bin2hex(random_bytes(6));
        mkdir($root, 0775, true);
        file_put_contents($root . '/index.php', "<?php echo 'not json';");
        $port = self::findFreePort();
        $proc = proc_open(
            [PHP_BINARY, '-S', '127.0.0.1:' . $port, '-t', $root],
            [1 => ['file', $root . '/log.txt', 'w'], 2 => ['file', $root . '/log.txt', 'w']],
            $pipes,
            $root
        );
        self::waitForReady($port);

        $result = OllamaProvisioner::downloadAndVerifyInstaller(
            self::$root . '/downloads',
            'http://127.0.0.1:' . $port . '/',
            'http://127.0.0.1:' . $port . '/'
        );

        $this->assertSame('error', $result['status']);

        proc_terminate($proc);
        proc_close($proc);
    }

    // ------------------------------------------------------------------
    // Teljes install() orchestráció — SOSE fut le valódi végrehajtás,
    // ha a letöltés/ellenőrzés már elbukott.
    // ------------------------------------------------------------------

    public function testInstallStopsBeforeExecutionWhenChecksumVerificationFails(): void
    {
        $stub = self::startGithubStub('fake-installer-bytes-12345', 'sha256:' . str_repeat('1', 64));

        // A publikus install() a saját, valódi GITHUB_LATEST_RELEASE_URL/
        // TRUSTED_ASSET_URL_PREFIX konstansait használná — itt ehelyett
        // közvetlenül a két al-lépést bizonyítjuk (downloadAndVerifyInstaller
        // MÁR bebizonyította fent, hogy elutasítja a checksum-eltérést,
        // ez itt azt bizonyítja, hogy egy elbukott letöltés/ellenőrzés
        // UTÁN SOSE hívódik meg runSilentInstaller — nincs "path" kulcs
        // a hiba-válaszban, amit továbbadhatna).
        $downloadResult = OllamaProvisioner::downloadAndVerifyInstaller(
            self::$root . '/downloads',
            $stub['baseUrl'] . '/release',
            $stub['baseUrl'] . '/'
        );

        $this->assertSame('error', $downloadResult['status']);
        $this->assertArrayNotHasKey('path', $downloadResult);

        self::stopStub($stub);
    }
}
