<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Phase 5 remediáció — DB-04: az összesített készlet (products.stock_qty) az
 * irányadó; a telephelyi bontás (location_stock, könyvelési célú) egy
 * visszáru után sem haladhatja meg. Korábban a túladás 0-ra vágott telephelyi
 * levonása + a visszáru teljes visszaírása nem létező, elmozgatható
 * telephelyi készletet hozott létre (1 db összesen → 3 db a telephelyen).
 */
final class LocationStockInvariantTest extends TestCase
{
    private function product(Database $db): int
    {
        $id = $db->saveProduct(['name' => 'DB04 ' . bin2hex(random_bytes(3)), 'barcode' => 'DB04-' . bin2hex(random_bytes(4)), 'price' => 10, 'net_price' => 7.87, 'vat_rate' => '27']);
        $db->setStock($id, 0);
        return $id;
    }

    /** sale.php hívássora: összesített + (ha van) telephelyi levonás. */
    private function sell(Database $db, int $pid, int $qty, ?int $locationId): int
    {
        $db->beginTransaction();
        $saleId = $db->insertSale($qty * 10.0, 'Készpénz', null, null, 0, 0, null, 0.0, 0.0, null, null, null, null, $locationId);
        $db->insertSaleItem($saleId, ['product_id' => $pid, 'name' => 'DB04', 'qty' => $qty, 'unit_price' => 10.0, 'vat_rate' => '27']);
        $db->decrementStock($pid, $qty);
        if ($locationId) {
            $db->decrementLocationStock($pid, $locationId, $qty);
        }
        $db->commit();
        return $saleId;
    }

    private function returnAll(Database $db, int $saleId, ?int $qty = null): void
    {
        $sale = $db->getSaleWithItems($saleId);
        $it = $sale['items'][0];
        $db->processReturn($saleId, [['sale_item_id' => (int) $it['id'], 'product_id' => $it['product_id'], 'name' => $it['name'], 'qty' => $qty ?? (int) $it['qty'], 'unit_price' => (float) $it['unit_price']]], 'DB04', null, 0.0, $sale);
    }

    private function locQty(Database $db, int $pid, int $loc): int
    {
        foreach ($db->getLocationStockForProduct($pid) as $row) {
            if ((int) $row['location_id'] === $loc) {
                return (int) $row['stock_qty'];
            }
        }
        return 0;
    }

    private function assignedTotal(Database $db, int $pid): int
    {
        return (int) array_sum(array_column($db->getLocationStockForProduct($pid), 'stock_qty'));
    }

    public function testReturnAfterOversellDoesNotCreateLocationStockAboveTotal(): void
    {
        $db = tests_new_database();
        $pid = $this->product($db);
        $a = $db->saveLocation(['name' => 'A', 'address' => '', 'is_default' => 1]);
        $b = $db->saveLocation(['name' => 'B', 'address' => '', 'is_default' => 0]);
        $db->transferStock($pid, null, $a, 1, null);

        $saleId = $this->sell($db, $pid, 3, $a);         // túladás: összesített -2, telephely 0
        $this->returnAll($db, $saleId);

        $this->assertSame(1, (int) $db->findProductById($pid)['stock_qty']);
        $this->assertSame(1, $this->locQty($db, $pid, $a), 'a telephelyre csak a valóban létező 1 db kerül vissza');
        $this->assertLessThanOrEqual((int) $db->findProductById($pid)['stock_qty'], $this->assignedTotal($db, $pid));

        // A korábban reprodukált következmény: a fantom készlet nem mozgatható.
        try {
            $db->transferStock($pid, $a, $b, 3, null);
            $this->fail('Nem létező készlet nem mozgatható.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('nincs elég készlet', $e->getMessage());
        }
        $this->assertSame(1, $this->assignedTotal($db, $pid));
    }

    public function testNormalReturnRestoresTheLocationFullyB05Regression(): void
    {
        $db = tests_new_database();
        $pid = $this->product($db);
        $a = $db->saveLocation(['name' => 'A', 'address' => '', 'is_default' => 1]);
        $db->transferStock($pid, null, $a, 10, null);

        $saleId = $this->sell($db, $pid, 4, $a);
        $this->returnAll($db, $saleId, 3);

        $this->assertSame(9, (int) $db->findProductById($pid)['stock_qty']);
        $this->assertSame(9, $this->locQty($db, $pid, $a));
    }

    public function testUnassignedStockIsPreservedAndReturnRestoresOnlyTheAssignableShare(): void
    {
        $db = tests_new_database();
        $pid = $this->product($db);
        $a = $db->saveLocation(['name' => 'A', 'address' => '', 'is_default' => 1]);
        $db->transferStock($pid, null, $a, 4, null);
        $db->incrementStock($pid, 6); // pl. telephelyhez nem rendelt beszerzés: összesen 10, ebből A-n 4

        $saleId = $this->sell($db, $pid, 2, $a);
        $this->returnAll($db, $saleId);

        $this->assertSame(10, (int) $db->findProductById($pid)['stock_qty']);
        $this->assertSame(4, $this->locQty($db, $pid, $a));
    }

    public function testReturnOfLocationlessSaleLeavesLocationsUntouched(): void
    {
        $db = tests_new_database();
        $pid = $this->product($db);
        $a = $db->saveLocation(['name' => 'A', 'address' => '', 'is_default' => 1]);
        $db->transferStock($pid, null, $a, 5, null);

        $saleId = $this->sell($db, $pid, 2, null);
        $this->returnAll($db, $saleId);

        $this->assertSame(5, (int) $db->findProductById($pid)['stock_qty']);
        $this->assertSame(5, $this->locQty($db, $pid, $a));
    }
}
