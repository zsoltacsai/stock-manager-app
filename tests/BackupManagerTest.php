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
}
