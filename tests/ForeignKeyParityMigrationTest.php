<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/Support/SqlCapturePdo.php';

/**
 * Phase 5 remediáció — DB-10: minden frissítési útvonal ugyanahhoz az
 * FK-teljes sémához jusson, mint a friss telepítés. A v37 migráció a
 * kanonikus sémafájlhoz méri az FK-kat, és csak az eltérő táblákat javítja
 * (SQLite: táblaújraépítés; MySQL: ALTER TABLE … ADD CONSTRAINT), adatvesztés
 * nélkül, idempotensen; orphan adat esetén az adott FK kimarad és naplózódik.
 * Az SQLite-ág valódi adatbázison, a MySQL-ág SQL-rögzítéssel ellenőrzött.
 */
final class ForeignKeyParityMigrationTest extends TestCase
{
    private function fkMap(PDO $pdo): array
    {
        $map = [];
        foreach ($pdo->query("SELECT name FROM sqlite_master WHERE type = 'table' AND name NOT LIKE 'sqlite_%'")->fetchAll(PDO::FETCH_COLUMN) as $t) {
            $fks = array_map(fn ($f) => $f['from'] . '->' . $f['table'] . '.' . $f['to'], $pdo->query("PRAGMA foreign_key_list($t)")->fetchAll(PDO::FETCH_ASSOC));
            sort($fks);
            $map[$t] = $fks;
        }
        ksort($map);
        return $map;
    }

    /** Egy régi, migrációval létrejött (FK nélküli) tábla szimulálása: újraépítés REFERENCES nélkül. */
    private function stripForeignKeys(PDO $pdo, string $table): void
    {
        $create = (string) $pdo->query("SELECT sql FROM sqlite_master WHERE type = 'table' AND name = '$table'")->fetchColumn();
        $noFk = preg_replace('/\s+REFERENCES\s+\w+\s*\(\s*\w+\s*\)/i', '', $create);
        $noFk = preg_replace('/CREATE TABLE (IF NOT EXISTS )?"?' . $table . '"?/', 'CREATE TABLE __old_' . $table, $noFk, 1);
        $pdo->exec('PRAGMA foreign_keys = OFF');
        $pdo->exec($noFk);
        $pdo->exec("INSERT INTO __old_$table SELECT * FROM $table");
        $pdo->exec("DROP TABLE $table");
        $pdo->exec("ALTER TABLE __old_$table RENAME TO $table");
        $pdo->exec('PRAGMA foreign_keys = ON');
    }

    private function upgradedDb(?string &$path, array $strip): void
    {
        $path = sys_get_temp_dir() . '/sm_db10_' . bin2hex(random_bytes(6)) . '.sqlite';
        register_shutdown_function(static function () use ($path) {
            foreach (['', '-wal', '-shm'] as $s) { @unlink($path . $s); }
        });
        $db = new Database(['driver' => 'sqlite', 'sqlite' => ['path' => $path]], dirname(__DIR__));
        $loc = $db->saveLocation(['name' => 'L', 'address' => '', 'is_default' => 1]);
        $reg = $db->saveCashRegister(['location_id' => $loc, 'name' => 'K', 'code' => 'K1']);
        $sid = $db->openCashSession($reg, null, 10.0);
        $db->recordCashMovement($sid, null, 'cash_in', 5.0, 'megőrzendő');
        $db->beginTransaction();
        $db->insertSale(12.5, 'Készpénz', null, null, 0, 0, null, 0.0, 0.0, null, null, null, $reg, $loc);
        $db->commit();
        $pdo = $db->pdo();
        foreach ($strip as $table) {
            $this->stripForeignKeys($pdo, $table);
        }
        $pdo->exec('UPDATE schema_version SET version = 36');
    }

    public function testUpgradedDatabaseReachesTheSameForeignKeySetAsAFreshInstallWithoutDataLoss(): void
    {
        $fresh = tests_new_database();
        $this->upgradedDb($path, ['cash_movements', 'cash_sessions', 'cash_registers', 'sales', 'returns', 'webshop_orders', 'wc_push_queue', 'client_sessions', 'invoice_modification_sequences', 'incoming_invoice_items']);
        $before = new PDO('sqlite:' . $path);
        $this->assertNotSame($this->fkMap($fresh->pdo()), $this->fkMap($before), 'kiinduló állapot: hiányzó FK-k (a régi kiadásokról frissített DB-k szimulációja)');
        $rows = fn (PDO $p) => [(int) $p->query('SELECT COUNT(*) FROM sales')->fetchColumn(), (float) $p->query('SELECT SUM(amount) FROM cash_movements')->fetchColumn(), $p->query('SELECT location_id FROM sales')->fetchColumn()];
        $dataBefore = $rows($before);
        unset($before);

        $upgraded = new Database(['driver' => 'sqlite', 'sqlite' => ['path' => $path]], dirname(__DIR__));

        $this->assertSame($this->fkMap($fresh->pdo()), $this->fkMap($upgraded->pdo()), 'FK-paritás a friss telepítéssel');
        $this->assertSame($dataBefore, $rows($upgraded->pdo()), 'az adatok megmaradtak');
        $this->assertSame([], $upgraded->pdo()->query('PRAGMA foreign_key_check')->fetchAll());
        $this->assertSame(38, (int) $upgraded->pdo()->query('SELECT version FROM schema_version')->fetchColumn());
        // Idempotens: egy újabb futás nem talál eltérést.
        $this->assertSame(['repaired' => [], 'skipped' => []], $upgraded->repairForeignKeysToCanonicalSchema(dirname(__DIR__) . '/schema.sql'));
        // Az AUTOINCREMENT-sorozat sem esett vissza.
        $upgraded->beginTransaction();
        $newSale = $upgraded->insertSale(1.0);
        $upgraded->commit();
        $this->assertSame(2, $newSale);
    }

    public function testOrphanDataBlocksOnlyThatTableAndIsLoggedNotHidden(): void
    {
        $this->upgradedDb($path, ['cash_movements', 'sales']);
        $pdo = new PDO('sqlite:' . $path);
        $pdo->exec('PRAGMA foreign_keys = OFF');
        $pdo->exec("INSERT INTO cash_movements (cash_session_id, type, amount, reason, created_at) VALUES (999, 'cash_in', 1, 'orphan', '2026-01-01 00:00:00')");
        unset($pdo);

        $db = new Database(['driver' => 'sqlite', 'sqlite' => ['path' => $path]], dirname(__DIR__));

        $fks = $this->fkMap($db->pdo());
        $this->assertSame([], $fks['cash_movements'], 'orphan sor mellett az FK nem pótolható — a tábla érintetlen maradt');
        $this->assertContains('cash_session_id->cash_sessions.id', $fks['sales'], 'a többi tábla javítása ettől függetlenül megtörtént');
        $this->assertSame(2, (int) $db->pdo()->query('SELECT COUNT(*) FROM cash_movements')->fetchColumn(), 'adat nem veszett el');
        $event = $db->pdo()->query("SELECT technical_detail FROM system_events WHERE event_type = 'fk_repair_skipped'")->fetchColumn();
        $this->assertStringContainsString('cash_movements', (string) $event);
        $this->assertStringContainsString('orphan', (string) $event);
    }

    public function testMysqlFreshSchemaNowDeclaresEverySqliteForeignKey(): void
    {
        $sqlite = $this->fkMap(tests_new_database()->pdo());
        $mysql = [];
        foreach (Database::canonicalMysqlForeignKeys(dirname(__DIR__) . '/schema.mysql.sql') as $fk) {
            $mysql[$fk['table']][] = $fk['column'] . '->' . $fk['ref_table'] . '.' . $fk['ref_column'];
        }
        foreach ($sqlite as $table => $fks) {
            $m = $mysql[$table] ?? [];
            sort($m);
            $this->assertSame([], array_values(array_diff($fks, $m)), "a MySQL friss sémából hiányzó FK ($table)");
        }
    }

    /** MySQL-ág (SQL-rögzítés): csak a hiányzó FK-k kerülnek ALTER-rel pótlásra; orphan esetén kihagyás. */
    public function testMysqlRepairAddsOnlyMissingConstraintsAndSkipsOrphans(): void
    {
        $pdo = new SqlCapturePdo();
        $db = (new ReflectionClass(Database::class))->newInstanceWithoutConstructor();
        foreach (['driver' => 'mysql', 'pdo' => $pdo, 'dbConfig' => ['driver' => 'mysql']] as $k => $v) {
            $rp = new ReflectionProperty(Database::class, $k);
            $rp->setAccessible(true);
            $rp->setValue($db, $v);
        }
        $existing = [];
        foreach (Database::canonicalMysqlForeignKeys(dirname(__DIR__) . '/schema.mysql.sql') as $fk) {
            if (!in_array($fk['name'], ['fk_cash_movements_session', 'fk_sales_location'], true)) {
                $existing[] = ['TABLE_NAME' => $fk['table'], 'COLUMN_NAME' => $fk['column'], 'REFERENCED_TABLE_NAME' => $fk['ref_table']];
            }
        }
        $pdo->results['/information_schema\.KEY_COLUMN_USAGE/'] = $existing;
        $pdo->results['/FROM `sales` c LEFT JOIN `locations`/'] = [['c' => 3]]; // 3 orphan sor
        $pdo->results['/FROM `cash_movements` c LEFT JOIN/'] = [['c' => 0]];

        $report = $db->repairForeignKeysToCanonicalSchema(dirname(__DIR__) . '/schema.mysql.sql');

        $alters = array_values(array_filter($pdo->statements(), fn ($s) => str_starts_with($s, 'ALTER TABLE')));
        $this->assertSame(['ALTER TABLE `cash_movements` ADD CONSTRAINT `fk_cash_movements_session` FOREIGN KEY (`cash_session_id`) REFERENCES `cash_sessions`(`id`)'], $alters);
        $this->assertSame(['cash_movements.cash_session_id->cash_sessions'], $report['repaired']);
        $this->assertArrayHasKey('sales.location_id->locations', $report['skipped']);
        $this->assertStringContainsString('3 FK-sértő', $report['skipped']['sales.location_id->locations']);
    }
}
