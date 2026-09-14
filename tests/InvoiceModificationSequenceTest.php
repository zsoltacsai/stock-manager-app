<?php

declare(strict_types=1);

require_once __DIR__ . '/../src/Database.php';

use PHPUnit\Framework\TestCase;

/**
 * Database::allocateModificationIndex() — a NAV `modificationIndex`
 * (InvoiceReferenceType) eredeti-számlánkénti, MODIFY és STORNO között
 * KÖZÖS, atomikus számlálója. Lásd migrateV22InvoiceOperationsBody()
 * docblockja a NAV hivatalos dokumentációjából igazolt tervezési
 * döntésért (github.com/nav-gov-hu/Online-Invoice
 * MODIFICATION_INDEX_NOT_UNIQUE hibaosztálya).
 */
final class InvoiceModificationSequenceTest extends TestCase
{
    private function createNormalInvoice(Database $db, int $saleId): array
    {
        $row = $db->insertQueuedInvoice($saleId, 'nav', 800.0, 200.0, 1000.0, 'HUF', ['buyer' => [], 'items' => []]);
        $this->assertNotNull($row);
        return $row;
    }

    private function createSale(Database $db): int
    {
        return $db->insertSale(1000.0, 'Készpénz', null, null, 0, 0, null, 0, 0, null, uniqid('idem_', true), null);
    }

    public function testFirstModificationGetsIndexOne(): void
    {
        $db = tests_new_database();
        $original = $this->createNormalInvoice($db, $this->createSale($db));
        $this->assertSame(1, $db->allocateModificationIndex((int) $original['id']));
    }

    public function testSecondModificationGetsIndexTwo(): void
    {
        $db = tests_new_database();
        $original = $this->createNormalInvoice($db, $this->createSale($db));
        $this->assertSame(1, $db->allocateModificationIndex((int) $original['id']));
        $this->assertSame(2, $db->allocateModificationIndex((int) $original['id']));
    }

    /**
     * A kérés 9. pontjának explicit példája: MODIFY→1, MODIFY→2, STORNO→3
     * — a számláló nem tesz különbséget MODIFY és STORNO között, mindkettő
     * ugyanabból a folyamatos sorozatból merít.
     */
    public function testModifyThenModifyGivesOneAndTwo(): void
    {
        $db = tests_new_database();
        $original = $this->createNormalInvoice($db, $this->createSale($db));
        $modify1 = $db->createInvoiceOperation([
            'sale_id' => $original['sale_id'], 'provider' => 'nav', 'invoice_type' => 'modification',
            'original_invoice_id' => (int) $original['id'], 'operation_key' => 'modify:' . $original['id'] . ':' . uniqid(),
        ]);
        $modify2 = $db->createInvoiceOperation([
            'sale_id' => $original['sale_id'], 'provider' => 'nav', 'invoice_type' => 'modification',
            'original_invoice_id' => (int) $original['id'], 'operation_key' => 'modify:' . $original['id'] . ':' . uniqid(),
        ]);
        $this->assertSame(1, $modify1['modification_index']);
        $this->assertSame(2, $modify2['modification_index']);
    }

    public function testModifyThenStornoGivesOneAndTwo(): void
    {
        $db = tests_new_database();
        $original = $this->createNormalInvoice($db, $this->createSale($db));
        $modify = $db->createInvoiceOperation([
            'sale_id' => $original['sale_id'], 'provider' => 'nav', 'invoice_type' => 'modification',
            'original_invoice_id' => (int) $original['id'], 'operation_key' => 'modify:' . $original['id'] . ':' . uniqid(),
        ]);
        $storno = $db->createInvoiceOperation([
            'sale_id' => $original['sale_id'], 'provider' => 'nav', 'invoice_type' => 'storno',
            'original_invoice_id' => (int) $original['id'], 'operation_key' => 'storno:' . $original['id'],
        ]);
        $this->assertSame(1, $modify['modification_index']);
        $this->assertSame(2, $storno['modification_index']);
    }

    public function testStornoThenModifyGivesOneAndTwo(): void
    {
        $db = tests_new_database();
        $original = $this->createNormalInvoice($db, $this->createSale($db));
        $storno = $db->createInvoiceOperation([
            'sale_id' => $original['sale_id'], 'provider' => 'nav', 'invoice_type' => 'storno',
            'original_invoice_id' => (int) $original['id'], 'operation_key' => 'storno:' . $original['id'],
        ]);
        $modify = $db->createInvoiceOperation([
            'sale_id' => $original['sale_id'], 'provider' => 'nav', 'invoice_type' => 'modification',
            'original_invoice_id' => (int) $original['id'], 'operation_key' => 'modify:' . $original['id'] . ':' . uniqid(),
        ]);
        $this->assertSame(1, $storno['modification_index']);
        $this->assertSame(2, $modify['modification_index']);
    }

    public function testAllocationFailsForNonexistentOriginalInvoice(): void
    {
        $db = tests_new_database();
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/nem létezik/i');
        $db->allocateModificationIndex(999999);
    }

    /**
     * "normal invoice cannot receive modificationIndex" — egy módosítás/
     * sztornó sorra (ami MAGA is rendelkezik modification_index-szel)
     * HIVATKOZVA, mint "eredeti", a láncnak MINDIG a gyökér eredetihez
     * kell kötődnie (lásd a kör 9. pontjának ábrája) — egy közbenső
     * módosításra hivatkozás elutasítva.
     */
    public function testAllocationFailsWhenOriginalIsItselfAModification(): void
    {
        $db = tests_new_database();
        $original = $this->createNormalInvoice($db, $this->createSale($db));
        $modify = $db->createInvoiceOperation([
            'sale_id' => $original['sale_id'], 'provider' => 'nav', 'invoice_type' => 'modification',
            'original_invoice_id' => (int) $original['id'], 'operation_key' => 'modify:' . $original['id'] . ':' . uniqid(),
        ]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/kizárólag EREDETI/i');
        $db->allocateModificationIndex((int) $modify['id']);
    }

    public function testNewlyCreatedNormalInvoiceHasNullModificationIndex(): void
    {
        $db = tests_new_database();
        $original = $this->createNormalInvoice($db, $this->createSale($db));
        $this->assertNull($original['modification_index']);
        $this->assertSame('normal', $original['invoice_type']);
        $this->assertNull($original['original_invoice_id']);
    }

    public function testAllocationInsideARolledBackTransactionDoesNotLeaveAGap(): void
    {
        $db = tests_new_database();
        $original = $this->createNormalInvoice($db, $this->createSale($db));

        $db->beginTransaction();
        $wasted = $db->allocateModificationIndex((int) $original['id']);
        $db->rollBack();

        $this->assertSame(1, $wasted);
        $this->assertSame(1, $db->allocateModificationIndex((int) $original['id']));
    }

    /**
     * Valódi, több különálló OS-folyamattal bizonyítja, hogy 16
     * konkurrens, KÜLÖNBÖZŐ módosítási kísérlet mindegyike EGYEDI,
     * folyamatos sorszámot kap — ugyanaz a minta, mint
     * InvoiceSequenceTest::testAllocationIsAtomicAcrossRealConcurrentProcesses().
     * FONTOS: ez a teszt a számláló NYERS atomicitását bizonyítja — attól
     * függetlenül, hogy egy éles üzleti szabály ENGEDÉLYEZNÉ-e ténylegesen
     * 16 párhuzamos módosítást ugyanarra a számlára (lásd a kör auditjának
     * "operation_key" tervezési döntése a KÜLÖN, üzleti-szintű
     * idempotencia/egyediség-védelemért).
     */
    public function testConcurrentAllocationIsAtomicAcrossRealProcesses(): void
    {
        if (!function_exists('proc_open')) {
            $this->markTestSkipped('proc_open nem elérhető — VALÓDI többfolyamatos konkurrencia-teszt itt nem futott le.');
        }

        $dbPath = sys_get_temp_dir() . '/ft_mod_index_concurrency_test_' . bin2hex(random_bytes(8)) . '.sqlite';
        register_shutdown_function(static function () use ($dbPath) {
            @unlink($dbPath);
            @unlink($dbPath . '-shm');
            @unlink($dbPath . '-wal');
        });

        $projectRoot = dirname(__DIR__);
        $processCount = 16;

        $setupDb = new Database(['driver' => 'sqlite', 'sqlite' => ['path' => $dbPath]], $projectRoot);
        $saleId = $setupDb->insertSale(1000.0, 'Készpénz', null, null, 0, 0, null, 0, 0, null, uniqid('idem_', true), null);
        $original = $setupDb->insertQueuedInvoice($saleId, 'nav', 800.0, 200.0, 1000.0, 'HUF', ['buyer' => [], 'items' => []]);
        $originalId = (int) $original['id'];
        unset($setupDb);

        $resultFile = sys_get_temp_dir() . '/ft_mod_index_concurrency_result_' . bin2hex(random_bytes(6)) . '.txt';
        register_shutdown_function(static function () use ($resultFile) {
            @unlink($resultFile);
        });

        $childScriptPath = sys_get_temp_dir() . '/ft_mod_index_concurrency_child_' . bin2hex(random_bytes(6)) . '.php';
        file_put_contents($childScriptPath, <<<'PHP'
            <?php
            require $argv[1] . '/src/Database.php';
            $db = new Database(['driver' => 'sqlite', 'sqlite' => ['path' => $argv[2]]], $argv[1]);
            $index = $db->allocateModificationIndex((int) $argv[3]);
            file_put_contents($argv[4], $index . "\n", FILE_APPEND | LOCK_EX);
            PHP);
        register_shutdown_function(static function () use ($childScriptPath) {
            @unlink($childScriptPath);
        });

        $handles = [];
        $devNull = sys_get_temp_dir() . '/ft_mod_index_concurrency_out_' . bin2hex(random_bytes(4)) . '.log';
        for ($i = 0; $i < $processCount; $i++) {
            $handles[] = proc_open(
                [PHP_BINARY, $childScriptPath, $projectRoot, $dbPath, (string) $originalId, $resultFile],
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

        $indices = array_map('intval', array_filter(explode("\n", trim((string) @file_get_contents($resultFile)))));

        $this->assertCount($processCount, $indices);
        $this->assertCount($processCount, array_unique($indices), 'Két konkurrens hívás SOSE kaphatja ugyanazt a modificationIndex-et.');
        sort($indices);
        $this->assertSame(range(1, $processCount), $indices);
    }
}
