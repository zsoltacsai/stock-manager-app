<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * B-03 (correctness audit) regresszió — Database::adjustLocationStock().
 *
 * A metódus MySQL-en SQLite-only szintaxist (ON CONFLICT … MAX(0, …))
 * futtatott, ami minden telephelyhez kötött kasszai eladást és minden
 * készletmozgatást elbuktatott. A javítás driver-függő, atomikus UPSERT:
 * SQLite-on a változatlan ON CONFLICT ág, MySQL-en ON DUPLICATE KEY UPDATE
 * GREATEST()-tel. Ez a fájl a (futtatható) SQLite viselkedést rögzíti — a
 * MySQL-ág generált SQL-jét a MysqlDialectRegressionTest ellenőrzi.
 */
final class LocationStockUpsertTest extends TestCase
{
    private function setUpProduct(Database $db): int
    {
        return $db->saveProduct([
            'name' => 'Telephelyi termék', 'unit' => 'db', 'vat_rate' => '27',
            'net_price' => 1000, 'price' => 1270, 'barcode' => null,
        ]);
    }

    private function adjust(Database $db, int $productId, int $locationId, int $delta): void
    {
        $method = new ReflectionMethod(Database::class, 'adjustLocationStock');
        $method->setAccessible(true);
        $method->invoke($db, $productId, $locationId, $delta);
    }

    private function locationQty(Database $db, int $productId, int $locationId): int
    {
        $rows = array_column($db->getLocationStockForProduct($productId), 'stock_qty', 'location_id');
        return (int) $rows[$locationId];
    }

    private function rowCount(Database $db, int $productId, int $locationId): int
    {
        $stmt = $db->pdo()->prepare('SELECT COUNT(*) FROM location_stock WHERE product_id = ? AND location_id = ?');
        $stmt->execute([$productId, $locationId]);
        return (int) $stmt->fetchColumn();
    }

    public function testPositiveDeltaInsertsNewRow(): void
    {
        $db = tests_new_database();
        $productId = $this->setUpProduct($db);
        $locationId = $db->saveLocation(['name' => 'Bolt']);

        $this->assertSame(0, $this->rowCount($db, $productId, $locationId));
        $this->adjust($db, $productId, $locationId, 5);

        $this->assertSame(1, $this->rowCount($db, $productId, $locationId));
        $this->assertSame(5, $this->locationQty($db, $productId, $locationId));
    }

    public function testNegativeDeltaOnMissingRowInsertsZeroNotNegative(): void
    {
        $db = tests_new_database();
        $productId = $this->setUpProduct($db);
        $locationId = $db->saveLocation(['name' => 'Bolt']);

        $this->adjust($db, $productId, $locationId, -3);

        $this->assertSame(1, $this->rowCount($db, $productId, $locationId));
        $this->assertSame(0, $this->locationQty($db, $productId, $locationId));
    }

    public function testExistingRowIsUpdatedInPlaceWithPositiveAndNegativeDeltas(): void
    {
        $db = tests_new_database();
        $productId = $this->setUpProduct($db);
        $locationId = $db->saveLocation(['name' => 'Bolt']);

        $this->adjust($db, $productId, $locationId, 10);
        $this->adjust($db, $productId, $locationId, 4);
        $this->assertSame(14, $this->locationQty($db, $productId, $locationId));
        $this->adjust($db, $productId, $locationId, -6);
        $this->assertSame(8, $this->locationQty($db, $productId, $locationId));

        $this->assertSame(1, $this->rowCount($db, $productId, $locationId), 'Az UPSERT sosem hozhat létre második sort ugyanarra a kulcsra.');
    }

    public function testNegativeDeltaClampsAtZero(): void
    {
        $db = tests_new_database();
        $productId = $this->setUpProduct($db);
        $locationId = $db->saveLocation(['name' => 'Bolt']);

        $this->adjust($db, $productId, $locationId, 2);
        $db->decrementLocationStock($productId, $locationId, 5);

        $this->assertSame(0, $this->locationQty($db, $productId, $locationId));
    }

    public function testRepeatedDecrementsAccumulateAndStayClamped(): void
    {
        $db = tests_new_database();
        $productId = $this->setUpProduct($db);
        $locationId = $db->saveLocation(['name' => 'Bolt']);
        $this->adjust($db, $productId, $locationId, 5);

        foreach ([1, 1, 2] as $qty) {
            $db->decrementLocationStock($productId, $locationId, $qty);
        }
        $this->assertSame(1, $this->locationQty($db, $productId, $locationId));

        $db->decrementLocationStock($productId, $locationId, 1);
        $db->decrementLocationStock($productId, $locationId, 1);
        $this->assertSame(0, $this->locationQty($db, $productId, $locationId));

        $this->adjust($db, $productId, $locationId, 3);
        $this->assertSame(3, $this->locationQty($db, $productId, $locationId), 'A 0-ra vágás után a növelés a 0-ról indul.');
    }

    public function testSameProductAtSeveralLocationsIsIndependent(): void
    {
        $db = tests_new_database();
        $productId = $this->setUpProduct($db);
        $otherProductId = $db->saveProduct([
            'name' => 'Másik termék', 'unit' => 'db', 'vat_rate' => '27',
            'net_price' => 100, 'price' => 127, 'barcode' => null,
        ]);
        $shop = $db->saveLocation(['name' => 'Bolt']);
        $store = $db->saveLocation(['name' => 'Raktár']);

        $this->adjust($db, $productId, $shop, 5);
        $this->adjust($db, $productId, $store, 9);
        $this->adjust($db, $otherProductId, $shop, 2);
        $db->decrementLocationStock($productId, $shop, 2);

        $this->assertSame(3, $this->locationQty($db, $productId, $shop));
        $this->assertSame(9, $this->locationQty($db, $productId, $store));
        $this->assertSame(2, $this->locationQty($db, $otherProductId, $shop));
    }

    public function testSaleTransactionRollbackUndoesLocationDecrement(): void
    {
        $db = tests_new_database();
        $productId = $this->setUpProduct($db);
        $locationId = $db->saveLocation(['name' => 'Bolt', 'is_default' => true]);
        $db->transferStock($productId, null, $locationId, 6, null);   // globális 6, bolt 6

        $db->beginTransaction();
        try {
            $saleId = $db->insertSale(1270.0, 'Készpénz');
            $db->insertSaleItem($saleId, ['product_id' => $productId, 'name' => 'Tétel', 'qty' => 2, 'unit_price' => 1270, 'vat_rate' => '27']);
            $db->decrementStock($productId, 2);
            $db->decrementLocationStock($productId, $locationId, 2);
            throw new RuntimeException('szimulált hiba a tranzakció végén');
        } catch (RuntimeException $e) {
            $db->rollBack();
        }

        $this->assertSame(6, $this->locationQty($db, $productId, $locationId));
        $this->assertSame(6, (int) $db->findProductById($productId)['stock_qty']);
    }

    public function testCommittedSaleDecrementsLocationStock(): void
    {
        $db = tests_new_database();
        $productId = $this->setUpProduct($db);
        $locationId = $db->saveLocation(['name' => 'Bolt', 'is_default' => true]);
        $db->transferStock($productId, null, $locationId, 6, null);

        $db->beginTransaction();
        $saleId = $db->insertSale(1270.0, 'Készpénz');
        $db->insertSaleItem($saleId, ['product_id' => $productId, 'name' => 'Tétel', 'qty' => 2, 'unit_price' => 1270, 'vat_rate' => '27']);
        $db->decrementStock($productId, 2);
        $db->decrementLocationStock($productId, $locationId, 2);
        $db->commit();

        $this->assertSame(4, $this->locationQty($db, $productId, $locationId));
        $this->assertSame(4, (int) $db->findProductById($productId)['stock_qty']);
    }

    public function testTransferBetweenLocationsMovesStockAndCreatesTargetRow(): void
    {
        $db = tests_new_database();
        $productId = $this->setUpProduct($db);
        $shop = $db->saveLocation(['name' => 'Bolt']);
        $store = $db->saveLocation(['name' => 'Raktár']);
        $db->transferStock($productId, null, $store, 10, null);

        $this->assertSame(0, $this->rowCount($db, $productId, $shop));
        $db->transferStock($productId, $store, $shop, 4, null);   // új cél-sor
        $db->transferStock($productId, $store, $shop, 1, null);   // meglévő cél-sor

        $this->assertSame(5, $this->locationQty($db, $productId, $store));
        $this->assertSame(5, $this->locationQty($db, $productId, $shop));
        $this->assertSame(1, $this->rowCount($db, $productId, $shop));
        $this->assertSame(10, (int) $db->findProductById($productId)['stock_qty'], 'Telephelyek közötti mozgatás a globális készletet nem változtatja.');
    }

    public function testFailedTransferRollsBackCompletely(): void
    {
        $db = tests_new_database();
        $productId = $this->setUpProduct($db);
        $shop = $db->saveLocation(['name' => 'Bolt']);
        $store = $db->saveLocation(['name' => 'Raktár']);
        $db->transferStock($productId, null, $store, 3, null);

        try {
            $db->transferStock($productId, $store, $shop, 5, null);
            $this->fail('A fedezet nélküli mozgatásnak el kell buknia.');
        } catch (RuntimeException $e) {
            // várt
        }

        $this->assertSame(3, $this->locationQty($db, $productId, $store));
        $this->assertSame(0, $this->rowCount($db, $productId, $shop));
        $count = (int) $db->pdo()->query('SELECT COUNT(*) FROM stock_transfers')->fetchColumn();
        $this->assertSame(1, $count, 'A sikertelen mozgatás nem hagyhat naplósort.');
    }
}
