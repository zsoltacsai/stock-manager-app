<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Phase 5 remediáció — DB-09: a hűségpont-egyenleg (customers.loyalty_points)
 * az irányadó, 0 alá nem mehet; a főkönyvbe (loyalty_transactions) a
 * TÉNYLEGESEN alkalmazott változás kerül, így Σ points_delta = egyenleg.
 */
final class LoyaltyLedgerConsistencyTest extends TestCase
{
    private function ledger(Database $db, int $cid): int
    {
        return (int) $db->pdo()->query("SELECT COALESCE(SUM(points_delta), 0) FROM loyalty_transactions WHERE customer_id = $cid")->fetchColumn();
    }

    private function balance(Database $db, int $cid): int
    {
        return (int) $db->findCustomerById($cid)['loyalty_points'];
    }

    private function sale(Database $db, int $cid, int $earn, int $redeem = 0): int
    {
        $pid = $db->saveProduct(['name' => 'DB09 ' . bin2hex(random_bytes(3)), 'barcode' => 'DB09-' . bin2hex(random_bytes(4)), 'price' => 1000, 'net_price' => 787.4, 'vat_rate' => '27']);
        $db->setStock($pid, 10);
        $db->beginTransaction();
        if ($redeem > 0) {
            $this->assertTrue($db->tryClaimLoyaltyPoints($cid, $redeem));
        }
        $saleId = $db->insertSale(1000.0, 'Készpénz', null, $cid, $earn, $redeem);
        $db->insertSaleItem($saleId, ['product_id' => $pid, 'name' => 'DB09', 'qty' => 1, 'unit_price' => 1000.0, 'vat_rate' => '27']);
        $db->decrementStock($pid, 1);
        if ($redeem > 0) {
            $db->recordLoyaltyPointsRedemption($cid, $redeem, $saleId);
        }
        if ($earn > 0) {
            $db->applyLoyaltyPoints($cid, $earn, $saleId, 'jóváírás');
        }
        $db->commit();
        return $saleId;
    }

    private function fullReturn(Database $db, int $saleId): void
    {
        $sale = $db->getSaleWithItems($saleId);
        $it = $sale['items'][0];
        $db->processReturn($saleId, [['sale_item_id' => (int) $it['id'], 'product_id' => $it['product_id'], 'name' => $it['name'], 'qty' => 1, 'unit_price' => 1000.0]], 'DB09', null, 0.0, $sale);
    }

    public function testEarnSpendAndFullReversalKeepLedgerEqualToBalance(): void
    {
        $db = tests_new_database();
        $cid = $db->saveCustomer(['name' => 'DB09 vevő']);
        $s1 = $this->sale($db, $cid, 10);
        $this->assertSame([10, 10], [$this->balance($db, $cid), $this->ledger($db, $cid)]);
        $this->sale($db, $cid, 0, 10);
        $this->assertSame([0, 0], [$this->balance($db, $cid), $this->ledger($db, $cid)]);

        $this->fullReturn($db, $s1); // a visszavonás 0-ra korlátozódik

        $this->assertSame(0, $this->balance($db, $cid));
        $this->assertSame(0, $this->ledger($db, $cid), 'a főkönyv a ténylegesen alkalmazott (0) változást rögzíti');
        $note = (string) $db->pdo()->query("SELECT note FROM loyalty_transactions WHERE customer_id = $cid ORDER BY id DESC LIMIT 1")->fetchColumn();
        $this->assertStringContainsString('kért: -10', $note);
    }

    public function testPartialClampRecordsOnlyTheAppliedPart(): void
    {
        $db = tests_new_database();
        $cid = $db->saveCustomer(['name' => 'DB09 vevő 2']);
        $s1 = $this->sale($db, $cid, 10);
        $this->sale($db, $cid, 0, 6); // egyenleg 4

        $this->fullReturn($db, $s1);

        $this->assertSame(0, $this->balance($db, $cid));
        $this->assertSame(0, $this->ledger($db, $cid));
        $this->assertSame(-4, (int) $db->pdo()->query("SELECT points_delta FROM loyalty_transactions WHERE customer_id = $cid ORDER BY id DESC LIMIT 1")->fetchColumn());
    }

    public function testUnclampedReversalAndRedeemedPointsRefundStayExact(): void
    {
        $db = tests_new_database();
        $cid = $db->saveCustomer(['name' => 'DB09 vevő 3']);
        $this->sale($db, $cid, 30);
        $s2 = $this->sale($db, $cid, 5, 20); // egyenleg 30 - 20 + 5 = 15

        $this->fullReturn($db, $s2); // -5 szerzett, +20 beváltott vissza → 30

        $this->assertSame(30, $this->balance($db, $cid));
        $this->assertSame(30, $this->ledger($db, $cid));
    }

    public function testManualAdjustmentOutsideTransactionAndZeroBalance(): void
    {
        $db = tests_new_database();
        $cid = $db->saveCustomer(['name' => 'DB09 vevő 4']);
        $this->assertSame(0, $db->applyLoyaltyPoints($cid, -5, null, 'kézi levonás 0 egyenlegről'));
        $this->assertSame(7, $db->applyLoyaltyPoints($cid, 7, null, 'kézi jóváírás'));
        $this->assertFalse($db->pdo()->inTransaction());
        $this->assertSame([7, 7], [$this->balance($db, $cid), $this->ledger($db, $cid)]);
        $this->assertSame(0, $db->applyLoyaltyPoints(999999, 5, null, 'nem létező vevő'));
    }

    public function testRealConcurrentAdjustmentsKeepLedgerEqualToBalance(): void
    {
        if (!function_exists('proc_open')) {
            $this->markTestSkipped('proc_open nem elérhető.');
        }
        $path = sys_get_temp_dir() . '/sm_db09_' . bin2hex(random_bytes(6)) . '.sqlite';
        register_shutdown_function(static function () use ($path) {
            foreach (['', '-wal', '-shm', '.child.php', '.log', '.gate'] as $s) { @unlink($path . $s); }
        });
        $db = new Database(['driver' => 'sqlite', 'sqlite' => ['path' => $path]], dirname(__DIR__));
        $cid = $db->saveCustomer(['name' => 'DB09 párhuzamos']);
        $db->applyLoyaltyPoints($cid, 5, null, 'kezdő');
        file_put_contents($path . '.child.php', <<<'PHP'
            <?php
            require $argv[1] . '/src/Database.php';
            $db = new Database(['driver' => 'sqlite', 'sqlite' => ['path' => $argv[2]]], $argv[1]);
            while (!is_file($argv[5])) { usleep(1000); }
            try { $db->applyLoyaltyPoints((int) $argv[3], (int) $argv[4], null, 'párhuzamos'); } catch (Throwable $e) {}
            PHP);
        $handles = [];
        foreach ([-3, -3, -3, 4, -3, 2, -3, -3] as $delta) {
            $handles[] = proc_open([PHP_BINARY, $path . '.child.php', dirname(__DIR__), $path, (string) $cid, (string) $delta, $path . '.gate'], [1 => ['file', $path . '.log', 'a'], 2 => ['file', $path . '.log', 'a']], $pipes);
        }
        usleep(300000);
        touch($path . '.gate');
        foreach ($handles as $h) {
            proc_close($h);
        }

        $verify = new Database(['driver' => 'sqlite', 'sqlite' => ['path' => $path]], dirname(__DIR__));
        $this->assertGreaterThanOrEqual(0, $this->balance($verify, $cid));
        $this->assertSame($this->balance($verify, $cid), $this->ledger($verify, $cid));
    }
}
