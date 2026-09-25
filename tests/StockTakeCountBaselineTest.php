<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * B-01 (correctness audit) regresszió: a leltári korrekció alapja a termék
 * rendszerkészlete a MEGSZÁMOLÁS pillanatában (stock_take_items.
 * system_qty_at_count), nem a leltár indításakor rögzített expected_qty.
 *
 * A hiba: completeStockTake() a (counted - expected_qty) eltérést a
 * JELENLEGI stock_qty-re alkalmazta — az indítás és a megszámolás közötti
 * mozgás így kétszer számított (egyszer a stock_qty-ban, egyszer a
 * korrekcióban). Pl. 10 → indítás → eladás 2 → számolás 8 → lezárás = 6
 * (helyesen 8).
 */
final class StockTakeCountBaselineTest extends TestCase
{
    private function product(Database $db, string $name, int $stock): int
    {
        $id = $db->saveProduct([
            'name' => $name, 'unit' => 'db', 'vat_rate' => '27',
            'net_price' => 1000, 'price' => 1270, 'barcode' => null,
        ]);
        if ($stock > 0) {
            $db->incrementStock($id, $stock);
        }
        return $id;
    }

    private function stock(Database $db, int $productId): int
    {
        return (int) $db->findProductById($productId)['stock_qty'];
    }

    /** Valódi kasszai eladás (sale.php sorrendje): fej, tétel, globális készlet-csökkentés. */
    private function sell(Database $db, int $productId, int $qty, ?int $locationId = null): int
    {
        $db->beginTransaction();
        try {
            $saleId = $db->insertSale(1270.0 * $qty, 'Készpénz');
            $db->insertSaleItem($saleId, [
                'product_id' => $productId, 'name' => 'Tétel', 'qty' => $qty,
                'unit_price' => 1270, 'vat_rate' => '27',
            ]);
            $db->decrementStock($productId, $qty);
            if ($locationId !== null) {
                $db->decrementLocationStock($productId, $locationId, $qty);
            }
            $db->commit();
        } catch (Throwable $e) {
            $db->rollBack();
            throw $e;
        }
        return $saleId;
    }

    private function returnWholeSale(Database $db, int $saleId, int $productId, int $qty): void
    {
        $sale = $db->getSaleWithItems($saleId);
        $db->processReturn($saleId, [[
            'sale_item_id' => (int) $sale['items'][0]['id'], 'product_id' => $productId,
            'name' => 'Tétel', 'qty' => $qty, 'unit_price' => 1270,
        ]], 'visszáru', null, 1270.0 * $qty, $sale);
    }

    private function purchase(Database $db, int $productId, int $qty): void
    {
        $db->recordPurchase(
            ['supplier_name' => 'Beszállító', 'payment_method' => 'Átutalás', 'currency' => 'HUF'],
            [['product_id' => $productId, 'name' => 'Tétel', 'qty' => $qty, 'vat_rate' => '27', 'unit_cost_net' => 500, 'unit_cost_gross' => 635]]
        );
    }

    private function itemRow(Database $db, int $takeId, int $productId): array
    {
        $stmt = $db->pdo()->prepare('SELECT * FROM stock_take_items WHERE stock_take_id = ? AND product_id = ?');
        $stmt->execute([$takeId, $productId]);
        return $stmt->fetch(PDO::FETCH_ASSOC);
    }

    // ------------------------------------------------------------------
    // A hiba reprodukciója — a spec kötelező forgatókönyve
    // ------------------------------------------------------------------

    public function testSaleBetweenStartAndCountIsNotCountedTwice(): void
    {
        $db = tests_new_database();
        $productId = $this->product($db, 'B01 alap', 10);

        $takeId = $db->startStockTake(null, 'B-01');
        $this->sell($db, $productId, 2);                                   // 10 -> 8
        $this->assertSame(8, $db->updateStockTakeCount($takeId, $productId, 8), 'A számláláskor a rendszer 8-at lát.');
        $db->completeStockTake($takeId, true);

        // A hibás (expected_qty-alapú) logika 8 + (8 - 10) = 6-ot adott.
        $this->assertSame(8, $this->stock($db, $productId));
    }

    public function testReturnBetweenStartAndCountIsNotCountedTwice(): void
    {
        $db = tests_new_database();
        $productId = $this->product($db, 'B01 visszáru', 10);
        $saleId = $this->sell($db, $productId, 3);                         // 10 -> 7

        $takeId = $db->startStockTake(null, '');                           // expected 7
        $this->returnWholeSale($db, $saleId, $productId, 3);               // 7 -> 10
        $db->updateStockTakeCount($takeId, $productId, 9);                 // 1 hiányzik
        $db->completeStockTake($takeId, true);

        // Hibás logika: 10 + (9 - 7) = 12.
        $this->assertSame(9, $this->stock($db, $productId));
    }

    public function testPurchaseBetweenStartAndCountIsNotCountedTwice(): void
    {
        $db = tests_new_database();
        $productId = $this->product($db, 'B01 beszerzés', 10);

        $takeId = $db->startStockTake(null, '');                           // expected 10
        $this->purchase($db, $productId, 5);                               // 10 -> 15
        $db->updateStockTakeCount($takeId, $productId, 14);                // 1 hiányzik
        $db->completeStockTake($takeId, true);

        // Hibás logika: 15 + (14 - 10) = 19.
        $this->assertSame(14, $this->stock($db, $productId));
    }

    public function testMovementsAfterCountAreKeptExactlyOnce(): void
    {
        $db = tests_new_database();
        $productId = $this->product($db, 'B01 számlálás után', 10);
        $earlierSaleId = $this->sell($db, $productId, 1);                  // 10 -> 9

        $takeId = $db->startStockTake(null, '');                           // expected 9
        $db->updateStockTakeCount($takeId, $productId, 8);                 // baseline 9, eltérés -1
        $this->sell($db, $productId, 2);                                   // 9 -> 7
        $this->returnWholeSale($db, $earlierSaleId, $productId, 1);        // 7 -> 8
        $this->purchase($db, $productId, 5);                               // 8 -> 13
        $db->completeStockTake($takeId, true);

        // A megszámolás utáni mozgások (-2 +1 +5) egyszer, a -1 eltérés egyszer.
        $this->assertSame(12, $this->stock($db, $productId));
    }

    public function testMultipleProductsInOneStockTake(): void
    {
        $db = tests_new_database();
        $moved = $this->product($db, 'Mozgott', 10);
        $unchanged = $this->product($db, 'Egyező', 5);
        $short = $this->product($db, 'Hiányos', 7);
        $uncounted = $this->product($db, 'Nem számolt', 4);

        $takeId = $db->startStockTake(null, '');
        $this->sell($db, $moved, 3);                                       // 10 -> 7
        $db->updateStockTakeCount($takeId, $moved, 7);                     // egyezik a számláláskori 7-tel
        $db->updateStockTakeCount($takeId, $unchanged, 5);
        $db->updateStockTakeCount($takeId, $short, 4);                     // -3
        $this->sell($db, $uncounted, 1);                                   // 4 -> 3, nem számolt
        $db->completeStockTake($takeId, true);

        $this->assertSame(7, $this->stock($db, $moved));
        $this->assertSame(5, $this->stock($db, $unchanged));
        $this->assertSame(4, $this->stock($db, $short));
        $this->assertSame(3, $this->stock($db, $uncounted), 'A meg nem számolt tétel készlete érintetlen marad.');
    }

    public function testUnchangedStockProducesNoUpdateAndNoWooCommercePush(): void
    {
        $db = tests_new_database();
        $productId = $this->product($db, 'WC egyező', 6);
        $db->pdo()->prepare('UPDATE products SET wc_product_id = 901, sync_to_woocommerce = 1 WHERE id = ?')->execute([$productId]);

        $takeId = $db->startStockTake(null, '');
        $this->sell($db, $productId, 2);                                   // 6 -> 4
        $db->updateStockTakeCount($takeId, $productId, 4);
        $updated = $db->completeStockTake($takeId, true);

        $this->assertSame([], $updated, 'Eltérés nélkül nincs WC-szinkronra jelölt termék.');
        $this->assertSame(4, $this->stock($db, $productId));
        $queued = (int) $db->pdo()->query("SELECT COUNT(*) FROM wc_push_queue WHERE trigger_type = 'stock_take'")->fetchColumn();
        $this->assertSame(0, $queued, 'Egyező számlálásnál nem kerülhet leltári push a sorba.');
    }

    public function testWooCommercePushIsQueuedWithTheCorrectResultingStock(): void
    {
        $db = tests_new_database();
        $productId = $this->product($db, 'WC eltérő', 10);
        $db->pdo()->prepare('UPDATE products SET wc_product_id = 902, sync_to_woocommerce = 1 WHERE id = ?')->execute([$productId]);

        $takeId = $db->startStockTake(null, '');
        $this->sell($db, $productId, 2);                                   // 10 -> 8
        $db->updateStockTakeCount($takeId, $productId, 7);                 // -1
        $updated = $db->completeStockTake($takeId, true);

        $this->assertCount(1, $updated);
        $this->assertSame(7, $updated[0]['stock_qty'], 'A WC-push a helyes, végső készletet kapja (nem a duplán számolt 5-öt).');
        $this->assertSame(7, $this->stock($db, $productId));
        $stmt = $db->pdo()->prepare("SELECT COUNT(*) FROM wc_push_queue WHERE trigger_type = 'stock_take' AND trigger_id = ? AND product_id = ?");
        $stmt->execute([$takeId, $productId]);
        $this->assertSame(1, (int) $stmt->fetchColumn());
    }

    public function testCountRecordsSystemQuantityAtCountAndRecountRefreshesIt(): void
    {
        $db = tests_new_database();
        $productId = $this->product($db, 'Újraszámolt', 10);

        $takeId = $db->startStockTake(null, '');
        $this->assertSame(10, $db->updateStockTakeCount($takeId, $productId, 8));
        $this->sell($db, $productId, 1);                                   // 10 -> 9
        // Újraszámolás: az alap az ÚJ számlálás pillanatának készlete.
        $this->assertSame(9, $db->updateStockTakeCount($takeId, $productId, 8));

        $row = $this->itemRow($db, $takeId, $productId);
        $this->assertSame(10, (int) $row['expected_qty'], 'Az indításkori pillanatkép változatlan marad.');
        $this->assertSame(9, (int) $row['system_qty_at_count']);
        $this->assertSame(8, (int) $row['counted_qty']);

        $db->completeStockTake($takeId, true);
        $this->assertSame(8, $this->stock($db, $productId));
    }

    public function testClearingACountClearsItsBaselineAndIsNotApplied(): void
    {
        $db = tests_new_database();
        $productId = $this->product($db, 'Törölt számlálás', 10);

        $takeId = $db->startStockTake(null, '');
        $db->updateStockTakeCount($takeId, $productId, 3);
        $this->assertNull($db->updateStockTakeCount($takeId, $productId, null));

        $row = $this->itemRow($db, $takeId, $productId);
        $this->assertNull($row['counted_qty']);
        $this->assertNull($row['system_qty_at_count']);

        $db->completeStockTake($takeId, true);
        $this->assertSame(10, $this->stock($db, $productId));
    }

    public function testLegacyCountWithoutBaselineFallsBackToExpectedQty(): void
    {
        // Egy V33 előtt rögzített számlálásnál system_qty_at_count NULL —
        // a lezárás ilyenkor a korábbi (expected_qty-alapú) viselkedést adja.
        $db = tests_new_database();
        $productId = $this->product($db, 'Régi számlálás', 10);

        $takeId = $db->startStockTake(null, '');
        $db->updateStockTakeCount($takeId, $productId, 8);
        $db->pdo()->prepare('UPDATE stock_take_items SET system_qty_at_count = NULL WHERE stock_take_id = ?')->execute([$takeId]);
        $db->decrementStock($productId, 1);                                // 10 -> 9
        $db->completeStockTake($takeId, true);

        $this->assertSame(7, $this->stock($db, $productId), '9 + (8 - 10) — a régi alap változatlanul érvényes.');
    }

    public function testCompletionWithoutApplyingCorrectionsLeavesStockUntouched(): void
    {
        $db = tests_new_database();
        $productId = $this->product($db, 'Korrekció nélkül', 10);

        $takeId = $db->startStockTake(null, '');
        $this->sell($db, $productId, 2);
        $db->updateStockTakeCount($takeId, $productId, 5);
        $this->assertSame([], $db->completeStockTake($takeId, false));

        $this->assertSame(8, $this->stock($db, $productId));
    }

    public function testMultipleLocationsGlobalStockCorrectAndLocationStockUntouchedByStockTake(): void
    {
        $db = tests_new_database();
        $productId = $this->product($db, 'Több telephely', 0);
        $shop = $db->saveLocation(['name' => 'Bolt', 'is_default' => true]);
        $store = $db->saveLocation(['name' => 'Raktár']);
        $db->transferStock($productId, null, $shop, 6, null);             // globális 6
        $db->transferStock($productId, null, $store, 4, null);            // globális 10

        $takeId = $db->startStockTake(null, '');
        $this->sell($db, $productId, 2, $shop);                           // globális 8, bolt 4
        $db->updateStockTakeCount($takeId, $productId, 7);                // -1
        $this->sell($db, $productId, 1, $store);                          // globális 7, raktár 3
        $db->completeStockTake($takeId, true);

        $this->assertSame(6, $this->stock($db, $productId));
        $byLocation = array_column($db->getLocationStockForProduct($productId), 'stock_qty', 'location_id');
        // A leltár a globális készletet korrigálja — a telephelyi bontást
        // (a korábbi viselkedéssel azonosan) nem módosítja.
        $this->assertSame(4, (int) $byLocation[$shop]);
        $this->assertSame(3, (int) $byLocation[$store]);
    }

    public function testMovementReportUsesCountTimeBaseline(): void
    {
        $db = tests_new_database();
        $productId = $this->product($db, 'Mozgásnapló', 10);

        $takeId = $db->startStockTake(null, '');
        $this->sell($db, $productId, 2);
        $db->updateStockTakeCount($takeId, $productId, 7);
        $db->completeStockTake($takeId, true);

        $today = date('Y-m-d');
        $rows = $db->getStockMovements(['date_from' => $today, 'date_to' => $today, 'type' => 'stock_take', 'product_id' => $productId])['movements'];
        $this->assertCount(1, $rows);
        $this->assertSame(-1, $rows[0]['qty_change'], 'A napló a ténylegesen könyvelt eltérést mutatja (nem a -3-at).');
    }

    public function testMovementReportOmitsCountThatMatchedCountTimeStock(): void
    {
        $db = tests_new_database();
        $productId = $this->product($db, 'Mozgásnapló egyező', 10);

        $takeId = $db->startStockTake(null, '');
        $this->sell($db, $productId, 2);
        $db->updateStockTakeCount($takeId, $productId, 8);
        $db->completeStockTake($takeId, true);

        $today = date('Y-m-d');
        $rows = $db->getStockMovements(['date_from' => $today, 'date_to' => $today, 'type' => 'stock_take', 'product_id' => $productId])['movements'];
        $this->assertCount(0, $rows);
    }

    public function testStockTakeDetailExposesTheBaselineForTheUi(): void
    {
        $db = tests_new_database();
        $productId = $this->product($db, 'Részletek', 10);

        $takeId = $db->startStockTake(null, '');
        $this->sell($db, $productId, 2);
        $db->updateStockTakeCount($takeId, $productId, 8);

        $take = $db->getStockTake($takeId);
        $item = current(array_filter($take['items'], fn($i) => (int) $i['product_id'] === $productId));
        $this->assertSame(10, (int) $item['expected_qty']);
        $this->assertSame(8, (int) $item['system_qty_at_count']);
    }

    // ------------------------------------------------------------------
    // V33 migráció (SQLite; a MySQL DDL-t a MysqlDialectRegressionTest ellenőrzi)
    // ------------------------------------------------------------------

    public function testV33MigrationAddsColumnOnUpgradeAndKeepsLegacyCountsWorking(): void
    {
        $db = tests_new_database();
        $productId = $this->product($db, 'Frissítés', 10);
        $takeId = $db->startStockTake(null, '');
        $db->updateStockTakeCount($takeId, $productId, 8);

        $pathProp = new ReflectionProperty(Database::class, 'dbConfig');
        $pathProp->setAccessible(true);
        $path = $pathProp->getValue($db)['sqlite']['path'];
        unset($db);

        // Valódi v32-es állapot: az oszlop még nem létezik.
        $pdo = new PDO('sqlite:' . $path);
        $pdo->exec('ALTER TABLE stock_take_items DROP COLUMN system_qty_at_count');
        $pdo->exec('UPDATE schema_version SET version = 32');
        $pdo = null;

        $db = new Database(['driver' => 'sqlite', 'sqlite' => ['path' => $path]], dirname(__DIR__));
        $cols = array_column($db->pdo()->query('PRAGMA table_info(stock_take_items)')->fetchAll(PDO::FETCH_ASSOC), 'type', 'name');
        $this->assertSame('INTEGER', $cols['system_qty_at_count'] ?? null);
        $this->assertSame(33, (int) $db->pdo()->query('SELECT version FROM schema_version')->fetchColumn());

        $row = $this->itemRow($db, $takeId, $productId);
        $this->assertSame(8, (int) $row['counted_qty'], 'A meglévő számlálás megmarad.');
        $this->assertNull($row['system_qty_at_count']);

        $db->completeStockTake($takeId, true);
        $this->assertSame(8, $this->stock($db, $productId), 'Mozgás nélkül a régi és az új alap azonos eredményt ad.');
    }

    public function testV33MigrationRerunIsANoOp(): void
    {
        $db = tests_new_database();
        $method = new ReflectionMethod(Database::class, 'migrateV33StockTakeCountBaseline');
        $method->setAccessible(true);
        $method->invoke($db);
        $method->invoke($db);

        $cols = array_column($db->pdo()->query('PRAGMA table_info(stock_take_items)')->fetchAll(PDO::FETCH_ASSOC), 'name');
        $this->assertSame(1, count(array_keys($cols, 'system_qty_at_count', true)));
    }
}
