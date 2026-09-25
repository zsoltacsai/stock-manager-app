<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * B-05 (correctness audit) regresszió — a visszáru a telephelyi készletet
 * is visszaállítja, arra a telephelyre, ahonnan az eladás történt
 * (sales.location_id, séma v34).
 *
 * A készletmodell (README "Több telephely / raktár kezelése", Telephelyek
 * oldal): products.stock_qty az ELSŐDLEGES összesített készlet; a
 * location_stock egy kiegészítő bontás. Telephelyhez kötött mozgás:
 * kasszai eladás (a kiválasztott telephelyről), készletmozgatás, és —
 * ezzel a javítással — a visszáru (az eladás telephelyére). Telephely
 * NÉLKÜLI mozgás (a modellben nincs hozzá telephely-adat): beszerzés,
 * leltár, import, webes rendelés — ezek csak az összesítettet változtatják.
 */
final class SaleLocationReturnTest extends TestCase
{
    private function product(Database $db, string $name = 'Telephelyes termék'): int
    {
        return $db->saveProduct(['name' => $name, 'unit' => 'db', 'vat_rate' => '27', 'net_price' => 1000, 'price' => 1270, 'barcode' => null]);
    }

    /** sale.php tranzakciója: fej (telephellyel), tételek, összesített + telephelyi csökkentés. */
    private function sell(Database $db, array $lines, ?int $locationId): int
    {
        $db->beginTransaction();
        try {
            $saleId = $db->insertSale(1270.0 * array_sum(array_column($lines, 'qty')), 'Készpénz', null, null, 0, 0, null, 0.0, 0.0, null, null, null, null, $locationId);
            foreach ($lines as $line) {
                $db->insertSaleItem($saleId, ['product_id' => $line['product_id'], 'name' => 'Tétel', 'qty' => $line['qty'], 'unit_price' => 1270, 'vat_rate' => '27']);
                $db->decrementStock($line['product_id'], $line['qty']);
                if ($locationId !== null) {
                    $db->decrementLocationStock($line['product_id'], $locationId, $line['qty']);
                }
            }
            $db->commit();
        } catch (Throwable $e) {
            $db->rollBack();
            throw $e;
        }
        return $saleId;
    }

    /** @param array<int,int> $qtyByProduct */
    private function returnItems(Database $db, int $saleId, array $qtyByProduct): int
    {
        $sale = $db->getSaleWithItems($saleId);
        $items = [];
        foreach ($sale['items'] as $si) {
            $pid = (int) $si['product_id'];
            if (!empty($qtyByProduct[$pid])) {
                $items[] = ['sale_item_id' => (int) $si['id'], 'product_id' => $pid, 'name' => $si['name'], 'qty' => $qtyByProduct[$pid], 'unit_price' => (float) $si['unit_price']];
            }
        }
        return $db->processReturn($saleId, $items, 'visszáru', null, 0.0, $sale);
    }

    private function total(Database $db, int $productId): int
    {
        return (int) $db->findProductById($productId)['stock_qty'];
    }

    private function at(Database $db, int $productId, int $locationId): int
    {
        return (int) array_column($db->getLocationStockForProduct($productId), 'stock_qty', 'location_id')[$locationId];
    }

    public function testMandatoryScenarioSaleThenFullReturnRestoresBothTotals(): void
    {
        $db = tests_new_database();
        $p = $this->product($db);
        $loc = $db->saveLocation(['name' => 'Bolt', 'is_default' => true]);
        $db->transferStock($p, null, $loc, 5, null);
        $this->assertSame([5, 5], [$this->total($db, $p), $this->at($db, $p, $loc)]);

        $saleId = $this->sell($db, [['product_id' => $p, 'qty' => 2]], $loc);
        $this->assertSame([3, 3], [$this->total($db, $p), $this->at($db, $p, $loc)]);

        $this->returnItems($db, $saleId, [$p => 2]);
        // Az audit reprodukciója: korábban 5 / 3.
        $this->assertSame([5, 5], [$this->total($db, $p), $this->at($db, $p, $loc)]);
    }

    public function testSaleRecordsItsLocation(): void
    {
        $db = tests_new_database();
        $p = $this->product($db);
        $loc = $db->saveLocation(['name' => 'Bolt']);
        $saleId = $this->sell($db, [['product_id' => $p, 'qty' => 1]], $loc);

        $this->assertSame($loc, (int) $db->getSaleWithItems($saleId)['location_id']);
    }

    public function testReturnGoesBackToTheOriginalLocationAcrossTwoLocations(): void
    {
        $db = tests_new_database();
        $p = $this->product($db);
        $shop = $db->saveLocation(['name' => 'Bolt']);
        $store = $db->saveLocation(['name' => 'Raktár']);
        $db->transferStock($p, null, $shop, 5, null);
        $db->transferStock($p, null, $store, 5, null);

        $atShop = $this->sell($db, [['product_id' => $p, 'qty' => 2]], $shop);
        $atStore = $this->sell($db, [['product_id' => $p, 'qty' => 3]], $store);
        $this->assertSame([5, 3, 2], [$this->total($db, $p), $this->at($db, $p, $shop), $this->at($db, $p, $store)]);

        $this->returnItems($db, $atStore, [$p => 3]);
        $this->assertSame([8, 3, 5], [$this->total($db, $p), $this->at($db, $p, $shop), $this->at($db, $p, $store)], 'A raktári eladás visszárúja a raktárba megy vissza.');

        $this->returnItems($db, $atShop, [$p => 2]);
        $this->assertSame([10, 5, 5], [$this->total($db, $p), $this->at($db, $p, $shop), $this->at($db, $p, $store)]);
    }

    public function testPartialReturnsRestoreOnlyTheReturnedQuantity(): void
    {
        $db = tests_new_database();
        $p = $this->product($db);
        $loc = $db->saveLocation(['name' => 'Bolt']);
        $db->transferStock($p, null, $loc, 10, null);
        $saleId = $this->sell($db, [['product_id' => $p, 'qty' => 4]], $loc);

        $this->returnItems($db, $saleId, [$p => 1]);
        $this->assertSame([7, 7], [$this->total($db, $p), $this->at($db, $p, $loc)]);
        $this->returnItems($db, $saleId, [$p => 3]);
        $this->assertSame([10, 10], [$this->total($db, $p), $this->at($db, $p, $loc)]);
    }

    public function testMultiProductSaleAndReturn(): void
    {
        $db = tests_new_database();
        $a = $this->product($db, 'A');
        $b = $this->product($db, 'B');
        $loc = $db->saveLocation(['name' => 'Bolt']);
        $db->transferStock($a, null, $loc, 6, null);
        $db->transferStock($b, null, $loc, 4, null);
        $saleId = $this->sell($db, [['product_id' => $a, 'qty' => 2], ['product_id' => $b, 'qty' => 3]], $loc);

        $this->returnItems($db, $saleId, [$a => 2, $b => 1]);

        $this->assertSame([6, 6], [$this->total($db, $a), $this->at($db, $a, $loc)]);
        $this->assertSame([2, 2], [$this->total($db, $b), $this->at($db, $b, $loc)]);
    }

    public function testRepeatedReturnIsRejectedAndRestoresNothingTwice(): void
    {
        $db = tests_new_database();
        $p = $this->product($db);
        $loc = $db->saveLocation(['name' => 'Bolt']);
        $db->transferStock($p, null, $loc, 5, null);
        $saleId = $this->sell($db, [['product_id' => $p, 'qty' => 2]], $loc);
        $this->returnItems($db, $saleId, [$p => 2]);

        try {
            $this->returnItems($db, $saleId, [$p => 2]);
            $this->fail('Az ismételt visszáru elutasítandó.');
        } catch (RuntimeException $e) {
            // várt
        }
        $this->assertSame([5, 5], [$this->total($db, $p), $this->at($db, $p, $loc)]);
    }

    public function testFailingReturnRollsBackLocationStockToo(): void
    {
        $db = tests_new_database();
        $p = $this->product($db);
        $loc = $db->saveLocation(['name' => 'Bolt']);
        $db->transferStock($p, null, $loc, 5, null);
        $saleId = $this->sell($db, [['product_id' => $p, 'qty' => 2]], $loc);

        // A visszáru-tételsor beszúrása UTÁN (a készlet-visszaírás előtt/közben) hiba.
        $db->pdo()->exec("CREATE TRIGGER fail_return_items AFTER INSERT ON return_items BEGIN SELECT RAISE(ABORT, 'szimulált hiba'); END");
        try {
            $this->returnItems($db, $saleId, [$p => 2]);
            $this->fail('A visszárunak el kell buknia.');
        } catch (PDOException $e) {
            $this->assertStringContainsString('szimulált hiba', $e->getMessage());
        }
        $this->assertSame([3, 3], [$this->total($db, $p), $this->at($db, $p, $loc)]);
        $this->assertSame([], $db->getReturnsForSale($saleId));
    }

    public function testTransferSaleAndReturnCombination(): void
    {
        $db = tests_new_database();
        $p = $this->product($db);
        $shop = $db->saveLocation(['name' => 'Bolt']);
        $store = $db->saveLocation(['name' => 'Raktár']);
        $db->transferStock($p, null, $store, 10, null);    // összes 10, raktár 10
        $db->transferStock($p, $store, $shop, 4, null);    // bolt 4, raktár 6
        $saleId = $this->sell($db, [['product_id' => $p, 'qty' => 3]], $shop);   // bolt 1
        $db->transferStock($p, $store, $shop, 2, null);    // bolt 3, raktár 4
        $this->returnItems($db, $saleId, [$p => 3]);       // bolt 6

        $this->assertSame([10, 6, 4], [$this->total($db, $p), $this->at($db, $p, $shop), $this->at($db, $p, $store)]);
    }

    public function testSaleWithoutLocationReturnsOnlyToTotalStock(): void
    {
        // Egy telephely nélküli (pl. telephely-választó nélküli bolt, webes
        // rendelés) vagy a V34 előtti eladásnál nincs location_id: az eladás
        // sem csökkentett telephelyi készletet, a visszáru sem talál ki
        // telephelyet.
        $db = tests_new_database();
        $p = $this->product($db);
        $loc = $db->saveLocation(['name' => 'Bolt', 'is_default' => true]);
        $db->transferStock($p, null, $loc, 5, null);
        $saleId = $this->sell($db, [['product_id' => $p, 'qty' => 2]], null);
        $this->assertNull($db->getSaleWithItems($saleId)['location_id']);
        $this->assertSame([3, 5], [$this->total($db, $p), $this->at($db, $p, $loc)]);

        $this->returnItems($db, $saleId, [$p => 2]);
        $this->assertSame([5, 5], [$this->total($db, $p), $this->at($db, $p, $loc)]);
    }

    public function testLegacySaleWithLocationStockDecrementButNoStoredLocationIsNotGuessed(): void
    {
        // V34 előtti eladás: a telephelyi készlet csökkent, de a location_id
        // nincs tárolva. A visszáru NEM találgat (pl. alapértelmezett vagy a
        // pénztárgép telephelye alapján) — a telephelyi bontás a Telephelyek
        // oldalon igazítható, ahogy a leltár utáni egyeztetésnél is.
        $db = tests_new_database();
        $p = $this->product($db);
        $loc = $db->saveLocation(['name' => 'Bolt', 'is_default' => true]);
        $db->transferStock($p, null, $loc, 5, null);
        $saleId = $this->sell($db, [['product_id' => $p, 'qty' => 2]], $loc);
        $db->pdo()->prepare('UPDATE sales SET location_id = NULL WHERE id = ?')->execute([$saleId]);

        $this->returnItems($db, $saleId, [$p => 2]);
        $this->assertSame([5, 3], [$this->total($db, $p), $this->at($db, $p, $loc)]);
    }

    public function testManualReturnLineWithoutProductTouchesNoStock(): void
    {
        $db = tests_new_database();
        $loc = $db->saveLocation(['name' => 'Bolt']);
        $db->beginTransaction();
        $saleId = $db->insertSale(500.0, 'Készpénz', null, null, 0, 0, null, 0.0, 0.0, null, null, null, null, $loc);
        $db->insertSaleItem($saleId, ['product_id' => null, 'name' => 'Szolgáltatás', 'qty' => 1, 'unit_price' => 500, 'vat_rate' => '27']);
        $db->commit();
        $sale = $db->getSaleWithItems($saleId);
        $db->processReturn($saleId, [['sale_item_id' => (int) $sale['items'][0]['id'], 'product_id' => null, 'name' => 'Szolgáltatás', 'qty' => 1, 'unit_price' => 500]], 'x', null, 500.0, $sale);

        $this->assertSame(0, (int) $db->pdo()->query('SELECT COUNT(*) FROM location_stock')->fetchColumn());
    }

    public function testReturnQueuesWooCommercePushForLinkedProduct(): void
    {
        $db = tests_new_database();
        $p = $this->product($db);
        $db->pdo()->prepare('UPDATE products SET wc_product_id = 700, sync_to_woocommerce = 1 WHERE id = ?')->execute([$p]);
        $loc = $db->saveLocation(['name' => 'Bolt']);
        $db->transferStock($p, null, $loc, 5, null);
        $saleId = $this->sell($db, [['product_id' => $p, 'qty' => 2]], $loc);
        $returnId = $this->returnItems($db, $saleId, [$p => 2]);

        $stmt = $db->pdo()->prepare("SELECT COUNT(*) FROM wc_push_queue WHERE trigger_type = 'return' AND trigger_id = ? AND product_id = ?");
        $stmt->execute([$returnId, $p]);
        $this->assertSame(1, (int) $stmt->fetchColumn());
    }

    public function testNewStockTransferQueuesWooCommercePushButInterLocationMoveDoesNot(): void
    {
        $db = tests_new_database();
        $p = $this->product($db);
        $db->pdo()->prepare('UPDATE products SET wc_product_id = 701, sync_to_woocommerce = 1 WHERE id = ?')->execute([$p]);
        $a = $db->saveLocation(['name' => 'A']);
        $b = $db->saveLocation(['name' => 'B']);

        $db->transferStock($p, null, $a, 5, null);  // összesített +5 → push
        $db->transferStock($p, $a, $b, 2, null);    // összesített változatlan → nincs push

        $this->assertSame(1, (int) $db->pdo()->query("SELECT COUNT(*) FROM wc_push_queue WHERE trigger_type = 'transfer'")->fetchColumn());
    }

    public function testStockMovementReportStaysConsistentWithReturn(): void
    {
        $db = tests_new_database();
        $p = $this->product($db);
        $loc = $db->saveLocation(['name' => 'Bolt']);
        $db->transferStock($p, null, $loc, 5, null);
        $saleId = $this->sell($db, [['product_id' => $p, 'qty' => 2]], $loc);
        $this->returnItems($db, $saleId, [$p => 2]);

        $today = date('Y-m-d');
        $moves = $db->getStockMovements(['date_from' => $today, 'date_to' => $today, 'product_id' => $p])['movements'];
        $net = array_sum(array_column($moves, 'qty_change'));
        // +5 (új készlet) −2 (eladás) +2 (visszáru) = az összesített készlet.
        $this->assertSame($this->total($db, $p), $net);
    }

    public function testPurchaseStockTakeAndImportLeaveLocationBreakdownUntouched(): void
    {
        // Egyik művelet modellje sem hordoz telephelyet (nincs purchases.
        // location_id, a leltár UI kifejezetten csak az összesítettet kezeli,
        // az import-profilokban nincs telephely-oszlop) — a készlet nem kerül
        // mesterségesen szétosztásra.
        $db = tests_new_database();
        $p = $db->saveProduct(['name' => 'Import termék', 'unit' => 'db', 'vat_rate' => '27', 'net_price' => 1000, 'price' => 1270, 'barcode' => '5990000000011']);
        $loc = $db->saveLocation(['name' => 'Bolt']);
        $db->transferStock($p, null, $loc, 5, null);

        $db->recordPurchase(['supplier_name' => 'B', 'payment_method' => 'Átutalás', 'currency' => 'HUF'], [['product_id' => $p, 'name' => 'x', 'qty' => 3, 'vat_rate' => '27', 'unit_cost_net' => 500, 'unit_cost_gross' => 635]]);
        $takeId = $db->startStockTake(null, '');
        $db->updateStockTakeCount($takeId, $p, 7);
        $db->completeStockTake($takeId, true);
        $db->importUpsertProduct(['name' => 'Import termék', 'unit' => 'db', 'group_name' => '', 'cikkszam' => '', 'barcode' => '5990000000011', 'currency' => 'HUF', 'vat_rate' => '27', 'net_price' => 1000, 'price' => 1270, 'notes' => '', 'stock_qty' => 9, 'purchase_price_net' => 500]);

        $this->assertSame(9, $this->total($db, $p));
        $this->assertSame(5, $this->at($db, $p, $loc));
    }
}
