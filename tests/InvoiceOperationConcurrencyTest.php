<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * A kör 24. pontja: legalább 16 VALÓDI, különálló OS-folyamat egyidejű
 * MODIFY, illetve STORNO kísérlete UGYANARRA az eredeti számlára,
 * UGYANAZZAL az operation_uuid-vel (MODIFY) / determinisztikus kulccsal
 * (STORNO) — ez a "dupla kattintás/böngésző-retry/worker-retry" forgatókönyvet
 * szimulálja, NEM a distinkt-modificationIndex forgatókönyvet (azt lásd
 * InvoiceModificationSequenceTest::testConcurrentAllocationIsAtomicAcrossRealProcesses(),
 * ahol MINDEN folyamat KÜLÖN uuid-t használ, és mind sikeresen kap egyedi
 * indexet). Itt a Database::createInvoiceOperation() UNIQUE(operation_key)
 * indexén át a TELJES NavInvoiceProvider::requestModification()/
 * requestStorno() hívási láncot bizonyítjuk race-safe-nek — nem csak a
 * puszta DB-hívást (azt lásd tests/InvoiceOperationRelationTest.php, a
 * séma/numbering körből).
 */
final class InvoiceOperationConcurrencyTest extends TestCase
{
    private function runConcurrentAttempts(string $childScript, int $processCount = 16): array
    {
        $dbPath = sys_get_temp_dir() . '/ft_op_concurrency_test_' . bin2hex(random_bytes(8)) . '.sqlite';
        register_shutdown_function(static function () use ($dbPath) {
            @unlink($dbPath);
            @unlink($dbPath . '-shm');
            @unlink($dbPath . '-wal');
        });

        $projectRoot = dirname(__DIR__);

        $setupDb = new Database(['driver' => 'sqlite', 'sqlite' => ['path' => $dbPath]], $projectRoot);
        $saleId = $setupDb->insertSale(1270.0, 'Készpénz', null, null, 0, 0, null, 0, 0, null, uniqid('idem_', true), null);
        $original = $setupDb->insertQueuedInvoice($saleId, 'nav', 1000.0, 270.0, 1270.0, 'HUF', [
            'buyer' => ['nev' => 'Teszt Vevő', 'irsz' => '1111', 'telepules' => 'Budapest', 'cim' => 'Fő utca 1.', 'adoszam' => null],
            'items' => [['name' => 'Termék', 'qty' => 1, 'unit_price_gross' => 1270.0, 'vat_rate' => '27']],
            'payment_method' => 'Készpénz',
            'supplier' => ['tax_number' => '12345678', 'name' => 'Teszt Kft', 'zip' => '6720', 'city' => 'Szeged', 'address' => 'Fő utca 1.'],
        ]);
        $originalId = (int) $original['id'];
        unset($setupDb);

        $resultFile = sys_get_temp_dir() . '/ft_op_concurrency_result_' . bin2hex(random_bytes(6)) . '.txt';
        register_shutdown_function(static function () use ($resultFile) {
            @unlink($resultFile);
        });

        $childScriptPath = sys_get_temp_dir() . '/ft_op_concurrency_child_' . bin2hex(random_bytes(6)) . '.php';
        file_put_contents($childScriptPath, $childScript);
        register_shutdown_function(static function () use ($childScriptPath) {
            @unlink($childScriptPath);
        });

        $handles = [];
        $devNull = sys_get_temp_dir() . '/ft_op_concurrency_out_' . bin2hex(random_bytes(4)) . '.log';
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
        return array_map(static fn (string $l) => json_decode($l, true), $lines);
    }

    public function testConcurrentDuplicateModifyAttemptsWithSameOperationUuidYieldExactlyOneOperation(): void
    {
        $childScript = <<<'PHP'
            <?php
            require $argv[1] . '/src/Database.php';
            require $argv[1] . '/src/NavInvoiceProvider.php';

            $db = new Database(['driver' => 'sqlite', 'sqlite' => ['path' => $argv[2]]], $argv[1]);
            $originalId = (int) $argv[3];
            $original = $db->getInvoiceById($originalId);

            $navConfig = ['nav_login' => 'x', 'nav_password' => 'x', 'nav_signer_key' => 'x', 'nav_exchange_key' => 'ABCDEFGHIJKLMNOP', 'nav_tax_number' => '12345678', 'nav_test_mode' => true];
            $supplierConfig = ['tax_number' => '12345678', 'name' => 'Teszt Kft', 'zip' => '6720', 'city' => 'Szeged', 'address' => 'Fő utca 1.'];
            $provider = new NavInvoiceProvider($navConfig, $supplierConfig, new NavTokenCache(sys_get_temp_dir() . '/ft_op_concurrency_token_' . getmypid() . '.json'));

            $context = [
                'buyer' => ['nev' => 'Teszt Vevő', 'irsz' => '1111', 'telepules' => 'Budapest', 'cim' => 'Fő utca 1.', 'adoszam' => null],
                'items' => [['name' => 'Javított tétel', 'qty' => 1, 'unit_price_gross' => 1270.0, 'vat_rate' => '27']],
                'payment_method' => 'Készpénz',
                'totals' => ['net' => 1000.0, 'vat' => 270.0, 'gross' => 1270.0, 'currency' => 'HUF'],
                // UGYANAZ az operation_uuid MINDEN gyerekfolyamatban — ez a
                // "16 különböző folyamat próbálja UGYANAZT a felhasználói
                // kattintást újraküldeni" forgatókönyv.
                'operation_uuid' => 'shared-attempt-uuid-fixed',
            ];

            $result = $provider->requestModification($db, $original, $context);
            file_put_contents($argv[4], json_encode(['pending' => $result['pending'], 'already_in_progress' => $result['already_in_progress'] ?? false]) . "\n", FILE_APPEND | LOCK_EX);
            PHP;

        $results = $this->runConcurrentAttempts($childScript);

        $this->assertCount(16, $results);
        $succeeded = array_filter($results, static fn (array $r) => $r['pending'] === true);
        $rejected = array_filter($results, static fn (array $r) => $r['already_in_progress'] === true);

        $this->assertCount(1, $succeeded, 'Pontosan EGY konkurrens MODIFY-kísérletnek szabad ténylegesen queue-bejegyzést létrehoznia.');
        $this->assertCount(15, $rejected, 'A többi 15-nek graceful already_in_progress eredménnyel kell visszatérnie, NEM kivétellel/duplikátummal.');
    }

    public function testConcurrentDuplicateStornoAttemptsYieldExactlyOneOperation(): void
    {
        $childScript = <<<'PHP'
            <?php
            require $argv[1] . '/src/Database.php';
            require $argv[1] . '/src/NavInvoiceProvider.php';

            $db = new Database(['driver' => 'sqlite', 'sqlite' => ['path' => $argv[2]]], $argv[1]);
            $originalId = (int) $argv[3];
            $original = $db->getInvoiceById($originalId);

            $navConfig = ['nav_login' => 'x', 'nav_password' => 'x', 'nav_signer_key' => 'x', 'nav_exchange_key' => 'ABCDEFGHIJKLMNOP', 'nav_tax_number' => '12345678', 'nav_test_mode' => true];
            $supplierConfig = ['tax_number' => '12345678', 'name' => 'Teszt Kft', 'zip' => '6720', 'city' => 'Szeged', 'address' => 'Fő utca 1.'];
            $provider = new NavInvoiceProvider($navConfig, $supplierConfig, new NavTokenCache(sys_get_temp_dir() . '/ft_op_concurrency_token_st_' . getmypid() . '.json'));

            $context = [
                'buyer' => ['nev' => 'Teszt Vevő', 'irsz' => '1111', 'telepules' => 'Budapest', 'cim' => 'Fő utca 1.', 'adoszam' => null],
                'items' => [['name' => 'Termék', 'qty' => -1, 'unit_price_gross' => 1270.0, 'vat_rate' => '27']],
                'payment_method' => 'Készpénz',
                'totals' => ['net' => -1000.0, 'vat' => -270.0, 'gross' => -1270.0, 'currency' => 'HUF'],
            ];

            // STORNO-nál nincs uuid — az operation_key determinisztikus
            // ('storno:{id}'), ez ÖNMAGÁBAN adja a strukturális egyediséget.
            $result = $provider->requestStorno($db, $original, $context);
            file_put_contents($argv[4], json_encode(['pending' => $result['pending'], 'already_in_progress' => $result['already_in_progress'] ?? false]) . "\n", FILE_APPEND | LOCK_EX);
            PHP;

        $results = $this->runConcurrentAttempts($childScript);

        $this->assertCount(16, $results);
        $succeeded = array_filter($results, static fn (array $r) => $r['pending'] === true);
        $rejected = array_filter($results, static fn (array $r) => $r['already_in_progress'] === true);

        $this->assertCount(1, $succeeded, 'Pontosan EGY konkurrens STORNO-kísérletnek szabad ténylegesen queue-bejegyzést létrehoznia — a sztornó strukturálisan egyszeri.');
        $this->assertCount(15, $rejected);
    }
}
