<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * C6 javítás regressziós tesztje: a decryptToTempFile() által létrehozott
 * ideiglenes, visszafejtett fájl (ami a teljes adatbázist, illetve a
 * settings.json sidecar esetén minden integrációs hitelesítő adatot
 * tartalmazza) 0600 jogosultsággal jöjjön létre — ne örökölje a folyamat
 * umaskját (jellemzően 0644, világ-olvasható).
 *
 * A metódusok private-ek, ezért Reflection-nel hívjuk — ugyanaz a minta,
 * mint AuthTest.php-ban.
 */
final class BackupManagerTest extends TestCase
{
    private string $tmpRoot;

    protected function setUp(): void
    {
        $this->tmpRoot = sys_get_temp_dir() . '/sm_backupmgr_test_' . bin2hex(random_bytes(6));
        mkdir($this->tmpRoot . '/data/backups', 0775, true);
    }

    protected function tearDown(): void
    {
        $this->removeDir($this->tmpRoot);
    }

    private function removeDir(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        foreach (scandir($dir) as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $path = $dir . '/' . $item;
            is_dir($path) ? $this->removeDir($path) : @unlink($path);
        }
        @rmdir($dir);
    }

    private function invokePrivate(object $obj, string $method, array $args)
    {
        $ref = new ReflectionMethod(get_class($obj), $method);
        $ref->setAccessible(true);
        return $ref->invokeArgs($obj, $args);
    }

    public function testDecryptedTempFileHasRestrictivePermissionsOnPosix(): void
    {
        if (DIRECTORY_SEPARATOR === '\\') {
            $this->markTestSkipped(
                'POSIX fájl-jogosultsági bitek (0600) Windows alatt nem értelmezhetők ugyanúgy — ' .
                'ez a teszt csak POSIX rendszeren fut. A chmod()-hívás maga (lásd BackupManager::' .
                'decryptToTempFile()) minden platformon lefut, de az eredmény csak POSIX-on ellenőrizhető.'
            );
        }

        $backupDir = $this->tmpRoot . '/data/backups';
        $manager = new BackupManager(['driver' => 'sqlite', 'sqlite' => ['path' => $this->tmpRoot . '/unused.sqlite']], $backupDir);

        $plainPath = $backupDir . '/plain-test.txt';
        file_put_contents($plainPath, 'erzekeny-sima-szoveges-tartalom-teszthez');
        $encPath = $backupDir . '/plain-test.txt.enc';

        // encryptFileInPlace() törli az eredeti sima fájlt a végén — ez a
        // valós lefolyás (backup készítéskor is így történik).
        $this->invokePrivate($manager, 'encryptFileInPlace', [$plainPath, $encPath]);
        $this->assertFileDoesNotExist($plainPath);
        $this->assertFileExists($encPath);

        $tmpPath = $this->invokePrivate($manager, 'decryptToTempFile', [$encPath, '.txt']);
        try {
            $this->assertFileExists($tmpPath);
            $perms = fileperms($tmpPath) & 0777;
            $this->assertSame(0600, $perms, sprintf(
                'A visszafejtett ideiglenes fájlnak 0600 jogosultsággal kell rendelkeznie, %o helyett.',
                $perms
            ));
        } finally {
            @unlink($tmpPath);
        }
    }

    public function testEncryptedBackupFileAlsoHasRestrictivePermissionsOnPosix(): void
    {
        if (DIRECTORY_SEPARATOR === '\\') {
            $this->markTestSkipped('POSIX fájl-jogosultsági bitek Windows alatt nem értelmezhetők ugyanúgy.');
        }

        $backupDir = $this->tmpRoot . '/data/backups';
        $manager = new BackupManager(['driver' => 'sqlite', 'sqlite' => ['path' => $this->tmpRoot . '/unused.sqlite']], $backupDir);

        $plainPath = $backupDir . '/plain-test2.txt';
        file_put_contents($plainPath, 'meg-tobb-erzekeny-tartalom');
        $encPath = $backupDir . '/plain-test2.txt.enc';

        $this->invokePrivate($manager, 'encryptFileInPlace', [$plainPath, $encPath]);

        $perms = fileperms($encPath) & 0777;
        $this->assertSame(0600, $perms, sprintf('A titkosított mentés-fájlnak is 0600 jogosultsággal kell rendelkeznie, %o helyett.', $perms));
    }

    // ------------------------------------------------------------------
    // P0-2 regresszió: a backup fájlnevek 1 másodperces felbontása miatt a
    // visszaállítás-előtti biztonsági mentés név-ütközés esetén csendben
    // felülírhatta a ténylegesen visszaállítandó forrás-mentést — empirikusan
    // reprodukálva és javítva (lásd BackupManager::generateBackupFilename()/
    // ensureSafetyBackupFilenameDoesNotCollideWithSource()).
    // ------------------------------------------------------------------

    public function testGeneratedBackupFilenamesAreUniqueEvenWithinTheSameSecond(): void
    {
        $manager = new BackupManager(
            ['driver' => 'sqlite', 'sqlite' => ['path' => $this->tmpRoot . '/unused.sqlite']],
            $this->tmpRoot . '/data/backups'
        );

        $names = [];
        for ($i = 0; $i < 200; $i++) {
            $names[] = $this->invokePrivate($manager, 'generateBackupFilename', []);
        }

        $this->assertCount(
            200,
            array_unique($names),
            'A generateBackupFilename() 200 gyors, egymást követő hívása közül legalább kettő ' .
            'azonos nevet adott — ez pontosan a P0-2 eredeti hibájának előfeltétele (1 másodperces ' .
            'felbontású, disambiguator nélküli fájlnév).'
        );
    }

    public function testSafetyBackupCollisionGuardAbortsWhenFilenamesWouldMatch(): void
    {
        $manager = new BackupManager(
            ['driver' => 'sqlite', 'sqlite' => ['path' => $this->tmpRoot . '/unused.sqlite']],
            $this->tmpRoot . '/data/backups'
        );

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/egybeesne/');
        $this->invokePrivate($manager, 'ensureSafetyBackupFilenameDoesNotCollideWithSource', [
            'stockmanager_backup_20260101_120000_deadbeef.sqlite.enc',
            $this->tmpRoot . '/data/backups/stockmanager_backup_20260101_120000_deadbeef.sqlite.enc',
        ]);
    }

    public function testSafetyBackupCollisionGuardAllowsDistinctFilenames(): void
    {
        $manager = new BackupManager(
            ['driver' => 'sqlite', 'sqlite' => ['path' => $this->tmpRoot . '/unused.sqlite']],
            $this->tmpRoot . '/data/backups'
        );

        $this->invokePrivate($manager, 'ensureSafetyBackupFilenameDoesNotCollideWithSource', [
            'stockmanager_backup_20260101_120000_deadbeef.sqlite.enc',
            $this->tmpRoot . '/data/backups/stockmanager_backup_20260101_120000_c0ffee00.sqlite.enc',
        ]);
        // Nem dobott kivételt — pontosan ez az elvárt viselkedés különböző nevekre.
        $this->addToAssertionCount(1);
    }

    public function testBackupThenRestoreRoundTripNeverOverwritesSourceAndSucceeds(): void
    {
        $livePath = $this->tmpRoot . '/live.sqlite';
        $db = new Database(['driver' => 'sqlite', 'sqlite' => ['path' => $livePath]], dirname(__DIR__));
        $db->pdo()->exec("INSERT INTO products (name) VALUES ('ORIGINAL-MARKER-PRODUCT')");
        unset($db);

        $backupDir = $this->tmpRoot . '/data/backups';
        $manager = new BackupManager(['driver' => 'sqlite', 'sqlite' => ['path' => $livePath]], $backupDir);

        $backupResult = $manager->run(['backup_retention_count' => 10, 'backup_provider' => 'none']);
        $sourceFilename = $backupResult['filename'];
        $sourcePath = $backupDir . '/' . $sourceFilename;
        $this->assertFileExists($sourcePath);
        $originalSourceBytes = file_get_contents($sourcePath);

        // Módosítjuk az élő adatbázist, hogy a visszaállítás hatása ténylegesen megfigyelhető legyen.
        $liveDb = new PDO('sqlite:' . $livePath);
        $liveDb->exec('DELETE FROM products');
        $liveDb->exec("INSERT INTO products (name) VALUES ('POST-BACKUP-CHANGE')");
        unset($liveDb);

        $restoreResult = $manager->restoreFromFile($sourcePath);

        // A forrásfájl (amiből visszaállítottunk) BYTE-RA PONTOSAN változatlan maradt.
        $this->assertFileExists($sourcePath, 'A visszaállítás forrásfájljának meg kell maradnia a lemezen.');
        $this->assertSame(
            $originalSourceBytes,
            file_get_contents($sourcePath),
            'A forrás mentés-fájl tartalma nem változhat a visszaállítás során — ez pontosan a P0-2 hiba.'
        );

        // A visszaállítás ténylegesen lefutott: az élő DB az EREDETI markert tartalmazza.
        $verifyDb = new PDO('sqlite:' . $livePath);
        $names = $verifyDb->query('SELECT name FROM products')->fetchAll(PDO::FETCH_COLUMN);
        $this->assertSame(
            ['ORIGINAL-MARKER-PRODUCT'],
            $names,
            'A visszaállítás után az élő adatbázisnak az EREDETI (mentéskori) állapotot kell tükröznie.'
        );
        unset($verifyDb);

        // A visszaállítás-előtti biztonsági mentés külön fájlként létrejött, és elérhető marad.
        $this->assertNotSame(
            $sourceFilename,
            $restoreResult['safety_backup'],
            'A biztonsági mentésnek KÜLÖN fájlnak kell lennie, nem a forrás felülírásának.'
        );
        $this->assertFileExists(
            $backupDir . '/' . $restoreResult['safety_backup'],
            'A visszaállítás-előtti biztonsági mentésnek elérhetőnek kell maradnia.'
        );

        // Mindkét fájl (forrás + biztonsági mentés) szerepel a listázásban.
        $localFilenames = array_column($manager->listLocal(), 'filename');
        $this->assertContains($sourceFilename, $localFilenames);
        $this->assertContains($restoreResult['safety_backup'], $localFilenames);
    }

    public function testRestoreFromCorruptBackupFailsCleanlyWithoutTouchingLiveDatabase(): void
    {
        $livePath = $this->tmpRoot . '/live2.sqlite';
        $db = new Database(['driver' => 'sqlite', 'sqlite' => ['path' => $livePath]], dirname(__DIR__));
        $db->pdo()->exec("INSERT INTO products (name) VALUES ('SHOULD-SURVIVE-A-FAILED-RESTORE')");
        unset($db);

        $backupDir = $this->tmpRoot . '/data/backups';
        $manager = new BackupManager(['driver' => 'sqlite', 'sqlite' => ['path' => $livePath]], $backupDir);

        $garbagePath = $backupDir . '/stockmanager_backup_corrupt.sqlite.enc';
        file_put_contents($garbagePath, random_bytes(64));

        try {
            $manager->restoreFromFile($garbagePath);
            $this->fail('A sérült mentésből történő visszaállításnak hibát kellett volna dobnia.');
        } catch (RuntimeException $e) {
            // Elvárt kimenet — a lényeg az, hogy az élő adatbázis érintetlen maradjon.
        }

        $liveDb = new PDO('sqlite:' . $livePath);
        $names = $liveDb->query('SELECT name FROM products')->fetchAll(PDO::FETCH_COLUMN);
        $this->assertSame(
            ['SHOULD-SURVIVE-A-FAILED-RESTORE'],
            $names,
            'Egy sikertelen visszaállítás nem módosíthatja az élő adatbázist.'
        );
    }
}
