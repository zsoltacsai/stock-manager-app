<?php

declare(strict_types=1);

require_once __DIR__ . '/../src/Database.php';

use PHPUnit\Framework\TestCase;

/**
 * Database::allocateInvoiceNumber() — a FountainTrade 1.1.0 MODIFY/STORNO
 * előkészítő körének provider-kulcsolt, atomikus számlaszám-sorozata.
 * Lásd Database::migrateV22InvoiceOperationsBody() docblockja a teljes
 * tervezési indoklásért (miért NEM invoices.id/MAX()+1-alapú).
 */
final class InvoiceSequenceTest extends TestCase
{
    public function testFirstAllocationReturnsOne(): void
    {
        $db = tests_new_database();
        $this->assertSame(1, $db->allocateInvoiceNumber('nav'));
    }

    public function testSequentialAllocationIncrementsByOne(): void
    {
        $db = tests_new_database();
        $this->assertSame(1, $db->allocateInvoiceNumber('nav'));
        $this->assertSame(2, $db->allocateInvoiceNumber('nav'));
        $this->assertSame(3, $db->allocateInvoiceNumber('nav'));
    }

    public function testSeparateProvidersHaveIndependentSequences(): void
    {
        $db = tests_new_database();
        $this->assertSame(1, $db->allocateInvoiceNumber('nav'));
        $this->assertSame(2, $db->allocateInvoiceNumber('nav'));
        // Egy MÁSIK provider sorozata NEM folytatja a nav-ét — saját, 1-től induló számláló.
        $this->assertSame(1, $db->allocateInvoiceNumber('szamlazz'));
        $this->assertSame(3, $db->allocateInvoiceNumber('nav'));
        $this->assertSame(2, $db->allocateInvoiceNumber('szamlazz'));
    }

    public function testExistingInvoicesAreUnaffectedByNewAllocations(): void
    {
        $db = tests_new_database();
        $saleId = $db->insertSale(1000.0, 'Készpénz', null, null, 0, 0, null, 0, 0, null, '', null);
        $existing = $db->insertQueuedInvoice($saleId, 'nav', 800.0, 200.0, 1000.0, 'HUF', ['buyer' => [], 'items' => []]);
        $this->assertNotNull($existing);
        $originalNumber = $existing['invoice_number'];
        $this->assertStringStartsWith('FT-NAV-', $originalNumber);

        // Egy KÉSŐBBI allokálás nem változtatja meg a MÁR kiosztott számot.
        $db->allocateInvoiceNumber('nav');
        $db->allocateInvoiceNumber('nav');

        $reread = $db->getInvoiceById((int) $existing['id']);
        $this->assertSame($originalNumber, $reread['invoice_number']);
    }

    /**
     * Valódi, több különálló OS-folyamattal bizonyítja, hogy az
     * allokáció ténylegesen kizárja az egyidejű duplikálást — ugyanaz a
     * minta, mint DatabaseTest::testNavInvoiceQueueClaimIsAtomicAcrossRealConcurrentProcesses()
     * és UpdateLockTest::testUpdateLockClaimIsAtomicAcrossRealConcurrentProcesses().
     * A kérés 17. pontjának explicit tiltása szerint SOSE MAX()+1 (ami
     * konkurrens folyamatok között duplikátumot adna) — ez a teszt EZT a
     * hibaosztályt zárja ki, valódi konkurrenciával, nem mockolt zárral.
     */
    public function testAllocationIsAtomicAcrossRealConcurrentProcesses(): void
    {
        if (!function_exists('proc_open')) {
            $this->markTestSkipped('proc_open nem elérhető — VALÓDI többfolyamatos konkurrencia-teszt itt nem futott le.');
        }

        $dbPath = sys_get_temp_dir() . '/ft_invoice_sequence_concurrency_test_' . bin2hex(random_bytes(8)) . '.sqlite';
        register_shutdown_function(static function () use ($dbPath) {
            @unlink($dbPath);
            @unlink($dbPath . '-shm');
            @unlink($dbPath . '-wal');
        });

        $projectRoot = dirname(__DIR__);
        $processCount = 16;

        $setupDb = new Database(['driver' => 'sqlite', 'sqlite' => ['path' => $dbPath]], $projectRoot);
        unset($setupDb);

        $resultFile = sys_get_temp_dir() . '/ft_invoice_sequence_concurrency_result_' . bin2hex(random_bytes(6)) . '.txt';
        register_shutdown_function(static function () use ($resultFile) {
            @unlink($resultFile);
        });

        $childScriptPath = sys_get_temp_dir() . '/ft_invoice_sequence_concurrency_child_' . bin2hex(random_bytes(6)) . '.php';
        file_put_contents($childScriptPath, <<<'PHP'
            <?php
            require $argv[1] . '/src/Database.php';
            $db = new Database(['driver' => 'sqlite', 'sqlite' => ['path' => $argv[2]]], $argv[1]);
            $number = $db->allocateInvoiceNumber('nav');
            file_put_contents($argv[3], $number . "\n", FILE_APPEND | LOCK_EX);
            PHP);
        register_shutdown_function(static function () use ($childScriptPath) {
            @unlink($childScriptPath);
        });

        $handles = [];
        $devNull = sys_get_temp_dir() . '/ft_invoice_sequence_concurrency_out_' . bin2hex(random_bytes(4)) . '.log';
        for ($i = 0; $i < $processCount; $i++) {
            $handles[] = proc_open(
                [PHP_BINARY, $childScriptPath, $projectRoot, $dbPath, $resultFile],
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

        $numbers = array_map('intval', array_filter(explode("\n", trim((string) @file_get_contents($resultFile)))));

        $this->assertCount($processCount, $numbers, "Mind a $processCount folyamatnak sikeresen kellett volna allokálnia.");
        $this->assertCount($processCount, array_unique($numbers), 'Két folyamat SOSE kaphatja ugyanazt a sorszámot.');
        sort($numbers);
        $this->assertSame(range(1, $processCount), $numbers, 'A 16 konkurrens allokálásnak pontosan az 1..16 tartományt kell lefednie, kihagyás/duplikálás nélkül.');
    }

    public function testAllocationComposesWithAnAlreadyOpenTransaction(): void
    {
        $db = tests_new_database();
        $db->beginTransaction();
        $first = $db->allocateInvoiceNumber('nav');
        $second = $db->allocateInvoiceNumber('nav');
        $db->commit();

        $this->assertSame(1, $first);
        $this->assertSame(2, $second);
    }

    /**
     * Ha egy KÜLSŐ tranzakció (amiben az allokálás történt) rollback-elődik,
     * az allokált szám NEM "él tovább" — a következő allokálás onnan
     * folytatja, mintha a sikertelen próbálkozás meg sem történt volna
     * (ez PONTOSAN a kért "ne legyen gap sikertelen tranzakció miatt,
     * ha ez elkerülhető" — itt elkerülhető, mert a számláló-inkrementet
     * MAGA a külső tranzakció vonja vissza).
     */
    public function testAllocationInsideARolledBackTransactionDoesNotLeaveAGap(): void
    {
        $db = tests_new_database();
        $db->beginTransaction();
        $wasted = $db->allocateInvoiceNumber('nav');
        $db->rollBack();

        $this->assertSame(1, $wasted);
        $this->assertSame(1, $db->allocateInvoiceNumber('nav'), 'A visszavont allokálás után a számlálónak úgy kell viselkednie, mintha az sose történt volna meg.');
    }
}
