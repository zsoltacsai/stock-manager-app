<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * 1.3.0 — a Database.php beszerzési döntéstámogató/árrés/készletérték
 * bulk-metódusainak tesztjei. A képletek helyessége már bizonyítva van
 * a PurchaseDecisionServiceTest.php-ban — itt a helyes ADAT-összeállítás
 * (bulk lekérdezések, visszáru-nettósítás, megbízhatatlan költség
 * kiszűrése) a tárgy.
 */
final class PurchaseDecisionDbTest extends TestCase
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
    // getPurchaseRecommendations
    // -----------------------------------------------------------------

    public function testPurchaseRecommendationsClassifyUrgencyAndComputeReorderFieldsFromRealSalesHistory(): void
    {
        $db = tests_new_database();

        // Sürgős: nagyon alacsony készlet, megbízható, gyors fogyás.
        $urgent = $db->saveProduct($this->sampleProduct(['name' => 'Sürgős termék', 'low_stock_threshold' => 5]));
        $db->incrementStock($urgent, 2);
        for ($i = 0; $i < 20; $i++) {
            $saleId = $db->insertSale(1270.0, 'Készpénz');
            $db->insertSaleItem($saleId, ['product_id' => $urgent, 'name' => 'X', 'qty' => 1, 'unit_price' => 1270, 'vat_rate' => '27']);
            $this->backdate($db, 'sales', $saleId, date('Y-m-d', strtotime('-' . ($i + 1) . ' days')) . ' 10:00:00');
        }

        // "no sales history" — alacsony készlet, de nincs elég eladási előzmény.
        $noHistory = $db->saveProduct($this->sampleProduct(['name' => 'Előzmény nélküli termék', 'low_stock_threshold' => 5]));
        $db->incrementStock($noHistory, 3);

        // Rendben lévő (nem alacsony) termék — ki sem kerülhet a listába.
        $ok = $db->saveProduct($this->sampleProduct(['name' => 'Rendben lévő termék', 'low_stock_threshold' => 5]));
        $db->incrementStock($ok, 500);

        $recommendations = $db->getPurchaseRecommendations(5, 30);
        $byId = [];
        foreach ($recommendations as $r) { $byId[$r['id']] = $r; }

        $this->assertArrayNotHasKey($ok, $byId, 'A megfelelő készletű termék ne kerüljön a javaslati listába.');

        $this->assertSame('urgent', $byId[$urgent]['urgency']);
        $this->assertSame('suggested', $byId[$urgent]['workflow_status']);
        $this->assertNotNull($byId[$urgent]['avg_daily_consumption']);
        $this->assertGreaterThan($byId[$urgent]['safety_stock'], $byId[$urgent]['reorder_point'], 'Valódi fogyás mellett a rendelési pontnak a biztonsági készlet FÖLÖTT kell lennie.');
        $this->assertGreaterThan(0, $byId[$urgent]['recommended_qty']);
        $this->assertStringContainsString('napon belül elfogy', $byId[$urgent]['reason']);

        $this->assertSame('low', $byId[$noHistory]['urgency'], '"no sales history" esetén sose "urgent"/"soon", mert nincs megbízható előrejelzés.');
        $this->assertNull($byId[$noHistory]['avg_daily_consumption']);
        $this->assertSame($byId[$noHistory]['safety_stock'], $byId[$noHistory]['reorder_point'], 'Fogyás-adat nélkül a rendelési pont visszaesik a biztonsági készletre.');

        // Sürgősség szerint csökkenő sorrend: az urgent az élen.
        $this->assertSame('urgent', $recommendations[0]['urgency']);
    }

    public function testPurchaseRecommendationsMarksRecentlyPurchasedProductsAsInProgress(): void
    {
        $db = tests_new_database();
        $product = $db->saveProduct($this->sampleProduct(['name' => 'Nemrég rendelt termék', 'low_stock_threshold' => 20]));
        $db->incrementStock($product, 5); // még mindig alacsony a küszöb alatt

        $db->recordPurchase(
            ['payment_method' => 'készpénz'],
            [['product_id' => $product, 'name' => 'X', 'qty' => 3, 'vat_rate' => '27', 'unit_cost_net' => 800, 'unit_cost_gross' => 1016]]
        );

        $recommendations = $db->getPurchaseRecommendations(20, 30);
        $row = null;
        foreach ($recommendations as $r) { if ($r['id'] === $product) { $row = $r; } }
        $this->assertNotNull($row);
        $this->assertSame('in_progress', $row['workflow_status'], 'Egy nemrég (3 napon belül) rögzített beszerzés esetén a státusz "in_progress", nem "suggested".');
    }

    public function testPurchaseRecommendationsForZeroStockProduct(): void
    {
        $db = tests_new_database();
        $product = $db->saveProduct($this->sampleProduct(['name' => 'Kifogyott termék', 'low_stock_threshold' => 5]));
        // Nincs incrementStock hívás — 0 készlettel indul.

        $recommendations = $db->getPurchaseRecommendations(5, 30);
        $row = $recommendations[0];
        $this->assertSame($product, $row['id']);
        $this->assertSame(0, $row['stock_qty']);
        $this->assertSame('urgent', $row['urgency']);
        $this->assertStringContainsString('nincs készleten', $row['reason']);
    }

    // -----------------------------------------------------------------
    // Árrés — getTopProductsReport() margin mezői + getSalesMarginSummary()
    // -----------------------------------------------------------------

    public function testTopProductsMarginUsesCurrentCostAndIsNullWithoutReliableCost(): void
    {
        $db = tests_new_database();
        $today = date('Y-m-d');

        // Megbízható költséggel rendelkező termék (VOLT beszerzés).
        $withCost = $db->saveProduct($this->sampleProduct(['name' => 'Költséggel', 'net_price' => 1000, 'price' => 1270, 'vat_rate' => '27']));
        $db->recordPurchase(
            ['payment_method' => 'készpénz'],
            [['product_id' => $withCost, 'name' => 'X', 'qty' => 1, 'vat_rate' => '27', 'unit_cost_net' => 600, 'unit_cost_gross' => 762]]
        );
        // 1.1.1 óta recordPurchase() a termék purchase_price_net mezőjét NEM
        // állítja be automatikusan (csak a beszerzési TÉTEL rögzül) — a
        // margin-számítás a products.purchase_price_net mezőből dolgozik,
        // ezért azt itt explicit be kell állítani, ahogy a termékszerkesztő
        // is tenné a "legutóbbi ismert beszerzési ár" mentésekor.
        $db->pdo()->exec("UPDATE products SET purchase_price_net = 600 WHERE id = $withCost");

        $saleId = $db->insertSale(1270.0, 'Készpénz');
        $db->insertSaleItem($saleId, ['product_id' => $withCost, 'name' => 'X', 'qty' => 1, 'unit_price' => 1270, 'vat_rate' => '27']);
        $this->backdate($db, 'sales', $saleId, $today . ' 10:00:00');

        // SOSE beszerzett termék — purchase_price_net alapértelmezetten 0.
        $noCost = $db->saveProduct($this->sampleProduct(['name' => 'Költség nélkül', 'net_price' => 1000, 'price' => 1270, 'vat_rate' => '27']));
        $saleId2 = $db->insertSale(1270.0, 'Készpénz');
        $db->insertSaleItem($saleId2, ['product_id' => $noCost, 'name' => 'X', 'qty' => 1, 'unit_price' => 1270, 'vat_rate' => '27']);
        $this->backdate($db, 'sales', $saleId2, $today . ' 11:00:00');

        $report = $db->getTopProductsReport($today, $today);
        $byId = [];
        foreach ($report as $r) { $byId[$r['product_id']] = $r; }

        $this->assertNotNull($byId[$withCost]['margin_net']);
        $this->assertEqualsWithDelta(1000 - 600, $byId[$withCost]['margin_net'], 0.5, 'Nettó eladás (1000) - nettó költség (600) = 400 Ft árrés.');
        $this->assertNotNull($byId[$withCost]['margin_pct']);

        $this->assertNull($byId[$noCost]['margin_net'], 'Sose beszerzett termékhez NE számoljon (hamis) árrést.');
        $this->assertNull($byId[$noCost]['cost_net_total']);
        $this->assertNull($byId[$noCost]['margin_pct']);
    }

    public function testTopProductsMarginNettedByReturnsUsingSameUnitValue(): void
    {
        $db = tests_new_database();
        $today = date('Y-m-d');

        $product = $db->saveProduct($this->sampleProduct(['name' => 'Visszárus termék', 'net_price' => 1000, 'price' => 1270, 'vat_rate' => '27']));
        $db->recordPurchase(
            ['payment_method' => 'készpénz'],
            [['product_id' => $product, 'name' => 'X', 'qty' => 1, 'vat_rate' => '27', 'unit_cost_net' => 500, 'unit_cost_gross' => 635]]
        );
        $db->pdo()->exec("UPDATE products SET purchase_price_net = 500 WHERE id = $product");

        $saleId = $db->insertSale(3 * 1270.0, 'Készpénz');
        $itemId = $this->insertSaleItemAndGetId($db, $saleId, $product, 3, 1270, '27');
        $this->backdate($db, 'sales', $saleId, $today . ' 09:00:00');

        // 1 db-ot visszaviszünk -> nettó 2 db marad.
        $returnId = $db->processReturn($saleId, [
            ['sale_item_id' => $itemId, 'product_id' => $product, 'name' => 'X', 'qty' => 1, 'unit_price' => 1270],
        ], 'teszt', null, 1270.0, []);
        $this->backdate($db, 'returns', $returnId, $today . ' 12:00:00');

        $report = $db->getTopProductsReport($today, $today);
        $row = $report[0];
        $this->assertSame(2, $row['qty']);
        $this->assertEqualsWithDelta(2 * 500, $row['cost_net_total'], 0.5, 'A költségnek a NETTÓSÍTOTT (2 db) mennyiségre kell vonatkoznia, nem a bruttó eladott 3 db-ra.');
        $this->assertEqualsWithDelta((1000 - 500) * 2, $row['margin_net'], 1.0);
    }

    public function testSalesMarginSummaryAggregatesAcrossProductsAndReportsUnreliableCount(): void
    {
        $db = tests_new_database();
        $today = date('Y-m-d');

        $withCost = $db->saveProduct($this->sampleProduct(['name' => 'A', 'net_price' => 1000, 'price' => 1270, 'vat_rate' => '27']));
        $db->recordPurchase(['payment_method' => 'készpénz'], [['product_id' => $withCost, 'name' => 'A', 'qty' => 1, 'vat_rate' => '27', 'unit_cost_net' => 400, 'unit_cost_gross' => 508]]);
        $db->pdo()->exec("UPDATE products SET purchase_price_net = 400 WHERE id = $withCost");
        $s1 = $db->insertSale(1270.0, 'Készpénz');
        $db->insertSaleItem($s1, ['product_id' => $withCost, 'name' => 'A', 'qty' => 1, 'unit_price' => 1270, 'vat_rate' => '27']);
        $this->backdate($db, 'sales', $s1, $today . ' 10:00:00');

        $noCost = $db->saveProduct($this->sampleProduct(['name' => 'B', 'net_price' => 500, 'price' => 635, 'vat_rate' => '27']));
        $s2 = $db->insertSale(635.0, 'Készpénz');
        $db->insertSaleItem($s2, ['product_id' => $noCost, 'name' => 'B', 'qty' => 1, 'unit_price' => 635, 'vat_rate' => '27']);
        $this->backdate($db, 'sales', $s2, $today . ' 11:00:00');

        $summary = $db->getSalesMarginSummary($today, $today);
        $this->assertSame(1, $summary['products_with_margin']);
        $this->assertSame(1, $summary['products_without_margin']);
        $this->assertEqualsWithDelta(1000 - 400, $summary['margin_net'], 1.0, 'Az összesítő árrésnek CSAK a megbízható-költségű termékből kell jönnie.');
    }

    private function insertSaleItemAndGetId(Database $db, int $saleId, ?int $productId, int $qty, float $unitPrice, string $vatRate): int
    {
        $db->insertSaleItem($saleId, ['product_id' => $productId, 'name' => 'X', 'qty' => $qty, 'unit_price' => $unitPrice, 'vat_rate' => $vatRate]);
        return (int) $db->pdo()->query('SELECT id FROM sale_items ORDER BY id DESC LIMIT 1')->fetchColumn();
    }

    // -----------------------------------------------------------------
    // Termékszintű árrés több ÁFA-kulcs mellett — a nettósítás helyessége.
    // -----------------------------------------------------------------

    public function testMarginNettingIsCorrectAcrossDifferentVatRates(): void
    {
        $db = tests_new_database();
        $today = date('Y-m-d');

        // 27%-os termék: bruttó 1270 -> nettó 1000.
        $p27 = $db->saveProduct($this->sampleProduct(['name' => '27%-os', 'net_price' => 1000, 'price' => 1270, 'vat_rate' => '27']));
        $db->recordPurchase(['payment_method' => 'készpénz'], [['product_id' => $p27, 'name' => 'X', 'qty' => 1, 'vat_rate' => '27', 'unit_cost_net' => 700, 'unit_cost_gross' => 889]]);
        $db->pdo()->exec("UPDATE products SET purchase_price_net = 700 WHERE id = $p27");
        $s1 = $db->insertSale(1270.0, 'Készpénz');
        $db->insertSaleItem($s1, ['product_id' => $p27, 'name' => 'X', 'qty' => 1, 'unit_price' => 1270, 'vat_rate' => '27']);
        $this->backdate($db, 'sales', $s1, $today . ' 10:00:00');

        // 5%-os termék: bruttó 1050 -> nettó 1000.
        $p5 = $db->saveProduct($this->sampleProduct(['name' => '5%-os', 'net_price' => 1000, 'price' => 1050, 'vat_rate' => '5']));
        $db->recordPurchase(['payment_method' => 'készpénz'], [['product_id' => $p5, 'name' => 'Y', 'qty' => 1, 'vat_rate' => '5', 'unit_cost_net' => 700, 'unit_cost_gross' => 735]]);
        $db->pdo()->exec("UPDATE products SET purchase_price_net = 700 WHERE id = $p5");
        $s2 = $db->insertSale(1050.0, 'Készpénz');
        $db->insertSaleItem($s2, ['product_id' => $p5, 'name' => 'Y', 'qty' => 1, 'unit_price' => 1050, 'vat_rate' => '5']);
        $this->backdate($db, 'sales', $s2, $today . ' 11:00:00');

        $report = $db->getTopProductsReport($today, $today);
        $byId = [];
        foreach ($report as $r) { $byId[$r['product_id']] = $r; }

        // Mindkét termék nettó eladási ára 1000, nettó költsége 700 -> 300
        // Ft árrés MINDKETTŐNÉL, annak ellenére, hogy a bruttó áraik
        // (1270 vs 1050) az eltérő ÁFA-kulcs miatt különböznek.
        $this->assertEqualsWithDelta(1000.0, $byId[$p27]['revenue_net'], 0.5);
        $this->assertEqualsWithDelta(1000.0, $byId[$p5]['revenue_net'], 0.5);
        $this->assertEqualsWithDelta(300.0, $byId[$p27]['margin_net'], 0.5);
        $this->assertEqualsWithDelta(300.0, $byId[$p5]['margin_net'], 0.5);
    }

    // -----------------------------------------------------------------
    // Készletérték
    // -----------------------------------------------------------------

    public function testInventoryValuationSummarySeparatesReliableFromUnreliableCost(): void
    {
        $db = tests_new_database();

        // recordPurchase() maga is növeli a készletet (applyPurchaseLine) —
        // NEM adunk hozzá külön incrementStock()-ot is, hogy a végső
        // készlet pontosan a beszerzett mennyiség (10 db) legyen.
        $withCost = $db->saveProduct($this->sampleProduct(['name' => 'A', 'net_price' => 1000, 'price' => 1270]));
        $db->recordPurchase(['payment_method' => 'készpénz'], [['product_id' => $withCost, 'name' => 'A', 'qty' => 10, 'vat_rate' => '27', 'unit_cost_net' => 600, 'unit_cost_gross' => 762]]);
        $db->pdo()->exec("UPDATE products SET purchase_price_net = 600 WHERE id = $withCost");

        $noCost = $db->saveProduct($this->sampleProduct(['name' => 'B', 'net_price' => 500, 'price' => 635]));
        $db->incrementStock($noCost, 20);

        $summary = $db->getInventoryValuationSummary();
        $this->assertEqualsWithDelta(10 * 600, $summary['cost_value_net'], 0.5, 'A költségérték csak a megbízható költségű termékből számoljon.');
        $this->assertEqualsWithDelta((10 * 1000) + (20 * 500), $summary['retail_value_net'], 0.5, 'Az eladási érték minden készleten lévő termékből számol.');
        $this->assertEqualsWithDelta(10 * (1000 - 600), $summary['potential_margin_value_net'], 0.5);
        $this->assertSame(1, $summary['products_with_reliable_cost']);
        $this->assertSame(2, $summary['products_total']);
    }

    public function testInventoryValuationSummaryHandlesEmptyInventory(): void
    {
        $db = tests_new_database();
        $summary = $db->getInventoryValuationSummary();
        $this->assertSame(0.0, $summary['cost_value_net']);
        $this->assertSame(0.0, $summary['retail_value_net']);
        $this->assertSame(0, $summary['products_total']);
    }

    // -----------------------------------------------------------------
    // Termék mini-dashboard (getProductInsights) — egyetlen hívás
    // -----------------------------------------------------------------

    public function testProductInsightsReturnsConsolidatedDataInOneCall(): void
    {
        $db = tests_new_database();
        $today = date('Y-m-d');

        // A recordPurchase() maga is növeli a készletet (+5) — 3-ról indulva
        // a végső készlet pontosan 8 lesz, a küszöb (10) alatt.
        $product = $db->saveProduct($this->sampleProduct(['name' => 'Mini-dashboard termék', 'net_price' => 1000, 'price' => 1270, 'low_stock_threshold' => 10]));
        $db->incrementStock($product, 3);
        $db->recordPurchase(['payment_method' => 'készpénz'], [['product_id' => $product, 'name' => 'X', 'qty' => 5, 'vat_rate' => '27', 'unit_cost_net' => 650, 'unit_cost_gross' => 826]]);
        $db->pdo()->exec("UPDATE products SET purchase_price_net = 650 WHERE id = $product");

        $saleId = $db->insertSale(1270.0, 'Készpénz');
        $db->insertSaleItem($saleId, ['product_id' => $product, 'name' => 'X', 'qty' => 1, 'unit_price' => 1270, 'vat_rate' => '27']);
        $this->backdate($db, 'sales', $saleId, $today . ' 10:00:00');

        $insights = $db->getProductInsights($product, 5);

        $this->assertSame(8, $insights['status']['stock_qty']);
        $this->assertTrue($insights['status']['has_cost_history']);
        $this->assertEqualsWithDelta(1000 - 650, $insights['status']['margin_ft'], 0.5);
        $this->assertSame(1, $insights['sales']['last_30_days']['qty']);
        $this->assertSame(1, $insights['sales']['last_90_days']['qty']);
        $this->assertCount(1, $insights['recent_purchases']);
        $this->assertCount(1, $insights['price_trend']);
        $this->assertSame('low', $insights['urgency'], 'A készlet (8) a küszöb (10) alatt van, de nincs elég eladási előzmény a pontos sürgősséghez.');
    }

    public function testProductInsightsForNonexistentProductReturnsEmptyArray(): void
    {
        $db = tests_new_database();
        $this->assertSame([], $db->getProductInsights(999999, 5));
    }
}
