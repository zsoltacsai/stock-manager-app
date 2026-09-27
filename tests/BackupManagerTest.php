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

    // ------------------------------------------------------------------
    // Release-blocker javítás: a titkosítás-felismerés mostantól a fájl
    // TARTALMA alapján dönt, sose a nevéből/kiterjesztéséből — lásd
    // BackupManager::detectEncryption() docblokkja. Ezek a tesztek a
    // döntési logika mind a négy ágát közvetlenül, izoláltan bizonyítják
    // (a végpontig futó, VALÓDI feltöltéses bizonyíték: tests/
    // BackupRestoreHttpTest.php).
    // ------------------------------------------------------------------

    public function testDetectEncryptionRecognizesNewFormatMagicPrefixRegardlessOfFilename(): void
    {
        $backupDir = $this->tmpRoot . '/data/backups';
        $manager = new BackupManager(['driver' => 'sqlite', 'sqlite' => ['path' => $this->tmpRoot . '/unused.sqlite']], $backupDir);

        $plainPath = $backupDir . '/plain-for-detect.txt';
        file_put_contents($plainPath, 'tartalom, amit titkosítunk');
        // A fájlNÉV szándékosan NEM '.enc' — a PHP-tmp_name-szerű esetet
        // szimulálja, ahol a kiterjesztés nem áll rendelkezésre/nem megbízható.
        $encPathWithoutEncSuffix = $backupDir . '/random-php-tmp-name-abc123';
        $this->invokePrivate($manager, 'encryptFileInPlace', [$plainPath, $encPathWithoutEncSuffix]);

        $isEncrypted = $this->invokePrivate($manager, 'detectEncryption', [$encPathWithoutEncSuffix, false]);
        $this->assertTrue($isEncrypted, 'Az ÚJ formátumú titkosított tartalmat a fájlnévtől FÜGGETLENÜL, a tartalom-aláírás alapján kell felismerni.');
    }

    public function testDetectEncryptionRecognizesPlainSqliteMagicHeaderRegardlessOfFilename(): void
    {
        $backupDir = $this->tmpRoot . '/data/backups';
        $manager = new BackupManager(['driver' => 'sqlite', 'sqlite' => ['path' => $this->tmpRoot . '/unused.sqlite']], $backupDir);

        $livePath = $this->tmpRoot . '/plain-sqlite-for-detect.sqlite';
        $db = new Database(['driver' => 'sqlite', 'sqlite' => ['path' => $livePath]], dirname(__DIR__));
        unset($db);

        // Fájlnév itt is szándékosan NEM '.sqlite' — csak a tartalom-
        // aláírás (SQLITE_MAGIC) alapján kell "sima"-ként felismerni.
        $renamed = $backupDir . '/random-php-tmp-name-xyz789';
        copy($livePath, $renamed);

        $isEncrypted = $this->invokePrivate($manager, 'detectEncryption', [$renamed, false]);
        $this->assertFalse($isEncrypted, 'Egy VALÓDI SQLite fájlt a SQLite-formátum saját aláírása alapján "sima"-ként kell felismerni, a fájlnévtől függetlenül.');
    }

    public function testDetectEncryptionTrustsEncSuffixOnlyForServerManagedLegacyFiles(): void
    {
        $backupDir = $this->tmpRoot . '/data/backups';
        $manager = new BackupManager(['driver' => 'sqlite', 'sqlite' => ['path' => $this->tmpRoot . '/unused.sqlite']], $backupDir);

        // Régi (a formátum-jelző bevezetése ELŐTTI) titkosított tartalom
        // szimulálása: nyers IV||TAG||ciphertext, jelző NÉLKÜL.
        $legacyEncPath = $backupDir . '/stockmanager_backup_legacy.sqlite.enc';
        file_put_contents($legacyEncPath, random_bytes(12 + 16 + 32));

        $this->assertTrue(
            $this->invokePrivate($manager, 'detectEncryption', [$legacyEncPath, true]),
            'Egy régi formátumú, DE a Szerver saját data/backups mappájából fájlnév szerint kiválasztott .enc fájlt titkosítottként kell felismerni (visszafelé kompatibilitás).'
        );

        $this->expectException(RuntimeException::class);
        $this->invokePrivate($manager, 'detectEncryption', [$legacyEncPath, false]);
    }

    public function testDetectEncryptionRejectsAmbiguousUploadedContentWithAClearError(): void
    {
        $backupDir = $this->tmpRoot . '/data/backups';
        $manager = new BackupManager(['driver' => 'sqlite', 'sqlite' => ['path' => $this->tmpRoot . '/unused.sqlite']], $backupDir);

        $randomPath = $backupDir . '/uploaded-random-garbage';
        file_put_contents($randomPath, 'ez se nem SQLite, se nem a mi titkosított formátumunk');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/nem határozható meg egyértelműen/');
        // sourceIsServerManagedFile = false -> pontosan a feltöltési út,
        // ahol a fájlnév SOSE tekinthető megbízható jelnek.
        $this->invokePrivate($manager, 'detectEncryption', [$randomPath, false]);
    }

    public function testLegacyEncryptedFileWithoutMagicPrefixStillDecryptsCorrectly(): void
    {
        // Teljes visszafelé kompatibilitás: egy a formátum-jelző bevezetése
        // ELŐTT készült titkosított mentésnek (nyers IV||TAG||ciphertext,
        // jelző nélkül) a decryptToTempFile()-on keresztül is helyesen
        // vissza kell fejtődnie, ha egyszer a detectEncryption() már
        // titkosítottnak azonosította (lásd fenti teszt).
        $backupDir = $this->tmpRoot . '/data/backups';
        $manager = new BackupManager(['driver' => 'sqlite', 'sqlite' => ['path' => $this->tmpRoot . '/unused.sqlite']], $backupDir);

        $originalContent = 'ez a tartalom megy át egy RÉGI formátumú (jelző nélküli) titkosításon';

        // PERF-03 óta az encryptFileInPlace() a darabolt (FTBKENC2) formátumot
        // írja — a régi, egyben titkosított formátumot ezért itt közvetlenül
        // állítjuk elő: IV||TAG||ciphertext, jelző NÉLKÜL ("a jelző bevezetése
        // ELŐTTI" fájl), és ugyanez az FTBKENC1 jelzővel.
        $magic = (new ReflectionClassConstant(BackupManager::class, 'ENCRYPTED_MAGIC'))->getValue();
        $this->assertSame('FTBKENC1', $magic);
        $key = $this->invokePrivate($manager, 'encryptionKey', []);
        foreach (['' => 'jelző NÉLKÜLI', $magic => 'FTBKENC1 jelzős'] as $prefix => $label) {
            $iv = random_bytes(12);
            $tag = '';
            $ciphertext = openssl_encrypt($originalContent, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag);
            $encPath = $backupDir . '/legacy-plain-for-roundtrip-' . bin2hex(random_bytes(3)) . '.txt.enc';
            file_put_contents($encPath, $prefix . $iv . $tag . $ciphertext);

            $tmpPath = $this->invokePrivate($manager, 'decryptToTempFile', [$encPath, '.txt']);
            try {
                $this->assertSame($originalContent, file_get_contents($tmpPath), "Egy $label (régi formátumú) titkosított fájlnak is helyesen kell visszafejtődnie.");
            } finally {
                @unlink($tmpPath);
            }
        }
    }

    private function encryptedFixture(BackupManager $manager, string $content): string
    {
        $plainPath = $this->tmpRoot . '/data/backups/plain-' . bin2hex(random_bytes(4)) . '.bin';
        file_put_contents($plainPath, $content);
        $encPath = $plainPath . '.enc';
        $this->invokePrivate($manager, 'encryptFileInPlace', [$plainPath, $encPath]);
        $this->assertFileDoesNotExist($plainPath, 'A titkosítás után a sima fájl nem maradhat meg.');
        return $encPath;
    }

    /** @return list<string> a stockmanager_decrypt_* ideiglenes fájlok (a hibás visszafejtés nem hagyhat ilyet maga után) */
    private function decryptTempFiles(): array
    {
        return glob(sys_get_temp_dir() . '/stockmanager_decrypt_*') ?: [];
    }

    /**
     * PERF-03: a darabolt formátum oda-vissza azonos minden méretnél — üres fájl,
     * egy rekordnál kisebb, pontosan egy rekord (1 MiB), rekordhatárt átlépő és
     * több rekordos fájl.
     */
    public function testChunkedEncryptionRoundTripsAcrossRecordBoundaries(): void
    {
        $manager = new BackupManager(['driver' => 'sqlite', 'sqlite' => ['path' => $this->tmpRoot . '/unused.sqlite']], $this->tmpRoot . '/data/backups');
        $chunk = (new ReflectionClassConstant(BackupManager::class, 'ENCRYPTION_CHUNK_BYTES'))->getValue();
        foreach ([0, 1, 1000, $chunk - 1, $chunk, $chunk + 1, 2 * $chunk, 3 * $chunk + 12345] as $size) {
            $content = $size === 0 ? '' : random_bytes($size);
            $encPath = $this->encryptedFixture($manager, $content);
            $this->assertStringStartsWith('FTBKENC2', (string) file_get_contents($encPath, false, null, 0, 8));
            $this->assertTrue($this->invokePrivate($manager, 'detectEncryption', [$encPath, false]));
            $tmp = $this->invokePrivate($manager, 'decryptToTempFile', [$encPath, '.bin']);
            try {
                $this->assertSame(hash('sha256', $content), hash_file('sha256', $tmp), "Oda-vissza eltérés $size bájtnál.");
            } finally {
                @unlink($tmp);
            }
        }
    }

    /**
     * PERF-03: a darabolás nem gyengítheti a hitelesítést — rekord módosítása,
     * cseréje, elhagyása (csonkolás), az „utolsó” jelző átírása és a fájl
     * bővítése egyaránt visszafejtési hibát ad, és nem marad visszafejtett
     * ideiglenes fájl.
     */
    public function testChunkedEncryptionRejectsTamperingReorderingTruncationAndExtension(): void
    {
        $manager = new BackupManager(['driver' => 'sqlite', 'sqlite' => ['path' => $this->tmpRoot . '/unused.sqlite']], $this->tmpRoot . '/data/backups');
        $chunk = (new ReflectionClassConstant(BackupManager::class, 'ENCRYPTION_CHUNK_BYTES'))->getValue();
        $content = random_bytes(3 * $chunk);
        $encPath = $this->encryptedFixture($manager, $content);
        $raw = (string) file_get_contents($encPath);

        $header = 16;                       // "FTBKENC2" + 8 bájt prefix
        $record = 1 + 4 + 16 + $chunk;      // flag + hossz + tag + 1 MiB
        $this->assertSame($header + 3 * $record, strlen($raw), '3 teljes rekord, a harmadik az utolsó.');
        $r = fn (int $n) => substr($raw, $header + $n * $record, $record);

        $variants = [
            'ciphertext-bájt módosítva' => substr_replace($raw, chr(ord($raw[$header + 100]) ^ 1), $header + 100, 1),
            'tag módosítva'             => substr_replace($raw, chr(ord($raw[$header + 6]) ^ 1), $header + 6, 1),
            'két rekord felcserélve'    => substr($raw, 0, $header) . $r(1) . $r(0) . substr($raw, $header + 2 * $record),
            'utolsó rekord elhagyva'    => substr($raw, 0, $header + 2 * $record),
            'csak az első rekord'       => substr($raw, 0, $header + $record),
            'rekord közepén csonkolva'  => substr($raw, 0, $header + $record + 500),
            'közbenső rekord „utolsó”'  => substr_replace($raw, "\x01", $header, 1),
            'adat a záró rekord után'   => $raw . 'x',
            'prefix módosítva'          => substr_replace($raw, chr(ord($raw[9]) ^ 1), 9, 1),
            'túl nagy rekordhossz'      => substr_replace($raw, pack('N', 0x7FFFFFFF), $header + 1, 4),
        ];
        $before = $this->decryptTempFiles();
        foreach ($variants as $label => $bytes) {
            $bad = $this->tmpRoot . '/data/backups/tampered.enc';
            file_put_contents($bad, $bytes);
            try {
                $tmp = $this->invokePrivate($manager, 'decryptToTempFile', [$bad, '.bin']);
                @unlink($tmp);
                $this->fail("A(z) „{$label}” változatnak visszafejtési hibát kellett volna adnia.");
            } catch (RuntimeException $e) {
                $this->assertStringContainsString('sikertelen', $e->getMessage(), $label);
            }
        }
        $this->assertSame($before, $this->decryptTempFiles(), 'Hibás visszafejtés után nem maradhat (részleges) visszafejtett ideiglenes fájl.');
    }

    /**
     * PERF-03 regresszió: a mentés és a visszaállítás memóriaigénye nem a
     * DB-méret többszöröse. Egy ~64 MB-os adatbázis mentése + visszaállítása
     * egy 32 MB-os memory_limit-tel futó külön PHP-folyamatban sikerül
     * (korábban ~3× DB-méret, azaz ~190 MB kellett volna), az adatok
     * bitre azonosak, és a mért csúcs nem nő a DB méretével.
     */
    public function testBackupAndRestoreOfLargeDatabaseRunWithinSmallFixedMemoryLimit(): void
    {
        $dbPath = $this->tmpRoot . '/live.sqlite';
        $db = new Database(['driver' => 'sqlite', 'sqlite' => ['path' => $dbPath]], dirname(__DIR__));
        $pdo = $db->pdo();
        $pdo->exec('CREATE TABLE perf_filler (id INTEGER PRIMARY KEY, payload BLOB)');
        $pdo->exec('WITH RECURSIVE n(x) AS (SELECT 1 UNION ALL SELECT x + 1 FROM n WHERE x < 64) INSERT INTO perf_filler (payload) SELECT randomblob(1048576) FROM n');
        $pdo->exec("INSERT INTO products (name) VALUES ('PERF03-MARKER')");
        $digest = fn (PDO $p) => md5(implode('|', $p->query('SELECT hex(payload) FROM perf_filler ORDER BY id LIMIT 3')->fetchAll(PDO::FETCH_COLUMN)) . $p->query('SELECT COUNT(*) FROM perf_filler')->fetchColumn());
        $expectedDigest = $digest($pdo);
        unset($db, $pdo);
        $this->assertGreaterThan(64 * 1024 * 1024, filesize($dbPath));

        $script = $this->tmpRoot . '/run.php';
        file_put_contents($script, '<?php
            require ' . var_export(dirname(__DIR__) . '/src/BackupManager.php', true) . ';
            $bm = new BackupManager(["driver" => "sqlite", "sqlite" => ["path" => ' . var_export($dbPath, true) . ']], ' . var_export($this->tmpRoot . '/data/backups', true) . ');
            memory_reset_peak_usage();
            $r = $bm->run(["backup_retention_count" => 5, "backup_provider" => "none"]);
            $backupPeak = memory_get_peak_usage();
            memory_reset_peak_usage();
            $bm->restoreFromFile(' . var_export($this->tmpRoot . '/data/backups/', true) . ' . $r["filename"], true);
            echo json_encode(["file" => $r["filename"], "backup_peak" => $backupPeak, "restore_peak" => memory_get_peak_usage()]);
        ');
        $out = [];
        exec(escapeshellarg(PHP_BINARY) . ' -d memory_limit=32M ' . escapeshellarg($script) . ' 2>&1', $out, $code);
        $this->assertSame(0, $code, implode("\n", $out));
        $result = json_decode((string) end($out), true);
        $this->assertIsArray($result, implode("\n", $out));
        $this->assertLessThan(16 * 1024 * 1024, $result['backup_peak'], 'A mentés memóriacsúcsa nem függhet a ~64 MB-os DB-mérettől.');
        $this->assertLessThan(16 * 1024 * 1024, $result['restore_peak'], 'A visszaállítás memóriacsúcsa nem függhet a ~64 MB-os DB-mérettől.');
        $this->assertGreaterThan(64 * 1024 * 1024, filesize($this->tmpRoot . '/data/backups/' . $result['file']));

        $restored = new PDO('sqlite:' . $dbPath);
        $this->assertSame($expectedDigest, $digest($restored), 'A visszaállított adatbázis tartalma eltér a mentettől.');
        $this->assertSame('PERF03-MARKER', $restored->query("SELECT name FROM products WHERE name = 'PERF03-MARKER'")->fetchColumn());
        $this->assertSame('ok', $restored->query('PRAGMA integrity_check')->fetchColumn());
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
