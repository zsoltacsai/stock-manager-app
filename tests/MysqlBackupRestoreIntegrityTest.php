<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/Support/FakeMysqlPdo.php';
require_once __DIR__ . '/../src/BackupManager.php';

/**
 * Phase 5 remediáció — DB-01 (MySQL restore), DB-06 (számlaszám-sorozat a
 * restore után, MySQL-ág), DB-08 (konzisztens PHP-s MySQL dump).
 *
 * NINCS valódi MySQL: a BackupManager MySQL-ágai egy MySQL-t SZIMULÁLÓ PDO-n
 * (tests/Support/FakeMysqlPdo.php) futnak, a PHP-s (Windows-on alapértelmezett)
 * útvonalon. A szimuláció a MySQL 8 dokumentált FK-viselkedését modellezi
 * (bekapcsolt FOREIGN_KEY_CHECKS mellett egy hivatkozott tábla eldobása 3730-as
 * hibával bukik) — ez a teszt a logikát bizonyítja, NEM helyettesíti az élő
 * MySQL-validációt.
 */
final class MysqlBackupRestoreIntegrityTest extends TestCase
{
    private string $dir;
    private FakeMysqlPdo $mysql;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/sm_mysql_backup_' . bin2hex(random_bytes(6));
        mkdir($this->dir . '/backups', 0775, true);
        $this->mysql = new FakeMysqlPdo();
        $m = $this->mysql;
        $m->seedTable('customers', '`id` int', [['id' => 1]]);
        $m->seedTable('locations', '`id` int, `name` varchar(50)', [['id' => 1, 'name' => 'Bolt']]);
        $m->seedTable('cash_registers', '`id` int, `locations_id` int', [['id' => 1, 'locations_id' => 1]], ['locations']);
        $m->seedTable('cash_sessions', '`id` int, `cash_registers_id` int', [['id' => 1, 'cash_registers_id' => 1]], ['cash_registers']);
        $m->seedTable('products', '`id` int, `name` varchar(50)', [['id' => 1, 'name' => "O'Brien; -- nem komment"]]);
        $m->seedTable('sales', '`id` int, `customers_id` int, `total` decimal(12,2)', [['id' => 1, 'customers_id' => 1, 'total' => '10.00']], ['customers']);
        $m->seedTable('invoice_sequences', '`provider` varchar(16), `last_allocated_number` int, `updated_at` datetime', [['provider' => 'nav', 'last_allocated_number' => 1, 'updated_at' => '2026-09-01 10:00:00']]);
        $m->seedTable('invoices', '`id` int', [['id' => 1]]);
        $m->seedTable('invoice_modification_sequences', '`original_invoice_id` int, `last_allocated_index` int, `updated_at` datetime', []);
    }

    protected function tearDown(): void
    {
        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($this->dir, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST) as $f) {
            $f->isDir() ? @rmdir($f->getPathname()) : @unlink($f->getPathname());
        }
        @rmdir($this->dir);
    }

    private function manager(): BackupManager
    {
        $bm = new BackupManager(['driver' => 'mysql', 'mysql' => ['host' => 'x', 'database' => 'x', 'username' => 'x', 'password' => 'x']], $this->dir . '/backups');
        $bm->setMysqlPdoFactory(fn () => $this->mysql);
        $bm->setMysqlCliAvailable(false); // a Windows-on alapértelmezett PHP-s útvonal
        return $bm;
    }

    private function mutateLiveState(): void
    {
        $this->mysql->tables['sales']['rows'][] = ['id' => '2', 'customers_id' => '1', 'total' => '99.00'];
        $this->mysql->tables['cash_sessions']['rows'][] = ['id' => '2', 'cash_registers_id' => '1'];
        $this->mysql->tables['invoice_sequences']['rows'][0]['last_allocated_number'] = '3';
    }

    public function testRestoreOfDumpWithForeignKeyDependenciesSucceedsCompletely(): void
    {
        $bm = $this->manager();
        $backup = $bm->run(['backup_retention_count' => 10, 'backup_cloud_provider' => ''])['filename'];
        $atBackup = $this->mysql->snapshot();
        $this->mutateLiveState();

        $result = $bm->restoreFromFile($this->dir . '/backups/' . $backup, true);

        $expected = $atBackup;
        $expected['invoice_sequences'][0]['last_allocated_number'] = '3'; // DB-06, lásd külön teszt
        $actual = $this->mysql->snapshot();
        $actual['invoice_sequences'][0]['updated_at'] = $expected['invoice_sequences'][0]['updated_at'];
        $this->assertSame($expected, $actual, 'minden tábla a mentés állapotára állt vissza');
        $this->assertSame(1, $this->mysql->fkChecks, 'az FK-ellenőrzés a restore után visszakapcsolt');
        $firstFkOff = array_search('SET FOREIGN_KEY_CHECKS=0', $this->mysql->log, true);
        $firstDrop = array_key_first(array_filter($this->mysql->log, fn ($s) => str_starts_with($s, 'DROP TABLE')));
        $this->assertNotFalse($firstFkOff);
        $this->assertLessThan($firstDrop, $firstFkOff, 'az FK-ellenőrzés az első DROP előtt kikapcsolt');
        $this->assertTrue($result['invoice_sequences_reconciled']);
    }

    /** A korábbi viselkedés (FK-ellenőrzés bekapcsolva) a szimulációban 3730-as hibával bukik — ezt védi a javítás. */
    public function testSimulationRejectsDroppingAReferencedParentWhileForeignKeyChecksAreOn(): void
    {
        $this->expectExceptionMessage('3730');
        $this->mysql->exec('DROP TABLE IF EXISTS `cash_registers`');
    }

    public function testLegacyPhpDumpWhoseFkSwitchSharedAChunkWithCommentsRestoresCompletely(): void
    {
        $legacy = "-- FountainTrade PHP-based MySQL dump (mysqldump not available)\n-- Generated: 2026-09-01T10:00:00+02:00\n\nSET FOREIGN_KEY_CHECKS=0;\n\n";
        foreach ($this->mysql->tables as $name => $t) {
            $legacy .= "DROP TABLE IF EXISTS `$name`;\n" . $t['create'] . ";\n\n";
            foreach ($t['rows'] as $row) {
                $legacy .= "INSERT INTO `$name` (" . implode(',', array_map(fn ($c) => "`$c`", array_keys($row))) . ') VALUES (' . implode(',', array_map(fn ($v) => $v === null ? 'NULL' : $this->mysql->quote((string) $v), $row)) . ");\n";
            }
        }
        $legacy .= "SET FOREIGN_KEY_CHECKS=1;\n";
        file_put_contents($this->dir . '/legacy.sql', $legacy);
        $atDump = $this->mysql->snapshot();
        $this->mutateLiveState();

        $this->manager()->restoreFromFile($this->dir . '/legacy.sql', true);

        $actual = $this->mysql->snapshot();
        $this->assertSame($atDump['sales'], $actual['sales']);
        $this->assertSame($atDump['cash_sessions'], $actual['cash_sessions']);
        $this->assertSame(1, $this->mysql->fkChecks);
    }

    /**
     * A restore az FK-ellenőrzést maga kapcsolja ki — nem a dump tartalmára
     * hagyatkozik (egy mysqldump a kapcsolót /*!40014 … *\/ feltételes
     * kommentben hozza, egy kézzel/külső eszközzel készült dump pedig
     * egyáltalán nem tartalmazza).
     */
    public function testDumpWithoutAnyForeignKeySwitchStillRestoresCompletely(): void
    {
        $dump = "-- külső eszközzel készült dump, FK-kapcsoló nélkül\n";
        foreach ($this->mysql->tables as $name => $t) {
            $dump .= "DROP TABLE IF EXISTS `$name`;\n" . $t['create'] . ";\n";
            foreach ($t['rows'] as $row) {
                $dump .= "INSERT INTO `$name` (" . implode(',', array_map(fn ($c) => "`$c`", array_keys($row))) . ') VALUES (' . implode(',', array_map(fn ($v) => $v === null ? 'NULL' : $this->mysql->quote((string) $v), $row)) . ");\n";
            }
        }
        $dump .= "-- Dump completed on 2026-09-27 10:00:00\n";
        file_put_contents($this->dir . '/external.sql', $dump);
        $atDump = $this->mysql->snapshot();
        $this->mutateLiveState();

        $this->manager()->restoreFromFile($this->dir . '/external.sql', true);

        $this->assertSame($atDump['sales'], $this->mysql->snapshot()['sales']);
        $this->assertSame(1, $this->mysql->fkChecks);
    }

    public function testFailureDuringRestoreRollsBackToPreRestoreStateAndReportsFailure(): void
    {
        $bm = $this->manager();
        $backup = $bm->run(['backup_retention_count' => 10, 'backup_cloud_provider' => ''])['filename'];
        $this->mutateLiveState();
        $beforeRestore = $this->mysql->snapshot();
        $this->mysql->failOn = 'INSERT INTO `sales`';
        $this->mysql->failTimes = 1; // csak a restore-ban, a kompenzáló visszaállításban már nem

        try {
            $bm->restoreFromFile($this->dir . '/backups/' . $backup, true);
            $this->fail('A félbeszakadt restore nem jelezhet sikert.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('visszaállítás előtti állapotra állt vissza', $e->getMessage());
        }
        $after = $this->mysql->snapshot();
        $after['invoice_sequences'][0]['updated_at'] = $beforeRestore['invoice_sequences'][0]['updated_at'];
        $this->assertSame($beforeRestore, $after, 'nincs kevert (félig visszaállított) állapot');
        $this->assertSame(1, $this->mysql->fkChecks);
    }

    public function testFailedCompensationDemandsManualRecoveryAndNeverReportsSuccess(): void
    {
        $bm = $this->manager();
        $backup = $bm->run(['backup_retention_count' => 10, 'backup_cloud_provider' => ''])['filename'];
        $this->mysql->failOn = 'INSERT INTO `sales`';
        $this->mysql->failTimes = 2;

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('KÉZI HELYREÁLLÍTÁS SZÜKSÉGES');
        $bm->restoreFromFile($this->dir . '/backups/' . $backup, true);
    }

    public function testTruncatedDumpIsRejectedBeforeTouchingTheDatabase(): void
    {
        $bm = $this->manager();
        $backup = $bm->run(['backup_retention_count' => 10, 'backup_cloud_provider' => ''])['filename'];
        $plain = (new ReflectionMethod(BackupManager::class, 'decryptToTempFile'))->invoke($bm, $this->dir . '/backups/' . $backup, '.sql');
        $sql = (string) file_get_contents($plain);
        @unlink($plain);
        file_put_contents($this->dir . '/truncated.sql', substr($sql, 0, (int) (strlen($sql) * 0.6)));
        $before = $this->mysql->snapshot();
        $logBefore = count($this->mysql->log);

        try {
            $bm->restoreFromFile($this->dir . '/truncated.sql', true);
            $this->fail('Csonka dump nem állítható vissza.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('csonka', $e->getMessage());
        }
        $this->assertSame($before, $this->mysql->snapshot());
        $this->assertSame([], array_values(array_filter(array_slice($this->mysql->log, $logBefore), fn ($s) => str_starts_with($s, 'DROP'))));
    }

    /** DB-06 (MySQL-ág): a visszaállított sorozat nem eshet a visszaállítás előtti élő érték alá. */
    public function testRestoreOfOlderDumpDoesNotRewindInvoiceSequence(): void
    {
        $bm = $this->manager();
        $backup = $bm->run(['backup_retention_count' => 10, 'backup_cloud_provider' => ''])['filename'];
        $this->mysql->tables['invoice_sequences']['rows'][0]['last_allocated_number'] = '7';

        $bm->restoreFromFile($this->dir . '/backups/' . $backup, true);

        $this->assertSame('7', $this->mysql->tables['invoice_sequences']['rows'][0]['last_allocated_number']);
    }

    /** DB-08: a PHP-s dump egyetlen konzisztens InnoDB-pillanatképből készül, és teljesség-jelzővel zárul. */
    public function testPhpDumpRunsInsideOneConsistentSnapshotAndEndsWithCompletionMarker(): void
    {
        $bm = $this->manager();
        $file = (new ReflectionMethod(BackupManager::class, 'createMysqlSnapshot'))->invoke($bm, null);
        $sql = (string) file_get_contents($this->dir . '/backups/' . $file);
        $log = $this->mysql->log;

        $start = array_search('START TRANSACTION WITH CONSISTENT SNAPSHOT', $log, true);
        $iso = array_search('SET SESSION TRANSACTION ISOLATION LEVEL REPEATABLE READ', $log, true);
        $commit = array_search('COMMIT', $log, true);
        $selects = array_keys(array_filter($log, fn ($s) => str_starts_with($s, 'SELECT * FROM') || $s === 'SHOW TABLES'));
        $this->assertNotFalse($start);
        $this->assertLessThan($start, $iso);
        $this->assertLessThan(min($selects), $start, 'minden olvasás a pillanatkép megnyitása után');
        $this->assertGreaterThan(max($selects), $commit, 'a pillanatkép az utolsó olvasás után zárul');
        $this->assertStringEndsWith(BackupManager::PHP_DUMP_COMPLETED_MARKER . "\n", $sql);
        $this->assertMatchesRegularExpression('/^SET FOREIGN_KEY_CHECKS=0;$/m', $sql, 'az FK-kikapcsolás saját, komment nélküli sorban');
        $this->assertStringNotContainsString('+02:00', $sql);
    }

    public function testSqlSplitterRespectsQuotesCommentsAndConditionalComments(): void
    {
        $sql = "-- fejléc; nem utasítás\n/*!40101 SET NAMES utf8mb4 */;\nINSERT INTO `t` (`a`) VALUES ('x;\\'y'), ('O''Brien -- nem komment');\n# megjegyzés;\nSET FOREIGN_KEY_CHECKS=1;";
        $this->assertSame([
            '/*!40101 SET NAMES utf8mb4 */',
            "INSERT INTO `t` (`a`) VALUES ('x;\\'y'), ('O''Brien -- nem komment')",
            'SET FOREIGN_KEY_CHECKS=1',
        ], BackupManager::splitSqlStatements($sql));
    }

    /** DB-01: Windows-on a `command -v` nem létezik — a CLI-felderítés a `where`-t használja. */
    public function testCommandDetectionWorksOnWindows(): void
    {
        if (PHP_OS_FAMILY !== 'Windows') {
            $this->markTestSkipped('Csak Windows-on értelmezhető.');
        }
        $bm = new BackupManager(['driver' => 'sqlite', 'sqlite' => ['path' => $this->dir . '/x.sqlite']], $this->dir . '/backups');
        $m = new ReflectionMethod(BackupManager::class, 'commandExists');
        $this->assertTrue($m->invoke($bm, 'cmd'), 'a cmd.exe minden Windowson a PATH-on van');
        $this->assertFalse($m->invoke($bm, 'nincs-ilyen-parancs-' . bin2hex(random_bytes(4))));
    }
}
