<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * 1.2.0 — Dashboard/riportok Database-szintű tesztjei. Reprezentatív,
 * több napra/terméktípusra kiterjedő fixture-t épít fel minden tesztben
 * (nem csak 2-3 dummy rekordot, lásd a kör 24. pontja), és MINDIG a
 * meglévő, már bevált Database-metódusokon (insertSale/recordPurchase/
 * processReturn/stock take/stock transfer) keresztül — sose nyers INSERT-
 * tel a mozgás-forrás táblákba, hogy a fixture ugyanazokat az invariánsokat
 * tükrözze, mint a valódi alkalmazás.
 */
final class DashboardReportsTest extends TestCase
{
    private function sampleProduct(array $overrides = []): array
    {
        return array_merge([
            'name' => 'Riport teszt termék',
            'unit' => 'db',
            'vat_rate' => '27',
            'net_price' => 1000,
            'price' => 1270,
        ], $overrides);
    }

    private function backdate(Database $db, string $table, int $id, string $when): void
    {
        $col = $table === 'stock_takes' ? 'completed_at' : 'created_at';
        $db->pdo()->prepare("UPDATE $table SET $col = ? WHERE id = ?")->execute([$when, $id]);
    }

    // -----------------------------------------------------------------
    // getSalesReportSummary — üres DB, normál adat, dátumtartomány,
    // fizetésimód-szűrés, napi bontás, visszáru-nettósítás.
    // -----------------------------------------------------------------

    public function testSalesReportSummaryOnEmptyDatabaseReturnsZeroedTotals(): void
    {
        $db = tests_new_database();
        $r = $db->getSalesReportSummary('2020-01-01', '2020-01-31');

        $this->assertSame(0, $r['sales_count']);
        $this->assertSame(0.0, $r['total_gross']);
        $this->assertSame(0.0, $r['total_net']);
        $this->assertSame(0.0, $r['avg_sale_gross']);
        $this->assertSame([], $r['by_payment_method']);
        $this->assertSame([], $r['by_day']);
    }

    public function testSalesReportSummaryAggregatesAcrossDateRangeAndPaymentMethods(): void
    {
        $db = tests_new_database();
        $productId = $db->saveProduct($this->sampleProduct());
        $db->incrementStock($productId, 100);

        // Három nap, két fizetési mód — a "ma" (dátumszűrésen kívüli
        // referenciapont) és két korábbi nap.
        $today = date('Y-m-d');
        $yesterday = date('Y-m-d', strtotime('-1 day'));
        $outsideWindow = date('Y-m-d', strtotime('-40 days'));

        $s1 = $db->insertSale(1270.0, 'Készpénz');
        $db->insertSaleItem($s1, ['product_id' => $productId, 'name' => 'X', 'qty' => 1, 'unit_price' => 1270, 'vat_rate' => '27']);
        $this->backdate($db, 'sales', $s1, $today . ' 10:00:00');

        $s2 = $db->insertSale(2540.0, 'Bankkártya');
        $db->insertSaleItem($s2, ['product_id' => $productId, 'name' => 'X', 'qty' => 2, 'unit_price' => 1270, 'vat_rate' => '27']);
        $this->backdate($db, 'sales', $s2, $yesterday . ' 11:00:00');

        $s3 = $db->insertSale(1270.0, 'Készpénz');
        $db->insertSaleItem($s3, ['product_id' => $productId, 'name' => 'X', 'qty' => 1, 'unit_price' => 1270, 'vat_rate' => '27']);
        $this->backdate($db, 'sales', $s3, $outsideWindow . ' 09:00:00');

        // Csak a tegnap-mai tartomány — a 40 nappal ezelőtti kimarad.
        $r = $db->getSalesReportSummary($yesterday, $today);
        $this->assertSame(2, $r['sales_count']);
        $this->assertEqualsWithDelta(3810.0, $r['total_gross'], 0.01);
        $this->assertCount(2, $r['by_day']);
        $this->assertEqualsWithDelta(3810.0 / (1.27), $r['total_net'], 0.5);

        $this->assertSame(1, $r['by_payment_method']['Készpénz']['count']);
        $this->assertSame(1, $r['by_payment_method']['Bankkártya']['count']);
        $this->assertEqualsWithDelta(33.3, $r['by_payment_method']['Készpénz']['percent'], 1.0);

        // Fizetésimód-szűrés: csak Készpénz a teljes (40 napot is lefedő) tartományra.
        $cashOnly = $db->getSalesReportSummary($outsideWindow, $today, 'Készpénz');
        $this->assertSame(2, $cashOnly['sales_count']);
        $this->assertEqualsWithDelta(2540.0, $cashOnly['total_gross'], 0.01);
    }

    public function testSalesReportSummarySubtractsReturnsOnTheReturnsOwnDayNotDoubleCounted(): void
    {
        $db = tests_new_database();
        $productId = $db->saveProduct($this->sampleProduct());
        $db->incrementStock($productId, 10);

        $saleId = $db->insertSale(1270.0, 'Készpénz');
        $itemId = $this->insertSaleItemAndGetId($db, $saleId, $productId, 1, 1270, '27');
        $today = date('Y-m-d');
        $this->backdate($db, 'sales', $saleId, $today . ' 09:00:00');

        $returnId = $db->processReturn($saleId, [
            ['sale_item_id' => $itemId, 'product_id' => $productId, 'name' => 'X', 'qty' => 1, 'unit_price' => 1270],
        ], 'teszt visszáru', null, 1270.0, []);
        $this->backdate($db, 'returns', $returnId, $today . ' 15:00:00');

        $r = $db->getSalesReportSummary($today, $today);
        // A visszáru pontosan nullázza a bevételt ezen a napon — nem
        // "duplán" (sale + invoice) kerül feldolgozásra, mert ez a metódus
        // az `invoices` táblához SOSE nyúl.
        $this->assertSame(1, $r['sales_count'], 'A sales_count az EREDETI eladást számolja, a visszáru nem szünteti meg a tranzakciót.');
        $this->assertEqualsWithDelta(0.0, $r['total_gross'], 0.01);
        $this->assertEqualsWithDelta(1270.0, $r['total_returns'], 0.01);
    }

    private function insertSaleItemAndGetId(Database $db, int $saleId, ?int $productId, int $qty, float $unitPrice, string $vatRate): int
    {
        $db->insertSaleItem($saleId, ['product_id' => $productId, 'name' => 'X', 'qty' => $qty, 'unit_price' => $unitPrice, 'vat_rate' => $vatRate]);
        return (int) $db->pdo()->query('SELECT id FROM sale_items ORDER BY id DESC LIMIT 1')->fetchColumn();
    }

    // -----------------------------------------------------------------
    // getTopProductsReport — csoport-szűrés, min. darabszám, visszáru-
    // nettósítás, nincs naiv SUM(qty).
    // -----------------------------------------------------------------

    public function testTopProductsReportNetsReturnsAndFiltersByGroupAndMinQty(): void
    {
        $db = tests_new_database();
        $today = date('Y-m-d');

        $productA = $db->saveProduct($this->sampleProduct(['name' => 'A termék', 'group_name' => 'Csoport 1']));
        $productB = $db->saveProduct($this->sampleProduct(['name' => 'B termék', 'group_name' => 'Csoport 2']));
        $db->incrementStock($productA, 50);
        $db->incrementStock($productB, 50);

        $saleA = $db->insertSale(5 * 1270.0, 'Készpénz');
        $itemA = $this->insertSaleItemAndGetId($db, $saleA, $productA, 5, 1270, '27');
        $this->backdate($db, 'sales', $saleA, $today . ' 10:00:00');

        $saleB = $db->insertSale(2 * 1270.0, 'Készpénz');
        $this->insertSaleItemAndGetId($db, $saleB, $productB, 2, 1270, '27');
        $this->backdate($db, 'sales', $saleB, $today . ' 10:00:00');

        // 2 db-ot visszaviszünk A termékből -> nettó 3 db marad.
        $returnId = $db->processReturn($saleA, [
            ['sale_item_id' => $itemA, 'product_id' => $productA, 'name' => 'A termék', 'qty' => 2, 'unit_price' => 1270],
        ], 'reszleges visszaru', null, 2 * 1270.0, []);
        $this->backdate($db, 'returns', $returnId, $today . ' 12:00:00');

        $all = $db->getTopProductsReport($today, $today);
        $byId = [];
        foreach ($all as $row) { $byId[$row['product_id']] = $row; }
        $this->assertSame(3, $byId[$productA]['qty'], 'A visszaru utan nettosan 3 db maradjon, NEM a nyers eladott 5 db (naiv SUM(qty) lenne).');
        $this->assertSame(2, $byId[$productB]['qty']);

        $group1Only = $db->getTopProductsReport($today, $today, 'Csoport 1');
        $this->assertCount(1, $group1Only);
        $this->assertSame($productA, $group1Only[0]['product_id']);

        $minQty3 = $db->getTopProductsReport($today, $today, null, 3);
        $this->assertCount(1, $minQty3, 'A min_qty szures kizarja a B termeket (2 db < 3).');
    }

    // -----------------------------------------------------------------
    // getInventoryOverview — normál/nulla/negatív/alacsony készlet,
    // készletérték a MEGLÉVŐ purchase_price_net mezőből.
    // -----------------------------------------------------------------

    public function testInventoryOverviewCountsAndValuation(): void
    {
        $db = tests_new_database();

        $inStock = $db->saveProduct($this->sampleProduct(['name' => 'Sok készlet']));
        $db->incrementStock($inStock, 100);
        $db->pdo()->exec("UPDATE products SET purchase_price_net = 500 WHERE id = $inStock");

        $zero = $db->saveProduct($this->sampleProduct(['name' => 'Nulla készlet']));

        $low = $db->saveProduct($this->sampleProduct(['name' => 'Alacsony készlet', 'low_stock_threshold' => 5]));
        $db->incrementStock($low, 3);

        $negative = $db->saveProduct($this->sampleProduct(['name' => 'Negatív készlet']));
        $db->decrementStock($negative, 10); // 0 -> -10, lásd Database::decrementStock() — nincs alsó korlát

        $overview = $db->getInventoryOverview(5, 10);
        $this->assertSame(4, $overview['total_products']);
        $this->assertSame(2, $overview['in_stock'], 'in_stock = minden pozitív készletű termék, a nagy ÉS az alacsony készletű is (a kettő nem zárja ki egymást).');
        $this->assertSame(1, $overview['zero_stock']);
        $this->assertSame(1, $overview['negative_stock']);
        $this->assertSame(1, $overview['low_stock']);
        $this->assertEqualsWithDelta(100 * 500, $overview['stock_value_net'], 0.01);
        $this->assertSame($inStock, $overview['top_by_value'][0]['id']);
    }

    // -----------------------------------------------------------------
    // getLowStockReport — 'low' vs 'out', javasolt mennyiség.
    // -----------------------------------------------------------------

    public function testLowStockReportFiltersLowVsOutAndComputesSuggestedQty(): void
    {
        $db = tests_new_database();

        $ok = $db->saveProduct($this->sampleProduct(['name' => 'Rendben']));
        $db->incrementStock($ok, 50);

        $low = $db->saveProduct($this->sampleProduct(['name' => 'Alacsony', 'low_stock_threshold' => 10]));
        $db->incrementStock($low, 4);

        $out = $db->saveProduct($this->sampleProduct(['name' => 'Kifogyott', 'low_stock_threshold' => 10]));

        $lowList = $db->getLowStockReport(5, 'low');
        $ids = array_column($lowList, 'id');
        $this->assertContains($low, $ids);
        $this->assertContains($out, $ids);
        $this->assertNotContains($ok, $ids);

        $lowRow = $lowList[array_search($low, $ids, true)];
        $this->assertSame(16, $lowRow['suggested_qty'], 'threshold*2 - stock_qty = 10*2 - 4 = 16.');

        $outList = $db->getLowStockReport(5, 'out');
        $outIds = array_column($outList, 'id');
        $this->assertContains($out, $outIds);
        $this->assertNotContains($low, $outIds, '"out" szűrő csak a ténylegesen kifogyottakat adja vissza.');
    }

    // -----------------------------------------------------------------
    // getStockMovements — sale/purchase/return/stock_take/transfer típusok,
    // csak az összesített készletet ténylegesen módosító transfer sorok.
    // -----------------------------------------------------------------

    public function testStockMovementsCoversAllSourceTypesWithinDateRange(): void
    {
        $db = tests_new_database();
        $today = date('Y-m-d');
        $productId = $db->saveProduct($this->sampleProduct());
        $db->incrementStock($productId, 100);

        // sale
        $saleId = $db->insertSale(1270.0, 'Készpénz');
        $itemId = $this->insertSaleItemAndGetId($db, $saleId, $productId, 2, 1270, '27');
        $db->decrementStock($productId, 2);
        $this->backdate($db, 'sales', $saleId, $today . ' 08:00:00');

        // purchase
        $purchase = $db->recordPurchase(
            ['payment_method' => 'készpénz', 'currency' => 'HUF'],
            [['product_id' => $productId, 'name' => 'X', 'qty' => 10, 'vat_rate' => '27', 'unit_cost_net' => 800, 'unit_cost_gross' => 1016]]
        );
        $this->backdate($db, 'purchases', $purchase['purchase_id'], $today . ' 09:00:00');

        // return
        $returnId = $db->processReturn($saleId, [
            ['sale_item_id' => $itemId, 'product_id' => $productId, 'name' => 'X', 'qty' => 1, 'unit_price' => 1270],
        ], 'teszt', null, 1270.0, []);
        $this->backdate($db, 'returns', $returnId, $today . ' 10:00:00');

        // stock take (discrepancy)
        $takeId = $db->startStockTake(null, 'teszt leltár');
        $currentStock = (int) $db->findProductById($productId)['stock_qty'];
        $db->updateStockTakeCount($takeId, $productId, $currentStock + 5);
        $db->completeStockTake($takeId, true);
        $this->backdate($db, 'stock_takes', $takeId, $today . ' 11:00:00');

        // transfer — "új készlet" (from_location_id NULL) ténylegesen növeli az összkészletet
        $locationId = $db->saveLocation(['name' => 'Raktár', 'is_default' => true]);
        $db->transferStock($productId, null, $locationId, 20, null);
        $this->backdate($db, 'stock_transfers', (int) $db->pdo()->query('SELECT id FROM stock_transfers ORDER BY id DESC LIMIT 1')->fetchColumn(), $today . ' 12:00:00');

        // transfer — telephelyek KÖZÖTTI mozgatás, nettó 0 hatás az összkészletre -> NEM szerepelhet
        $location2Id = $db->saveLocation(['name' => 'Bolt']);
        $db->transferStock($productId, $locationId, $location2Id, 5, null);

        $result = $db->getStockMovements(['date_from' => $today, 'date_to' => $today, 'product_id' => null, 'type' => null], 100, 0);
        $types = array_column($result['movements'], 'type');
        sort($types);
        $this->assertSame(['purchase', 'return', 'sale', 'stock_take', 'transfer'], $types, 'Minden forrástípusnak pontosan egyszer kell szerepelnie, a telephelyek közötti (nettó 0) mozgatás nélkül.');

        $byType = [];
        foreach ($result['movements'] as $m) { $byType[$m['type']] = $m; }
        $this->assertSame(-2, $byType['sale']['qty_change']);
        $this->assertSame(10, $byType['purchase']['qty_change']);
        $this->assertSame(1, $byType['return']['qty_change']);
        $this->assertSame(5, $byType['stock_take']['qty_change']);
        $this->assertSame(20, $byType['transfer']['qty_change']);
        $this->assertNull($byType['sale']['before_qty'], 'A meglévő adatmodell nem biztosít historikus before/after pillanatképet — ezt NULL-ként, nem hamis értékkel kell jelezni.');
    }

    public function testStockMovementsPaginationReportsHasMoreCorrectly(): void
    {
        $db = tests_new_database();
        $today = date('Y-m-d');
        $productId = $db->saveProduct($this->sampleProduct());
        $db->incrementStock($productId, 1000);

        for ($i = 0; $i < 5; $i++) {
            $saleId = $db->insertSale(1270.0, 'Készpénz');
            $db->insertSaleItem($saleId, ['product_id' => $productId, 'name' => 'X', 'qty' => 1, 'unit_price' => 1270, 'vat_rate' => '27']);
            $this->backdate($db, 'sales', $saleId, $today . ' 0' . $i . ':00:00');
        }

        $page1 = $db->getStockMovements(['date_from' => $today, 'date_to' => $today], 2, 0);
        $this->assertCount(2, $page1['movements']);
        $this->assertSame(5, $page1['total']);
        $this->assertTrue($page1['has_more']);

        $page3 = $db->getStockMovements(['date_from' => $today, 'date_to' => $today], 2, 4);
        $this->assertCount(1, $page3['movements']);
        $this->assertFalse($page3['has_more']);
    }

    // -----------------------------------------------------------------
    // getStockForecastBulk — insufficient data / zero consumption /
    // normál fogyás / negatív-invalid (already-out-of-stock) esetek.
    // -----------------------------------------------------------------

    public function testStockForecastInsufficientDataForSingleIsolatedSale(): void
    {
        $db = tests_new_database();
        $productId = $db->saveProduct($this->sampleProduct());
        $db->incrementStock($productId, 100);

        $saleId = $db->insertSale(1270.0, 'Készpénz');
        $db->insertSaleItem($saleId, ['product_id' => $productId, 'name' => 'X', 'qty' => 3, 'unit_price' => 1270, 'vat_rate' => '27']);
        $this->backdate($db, 'sales', $saleId, date('Y-m-d', strtotime('-2 days')) . ' 10:00:00');

        $forecast = $db->getStockForecastBulk([$productId], 30);
        $this->assertSame('insufficient_data', $forecast[$productId]['status'], 'Egyetlen elszigetelt eladási nap nem elég megbízható rátához.');
        $this->assertNull($forecast[$productId]['estimated_days_remaining']);
    }

    public function testStockForecastZeroConsumptionIsConfidentNotUncertain(): void
    {
        $db = tests_new_database();
        $productId = $db->saveProduct($this->sampleProduct());
        $db->incrementStock($productId, 100);
        // Nincs semmilyen eladás/visszáru a termékre — ez egy MEGBÍZHATÓ
        // "nem fogy" megállapítás, nem "nincs elegendő adat".

        $forecast = $db->getStockForecastBulk([$productId], 30);
        $this->assertSame('zero_consumption', $forecast[$productId]['status']);
        $this->assertSame(0.0, $forecast[$productId]['avg_daily_consumption']);
    }

    public function testStockForecastNormalConsumptionEstimatesRemainingDays(): void
    {
        $db = tests_new_database();
        $productId = $db->saveProduct($this->sampleProduct());
        $db->incrementStock($productId, 60);

        // 3 különböző napon, összesen 30 db eladva egy 30 napos ablakban ->
        // átlag 1 db/nap -> 60 db készlettel 60 napra elegendő.
        foreach ([1, 5, 10] as $daysAgo) {
            $saleId = $db->insertSale(1270.0, 'Készpénz');
            $db->insertSaleItem($saleId, ['product_id' => $productId, 'name' => 'X', 'qty' => 10, 'unit_price' => 1270, 'vat_rate' => '27']);
            $this->backdate($db, 'sales', $saleId, date('Y-m-d', strtotime("-$daysAgo days")) . ' 10:00:00');
        }

        $forecast = $db->getStockForecastBulk([$productId], 30);
        $this->assertSame('ok', $forecast[$productId]['status']);
        $this->assertSame(60, $forecast[$productId]['estimated_days_remaining']);
    }

    public function testStockForecastOutOfStockNeverReturnsDayCountEvenWithSalesHistory(): void
    {
        $db = tests_new_database();
        $productId = $db->saveProduct($this->sampleProduct());
        // Negatív készlet ÉS volt korábbi eladási előzmény is — a
        // "hány nap múlva fogy el" kérdés ekkor értelmetlen (már most sincs
        // készleten), NEM egy hamis "0 nap" vagy negatív szám.
        foreach ([1, 5] as $daysAgo) {
            $saleId = $db->insertSale(1270.0, 'Készpénz');
            $db->insertSaleItem($saleId, ['product_id' => $productId, 'name' => 'X', 'qty' => 1, 'unit_price' => 1270, 'vat_rate' => '27']);
            $this->backdate($db, 'sales', $saleId, date('Y-m-d', strtotime("-$daysAgo days")) . ' 10:00:00');
        }
        $db->decrementStock($productId, 5);

        $forecast = $db->getStockForecastBulk([$productId], 30);
        $this->assertSame('out_of_stock', $forecast[$productId]['status']);
        $this->assertNull($forecast[$productId]['estimated_days_remaining']);
    }

    // -----------------------------------------------------------------
    // WooCommerce / NAV queue összesítők — a MEGLÉVŐ 1.1.1-es
    // wc_push_queue/invoices állapotgépet olvassák, nem hoznak létre újat.
    // -----------------------------------------------------------------

    public function testWcQueueStatusSummaryGroupsByStatus(): void
    {
        $db = tests_new_database();
        $productId = $db->saveProduct($this->sampleProduct());

        $q1 = $db->enqueueWcPush($productId, 555, 'sale', 1);
        $db->markWcPushDone($q1['id']);

        $q2 = $db->enqueueWcPush($productId, 555, 'sale', 2);
        $db->markWcPushFailed($q2['id'], 'HTTP 400 teszt hiba');

        $db->enqueueWcPush($productId, 555, 'sale', 3); // queued marad

        $summary = $db->getWcQueueStatusSummary();
        $this->assertSame(1, $summary['counts']['queued']);
        $this->assertSame(1, $summary['counts']['done']);
        $this->assertSame(1, $summary['counts']['failed']);
        $this->assertCount(1, $summary['recent_failed']);
    }

    public function testInvoiceQueueStatusSummaryUsesExistingStatusBuckets(): void
    {
        $db = tests_new_database();
        $pdo = $db->pdo();
        $saleId = $db->insertSale(1000.0, 'Készpénz');
        $now = date('Y-m-d H:i:s');
        foreach (['queued', 'processing', 'submitted', 'done', 'failed', 'dead_letter', 'uncertain'] as $i => $status) {
            $pdo->prepare("
                INSERT INTO invoices (sale_id, provider, status, operation_key, created_at, updated_at)
                VALUES (?, 'nav', ?, ?, ?, ?)
            ")->execute([$saleId, $status, 'test-op-key-' . $i, $now, $now]);
        }

        $summary = $db->getInvoiceQueueStatusSummary('nav');
        $this->assertSame(1, $summary['buckets']['done']);
        $this->assertSame(3, $summary['buckets']['pending'], 'queued+processing+submitted.');
        $this->assertSame(3, $summary['buckets']['failed'], 'failed+dead_letter+uncertain.');
    }

    public function testPeriodPurchaseTotalSumsWithinRange(): void
    {
        $db = tests_new_database();
        $productId = $db->saveProduct($this->sampleProduct());
        $today = date('Y-m-d');

        $purchase = $db->recordPurchase(
            ['payment_method' => 'készpénz'],
            [['product_id' => $productId, 'name' => 'X', 'qty' => 5, 'vat_rate' => '27', 'unit_cost_net' => 1000, 'unit_cost_gross' => 1270]]
        );
        $this->backdate($db, 'purchases', $purchase['purchase_id'], $today . ' 09:00:00');

        $result = $db->getPeriodPurchaseTotal($today, $today);
        $this->assertSame(1, $result['count']);
        $this->assertEqualsWithDelta(5 * 1270, $result['total_gross'], 0.01);
    }

    // -----------------------------------------------------------------
    // countLowRunwayProducts — Dashboard "Figyelmet igényel" blokk. NEM
    // új forecast-képlet, csak egy küszöb szerinti összeszámolás a
    // MEGLÉVŐ getLowStockReport()/getStockForecastBulk() eredményén.
    // -----------------------------------------------------------------

    public function testCountLowRunwayProductsOnlyCountsReliableForecastsUnderTheThreshold(): void
    {
        $db = tests_new_database();

        // A: alacsony készletű (6 db, küszöb 20), a fogyás gyors és
        // megbízható (26 eladott db 26 különböző napon, 30 napos ablakban
        // -> atlag ~0,867/nap -> 6 / 0,867 = 6 nap, tehát a 7 napos
        // küszöb ALATT van) -> számítania kell.
        $a = $db->saveProduct($this->sampleProduct(['name' => 'A', 'low_stock_threshold' => 20]));
        $db->incrementStock($a, 6);
        for ($i = 0; $i < 26; $i++) {
            $saleId = $db->insertSale(1270.0, 'Készpénz');
            $db->insertSaleItem($saleId, ['product_id' => $a, 'name' => 'A', 'qty' => 1, 'unit_price' => 1270, 'vat_rate' => '27']);
            $this->backdate($db, 'sales', $saleId, date('Y-m-d', strtotime('-' . ($i + 1) . ' days')) . ' 10:00:00');
        }

        // B: alacsony készletű, de NEM fogy (zero_consumption) -> nem számít.
        $b = $db->saveProduct($this->sampleProduct(['name' => 'B', 'low_stock_threshold' => 20]));
        $db->incrementStock($b, 5);

        // C: bőséges készlettel, magas fogyással -> nem alacsony készletű, ki sem kerül a jelöltek közé.
        $c = $db->saveProduct($this->sampleProduct(['name' => 'C', 'low_stock_threshold' => 5]));
        $db->incrementStock($c, 1000);

        $count = $db->countLowRunwayProducts(5, 7);
        $this->assertSame(1, $count, 'Csak az A terméknek kell számítania: alacsony készlet + megbízható, 7 napon belüli kifogyás.');
    }
}
