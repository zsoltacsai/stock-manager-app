<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * A `tools/backfill-legacy-invoices.php` egyszeri, kézi backfill scriptet
 * teszteli — VALÓDI alfolyamatban futtatva (proc_open/exec), egy
 * eldobható ideiglenes SQLite fájlon, SOSE az éles adatbázison. A script
 * elfogad egy opcionális argv[1] DB-útvonalat kifejezetten erre a célra
 * (lásd a script tetején lévő docblockot).
 */
final class BackfillLegacyInvoicesTest extends TestCase
{
    private string $dbPath;
    private Database $db;

    protected function setUp(): void
    {
        $this->dbPath = sys_get_temp_dir() . '/sm_backfill_test_' . bin2hex(random_bytes(8)) . '.sqlite';
        $this->db = new Database(['driver' => 'sqlite', 'sqlite' => ['path' => $this->dbPath]], dirname(__DIR__));
    }

    protected function tearDown(): void
    {
        @unlink($this->dbPath);
        @unlink($this->dbPath . '-shm');
        @unlink($this->dbPath . '-wal');
    }

    private function runBackfill(): string
    {
        $script = escapeshellarg(dirname(__DIR__) . '/tools/backfill-legacy-invoices.php');
        $dbArg = escapeshellarg($this->dbPath);
        $php = escapeshellarg(PHP_BINARY);
        return (string) shell_exec("$php $script $dbArg 2>&1");
    }

    private function runBackfillDryRun(): string
    {
        $script = escapeshellarg(dirname(__DIR__) . '/tools/backfill-legacy-invoices.php');
        $dbArg = escapeshellarg($this->dbPath);
        $php = escapeshellarg(PHP_BINARY);
        return (string) shell_exec("$php $script --dry-run $dbArg 2>&1");
    }

    /** Egy régi (Phase 1-4 előtti mintájú) eladást hoz létre: van szamlazz_invoice_number, DE nincs invoices sor. */
    private function insertLegacySale(string $invoiceNumber, float $total = 1270.0, string $vatRate = '27'): int
    {
        $saleId = $this->db->insertSale($total, 'Készpénz');
        $this->db->pdo()->prepare('UPDATE sales SET szamlazz_invoice_number = ?, szamlazz_pdf_path = ? WHERE id = ?')
            ->execute([$invoiceNumber, '/invoices/' . $invoiceNumber . '.pdf', $saleId]);
        $this->db->insertSaleItem($saleId, ['product_id' => null, 'name' => 'Teszt tétel', 'qty' => 1, 'unit_price' => $total, 'vat_rate' => $vatRate]);
        return $saleId;
    }

    public function testMigratesOldSaleCorrectly(): void
    {
        $saleId = $this->insertLegacySale('SZ-2020-001', 1270.0, '27');

        $output = $this->runBackfill();

        $this->assertStringContainsString('Migrálható', $output, "A script kimenete: $output");

        $verifyDb = new Database(['driver' => 'sqlite', 'sqlite' => ['path' => $this->dbPath]], dirname(__DIR__));
        $invoice = $verifyDb->findInvoiceBySaleAndProvider($saleId, 'szamlazz');

        $this->assertNotNull($invoice, "A régi eladáshoz létre kellett volna jönnie egy invoices sornak. Script kimenet: $output");
        $this->assertSame('done', $invoice['status']);
        $this->assertSame('SZ-2020-001', $invoice['invoice_number']);
        $this->assertEqualsWithDelta(1000.0, (float) $invoice['net_total'], 0.01);
        $this->assertEqualsWithDelta(270.0, (float) $invoice['vat_total'], 0.01);
        $this->assertEqualsWithDelta(1270.0, (float) $invoice['gross_total'], 0.01);
        $this->assertSame('/invoices/SZ-2020-001.pdf', $invoice['pdf_path']);
    }

    public function testMigrationRespectsDiscountRatio(): void
    {
        // Egy tétel 1270 Ft bruttó lenne, DE a sale.total csak 1000 Ft (kedvezmény miatt) —
        // a visszaszámolt nettó+ÁFA-nak a TÉNYLEGES (kedvezményes) 1000 Ft-ot kell kiadnia, nem 1270-et.
        $saleId = $this->db->insertSale(1000.0, 'Készpénz');
        $this->db->pdo()->prepare('UPDATE sales SET szamlazz_invoice_number = ? WHERE id = ?')->execute(['SZ-DISC-1', $saleId]);
        $this->db->insertSaleItem($saleId, ['product_id' => null, 'name' => 'Tétel', 'qty' => 1, 'unit_price' => 1270.0, 'vat_rate' => '27']);

        $this->runBackfill();

        $verifyDb = new Database(['driver' => 'sqlite', 'sqlite' => ['path' => $this->dbPath]], dirname(__DIR__));
        $invoice = $verifyDb->findInvoiceBySaleAndProvider($saleId, 'szamlazz');

        $this->assertEqualsWithDelta(1000.0, (float) $invoice['gross_total'], 0.01);
        $this->assertEqualsWithDelta(
            (float) $invoice['net_total'] + (float) $invoice['vat_total'],
            (float) $invoice['gross_total'],
            0.02,
            'A visszaszámolt nettó+ÁFA összegnek a TÉNYLEGES (kedvezményezett) bruttó összeget kell kiadnia.'
        );
    }

    public function testSkipsAlreadyExistingInvoice(): void
    {
        $saleId = $this->insertLegacySale('SZ-2020-002');
        // Előre létrehozunk egy invoices sort erre a sale-re — a backfillnek ezt NEM szabad duplikálnia.
        $this->db->upsertInvoiceMirror($saleId, 'szamlazz', true, 'SZ-2020-002', null, null, 1000.0, 270.0, 1270.0, 'HUF');

        $output = $this->runBackfill();

        $verifyDb = new Database(['driver' => 'sqlite', 'sqlite' => ['path' => $this->dbPath]], dirname(__DIR__));
        $stmt = $verifyDb->pdo()->prepare('SELECT COUNT(*) FROM invoices WHERE sale_id = ? AND provider = ?');
        $stmt->execute([$saleId, 'szamlazz']);
        $this->assertSame(1, (int) $stmt->fetchColumn(), "Pontosan egy invoices sornak szabad léteznie, nem duplikálva. Kimenet: $output");
        $this->assertStringContainsString('Már meglévő matching invoices rekord: 1', $output);
    }

    public function testIdempotentAcrossTwoConsecutiveRuns(): void
    {
        $this->insertLegacySale('SZ-2020-003');
        $this->insertLegacySale('SZ-2020-004');

        $this->runBackfill();
        $secondOutput = $this->runBackfill();

        $this->assertStringContainsString('Újonnan létrehozott invoices rekord: 0', $secondOutput, "A második futtatásnak semmit se szabadna újra létrehoznia. Kimenet: $secondOutput");
        $this->assertStringContainsString('Már meglévő matching invoices rekord: 2', $secondOutput);

        $verifyDb = new Database(['driver' => 'sqlite', 'sqlite' => ['path' => $this->dbPath]], dirname(__DIR__));
        $count = (int) $verifyDb->pdo()->query("SELECT COUNT(*) FROM invoices WHERE provider = 'szamlazz'")->fetchColumn();
        $this->assertSame(2, $count, 'Két egymást követő futtatás után se lehet duplikátum.');
    }

    public function testReconciliationMathHolds(): void
    {
        $this->insertLegacySale('SZ-REC-1');
        $this->insertLegacySale('SZ-REC-2');
        // Egy már meglévő invoices sorral rendelkező sale is a jelöltek közé tartozik.
        $existingSaleId = $this->insertLegacySale('SZ-REC-3');
        $this->db->upsertInvoiceMirror($existingSaleId, 'szamlazz', true, 'SZ-REC-3', null, null, 1000.0, 270.0, 1270.0, 'HUF');
        // Egy jelölt sale_items NÉLKÜL — ezt a scriptnek ki kell hagynia (skipped).
        $noItemsSaleId = $this->db->insertSale(500.0, 'Készpénz');
        $this->db->pdo()->prepare('UPDATE sales SET szamlazz_invoice_number = ? WHERE id = ?')->execute(['SZ-REC-4', $noItemsSaleId]);

        $output = $this->runBackfill();

        preg_match('/Migrálható[^:]*:\s*(\d+)/u', $output, $mMigratable);
        preg_match('/meglévő matching invoices rekord:\s*(\d+)/u', $output, $mExisting);
        preg_match('/Újonnan létrehozott invoices rekord:\s*(\d+)/u', $output, $mCreated);
        preg_match('/Kihagyott rekord:\s*(\d+)/u', $output, $mSkipped);
        preg_match('/Hibás\/?[a-záéíóúöüőű]*\s*rekord:\s*(\d+)/u', $output, $mErrors);

        $migratable = (int) ($mMigratable[1] ?? -1);
        $existing = (int) ($mExisting[1] ?? -1);
        $created = (int) ($mCreated[1] ?? -1);
        $skipped = (int) ($mSkipped[1] ?? -1);
        $errors = (int) ($mErrors[1] ?? -1);

        $this->assertSame(4, $migratable, "Kimenet: $output");
        $this->assertSame(1, $existing);
        $this->assertSame(2, $created);
        $this->assertSame(1, $skipped);
        $this->assertSame(0, $errors);
        $this->assertSame($migratable, $existing + $created + $skipped + $errors, 'migrálható = meglévő + létrehozott + kihagyott + hibás');
        $this->assertStringContainsString('=> OK', $output);
    }

    public function testNeverModifiesSalesOrSaleItemsData(): void
    {
        $saleId = $this->insertLegacySale('SZ-2020-005', 999.0, '27');
        $beforeSale = $this->db->pdo()->query("SELECT * FROM sales WHERE id = $saleId")->fetch(PDO::FETCH_ASSOC);
        $beforeItems = $this->db->pdo()->query("SELECT * FROM sale_items WHERE sale_id = $saleId")->fetchAll(PDO::FETCH_ASSOC);

        $this->runBackfill();

        $verifyDb = new Database(['driver' => 'sqlite', 'sqlite' => ['path' => $this->dbPath]], dirname(__DIR__));
        $afterSale = $verifyDb->pdo()->query("SELECT * FROM sales WHERE id = $saleId")->fetch(PDO::FETCH_ASSOC);
        $afterItems = $verifyDb->pdo()->query("SELECT * FROM sale_items WHERE sale_id = $saleId")->fetchAll(PDO::FETCH_ASSOC);

        $this->assertSame($beforeSale, $afterSale, 'A sales sor egyetlen mezője se változhat a backfill hatására.');
        $this->assertSame($beforeItems, $afterItems, 'A sale_items sorok egyetlen mezője se változhat a backfill hatására.');
    }

    public function testSalesWithoutInvoiceNumberAreNeverMigrated(): void
    {
        // invoice_failed jellegű régi eladás: NINCS szamlazz_invoice_number.
        $this->db->insertSale(500.0, 'Készpénz');

        $output = $this->runBackfill();

        $this->assertStringContainsString('Migrálható', $output);
        preg_match('/Migrálható[^:]*:\s*(\d+)/u', $output, $m);
        $this->assertSame(0, (int) ($m[1] ?? -1), "Egy szamlazz_invoice_number nélküli sale sose lehet jelölt. Kimenet: $output");
    }

    // ---- --dry-run ----

    public function testDryRunMakesNoDatabaseChanges(): void
    {
        $this->insertLegacySale('SZ-DRY-1');
        $this->insertLegacySale('SZ-DRY-2');

        $output = $this->runBackfillDryRun();

        $this->assertStringContainsString('DRY RUN', $output);
        $this->assertStringContainsString('AZ ADATBÁZIS NEM MÓDOSULT', $output);

        $verifyDb = new Database(['driver' => 'sqlite', 'sqlite' => ['path' => $this->dbPath]], dirname(__DIR__));
        $count = (int) $verifyDb->pdo()->query("SELECT COUNT(*) FROM invoices WHERE provider = 'szamlazz'")->fetchColumn();
        $this->assertSame(0, $count, "Dry-run UTÁN egyetlen invoices sornak se szabad léteznie. Kimenet: $output");
    }

    public function testDryRunReportsSameCountsAsRealRunWouldProduce(): void
    {
        $this->insertLegacySale('SZ-DRY-3');
        $noItemsSaleId = $this->db->insertSale(500.0, 'Készpénz');
        $this->db->pdo()->prepare('UPDATE sales SET szamlazz_invoice_number = ? WHERE id = ?')->execute(['SZ-DRY-NOITEMS', $noItemsSaleId]);

        $dryOutput = $this->runBackfillDryRun();
        preg_match('/Migrálásra váró rekord:\s*(\d+)/u', $dryOutput, $mPending);
        preg_match('/Kihagyott rekord:\s*(\d+)/u', $dryOutput, $mSkipped);
        $this->assertSame(1, (int) ($mPending[1] ?? -1), "Kimenet: $dryOutput");
        $this->assertSame(1, (int) ($mSkipped[1] ?? -1), "Kimenet: $dryOutput");
        $this->assertStringContainsString("Kihagyott sale_id-k: $noItemsSaleId", $dryOutput, 'A konkrét sale_id-nak szerepelnie kell a riportban.');

        // A tényleges (nem dry-run) futtatásnak UGYANEZEKET a számokat
        // kell produkálnia (persze "Újonnan létrehozott" címkével).
        $realOutput = $this->runBackfill();
        preg_match('/Újonnan létrehozott invoices rekord:\s*(\d+)/u', $realOutput, $mCreated);
        preg_match('/Kihagyott rekord:\s*(\d+)/u', $realOutput, $mSkipped2);
        $this->assertSame(1, (int) ($mCreated[1] ?? -1), "Kimenet: $realOutput");
        $this->assertSame(1, (int) ($mSkipped2[1] ?? -1), "Kimenet: $realOutput");
    }

    public function testDryRunFollowedByRealRunProducesSameFinalStateAsRealRunAlone(): void
    {
        $this->insertLegacySale('SZ-DRY-4');
        $this->insertLegacySale('SZ-DRY-5');

        $this->runBackfillDryRun(); // NEM ír semmit
        $this->runBackfillDryRun(); // ismételt dry-run, ez se ír semmit
        $this->runBackfill();       // most már ténylegesen ír

        $verifyDb = new Database(['driver' => 'sqlite', 'sqlite' => ['path' => $this->dbPath]], dirname(__DIR__));
        $count = (int) $verifyDb->pdo()->query("SELECT COUNT(*) FROM invoices WHERE provider = 'szamlazz'")->fetchColumn();
        $this->assertSame(2, $count, 'Több dry-run, majd egy valódi futás után is pontosan a várt (nem duplikált) számú sornak kell létrejönnie.');
    }

    public function testRealRunIsSafelyResumableAfterSimulatedInterruption(): void
    {
        // Szimulált "menet közbeni megszakítás": két jelölt közül csak az
        // egyiket migráljuk (mintha a script itt állt volna le), majd egy
        // ÚJ futtatás fejezze be — a végállapotnak pontosan ugyanannak
        // kell lennie, mintha egyetlen, meg nem szakított futás dolgozta
        // volna fel mindkettőt (nincs félkész/duplikált sor).
        $saleId1 = $this->insertLegacySale('SZ-RESUME-1');
        $saleId2 = $this->insertLegacySale('SZ-RESUME-2');

        // "Az első futás" csak saleId1-et dolgozza fel — ezt egy közvetlen,
        // a scripttel AZONOS logikájú beszúrással szimuláljuk, mert a
        // scriptet magát nem lehet félbeszakítani determinisztikusan.
        $this->db->upsertInvoiceMirror($saleId1, 'szamlazz', true, 'SZ-RESUME-1', null, null, 1000.0, 270.0, 1270.0, 'HUF');

        // "A folytatás": egy teljesen új, normál (nem dry-run) futtatás.
        $output = $this->runBackfill();

        $verifyDb = new Database(['driver' => 'sqlite', 'sqlite' => ['path' => $this->dbPath]], dirname(__DIR__));
        $countStmt = $verifyDb->pdo()->prepare("SELECT COUNT(*) FROM invoices WHERE sale_id = ? AND provider = 'szamlazz'");
        $countStmt->execute([$saleId1]);
        $count1 = (int) $countStmt->fetchColumn();
        $countStmt->execute([$saleId2]);
        $count2 = (int) $countStmt->fetchColumn();

        $this->assertSame(1, $count1, "A 'megszakítás előtt' már migrált sale-hez pontosan egy sornak szabad léteznie (nem duplikálva). Kimenet: $output");
        $this->assertSame(1, $count2, "A 'megszakítás után' folytatásnak fel kellett dolgoznia a második sale-t is. Kimenet: $output");
    }
}
