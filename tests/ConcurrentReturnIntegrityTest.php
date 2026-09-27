<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/Support/SqlCapturePdo.php';

/**
 * Phase 5 remediáció — DB-02: két (vagy több) párhuzamos visszáru összesen
 * sem vehet vissza többet egy eladási sorból, mint amennyi eladásra került.
 * A védelem adatbázis-szintű: `sale_items.returned_qty` feltételes UPDATE-tel
 * (`returned_qty + k <= qty`) foglalva, MySQL-en az eladás sorainak
 * `SELECT … FOR UPDATE` zárolásával. A többfolyamatos teszt valódi SQLite-on
 * fut; a MySQL-ág SQL-rögzítéssel ellenőrzött (élő MySQL nincs).
 */
final class ConcurrentReturnIntegrityTest extends TestCase
{
    /** @return array{0:int,1:int,2:int} [saleId, saleItemId, productId] */
    private function seedSale(Database $db, int $qty = 5, ?int $customerId = null, int $pointsEarned = 0): array
    {
        $pid = $db->saveProduct(['name' => 'DB02 termék ' . bin2hex(random_bytes(3)), 'barcode' => 'DB02-' . bin2hex(random_bytes(4)), 'price' => 100, 'net_price' => 78.74, 'vat_rate' => '27']);
        $db->setStock($pid, 100);
        $db->beginTransaction();
        $saleId = $db->insertSale($qty * 100.0, 'Készpénz', null, $customerId, $pointsEarned);
        $db->insertSaleItem($saleId, ['product_id' => $pid, 'name' => 'DB02', 'qty' => $qty, 'unit_price' => 100.0, 'vat_rate' => '27']);
        $db->decrementStock($pid, $qty);
        if ($customerId !== null && $pointsEarned > 0) {
            $db->applyLoyaltyPoints($customerId, $pointsEarned, $saleId, 'jóváírás');
        }
        $db->commit();
        $sale = $db->getSaleWithItems($saleId);
        return [$saleId, (int) $sale['items'][0]['id'], $pid];
    }

    private function returnItems(Database $db, int $saleId, int $qty): array
    {
        $sale = $db->getSaleWithItems($saleId);
        $it = $sale['items'][0];
        return [[['sale_item_id' => (int) $it['id'], 'product_id' => $it['product_id'], 'name' => $it['name'], 'qty' => $qty, 'unit_price' => (float) $it['unit_price']]], $sale];
    }

    public function testNormalPartialAndFullReturnsStillWork(): void
    {
        $db = tests_new_database();
        [$saleId, $lineId, $pid] = $this->seedSale($db, 5);
        [$items, $sale] = $this->returnItems($db, $saleId, 2);
        $db->processReturn($saleId, $items, 'részleges', null, 0.0, $sale);
        [$items, $sale] = $this->returnItems($db, $saleId, 3);
        $db->processReturn($saleId, $items, 'maradék', null, 0.0, $sale);

        $this->assertSame([$lineId => 5], $db->getReturnedQuantitiesForSale($saleId));
        $this->assertSame(5, (int) $db->pdo()->query("SELECT returned_qty FROM sale_items WHERE id = $lineId")->fetchColumn());
        $this->assertSame(100, (int) $db->findProductById($pid)['stock_qty']);
        $this->assertEqualsWithDelta(500.0, (float) $db->pdo()->query("SELECT SUM(total_refund) FROM returns WHERE sale_id = $saleId")->fetchColumn(), 0.001);
    }

    public function testSequentialOverReturnIsRejectedAndLeavesNoTrace(): void
    {
        $db = tests_new_database();
        [$saleId, $lineId, $pid] = $this->seedSale($db, 5);
        [$items, $sale] = $this->returnItems($db, $saleId, 4);
        $db->processReturn($saleId, $items, 'első', null, 0.0, $sale, null, 'key-1');
        [$items, $sale] = $this->returnItems($db, $saleId, 2);

        try {
            $db->processReturn($saleId, $items, 'túl sok', null, 0.0, $sale, null, 'key-2');
            $this->fail('A 4 + 2 > 5 visszavételt el kell utasítani.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('1 db vihető vissza', $e->getMessage());
        }
        $this->assertSame(4, (int) $db->pdo()->query("SELECT returned_qty FROM sale_items WHERE id = $lineId")->fetchColumn());
        $this->assertNull($db->findReturnByIdempotencyKey('key-2'), 'az elutasított visszáru sora és kulcsa visszagördült');
        $this->assertSame(99, (int) $db->findProductById($pid)['stock_qty']);
    }

    public function testReturnLineOfAnotherSaleIsRejected(): void
    {
        $db = tests_new_database();
        [$saleA] = $this->seedSale($db, 2);
        [$saleB, $lineB] = $this->seedSale($db, 2);
        [, $sale] = $this->returnItems($db, $saleA, 1);

        $this->expectException(RuntimeException::class);
        $db->processReturn($saleA, [['sale_item_id' => $lineB, 'product_id' => null, 'name' => 'idegen', 'qty' => 1, 'unit_price' => 100.0]], 'x', null, 0.0, $sale);
    }

    /** @return list<string> a gyerekfolyamatok eredményei */
    private function runConcurrentReturns(string $dbPath, int $saleId, int $processes, int $qtyEach): array
    {
        $root = dirname(__DIR__);
        $out = $dbPath . '.results';
        $gate = $dbPath . '.gate';
        $child = $dbPath . '.child.php';
        file_put_contents($out, '');
        file_put_contents($child, <<<'PHP'
            <?php
            require $argv[1] . '/src/Database.php';
            $db = new Database(['driver' => 'sqlite', 'sqlite' => ['path' => $argv[2]]], $argv[1]);
            while (!is_file($argv[6])) { usleep(1000); }
            $sale = $db->getSaleWithItems((int) $argv[3]);
            $it = $sale['items'][0];
            try {
                $db->processReturn((int) $argv[3], [['sale_item_id' => (int) $it['id'], 'product_id' => $it['product_id'], 'name' => $it['name'], 'qty' => (int) $argv[4], 'unit_price' => (float) $it['unit_price']]], 'párhuzamos', null, 0.0, $sale, null, 'k-' . getmypid());
                $r = 'ok';
            } catch (RuntimeException $e) {
                $r = str_contains($e->getMessage(), 'vihető vissza') ? 'rejected' : 'error:' . $e->getMessage();
            } catch (PDOException $e) {
                $r = 'busy'; // P-G: SQLite busy — a kérés hibával tér vissza, adatsérülés nélkül
            }
            file_put_contents($argv[5], $r . "\n", FILE_APPEND | LOCK_EX);
            PHP);
        $handles = [];
        for ($i = 0; $i < $processes; $i++) {
            $handles[] = proc_open([PHP_BINARY, $child, $root, $dbPath, (string) $saleId, (string) $qtyEach, $out, $gate], [1 => ['file', $dbPath . '.log', 'a'], 2 => ['file', $dbPath . '.log', 'a']], $pipes);
        }
        usleep(400000);
        touch($gate);
        foreach ($handles as $h) {
            proc_close($h);
        }
        $lines = array_values(array_filter(explode("\n", trim((string) file_get_contents($out)))));
        foreach ([$out, $gate, $child, $dbPath . '.log'] as $f) {
            @unlink($f);
        }
        return $lines;
    }

    private function tempDb(?string &$path): Database
    {
        $path = sys_get_temp_dir() . '/sm_db02_' . bin2hex(random_bytes(6)) . '.sqlite';
        register_shutdown_function(static function () use ($path) {
            foreach (['', '-wal', '-shm'] as $s) { @unlink($path . $s); }
        });
        return new Database(['driver' => 'sqlite', 'sqlite' => ['path' => $path]], dirname(__DIR__));
    }

    public function testRealConcurrentReturnsOfTheSameLineNeverExceedTheSoldQuantity(): void
    {
        if (!function_exists('proc_open')) {
            $this->markTestSkipped('proc_open nem elérhető.');
        }
        $db = $this->tempDb($path);
        [$saleId, $lineId, $pid] = $this->seedSale($db, 5);

        $results = $this->runConcurrentReturns($path, $saleId, 8, 3);

        $this->assertCount(8, $results);
        $this->assertSame([], array_values(array_filter($results, fn ($r) => str_starts_with($r, 'error'))));
        $this->assertSame(1, count(array_filter($results, fn ($r) => $r === 'ok')), '3 + 3 > 5 — pontosan egy kérés lehet sikeres');
        $verify = new Database(['driver' => 'sqlite', 'sqlite' => ['path' => $path]], dirname(__DIR__));
        $this->assertSame(3, (int) $verify->pdo()->query("SELECT returned_qty FROM sale_items WHERE id = $lineId")->fetchColumn());
        $this->assertSame(3, (int) $verify->pdo()->query("SELECT SUM(qty) FROM return_items WHERE sale_item_id = $lineId")->fetchColumn());
        $this->assertSame(98, (int) $verify->findProductById($pid)['stock_qty']);
    }

    public function testRealConcurrentSingleUnitReturnsConsumeExactlyTheSoldQuantityAndReverseBenefitsOnce(): void
    {
        if (!function_exists('proc_open')) {
            $this->markTestSkipped('proc_open nem elérhető.');
        }
        $db = $this->tempDb($path);
        $cid = $db->saveCustomer(['name' => 'DB02 vevő']);
        [$saleId, $lineId, $pid] = $this->seedSale($db, 5, $cid, 20);

        $results = $this->runConcurrentReturns($path, $saleId, 10, 1);

        $ok = count(array_filter($results, fn ($r) => $r === 'ok'));
        $busy = count(array_filter($results, fn ($r) => $r === 'busy'));
        $this->assertSame([], array_values(array_filter($results, fn ($r) => str_starts_with($r, 'error'))));
        $verify = new Database(['driver' => 'sqlite', 'sqlite' => ['path' => $path]], dirname(__DIR__));
        $returned = (int) $verify->pdo()->query("SELECT returned_qty FROM sale_items WHERE id = $lineId")->fetchColumn();
        $this->assertSame($ok, $returned, 'minden sikeres kérés pontosan 1 darabot foglalt');
        $this->assertLessThanOrEqual(5, $returned);
        $this->assertSame(10, $ok + $busy + count(array_filter($results, fn ($r) => $r === 'rejected')));
        $this->assertSame(95 + $returned, (int) $verify->findProductById($pid)['stock_qty']);
        $reversals = (int) $verify->pdo()->query("SELECT COUNT(*) FROM loyalty_transactions WHERE sale_id = $saleId AND note LIKE 'Visszavonva%'")->fetchColumn();
        $this->assertSame($returned === 5 ? 1 : 0, $reversals, 'a kedvezmény-visszaforgatás legfeljebb egyszer fut');
    }

    /** MySQL-ág (SQL-rögzítés): az eladás sorai FOR UPDATE zárolással olvasódnak, a foglalás feltételes UPDATE. */
    public function testMysqlPathLocksSaleLinesAndClaimsWithConditionalUpdate(): void
    {
        $pdo = new SqlCapturePdo();
        $db = (new ReflectionClass(Database::class))->newInstanceWithoutConstructor();
        foreach (['driver' => 'mysql', 'pdo' => $pdo, 'dbConfig' => ['driver' => 'mysql']] as $k => $v) {
            $rp = new ReflectionProperty(Database::class, $k);
            $rp->setAccessible(true);
            $rp->setValue($db, $v);
        }
        $pdo->results['/FROM sale_items WHERE sale_id = \? ORDER BY id FOR UPDATE/'] = [['id' => 7, 'qty' => 5, 'returned_qty' => 4]];
        $pdo->rowCount = 0; // a feltételes UPDATE nem talál sort: 4 + 2 > 5

        try {
            $db->processReturn(3, [['sale_item_id' => 7, 'product_id' => null, 'name' => 'X', 'qty' => 2, 'unit_price' => 10.0]], 'r', null, 0.0, ['items' => []]);
            $this->fail('A túl nagy visszavételt el kell utasítani.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('1 db vihető vissza', $e->getMessage());
        }
        $sql = $pdo->statements();
        $lock = array_search('SELECT id, qty, returned_qty FROM sale_items WHERE sale_id = ? ORDER BY id FOR UPDATE', $sql, true);
        $claim = array_search('UPDATE sale_items SET returned_qty = returned_qty + :k WHERE id = :id AND sale_id = :sale AND returned_qty + :k2 <= qty', $sql, true);
        $this->assertNotFalse($lock);
        $this->assertNotFalse($claim);
        $this->assertLessThan($claim, $lock);
        $this->assertSame('ROLLBACK', end($sql));
        $this->assertNotContains('SELECT ri.sale_item_id, SUM(ri.qty) AS returned_qty FROM return_items ri JOIN returns r ON r.id = ri.return_id WHERE r.sale_id = ? GROUP BY ri.sale_item_id', $sql, 'a döntés nem egy nem-zároló pillanatkép-olvasáson múlik');
    }

    public function testV37MigrationBackfillsReturnedQuantityFromExistingReturns(): void
    {
        $db = $this->tempDb($path);
        [$saleId, $lineId] = $this->seedSale($db, 5);
        [$items, $sale] = $this->returnItems($db, $saleId, 3);
        $db->processReturn($saleId, $items, 'régi', null, 0.0, $sale);
        $db->pdo()->exec('UPDATE sale_items SET returned_qty = 0');
        $db->pdo()->exec('UPDATE schema_version SET version = 36');
        unset($db);

        $reopened = new Database(['driver' => 'sqlite', 'sqlite' => ['path' => $path]], dirname(__DIR__));

        $this->assertSame(3, (int) $reopened->pdo()->query("SELECT returned_qty FROM sale_items WHERE id = $lineId")->fetchColumn());
    }
}
