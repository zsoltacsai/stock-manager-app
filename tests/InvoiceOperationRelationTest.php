<?php

declare(strict_types=1);

require_once __DIR__ . '/../src/Database.php';

use PHPUnit\Framework\TestCase;

/**
 * Database::createInvoiceOperation() — az invoice_type/original_invoice_id/
 * operation_key kapcsolat- és validációs szabályai. Lásd
 * migrateV22InvoiceOperationsBody() docblockja a teljes tervezési
 * indoklásért.
 */
final class InvoiceOperationRelationTest extends TestCase
{
    private function createSale(Database $db): int
    {
        return $db->insertSale(1000.0, 'Készpénz', null, null, 0, 0, null, 0, 0, null, uniqid('idem_', true), null);
    }

    private function createNormalInvoice(Database $db, int $saleId): array
    {
        $row = $db->insertQueuedInvoice($saleId, 'nav', 800.0, 200.0, 1000.0, 'HUF', ['buyer' => [], 'items' => []]);
        $this->assertNotNull($row);
        return $row;
    }

    public function testNormalInvoiceHasNullOriginalReference(): void
    {
        $db = tests_new_database();
        $original = $this->createNormalInvoice($db, $this->createSale($db));
        $this->assertSame('normal', $original['invoice_type']);
        $this->assertNull($original['original_invoice_id']);
        $this->assertNull($original['modification_index']);
        $this->assertSame('create:' . $original['sale_id'] . ':nav', $original['operation_key']);
    }

    public function testModificationReferencesAValidOriginal(): void
    {
        $db = tests_new_database();
        $original = $this->createNormalInvoice($db, $this->createSale($db));

        $modify = $db->createInvoiceOperation([
            'sale_id' => $original['sale_id'], 'provider' => 'nav', 'invoice_type' => 'modification',
            'original_invoice_id' => (int) $original['id'], 'operation_key' => 'modify:' . $original['id'] . ':' . uniqid(),
            'net_total' => 800.0, 'vat_total' => 200.0, 'gross_total' => 1000.0, 'currency' => 'HUF',
        ]);

        $this->assertSame('modification', $modify['invoice_type']);
        $this->assertSame((int) $original['id'], (int) $modify['original_invoice_id']);
        $this->assertSame(1, $modify['modification_index']);
        $this->assertSame('queued', $modify['status']);
        $this->assertStringStartsWith('FT-NAV-', $modify['invoice_number']);
        $this->assertNotSame($original['invoice_number'], $modify['invoice_number'], 'A módosító számla SAJÁT, az eredetitől eltérő számlaszámot kap.');
    }

    public function testStornoReferencesAValidOriginal(): void
    {
        $db = tests_new_database();
        $original = $this->createNormalInvoice($db, $this->createSale($db));

        $storno = $db->createInvoiceOperation([
            'sale_id' => $original['sale_id'], 'provider' => 'nav', 'invoice_type' => 'storno',
            'original_invoice_id' => (int) $original['id'], 'operation_key' => 'storno:' . $original['id'],
        ]);

        $this->assertSame('storno', $storno['invoice_type']);
        $this->assertSame((int) $original['id'], (int) $storno['original_invoice_id']);
    }

    public function testOriginalInvoiceRowIsNeverOverwrittenByAnOperation(): void
    {
        $db = tests_new_database();
        $original = $this->createNormalInvoice($db, $this->createSale($db));
        $originalNumberBefore = $original['invoice_number'];

        $db->createInvoiceOperation([
            'sale_id' => $original['sale_id'], 'provider' => 'nav', 'invoice_type' => 'modification',
            'original_invoice_id' => (int) $original['id'], 'operation_key' => 'modify:' . $original['id'] . ':' . uniqid(),
        ]);
        $db->createInvoiceOperation([
            'sale_id' => $original['sale_id'], 'provider' => 'nav', 'invoice_type' => 'storno',
            'original_invoice_id' => (int) $original['id'], 'operation_key' => 'storno:' . $original['id'],
        ]);

        $rereadOriginal = $db->getInvoiceById((int) $original['id']);
        $this->assertSame('normal', $rereadOriginal['invoice_type']);
        $this->assertSame($originalNumberBefore, $rereadOriginal['invoice_number']);
        $this->assertNull($rereadOriginal['original_invoice_id']);

        // Az eredeti + a két művelet mind KÜLÖN sorként léteznek.
        $all = $db->pdo()->query('SELECT id, invoice_type FROM invoices WHERE sale_id = ' . (int) $original['sale_id'])->fetchAll(PDO::FETCH_ASSOC);
        $this->assertCount(3, $all);
    }

    public function testRejectsInvoiceTypeOtherThanModificationOrStorno(): void
    {
        $db = tests_new_database();
        $original = $this->createNormalInvoice($db, $this->createSale($db));

        $this->expectException(InvalidArgumentException::class);
        $db->createInvoiceOperation([
            'sale_id' => $original['sale_id'], 'provider' => 'nav', 'invoice_type' => 'normal',
            'original_invoice_id' => (int) $original['id'], 'operation_key' => 'bogus:' . $original['id'],
        ]);
    }

    public function testMissingOriginalInvoiceIsBlocked(): void
    {
        $db = tests_new_database();
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/nem létezik/i');
        $db->createInvoiceOperation([
            'sale_id' => 1, 'provider' => 'nav', 'invoice_type' => 'storno',
            'original_invoice_id' => 999999, 'operation_key' => 'storno:999999',
        ]);
    }

    /**
     * "cross-invalid relation blocked" — egy MODOSÍTÁS/SZTORNÓ sorra,
     * mint "eredeti"-re hivatkozás elutasítva (a lánc mindig a gyökér
     * eredetihez kötődik, lásd a kör 9. pontjának ábrája).
     */
    public function testReferencingAnotherModificationAsOriginalIsBlocked(): void
    {
        $db = tests_new_database();
        $original = $this->createNormalInvoice($db, $this->createSale($db));
        $modify = $db->createInvoiceOperation([
            'sale_id' => $original['sale_id'], 'provider' => 'nav', 'invoice_type' => 'modification',
            'original_invoice_id' => (int) $original['id'], 'operation_key' => 'modify:' . $original['id'] . ':' . uniqid(),
        ]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/kizárólag EREDETI/i');
        $db->createInvoiceOperation([
            'sale_id' => $original['sale_id'], 'provider' => 'nav', 'invoice_type' => 'storno',
            'original_invoice_id' => (int) $modify['id'], 'operation_key' => 'storno:' . $modify['id'],
        ]);
    }

    public function testDuplicateOperationKeyIsRejectedNotDuplicated(): void
    {
        $db = tests_new_database();
        $original = $this->createNormalInvoice($db, $this->createSale($db));
        $key = 'storno:' . $original['id'];

        $first = $db->createInvoiceOperation([
            'sale_id' => $original['sale_id'], 'provider' => 'nav', 'invoice_type' => 'storno',
            'original_invoice_id' => (int) $original['id'], 'operation_key' => $key,
        ]);
        $this->assertSame('storno', $first['invoice_type']);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/már létrehozott/i');
        $db->createInvoiceOperation([
            'sale_id' => $original['sale_id'], 'provider' => 'nav', 'invoice_type' => 'storno',
            'original_invoice_id' => (int) $original['id'], 'operation_key' => $key,
        ]);

        $count = (int) $db->pdo()->query("SELECT COUNT(*) FROM invoices WHERE original_invoice_id = {$original['id']} AND invoice_type = 'storno'")->fetchColumn();
        $this->assertSame(1, $count, 'A duplikált operation_key-es kísérlet NEM hozhat létre második sztornó-sort.');
    }

    /**
     * Ugyanaz, mint a fenti, de valódi konkurrenciával — 16 folyamat
     * próbál egyidejűleg sztornózni UGYANARRA az eredeti számlára,
     * UGYANAZZAL a (determinisztikus) operation_key-vel. Ez a kör 23.
     * pontjának ("16 egyidejű STORNO... pontosan 1 sikeres") közvetlen
     * bizonyítéka az adatmodell szintjén (a tényleges NAV/Számlázz.hu
     * kérés még nincs bekötve, lásd a kör stop-feltétele).
     */
    public function testConcurrentDuplicateStornoAttemptsAcrossRealProcessesYieldExactlyOneRow(): void
    {
        if (!function_exists('proc_open')) {
            $this->markTestSkipped('proc_open nem elérhető — VALÓDI többfolyamatos konkurrencia-teszt itt nem futott le.');
        }

        $dbPath = sys_get_temp_dir() . '/ft_storno_dup_concurrency_test_' . bin2hex(random_bytes(8)) . '.sqlite';
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

        $resultFile = sys_get_temp_dir() . '/ft_storno_dup_concurrency_result_' . bin2hex(random_bytes(6)) . '.txt';
        register_shutdown_function(static function () use ($resultFile) {
            @unlink($resultFile);
        });

        $childScriptPath = sys_get_temp_dir() . '/ft_storno_dup_concurrency_child_' . bin2hex(random_bytes(6)) . '.php';
        file_put_contents($childScriptPath, <<<'PHP'
            <?php
            require $argv[1] . '/src/Database.php';
            $db = new Database(['driver' => 'sqlite', 'sqlite' => ['path' => $argv[2]]], $argv[1]);
            $originalId = (int) $argv[3];
            try {
                $row = $db->createInvoiceOperation([
                    'sale_id' => 1, 'provider' => 'nav', 'invoice_type' => 'storno',
                    'original_invoice_id' => $originalId, 'operation_key' => 'storno:' . $originalId,
                ]);
                file_put_contents($argv[4], "created\n", FILE_APPEND | LOCK_EX);
            } catch (Throwable $e) {
                file_put_contents($argv[4], "rejected\n", FILE_APPEND | LOCK_EX);
            }
            PHP);
        register_shutdown_function(static function () use ($childScriptPath) {
            @unlink($childScriptPath);
        });

        $handles = [];
        $devNull = sys_get_temp_dir() . '/ft_storno_dup_concurrency_out_' . bin2hex(random_bytes(4)) . '.log';
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

        $lines = array_filter(explode("\n", trim((string) @file_get_contents($resultFile))));
        $created = count(array_filter($lines, fn ($l) => $l === 'created'));
        $rejected = count(array_filter($lines, fn ($l) => $l === 'rejected'));

        $this->assertCount($processCount, $lines);
        $this->assertSame(1, $created, '16 konkurrens, UGYANARRA az eredeti számlára irányuló sztornó-kísérlet közül pontosan 1-nek szabad sikeresen létrehoznia a sort.');
        $this->assertSame($processCount - 1, $rejected);

        $checkDb = new Database(['driver' => 'sqlite', 'sqlite' => ['path' => $dbPath]], $projectRoot);
        $count = (int) $checkDb->pdo()->query("SELECT COUNT(*) FROM invoices WHERE original_invoice_id = $originalId AND invoice_type = 'storno'")->fetchColumn();
        $this->assertSame(1, $count);
    }

    public function testRequiredIndexesExist(): void
    {
        $db = tests_new_database();
        $indexNames = array_column($db->pdo()->query("PRAGMA index_list(invoices)")->fetchAll(PDO::FETCH_ASSOC), 'name');
        $indexedColumns = [];
        foreach ($indexNames as $indexName) {
            foreach ($db->pdo()->query("PRAGMA index_info($indexName)")->fetchAll(PDO::FETCH_ASSOC) as $col) {
                $indexedColumns[] = $col['name'];
            }
        }

        foreach (['original_invoice_id', 'invoice_type', 'operation_key', 'sale_id'] as $expected) {
            $this->assertContains($expected, $indexedColumns, "Hiányzó index a(z) $expected oszlopon.");
        }

        // invoice_modification_sequences.original_invoice_id PRIMARY KEY —
        // SQLite-on ez a rowid-alias PK-k esetén NEM jelenik meg külön
        // bejegyzésként a PRAGMA index_list()-ben (nincs is rá szükség,
        // maga a rowid ad indexet), ezért itt VISELKEDÉS-alapján
        // bizonyítjuk az egyediséget, nem a PRAGMA-listázással.
        $original = $this->createNormalInvoice($db, $this->createSale($db));
        $db->pdo()->exec("INSERT INTO invoice_modification_sequences (original_invoice_id, last_allocated_index) VALUES ({$original['id']}, 1)");
        $duplicateRejected = false;
        try {
            $db->pdo()->exec("INSERT INTO invoice_modification_sequences (original_invoice_id, last_allocated_index) VALUES ({$original['id']}, 1)");
        } catch (PDOException $e) {
            $duplicateRejected = true;
        }
        $this->assertTrue($duplicateRejected, 'Az invoice_modification_sequences.original_invoice_id-nek egyedinek kell lennie.');
    }
}
