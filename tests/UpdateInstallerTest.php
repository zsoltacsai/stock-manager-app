<?php

declare(strict_types=1);

require_once __DIR__ . '/../src/Database.php';
require_once __DIR__ . '/../src/UpdateInstaller.php';
require_once __DIR__ . '/../src/Settings.php';
require_once __DIR__ . '/../src/BackupManager.php';
require_once __DIR__ . '/../src/GitHubReleaseClient.php';

use PHPUnit\Framework\TestCase;

/**
 * Teszt-dupla a GitHubReleaseClient-hez — a metaadat-hívásokat ÉS a
 * letöltést is helyi fixture-fájlokra cseréli, hogy a teszt SOHA ne
 * induljon valódi hálózati hívást a github.com felé, miközben a
 * PRODUCTION osztály (és annak host-fehérlistája) változatlan marad —
 * lásd tests/GitHubReleaseClientTest.php a fehérlista saját tesztjeiért.
 */
final class FakeGitHubReleaseClient extends GitHubReleaseClient
{
    private array $release;
    private string $manifestPath;
    private string $artifactPath;
    private string $resolvedCommitSha;
    public bool $throwOnDownload = false;

    public function __construct(array $release, string $manifestPath, string $artifactPath, string $resolvedCommitSha)
    {
        parent::__construct('zsoltacsai', 'stock-manager-app');
        $this->release = $release;
        $this->manifestPath = $manifestPath;
        $this->artifactPath = $artifactPath;
        $this->resolvedCommitSha = $resolvedCommitSha;
    }

    public function fetchLatestRelease(): array
    {
        return $this->release;
    }

    public function resolveTagCommitSha(string $tag): string
    {
        return $this->resolvedCommitSha;
    }

    public function downloadAsset(string $url, string $destPath): void
    {
        if ($this->throwOnDownload) {
            throw new RuntimeException('Szimulált letöltési hiba.');
        }
        $source = $url === 'fake://manifest' ? $this->manifestPath : $this->artifactPath;
        if (!copy($source, $destPath)) {
            throw new RuntimeException('Fixture-másolás sikertelen: ' . $source);
        }
    }
}

final class ThrowingBackupManager extends BackupManager
{
    public function __construct()
    {
    }

    public function run(array $settings): array
    {
        throw new RuntimeException('Szimulált biztonsági mentési hiba.');
    }
}

final class RestoreFailingBackupManager extends BackupManager
{
    public function restoreFromFile(string $sourcePath): array
    {
        throw new RuntimeException('Szimulált visszaállítási hiba.');
    }
}

final class UpdateInstallerTest extends TestCase
{
    /** @var string[] */
    private array $tempPaths = [];
    private string $fakeCommit;

    protected function setUp(): void
    {
        $this->fakeCommit = str_repeat('a', 40);
    }

    protected function tearDown(): void
    {
        foreach ($this->tempPaths as $path) {
            if (is_dir($path)) {
                $this->removeDirectory($path);
            } elseif (is_file($path)) {
                @unlink($path);
            }
        }
    }

    // ---- Fixture-építők ----

    private function tempDir(): string
    {
        $path = sys_get_temp_dir() . '/ft_installer_test_' . bin2hex(random_bytes(6));
        mkdir($path, 0775, true);
        $this->tempPaths[] = $path;
        return $path;
    }

    private function removeDirectory(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        $items = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($items as $item) {
            $item->isDir() ? @rmdir($item->getPathname()) : @unlink($item->getPathname());
        }
        @rmdir($dir);
    }

    private function copyTree(string $from, string $to): void
    {
        @mkdir($to, 0775, true);
        $items = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($from, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::SELF_FIRST);
        foreach ($items as $item) {
            $relative = substr($item->getPathname(), strlen($from) + 1);
            $dest = $to . '/' . str_replace('\\', '/', $relative);
            if ($item->isDir()) {
                @mkdir($dest, 0775, true);
            } else {
                @mkdir(dirname($dest), 0775, true);
                copy($item->getPathname(), $dest);
            }
        }
    }

    private function setAppVersionConstant(string $path, string $version): void
    {
        $content = file_get_contents($path);
        $content = preg_replace("/public const CURRENT = '[^']*';/", "public const CURRENT = '$version';", $content, 1);
        file_put_contents($path, $content);
    }

    /** Egy minimális, de VALÓDI (a projekt tényleges forrásfájljaiból másolt) "élő app-gyökér" fixture. */
    private function buildLiveAppRoot(string $version, string $sqlitePath): string
    {
        $projectRoot = dirname(__DIR__);
        $root = $this->tempDir();

        $this->copyTree($projectRoot . '/src', $root . '/src');
        $this->copyTree($projectRoot . '/webroot/api', $root . '/webroot/api');
        @mkdir($root . '/webroot', 0775, true);
        copy($projectRoot . '/webroot/index.php', $root . '/webroot/index.php');
        copy($projectRoot . '/schema.sql', $root . '/schema.sql');
        copy($projectRoot . '/schema.mysql.sql', $root . '/schema.mysql.sql');
        @mkdir($root . '/tools', 0775, true);
        copy($projectRoot . '/tools/update-post-deploy-check.php', $root . '/tools/update-post-deploy-check.php');
        @mkdir($root . '/data', 0775, true);
        @mkdir($root . '/config', 0775, true);

        $this->setAppVersionConstant($root . '/src/AppVersion.php', $version);

        file_put_contents(
            $root . '/config/config.php',
            "<?php\nreturn ['db' => ['driver' => 'sqlite', 'sqlite' => ['path' => " . var_export($sqlitePath, true) . "]], 'MARKER' => 'live-config-untouched', 'update' => ['php_cli_binary' => " . var_export(PHP_BINARY, true) . "]];\n"
        );

        return $root;
    }

    /**
     * @return array{0:string,1:string,2:array} [zip elérési útja, manifest.json elérési útja, manifest tömb]
     */
    private function buildReleaseZip(string $version, bool $brokenHealthCheck = false): array
    {
        $projectRoot = dirname(__DIR__);
        $releaseRoot = $this->tempDir();

        $this->copyTree($projectRoot . '/src', $releaseRoot . '/src');
        $this->copyTree($projectRoot . '/webroot/api', $releaseRoot . '/webroot/api');
        @mkdir($releaseRoot . '/webroot', 0775, true);
        copy($projectRoot . '/webroot/index.php', $releaseRoot . '/webroot/index.php');
        copy($projectRoot . '/schema.sql', $releaseRoot . '/schema.sql');
        copy($projectRoot . '/schema.mysql.sql', $releaseRoot . '/schema.mysql.sql');
        @mkdir($releaseRoot . '/tools', 0775, true);
        if ($brokenHealthCheck) {
            file_put_contents($releaseRoot . '/tools/update-post-deploy-check.php', "<?php\necho json_encode(['success' => false, 'error' => 'Szándékosan meghibásodó egészség-ellenőrzés (teszt).']);\nexit(1);\n");
        } else {
            copy($projectRoot . '/tools/update-post-deploy-check.php', $releaseRoot . '/tools/update-post-deploy-check.php');
        }

        $this->setAppVersionConstant($releaseRoot . '/src/AppVersion.php', $version);

        $zipPath = $this->tempDir() . '/fountaintrade-' . $version . '.zip';
        $zip = new ZipArchive();
        $zip->open($zipPath, ZipArchive::CREATE);
        $items = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($releaseRoot, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::SELF_FIRST);
        foreach ($items as $item) {
            $relative = str_replace('\\', '/', substr($item->getPathname(), strlen($releaseRoot) + 1));
            $item->isDir() ? $zip->addEmptyDir($relative) : $zip->addFile($item->getPathname(), $relative);
        }
        $zip->close();

        $manifest = [
            'product'                => 'FountainTrade',
            'channel'                => 'stable',
            'version'                => $version,
            'commit'                 => $this->fakeCommit,
            'artifact'               => basename($zipPath),
            'sha256'                 => hash_file('sha256', $zipPath),
            'min_upgradable_version' => '1.0.0',
        ];
        $manifestPath = $this->tempDir() . '/manifest.json';
        file_put_contents($manifestPath, json_encode($manifest, JSON_UNESCAPED_UNICODE));

        return [$zipPath, $manifestPath, $manifest];
    }

    private function fakeRelease(string $tag, string $manifestPath, string $artifactName): array
    {
        return [
            'tag_name'    => $tag,
            'body'        => 'Release notes for ' . $tag,
            'published_at' => date('c'),
            'assets'      => [
                ['name' => 'manifest.json', 'browser_download_url' => 'fake://manifest'],
                ['name' => $artifactName, 'browser_download_url' => 'fake://artifact'],
            ],
        ];
    }

    private function newTempDatabase(): array
    {
        $sqlitePath = $this->tempDir() . '/db_' . bin2hex(random_bytes(4)) . '.sqlite';
        $db = new Database(['driver' => 'sqlite', 'sqlite' => ['path' => $sqlitePath]], dirname(__DIR__));
        return [$db, $sqlitePath];
    }

    // ---- Sikeres, teljes frissítés ----

    public function testSuccessfulCompleteUpdateDeploysNewCodeAndRecordsHistory(): void
    {
        [$db, $sqlitePath] = $this->newTempDatabase();
        $appRoot = $this->buildLiveAppRoot(AppVersion::CURRENT, $sqlitePath);
        [$zipPath, $manifestPath] = $this->buildReleaseZip('1.1.0');

        $github = new FakeGitHubReleaseClient($this->fakeRelease('v1.1.0', $manifestPath, basename($zipPath)), $manifestPath, $zipPath, $this->fakeCommit);
        $settingsStore = new Settings($appRoot . '/data/settings.json');
        $config = ['db' => ['driver' => 'sqlite', 'sqlite' => ['path' => $sqlitePath]], 'update' => ['php_cli_binary' => PHP_BINARY]];
        $backupManager = new BackupManager($config['db'], $appRoot . '/data/backups');

        $installer = new UpdateInstaller($db, $config, $appRoot, $settingsStore, $github, null, $backupManager);
        $result = $installer->install('admin', 'test-admin');

        $this->assertTrue($result['ok'] ?? false, 'install() sikertelen: ' . ($result['error'] ?? 'ismeretlen'));
        $this->assertSame(AppVersion::CURRENT, $result['from_version']);
        $this->assertSame('1.1.0', $result['to_version']);

        // A LIVE AppVersion.php ténylegesen az új verziót tartalmazza a lemezen.
        $this->assertStringContainsString("CURRENT = '1.1.0'", file_get_contents($appRoot . '/src/AppVersion.php'));

        $state = $db->getUpdateState();
        $this->assertSame('completed', $state['state']);
        $this->assertSame('1.1.0', $state['current_version']);
        $this->assertSame('1.1.0', $state['last_successful_update_version']);
        $this->assertNotEmpty($state['last_successful_update_at']);

        $history = $db->listUpdateHistory(10);
        $this->assertCount(1, $history);
        $this->assertSame('completed', $history[0]['state']);
        $this->assertSame(AppVersion::CURRENT, $history[0]['from_version']);
        $this->assertSame('1.1.0', $history[0]['to_version']);
        $this->assertNotEmpty($history[0]['backup_reference']);

        // Karbantartási mód a sikeres futás VÉGÉRE ki van kapcsolva.
        $this->assertFalse((bool) $settingsStore->read()['maintenance_mode_active']);

        // A zár fel van oldva — egy következő install() ismét elindulhat.
        $this->assertFalse($db->isUpdateLockHeld());
    }

    /**
     * FONTOS: az UpdateInstaller a JELENLEG FUTÓ PHP-folyamatba MÁR
     * betöltött AppVersion::CURRENT konstanst tekinti "a telepített
     * verziónak" — ezt a fixture-be írt AppVersion.php fájl tartalma NEM
     * tudja felülírni (PHP nem tölti újra a már betöltött osztályokat).
     * Emiatt a verzió-ÖSSZEHASONLÍTÁS logikáját tesztelő esetek a
     * TÉNYLEGESEN futó AppVersion::CURRENT-hez KÉPEST építik a
     * cél-verziókat, nem tetszőleges fix stringekhez képest — a fájlszintű
     * "a live kód ténylegesen X verziót tartalmaz-e" ellenőrzések (lásd a
     * fenti sikeres/rollback tesztek) ettől függetlenül helyesek maradnak,
     * mert azok a KIMÁSOLT FÁJL TARTALMÁT vizsgálják, nem az in-process
     * konstanst.
     */
    public function testAlreadyUpToDateIsANoOp(): void
    {
        [$db, $sqlitePath] = $this->newTempDatabase();
        $appRoot = $this->buildLiveAppRoot(AppVersion::CURRENT, $sqlitePath);
        [$zipPath, $manifestPath] = $this->buildReleaseZip(AppVersion::CURRENT);

        $github = new FakeGitHubReleaseClient($this->fakeRelease('v' . AppVersion::CURRENT, $manifestPath, basename($zipPath)), $manifestPath, $zipPath, $this->fakeCommit);
        $settingsStore = new Settings($appRoot . '/data/settings.json');
        $config = ['db' => ['driver' => 'sqlite', 'sqlite' => ['path' => $sqlitePath]], 'update' => ['php_cli_binary' => PHP_BINARY]];

        $installer = new UpdateInstaller($db, $config, $appRoot, $settingsStore, $github);
        $result = $installer->install('admin', 'test-admin');

        $this->assertTrue($result['ok'] ?? false);
        $this->assertTrue($result['already_up_to_date'] ?? false);
        $this->assertEmpty($db->listUpdateHistory(10));
    }

    // ---- Downgrade / min-verzió blokkolás ----

    public function testDowngradeIsBlockedAndNothingIsDeployed(): void
    {
        [$db, $sqlitePath] = $this->newTempDatabase();
        $appRoot = $this->buildLiveAppRoot(AppVersion::CURRENT, $sqlitePath);
        // Bármi, ami garantáltan a ténylegesen futó AppVersion::CURRENT ALATT van.
        $olderVersion = '0.0.1';
        [$zipPath, $manifestPath, $manifest] = $this->buildReleaseZip($olderVersion);
        $manifest['min_upgradable_version'] = '0.0.1';
        $manifest['sha256'] = hash_file('sha256', $zipPath);
        file_put_contents($manifestPath, json_encode($manifest, JSON_UNESCAPED_UNICODE));

        $github = new FakeGitHubReleaseClient($this->fakeRelease('v' . $olderVersion, $manifestPath, basename($zipPath)), $manifestPath, $zipPath, $this->fakeCommit);
        $settingsStore = new Settings($appRoot . '/data/settings.json');
        $config = ['db' => ['driver' => 'sqlite', 'sqlite' => ['path' => $sqlitePath]], 'update' => ['php_cli_binary' => PHP_BINARY]];

        $installer = new UpdateInstaller($db, $config, $appRoot, $settingsStore, $github);
        $result = $installer->install('admin', 'test-admin');

        $this->assertFalse($result['ok'] ?? true);
        $this->assertStringContainsString('Downgrade', $result['error']);
        $this->assertStringContainsString("CURRENT = '" . AppVersion::CURRENT . "'", file_get_contents($appRoot . '/src/AppVersion.php'));
        $this->assertSame('failed', $db->getUpdateState()['state']);
    }

    public function testBelowMinimumUpgradableVersionIsBlocked(): void
    {
        [$db, $sqlitePath] = $this->newTempDatabase();
        $appRoot = $this->buildLiveAppRoot(AppVersion::CURRENT, $sqlitePath);
        [$major, $minor, $patch] = AppVersion::parse(AppVersion::CURRENT);
        $target = "$major.$minor." . ($patch + 1);
        [$zipPath, $manifestPath, $manifest] = $this->buildReleaseZip($target);
        // pontosan a kérés 10. pontjának példája: installed < min_upgradable_version(== target) -> tiltva
        $manifest['min_upgradable_version'] = $target;
        $manifest['sha256'] = hash_file('sha256', $zipPath);
        file_put_contents($manifestPath, json_encode($manifest, JSON_UNESCAPED_UNICODE));

        $github = new FakeGitHubReleaseClient($this->fakeRelease('v' . $target, $manifestPath, basename($zipPath)), $manifestPath, $zipPath, $this->fakeCommit);
        $settingsStore = new Settings($appRoot . '/data/settings.json');
        $config = ['db' => ['driver' => 'sqlite', 'sqlite' => ['path' => $sqlitePath]], 'update' => ['php_cli_binary' => PHP_BINARY]];

        $installer = new UpdateInstaller($db, $config, $appRoot, $settingsStore, $github);
        $result = $installer->install('admin', 'test-admin');

        $this->assertFalse($result['ok'] ?? true);
        $this->assertStringContainsString('közvetlenül frissíthető', $result['error']);
    }

    // ---- Manifest/identitás-ellenőrzések ----

    public function testWrongProductInManifestIsRejected(): void
    {
        [$db, $sqlitePath] = $this->newTempDatabase();
        $appRoot = $this->buildLiveAppRoot(AppVersion::CURRENT, $sqlitePath);
        [$zipPath, $manifestPath, $manifest] = $this->buildReleaseZip('1.1.0');
        $manifest['product'] = 'SomeOtherApp';
        file_put_contents($manifestPath, json_encode($manifest, JSON_UNESCAPED_UNICODE));

        $github = new FakeGitHubReleaseClient($this->fakeRelease('v1.1.0', $manifestPath, basename($zipPath)), $manifestPath, $zipPath, $this->fakeCommit);
        $settingsStore = new Settings($appRoot . '/data/settings.json');
        $config = ['db' => ['driver' => 'sqlite', 'sqlite' => ['path' => $sqlitePath]], 'update' => ['php_cli_binary' => PHP_BINARY]];

        $installer = new UpdateInstaller($db, $config, $appRoot, $settingsStore, $github);
        $result = $installer->install('admin', 'test-admin');

        $this->assertFalse($result['ok'] ?? true);
        $this->assertStringContainsString('más terméket', $result['error']);
    }

    public function testCommitMismatchBetweenManifestAndGitHubIsRejected(): void
    {
        [$db, $sqlitePath] = $this->newTempDatabase();
        $appRoot = $this->buildLiveAppRoot(AppVersion::CURRENT, $sqlitePath);
        [$zipPath, $manifestPath] = $this->buildReleaseZip('1.1.0');

        // A GitHub-tól "függetlenül feloldott" commit MÁS, mint amit a manifest állít.
        $differentCommit = str_repeat('f', 40);
        $github = new FakeGitHubReleaseClient($this->fakeRelease('v1.1.0', $manifestPath, basename($zipPath)), $manifestPath, $zipPath, $differentCommit);
        $settingsStore = new Settings($appRoot . '/data/settings.json');
        $config = ['db' => ['driver' => 'sqlite', 'sqlite' => ['path' => $sqlitePath]], 'update' => ['php_cli_binary' => PHP_BINARY]];

        $installer = new UpdateInstaller($db, $config, $appRoot, $settingsStore, $github);
        $result = $installer->install('admin', 'test-admin');

        $this->assertFalse($result['ok'] ?? true);
        $this->assertStringContainsString('commit-SHA', $result['error']);
    }

    public function testChecksumMismatchIsRejectedBeforeAnyFileIsDeployed(): void
    {
        [$db, $sqlitePath] = $this->newTempDatabase();
        $appRoot = $this->buildLiveAppRoot(AppVersion::CURRENT, $sqlitePath);
        [$zipPath, $manifestPath, $manifest] = $this->buildReleaseZip('1.1.0');
        $manifest['sha256'] = str_repeat('0', 64); // hamis checksum
        file_put_contents($manifestPath, json_encode($manifest, JSON_UNESCAPED_UNICODE));

        $github = new FakeGitHubReleaseClient($this->fakeRelease('v1.1.0', $manifestPath, basename($zipPath)), $manifestPath, $zipPath, $this->fakeCommit);
        $settingsStore = new Settings($appRoot . '/data/settings.json');
        $config = ['db' => ['driver' => 'sqlite', 'sqlite' => ['path' => $sqlitePath]], 'update' => ['php_cli_binary' => PHP_BINARY]];

        $installer = new UpdateInstaller($db, $config, $appRoot, $settingsStore, $github);
        $result = $installer->install('admin', 'test-admin');

        $this->assertFalse($result['ok'] ?? true);
        $this->assertStringContainsString('Checksum', $result['error']);
        $this->assertStringContainsString("CURRENT = '" . AppVersion::CURRENT . "'", file_get_contents($appRoot . '/src/AppVersion.php'));
        $this->assertFalse((bool) $settingsStore->read()['maintenance_mode_active']);
    }

    // ---- Backup kötelező ----

    public function testBackupFailureAbortsBeforeMaintenanceModeOrAnyFileWrite(): void
    {
        [$db, $sqlitePath] = $this->newTempDatabase();
        $appRoot = $this->buildLiveAppRoot(AppVersion::CURRENT, $sqlitePath);
        [$zipPath, $manifestPath] = $this->buildReleaseZip('1.1.0');

        $github = new FakeGitHubReleaseClient($this->fakeRelease('v1.1.0', $manifestPath, basename($zipPath)), $manifestPath, $zipPath, $this->fakeCommit);
        $settingsStore = new Settings($appRoot . '/data/settings.json');
        $config = ['db' => ['driver' => 'sqlite', 'sqlite' => ['path' => $sqlitePath]], 'update' => ['php_cli_binary' => PHP_BINARY]];

        $installer = new UpdateInstaller($db, $config, $appRoot, $settingsStore, $github, null, new ThrowingBackupManager());
        $result = $installer->install('admin', 'test-admin');

        $this->assertFalse($result['ok'] ?? true);
        $this->assertStringContainsString('biztonsági mentési hiba', $result['error']);
        $this->assertStringContainsString("CURRENT = '" . AppVersion::CURRENT . "'", file_get_contents($appRoot . '/src/AppVersion.php'));
        $this->assertFalse((bool) $settingsStore->read()['maintenance_mode_active'], 'A karbantartási mód SOSE kapcsolódhat be, ha a backup már elbukott.');
        $this->assertSame('failed', $db->getUpdateState()['state']);

        $history = $db->listUpdateHistory(10);
        $this->assertSame('failed', $history[0]['state']);
        $this->assertNull($history[0]['rollback_state']);
    }

    // ---- Egészség-ellenőrzés hibája -> rollback ----

    public function testHealthCheckFailureTriggersFullRollback(): void
    {
        [$db, $sqlitePath] = $this->newTempDatabase();
        $appRoot = $this->buildLiveAppRoot(AppVersion::CURRENT, $sqlitePath);
        [$zipPath, $manifestPath] = $this->buildReleaseZip('1.1.0', brokenHealthCheck: true);

        $github = new FakeGitHubReleaseClient($this->fakeRelease('v1.1.0', $manifestPath, basename($zipPath)), $manifestPath, $zipPath, $this->fakeCommit);
        $settingsStore = new Settings($appRoot . '/data/settings.json');
        $config = ['db' => ['driver' => 'sqlite', 'sqlite' => ['path' => $sqlitePath]], 'update' => ['php_cli_binary' => PHP_BINARY]];
        $backupManager = new BackupManager($config['db'], $appRoot . '/data/backups');

        $installer = new UpdateInstaller($db, $config, $appRoot, $settingsStore, $github, null, $backupManager);
        $result = $installer->install('admin', 'test-admin');

        $this->assertFalse($result['ok'] ?? true);
        $this->assertSame('success', $result['rollback_state']);

        // A LIVE kód VISSZA lett állítva a régi verzióra.
        $this->assertStringContainsString("CURRENT = '" . AppVersion::CURRENT . "'", file_get_contents($appRoot . '/src/AppVersion.php'));

        $state = $db->getUpdateState();
        $this->assertSame('rolled_back', $state['state']);

        $history = $db->listUpdateHistory(10);
        $this->assertSame('rolled_back', $history[0]['state']);
        $this->assertSame('success', $history[0]['rollback_state']);

        // A karbantartási mód a sikeres rollback UTÁN ismét ki van kapcsolva.
        $this->assertFalse((bool) $settingsStore->read()['maintenance_mode_active']);
        $this->assertFalse($db->isUpdateLockHeld());
    }

    public function testRollbackFailureLeadsToManualRecoveryRequiredAndKeepsMaintenanceModeOn(): void
    {
        [$db, $sqlitePath] = $this->newTempDatabase();
        $appRoot = $this->buildLiveAppRoot(AppVersion::CURRENT, $sqlitePath);
        [$zipPath, $manifestPath] = $this->buildReleaseZip('1.1.0', brokenHealthCheck: true);

        $github = new FakeGitHubReleaseClient($this->fakeRelease('v1.1.0', $manifestPath, basename($zipPath)), $manifestPath, $zipPath, $this->fakeCommit);
        $settingsStore = new Settings($appRoot . '/data/settings.json');
        $config = ['db' => ['driver' => 'sqlite', 'sqlite' => ['path' => $sqlitePath]], 'update' => ['php_cli_binary' => PHP_BINARY]];
        // A KEZDETI backup sikeres (run() valódi), de a rollback közbeni DB-visszaállítás szimuláltan elbukik.
        $backupManager = new RestoreFailingBackupManager($config['db'], $appRoot . '/data/backups');

        $installer = new UpdateInstaller($db, $config, $appRoot, $settingsStore, $github, null, $backupManager);
        $result = $installer->install('admin', 'test-admin');

        $this->assertFalse($result['ok'] ?? true);
        $this->assertSame('failed', $result['rollback_state']);

        $state = $db->getUpdateState();
        $this->assertSame('manual_recovery_required', $state['state']);

        $history = $db->listUpdateHistory(10);
        $this->assertSame('manual_recovery_required', $history[0]['state']);
        $this->assertSame('failed', $history[0]['rollback_state']);

        // A karbantartási mód SZÁNDÉKOSAN BEKAPCSOLVA marad — lásd UpdateInstaller docblockja.
        $this->assertTrue((bool) $settingsStore->read()['maintenance_mode_active']);
    }

    // ---- Konfiguráció-megőrzés ----

    public function testConfigAndDataDirectoriesAreNeverTouchedByADeploy(): void
    {
        [$db, $sqlitePath] = $this->newTempDatabase();
        $appRoot = $this->buildLiveAppRoot(AppVersion::CURRENT, $sqlitePath);
        [$zipPath, $manifestPath] = $this->buildReleaseZip('1.1.0');

        $configContentBefore = file_get_contents($appRoot . '/config/config.php');
        file_put_contents($appRoot . '/data/settings.json', json_encode(['custom_marker' => 'must-survive']));
        $settingsJsonBefore = file_get_contents($appRoot . '/data/settings.json');

        $github = new FakeGitHubReleaseClient($this->fakeRelease('v1.1.0', $manifestPath, basename($zipPath)), $manifestPath, $zipPath, $this->fakeCommit);
        $settingsStore = new Settings($appRoot . '/data/settings.json');
        $config = ['db' => ['driver' => 'sqlite', 'sqlite' => ['path' => $sqlitePath]], 'update' => ['php_cli_binary' => PHP_BINARY]];
        $backupManager = new BackupManager($config['db'], $appRoot . '/data/backups');

        $installer = new UpdateInstaller($db, $config, $appRoot, $settingsStore, $github, null, $backupManager);
        $result = $installer->install('admin', 'test-admin');

        $this->assertTrue($result['ok'] ?? false, $result['error'] ?? '');
        $this->assertSame($configContentBefore, file_get_contents($appRoot . '/config/config.php'), 'A config/config.php-t egy frissítés SOSE írhatja felül.');

        $settingsAfter = json_decode(file_get_contents($appRoot . '/data/settings.json'), true);
        $this->assertSame('must-survive', $settingsAfter['custom_marker'] ?? null, 'Egy meglévő settings.json egyedi mezője SOSE veszhet el egy frissítés során.');
    }

    // ---- Konkurrencia / zár ----

    public function testConcurrentInstallIsBlockedByTheDurableLock(): void
    {
        [$db, $sqlitePath] = $this->newTempDatabase();
        $appRoot = $this->buildLiveAppRoot(AppVersion::CURRENT, $sqlitePath);
        [$zipPath, $manifestPath] = $this->buildReleaseZip('1.1.0');
        $github = new FakeGitHubReleaseClient($this->fakeRelease('v1.1.0', $manifestPath, basename($zipPath)), $manifestPath, $zipPath, $this->fakeCommit);
        $settingsStore = new Settings($appRoot . '/data/settings.json');
        $config = ['db' => ['driver' => 'sqlite', 'sqlite' => ['path' => $sqlitePath]], 'update' => ['php_cli_binary' => PHP_BINARY]];

        // Egy MÁSIK folyamat/kérés zárja a sort, mintha épp futna egy telepítés.
        $otherLock = new UpdateLock($db, 'other-process-token', 'other-host');
        $this->assertTrue($otherLock->acquire());

        $installer = new UpdateInstaller($db, $config, $appRoot, $settingsStore, $github);
        $result = $installer->install('admin', 'test-admin');

        $this->assertFalse($result['ok'] ?? true);
        $this->assertSame('already_running', $result['reason']);
        $this->assertEmpty($db->listUpdateHistory(10), 'Egy elutasított, konkurrens indítási kísérlet ne kerüljön az előzményekbe.');

        $otherLock->release();
    }
}
