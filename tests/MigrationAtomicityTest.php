<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * A `Database::migrateV20IncomingInvoices()` atomicitását/megszakítás-
 * tűrését ellenőrző regressziós tesztek. ÉLESBEN reprodukált hibát
 * fednek le: a `data/stock.sqlite`-on a `schema_version` 20-ra ugrott
 * úgy, hogy az `incoming_invoices`/`incoming_invoice_items`/
 * `incoming_invoice_sync` táblák ténylegesen NEM jöttek létre (a
 * migráció NEM volt tranzakcióba csomagolva, és a seed-sor beszúrása
 * NEM volt idempotens — egy megszakadt-majd-újrafuttatott migráció a
 * seed-sor UNIQUE-ütközésén véglegesen elhasalt, MINDEN további kérést
 * fatal error-ral elutasítva).
 *
 * A javítás után:
 *   - SQLite-on VALÓDI, teljes atomicitás — egy genuinely sikertelen
 *     migráció SEMMIT nem hagy maga után (teljes rollback, a
 *     schema_version SEM lép előre).
 *   - Mindkét motoron (SQLite ÉS MySQL, ahol a DDL nem tranzakcionális)
 *     a migráció idempotens — egy megszakadt-majd-újrafuttatott
 *     migráció a KÖVETKEZŐ kérésnél hiba nélkül, a hiányzó résztől
 *     folytatva fejeződik be.
 */
final class MigrationAtomicityTest extends TestCase
{
    private function freshTempDbPath(): string
    {
        $path = sys_get_temp_dir() . '/sm_migration_atomicity_' . bin2hex(random_bytes(8)) . '.sqlite';
        register_shutdown_function(static function () use ($path) {
            @unlink($path);
            @unlink($path . '-shm');
            @unlink($path . '-wal');
        });
        return $path;
    }

    /**
     * Egy MÁR v20-ra migrált adatbázist manuálisan visszaállít egy
     * "v19, a 3 új tábla még nem létezik" állapotba — ez a kiindulópont
     * minden alábbi teszthez, ugyanaz a technika, amivel a Phase 6
     * checkpoint-jelentés élő adatbázison talált hibáját is
     * reprodukáltam és javítottam.
     */
    private function revertToPreV20State(PDO $pdo): void
    {
        $pdo->exec('DROP TABLE IF EXISTS incoming_invoices');
        $pdo->exec('DROP TABLE IF EXISTS incoming_invoice_items');
        $pdo->exec('DROP TABLE IF EXISTS incoming_invoice_sync');
        $pdo->exec('UPDATE schema_version SET version = 19');
    }

    /**
     * A KULCS-regressziós teszt: egy megszakadt migráció (a metódus
     * sikeresen lefut és létrehozza+seedeli a táblákat, DE a hívó —
     * mintha egy folyamat összeomlott volna — SOSE jut el a
     * setSchemaVersion()-ig) UTÁN a KÖVETKEZŐ `new Database()` hívásnak
     * (ami újra nekifut a teljes v20 migrációnak, mert a verzió még 19)
     * HIBA NÉLKÜL, helyesen kell befejeznie a migrációt — NEM szabad
     * UNIQUE-ütközést dobnia a már létező seed-sor miatt.
     */
    public function testInterruptedV20MigrationCanBeSafelyRetriedWithoutError(): void
    {
        $path = $this->freshTempDbPath();
        $db = new Database(['driver' => 'sqlite', 'sqlite' => ['path' => $path]], dirname(__DIR__));
        $pdo = new PDO('sqlite:' . $path);
        $this->revertToPreV20State($pdo);

        // Az ELSŐ (sikeres) migrációs kísérlet lefut — de a schema_version
        // frissítését KIHAGYJUK (ez szimulálja a "folyamat összeomlott
        // közvetlenül a setSchemaVersion() ELŐTT" esetet).
        $ref = new ReflectionMethod(Database::class, 'migrateV20IncomingInvoices');
        $ref->setAccessible(true);
        $ref->invoke($db);

        $this->assertSame('19', (string) $pdo->query('SELECT version FROM schema_version')->fetchColumn(), 'A verziószám még nem frissült — ez a szimulált "megszakadás" pontja.');
        $this->assertSame(1, (int) $pdo->query('SELECT COUNT(*) FROM incoming_invoice_sync')->fetchColumn());

        // A KÖVETKEZŐ kérés (új Database() példány) újra nekifut a teljes
        // migrációs láncnak, MERT a verzió még mindig 19 — ennek HIBA
        // NÉLKÜL kell lefutnia.
        $db2 = new Database(['driver' => 'sqlite', 'sqlite' => ['path' => $path]], dirname(__DIR__));

        $pdo2 = new PDO('sqlite:' . $path);
        $this->assertSame('20', (string) $pdo2->query('SELECT version FROM schema_version')->fetchColumn());
        $this->assertSame(1, (int) $pdo2->query('SELECT COUNT(*) FROM incoming_invoice_sync')->fetchColumn(), 'Nem duplikálódhat a seed-sor.');
        $row = $pdo2->query("SELECT * FROM incoming_invoice_sync WHERE provider = 'nav'")->fetch(PDO::FETCH_ASSOC);
        $this->assertSame('idle', $row['status']);
    }

    /**
     * Ugyanaz, mint fent, de a "megszakadást" a lapozás/tábla-létrehozás
     * KÖZEPÉN szimuláljuk (csak az `incoming_invoices` tábla jön létre,
     * a másik kettő NEM) — a retry-nek EBBŐL az állapotból is hiba
     * nélkül kell befejeznie.
     */
    public function testInterruptedV20MigrationMidwayThroughTableCreationCanBeSafelyRetried(): void
    {
        $path = $this->freshTempDbPath();
        $db = new Database(['driver' => 'sqlite', 'sqlite' => ['path' => $path]], dirname(__DIR__));
        $pdo = new PDO('sqlite:' . $path);
        $this->revertToPreV20State($pdo);

        // Csak az ELSŐ táblát hozzuk létre kézzel, mintha a migráció itt
        // szakadt volna meg (a schema_version marad 19).
        $pdo->exec("CREATE TABLE incoming_invoices (
            id INTEGER PRIMARY KEY AUTOINCREMENT, nav_transaction_id VARCHAR(64), invoice_number VARCHAR(64) NOT NULL,
            batch_index INT UNSIGNED NOT NULL DEFAULT 0, supplier_tax_number VARCHAR(16) NOT NULL,
            supplier_group_member_tax_number VARCHAR(16), supplier_name VARCHAR(512) NOT NULL, supplier_country VARCHAR(8),
            customer_tax_number VARCHAR(16), customer_name VARCHAR(512), invoice_operation VARCHAR(16) NOT NULL,
            invoice_category VARCHAR(16), original_invoice_number VARCHAR(64), modification_index VARCHAR(32),
            invoice_issue_date VARCHAR(16), invoice_delivery_date VARCHAR(16), payment_date VARCHAR(16), payment_method VARCHAR(16),
            currency VARCHAR(8) NOT NULL DEFAULT 'HUF', net_total REAL, vat_total REAL, gross_total REAL,
            nav_ins_date VARCHAR(32) NOT NULL, detail_fetched_at TEXT, first_seen_at TEXT NOT NULL DEFAULT (datetime('now')),
            last_synced_at TEXT NOT NULL DEFAULT (datetime('now')), created_at TEXT NOT NULL DEFAULT (datetime('now')), updated_at TEXT NOT NULL DEFAULT (datetime('now'))
        )");

        $db2 = new Database(['driver' => 'sqlite', 'sqlite' => ['path' => $path]], dirname(__DIR__));

        $pdo2 = new PDO('sqlite:' . $path);
        $this->assertSame('20', (string) $pdo2->query('SELECT version FROM schema_version')->fetchColumn());
        foreach (['incoming_invoices', 'incoming_invoice_items', 'incoming_invoice_sync'] as $table) {
            $pdo2->query("SELECT 1 FROM $table LIMIT 1"); // dob, ha nem létezik -- a teszt maga a bizonyíték
        }
        $this->assertSame(1, (int) $pdo2->query('SELECT COUNT(*) FROM incoming_invoice_sync')->fetchColumn());
    }

    /**
     * VALÓDI atomicitás-bizonyíték: egy GENUINE (nem "megszakadás",
     * hanem TÉNYLEGES) hiba a migráció KÖZEPÉN a MÁR létrehozott
     * táblákat is visszavonja (SQLite tranzakcionális DDL-je révén) — a
     * schema_version SEM lép előre. Ezt egy inkompatibilis, kézzel
     * előre létrehozott `incoming_invoice_sync` táblával idézzük elő
     * (a UNIQUE index létrehozása egy nemlétező oszlopra genuinely
     * hibázik, nem "already exists"-jellegű jóindulatú eset).
     */
    public function testGenuineFailurePartwayThroughRollsBackEverythingAndDoesNotAdvanceVersion(): void
    {
        $path = $this->freshTempDbPath();
        $db = new Database(['driver' => 'sqlite', 'sqlite' => ['path' => $path]], dirname(__DIR__));
        $pdo = new PDO('sqlite:' . $path);
        $this->revertToPreV20State($pdo);

        // Egy inkompatibilis "incoming_invoice_sync" tábla, aminek NINCS
        // "provider" oszlopa -- a CREATE TABLE IF NOT EXISTS erre nem
        // hibázik (jóindulatú "already exists"), DE a rákövetkező UNIQUE
        // index létrehozása a "provider" oszlopra genuinely el fog hasalni.
        $pdo->exec('CREATE TABLE incoming_invoice_sync (bogus_column INTEGER)');

        $ref = new ReflectionMethod(Database::class, 'migrateV20IncomingInvoices');
        $ref->setAccessible(true);

        $threw = false;
        try {
            $ref->invoke($db);
        } catch (Throwable $e) {
            $threw = true;
            $this->assertStringContainsString('no such column', $e->getMessage());
        }
        $this->assertTrue($threw, 'A genuinely inkompatibilis állapotnak hibát KELLETT dobnia.');

        // A KORÁBBAN, UGYANEBBEN A TRANZAKCIÓBAN sikeresen létrehozott
        // incoming_invoices/incoming_invoice_items tábláknak is EL KELL
        // TŰNNIÜK -- ez a valódi "minden vagy semmi" bizonyítéka.
        foreach (['incoming_invoices', 'incoming_invoice_items'] as $table) {
            try {
                $pdo->query("SELECT 1 FROM $table LIMIT 1");
                $this->fail("$table nem tűnt el a rollback után -- a migráció NEM volt atomikus.");
            } catch (PDOException $e) {
                $this->assertStringContainsString('no such table', $e->getMessage());
            }
        }
        $this->assertSame('19', (string) $pdo->query('SELECT version FROM schema_version')->fetchColumn(), 'Egy genuinely sikertelen migráció UTÁN a schema_version SEM léphet előre.');
    }

    /**
     * VALÓDI, több-folyamatos konkurrencia-teszt — a
     * `DatabaseTest::testNavInvoiceQueueClaimIsAtomicAcrossRealConcurrentProcesses()`
     * mintájára: N teljesen független PHP-folyamat EGYSZERRE
     * instantiate-el egy `Database`-t egy KÖZÖS, még v19-állapotú
     * SQLite fájlon — mindegyiknek HIBA NÉLKÜL kell lefutnia, és a
     * végállapotnak konzisztensnek kell lennie (mindhárom tábla
     * létrejön, PONTOSAN egy seed-sor, schema_version=20).
     */
    public function testConcurrentDatabaseInstantiationMigratesSafelyAcrossRealProcesses(): void
    {
        if (!function_exists('proc_open')) {
            $this->markTestSkipped('proc_open nem elérhető — VALÓDI többfolyamatos migrációs teszt itt nem futott le.');
        }

        $path = $this->freshTempDbPath();
        $projectRoot = dirname(__DIR__);

        $setupDb = new Database(['driver' => 'sqlite', 'sqlite' => ['path' => $path]], $projectRoot);
        $pdo = new PDO('sqlite:' . $path);
        $this->revertToPreV20State($pdo);
        unset($setupDb);

        $resultFile = sys_get_temp_dir() . '/sm_migration_concurrency_result_' . bin2hex(random_bytes(6)) . '.txt';
        register_shutdown_function(static function () use ($resultFile) {
            @unlink($resultFile);
        });

        $childScriptPath = sys_get_temp_dir() . '/sm_migration_concurrency_child_' . bin2hex(random_bytes(6)) . '.php';
        file_put_contents($childScriptPath, <<<'PHP'
            <?php
            require $argv[1] . '/src/Database.php';
            try {
                $db = new Database(['driver' => 'sqlite', 'sqlite' => ['path' => $argv[2]]], $argv[1]);
                file_put_contents($argv[3], "ok\n", FILE_APPEND | LOCK_EX);
            } catch (Throwable $e) {
                file_put_contents($argv[3], "error:" . $e->getMessage() . "\n", FILE_APPEND | LOCK_EX);
            }
            PHP);
        register_shutdown_function(static function () use ($childScriptPath) {
            @unlink($childScriptPath);
        });

        $processCount = 12;
        $handles = [];
        $devNull = sys_get_temp_dir() . '/sm_migration_concurrency_out_' . bin2hex(random_bytes(4)) . '.log';
        for ($i = 0; $i < $processCount; $i++) {
            $handles[] = proc_open(
                [PHP_BINARY, $childScriptPath, $projectRoot, $path, $resultFile],
                [1 => ['file', $devNull, 'a'], 2 => ['file', $devNull, 'a']],
                $pipes
            );
        }
        foreach ($handles as $handle) {
            if (is_resource($handle)) {
                proc_close($handle);
            }
        }
        @unlink($devNull);

        $lines = array_filter(explode("\n", trim((string) @file_get_contents($resultFile))));
        $okCount = count(array_filter($lines, static fn ($l) => $l === 'ok'));
        $errorLines = array_filter($lines, static fn ($l) => $l !== 'ok');

        $this->assertCount($processCount, $lines, "Mind a $processCount folyamatnak le kellett futnia.");
        $this->assertSame($processCount, $okCount, 'Egyetlen folyamat SEM hibázhat a konkurrens migráció miatt: ' . implode(' | ', $errorLines));

        $verifyPdo = new PDO('sqlite:' . $path);
        $this->assertSame('20', (string) $verifyPdo->query('SELECT version FROM schema_version')->fetchColumn());
        foreach (['incoming_invoices', 'incoming_invoice_items', 'incoming_invoice_sync'] as $table) {
            $verifyPdo->query("SELECT 1 FROM $table LIMIT 1");
        }
        $this->assertSame(1, (int) $verifyPdo->query('SELECT COUNT(*) FROM incoming_invoice_sync')->fetchColumn(), 'A konkurrens migráció ellenére sem duplikálódhat a seed-sor.');
    }
}
