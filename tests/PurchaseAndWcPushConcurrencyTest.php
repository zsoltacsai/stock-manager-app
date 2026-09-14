<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * 1.1.1 — valódi, különálló OS-folyamatokkal bizonyított konkurrencia-
 * védelem a PONTOSAN a tests/InvoiceOperationConcurrencyTest.php mintáját
 * követve (lásd ott a teljes indoklást): egy KÜLÖN gyerek-PHP-szkript,
 * `proc_open()`-nel 16-szor egyszerre elindítva, UGYANAZON a megosztott
 * SQLite fájlon dolgozva — nem mockolt mutex, hanem a TÉNYLEGES DB-szintű
 * UNIQUE index / feltételes UPDATE bizonyítja a race-safety-t.
 *
 *   1) 16 egyidejű, UGYANAZZAL az idempotencia-kulccsal induló
 *      purchase-save.php-ekvivalens (recordPurchase()) kísérlet →
 *      pontosan 1 valódi beszerzés, pontosan 1x-es készletnövekedés.
 *   2) 16 egyidejű claimQueuedWcPush() ugyanarra az EGYETLEN beütemezett
 *      WooCommerce push-sorra → pontosan 1 sikeres claim.
 */
final class PurchaseAndWcPushConcurrencyTest extends TestCase
{
    /**
     * @return array{0: string dbPath, 1: string resultFile, 2: string childScriptPath, 3: string projectRoot}
     */
    private function prepareRun(string $childScript, callable $setup): array
    {
        $dbPath = sys_get_temp_dir() . '/ft_purchase_wc_concurrency_' . bin2hex(random_bytes(8)) . '.sqlite';
        register_shutdown_function(static function () use ($dbPath) {
            @unlink($dbPath);
            @unlink($dbPath . '-shm');
            @unlink($dbPath . '-wal');
        });

        $projectRoot = dirname(__DIR__);
        $setupDb = new Database(['driver' => 'sqlite', 'sqlite' => ['path' => $dbPath]], $projectRoot);
        $setup($setupDb);
        unset($setupDb);

        $resultFile = sys_get_temp_dir() . '/ft_purchase_wc_concurrency_result_' . bin2hex(random_bytes(6)) . '.txt';
        register_shutdown_function(static function () use ($resultFile) {
            @unlink($resultFile);
        });

        $childScriptPath = sys_get_temp_dir() . '/ft_purchase_wc_concurrency_child_' . bin2hex(random_bytes(6)) . '.php';
        file_put_contents($childScriptPath, $childScript);
        register_shutdown_function(static function () use ($childScriptPath) {
            @unlink($childScriptPath);
        });

        return [$dbPath, $resultFile, $childScriptPath, $projectRoot];
    }

    private function runProcesses(string $childScriptPath, string $projectRoot, string $dbPath, string $resultFile, array $extraArgs = [], int $processCount = 16): array
    {
        $handles = [];
        $devNull = sys_get_temp_dir() . '/ft_purchase_wc_concurrency_out_' . bin2hex(random_bytes(4)) . '.log';
        for ($i = 0; $i < $processCount; $i++) {
            $handles[] = proc_open(
                array_merge([PHP_BINARY, $childScriptPath, $projectRoot, $dbPath, $resultFile], $extraArgs),
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

    public function testConcurrentDuplicatePurchaseAttemptsYieldExactlyOnePurchaseAndOneStockIncrease(): void
    {
        $productId = null;
        [$dbPath, $resultFile, $childScriptPath, $projectRoot] = $this->prepareRun(
            <<<'PHP'
                <?php
                require $argv[1] . '/src/Database.php';

                $db = new Database(['driver' => 'sqlite', 'sqlite' => ['path' => $argv[2]]], $argv[1]);
                $productId = (int) $argv[4];

                $items = [[
                    'product_id' => $productId, 'wc_product_id' => null, 'name' => 'Konkurrencia teszt termék',
                    'qty' => 5, 'vat_rate' => '27', 'unit_cost_net' => 1000.0, 'unit_cost_gross' => 1270.0,
                ]];

                try {
                    // UGYANAZ a rögzített idempotencia-kulcs MINDEN gyerekfolyamatban
                    // — ez a "16 böngésző-fül/hálózati-retry ugyanazt a beszerzés-
                    // rögzítést próbálja beküldeni" forgatókönyv.
                    $result = $db->recordPurchase(['discount_percent' => 0], $items, 'shared-purchase-idem-key');
                    file_put_contents($argv[3], json_encode(['success' => true, 'purchase_id' => $result['purchase_id']]) . "\n", FILE_APPEND | LOCK_EX);
                } catch (PDOException $e) {
                    $isDuplicate = str_contains($e->getMessage(), 'idempotency_key');
                    file_put_contents($argv[3], json_encode(['success' => false, 'duplicate' => $isDuplicate]) . "\n", FILE_APPEND | LOCK_EX);
                }
                PHP,
            function (Database $setupDb) use (&$productId) {
                $productId = $setupDb->saveProduct([
                    'name' => 'Konkurrencia teszt termék', 'unit' => 'db', 'vat_rate' => '27',
                    'net_price' => 1000, 'price' => 1270, 'stock_qty' => 0,
                ]);
            }
        );

        $results = $this->runProcesses($childScriptPath, $projectRoot, $dbPath, $resultFile, [(string) $productId]);

        $this->assertCount(16, $results);
        $succeeded = array_filter($results, static fn (array $r) => $r['success'] === true);
        $duplicates = array_filter($results, static fn (array $r) => ($r['duplicate'] ?? false) === true);

        $this->assertCount(1, $succeeded, 'Pontosan EGY konkurrens beszerzés-kísérletnek szabad ténylegesen rekordot létrehoznia.');
        $this->assertCount(15, $duplicates, 'A többi 15-nek az idempotencia-kulcs UNIQUE-ütközésével kell elbuknia, NEM egy külön, sikeres beszerzésként.');

        // A készletnek PONTOSAN egyszer (5 db-bal) kellett nőnie, nem 16-szor.
        $verifyDb = new Database(['driver' => 'sqlite', 'sqlite' => ['path' => $dbPath]], $projectRoot);
        $product = $verifyDb->findProductById($productId);
        $this->assertSame(5, (int) $product['stock_qty'], 'A készletnek pontosan 1x (5 db) kellett nőnie, nem 16x — nincs duplán megnövelt készlet.');

        $purchaseCount = (int) $verifyDb->pdo()->query('SELECT COUNT(*) FROM purchases')->fetchColumn();
        $this->assertSame(1, $purchaseCount, 'Pontosan 1 purchases-sor jöhetett létre.');
    }

    public function testConcurrentClaimQueuedWcPushYieldsExactlyOneWinner(): void
    {
        $productId = null;
        [$dbPath, $resultFile, $childScriptPath, $projectRoot] = $this->prepareRun(
            <<<'PHP'
                <?php
                require $argv[1] . '/src/Database.php';

                $db = new Database(['driver' => 'sqlite', 'sqlite' => ['path' => $argv[2]]], $argv[1]);
                $row = $db->claimQueuedWcPush(600);
                file_put_contents($argv[3], json_encode(['claimed' => $row !== null]) . "\n", FILE_APPEND | LOCK_EX);
                PHP,
            function (Database $setupDb) use (&$productId) {
                $productId = $setupDb->saveProduct([
                    'name' => 'WC push konkurrencia termék', 'unit' => 'db', 'vat_rate' => '27',
                    'net_price' => 1000, 'price' => 1270, 'stock_qty' => 10,
                ]);
                // EGYETLEN beütemezett push-sor — 16 folyamat versenyez ÉRTE.
                $setupDb->enqueueWcPush($productId, 999, 'sale', 1);
            }
        );

        $results = $this->runProcesses($childScriptPath, $projectRoot, $dbPath, $resultFile);

        $this->assertCount(16, $results);
        $winners = array_filter($results, static fn (array $r) => $r['claimed'] === true);
        $this->assertCount(1, $winners, 'Pontosan EGY folyamatnak szabad ténylegesen claim-elnie ugyanazt a push-sort — a WooCommerce-push sose futhat kétszer párhuzamosan.');

        $verifyDb = new Database(['driver' => 'sqlite', 'sqlite' => ['path' => $dbPath]], $projectRoot);
        $processingCount = (int) $verifyDb->pdo()->query("SELECT COUNT(*) FROM wc_push_queue WHERE status = 'processing'")->fetchColumn();
        $this->assertSame(1, $processingCount, 'Pontosan 1 sor kerülhetett processing állapotba.');
    }
}
