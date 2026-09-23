<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * A SalesTools eszközök determinisztikus, valódi adatbázis-alapú tesztjei
 * (Fázis 4) — lásd AiInventoryToolsTest.php azonos indoklását: minden
 * konkrét szám (forgalom, visszáru, %-os változás) a MEGLÉVŐ Database-
 * rétegből (vagy az arra épülő, kis, új Database-metódusokból) kell
 * jöjjön, SOSE a modellből. Nincs LLM/provider ebben a fájlban —
 * kizárólag a tool handler-eket teszteljük közvetlenül.
 */
final class AiSalesToolsTest extends TestCase
{
    private function seedProduct(Database $db, string $name, string $groupName = 'Italok', float $purchasePriceNet = 500.0): int
    {
        $pdo = $db->pdo();
        $stmt = $pdo->prepare('
            INSERT INTO products (name, unit, price, net_price, stock_qty, group_name, vat_rate, purchase_price_net)
            VALUES (?, "db", 1000, 787, 100, ?, "27", ?)
        ');
        $stmt->execute([$name, $groupName, $purchasePriceNet]);
        return (int) $pdo->lastInsertId();
    }

    /** @return int a létrehozott eladás azonosítója */
    private function seedSale(Database $db, int $productId, int $qty, float $unitPrice, string $createdAt, string $paymentMethod = 'Készpénz'): int
    {
        $pdo = $db->pdo();
        $pdo->prepare("INSERT INTO sales (total, payment_method, created_at) VALUES (?, ?, ?)")
            ->execute([$qty * $unitPrice, $paymentMethod, $createdAt]);
        $saleId = (int) $pdo->lastInsertId();
        $pdo->prepare('INSERT INTO sale_items (sale_id, product_id, name, qty, unit_price, vat_rate) VALUES (?, ?, "teszt", ?, ?, "27")')
            ->execute([$saleId, $productId, $qty, $unitPrice]);
        return $saleId;
    }

    private function seedReturn(Database $db, int $saleId, int $productId, int $qty, float $unitPrice, string $createdAt): int
    {
        $pdo = $db->pdo();
        $totalRefund = $qty * $unitPrice;
        $pdo->prepare('INSERT INTO returns (sale_id, total_refund, created_at) VALUES (?, ?, ?)')
            ->execute([$saleId, $totalRefund, $createdAt]);
        $returnId = (int) $pdo->lastInsertId();
        $pdo->prepare('INSERT INTO return_items (return_id, product_id, name, qty, unit_price, created_at) VALUES (?, ?, "teszt", ?, ?, ?)')
            ->execute([$returnId, $productId, $qty, $unitPrice, $createdAt]);
        return $returnId;
    }

    private function tools(Database $db): SalesTools
    {
        return new SalesTools($db, []);
    }

    // ------------------------------------------------------------------
    // get_sales_summary — bruttó/nettó/visszáru/kosárérték szemantika
    // ------------------------------------------------------------------

    public function testSalesSummaryGrossNetReturnsSemantics(): void
    {
        $db = tests_new_database();
        $pid = $this->seedProduct($db, 'Termék A');
        $saleId = $this->seedSale($db, $pid, 2, 1000.0, '2026-09-23 10:00:00');
        $this->seedReturn($db, $saleId, $pid, 1, 1000.0, '2026-09-23 11:00:00');

        $result = $this->tools($db)->getSalesSummary(['date_from' => '2026-09-23', 'date_to' => '2026-09-23']);

        // Az eladás bruttó összege 2000, a visszáru 1000 — a MEGLÉVŐ
        // Database::getSalesReportSummary() a visszárut MÁR levonja a
        // "gross" mezőből (lásd ott a forráskódot/docblokkot) — a
        // SalesTools ezt a definíciót változatlanul veszi át.
        $this->assertSame(1000.0, $result['gross_sales']);
        $this->assertSame(1000.0, $result['returns']);
        $this->assertSame(1, $result['transactions']);
        $this->assertIsFloat($result['net_sales']);
        $this->assertLessThan($result['gross_sales'] + 1, $result['net_sales']); // nettó <= bruttó (áfa nélkül)
        $this->assertArrayHasKey('avg_basket', $result);
        $this->assertArrayHasKey('by_payment_method', $result);
    }

    public function testSalesSummaryAvgBasketIsGrossDividedByTransactionCount(): void
    {
        $db = tests_new_database();
        $pid = $this->seedProduct($db, 'Termék A');
        $this->seedSale($db, $pid, 1, 1000.0, '2026-09-23 10:00:00');
        $this->seedSale($db, $pid, 1, 3000.0, '2026-09-23 11:00:00');

        $result = $this->tools($db)->getSalesSummary(['date_from' => '2026-09-23', 'date_to' => '2026-09-23']);

        $this->assertSame(2, $result['transactions']);
        $this->assertSame(4000.0, $result['gross_sales']);
        $this->assertSame(2000.0, $result['avg_basket']);
    }

    public function testSalesSummaryEmptyPeriodReturnsZeroesNotError(): void
    {
        $db = tests_new_database();
        $result = $this->tools($db)->getSalesSummary(['date_from' => '2026-01-01', 'date_to' => '2026-01-02']);

        $this->assertSame(0, $result['transactions']);
        $this->assertSame(0.0, $result['gross_sales']);
        $this->assertSame(0.0, $result['net_sales']);
        $this->assertSame(0.0, $result['returns']);
    }

    public function testSalesSummaryAcceptsPeriodKeywordInsteadOfExplicitDates(): void
    {
        $db = tests_new_database();
        $pid = $this->seedProduct($db, 'Termék A');
        $this->seedSale($db, $pid, 1, 1000.0, date('Y-m-d') . ' 10:00:00');

        $result = $this->tools($db)->getSalesSummary(['period' => 'today']);

        $this->assertSame(1, $result['transactions']);
        $this->assertSame(date('Y-m-d'), $result['date_from']);
    }

    // ------------------------------------------------------------------
    // compare_sales_periods — a %-os változást a backend számítja
    // ------------------------------------------------------------------

    public function testComparePeriodsComputesAbsoluteAndPercentageChangeInBackend(): void
    {
        $db = tests_new_database();
        $pid = $this->seedProduct($db, 'Termék A');
        $this->seedSale($db, $pid, 1, 1000.0, '2026-08-01 10:00:00'); // előző hónap
        $this->seedSale($db, $pid, 1, 1500.0, '2026-09-01 10:00:00'); // ez a hónap

        $result = $this->tools($db)->compareSalesPeriods([
            'current_from' => '2026-09-01', 'current_to' => '2026-09-30',
            'previous_from' => '2026-08-01', 'previous_to' => '2026-08-31',
        ]);

        $this->assertSame(1500.0, $result['current']['gross_sales']);
        $this->assertSame(1000.0, $result['previous']['gross_sales']);
        $this->assertSame(500.0, $result['diff']['gross_sales_change']);
        $this->assertSame(50.0, $result['diff']['gross_sales_change_pct']);
    }

    public function testComparePeriodsAcceptsPeriodKeywordsForBothSides(): void
    {
        $db = tests_new_database();
        $pid = $this->seedProduct($db, 'Termék A');
        $this->seedSale($db, $pid, 1, 1000.0, date('Y-m-d') . ' 10:00:00');

        $result = $this->tools($db)->compareSalesPeriods([
            'current_period' => 'today',
            'previous_period' => 'yesterday',
        ]);

        $this->assertSame(1, $result['current']['transactions']);
        $this->assertSame(0, $result['previous']['transactions']);
    }

    public function testComparePeriodsPercentageChangeIsNullWhenPreviousIsZero(): void
    {
        $db = tests_new_database();
        $pid = $this->seedProduct($db, 'Termék A');
        $this->seedSale($db, $pid, 1, 1000.0, '2026-09-01 10:00:00');

        $result = $this->tools($db)->compareSalesPeriods([
            'current_from' => '2026-09-01', 'current_to' => '2026-09-01',
            'previous_from' => '2026-08-01', 'previous_to' => '2026-08-01',
        ]);

        $this->assertNull($result['diff']['gross_sales_change_pct']);
        $this->assertSame(1000.0, $result['diff']['gross_sales_change']);
    }

    // ------------------------------------------------------------------
    // get_top_selling_products
    // ------------------------------------------------------------------

    public function testTopSellingProductsOrderedByQuantityDescending(): void
    {
        $db = tests_new_database();
        $pidA = $this->seedProduct($db, 'Kevésbé népszerű');
        $pidB = $this->seedProduct($db, 'Népszerű');
        $this->seedSale($db, $pidA, 1, 1000.0, '2026-09-23 10:00:00');
        $this->seedSale($db, $pidB, 5, 1000.0, '2026-09-23 11:00:00');

        $result = $this->tools($db)->getTopSellingProducts(['date_from' => '2026-09-23', 'date_to' => '2026-09-23']);

        $this->assertSame(2, $result['count']);
        $this->assertSame('Népszerű', $result['products'][0]['name']);
        $this->assertSame(5, $result['products'][0]['qty']);
    }

    public function testTopSellingProductsInvalidLimitIsClampedNotRejected(): void
    {
        $db = tests_new_database();
        $pid = $this->seedProduct($db, 'Termék A');
        $this->seedSale($db, $pid, 1, 1000.0, '2026-09-23 10:00:00');

        $tooLarge = $this->tools($db)->getTopSellingProducts(['date_from' => '2026-09-23', 'date_to' => '2026-09-23', 'limit' => 99999]);
        $this->assertLessThanOrEqual(50, count($tooLarge['products']));

        $negative = $this->tools($db)->getTopSellingProducts(['date_from' => '2026-09-23', 'date_to' => '2026-09-23', 'limit' => -5]);
        $this->assertLessThanOrEqual(1, count($negative['products']), 'Negatív limit is legalább 1-re kell záródjon, sose okozzon hibát vagy 0-nál kisebb limitet.');
    }

    // ------------------------------------------------------------------
    // get_top_categories
    // ------------------------------------------------------------------

    public function testTopCategoriesAggregatesByProductGroupName(): void
    {
        $db = tests_new_database();
        $pidDrink1 = $this->seedProduct($db, 'Kóla', 'Italok');
        $pidDrink2 = $this->seedProduct($db, 'Szörp', 'Italok');
        $pidSnack = $this->seedProduct($db, 'Chips', 'Snack');
        $this->seedSale($db, $pidDrink1, 2, 1000.0, '2026-09-23 10:00:00');
        $this->seedSale($db, $pidDrink2, 1, 1000.0, '2026-09-23 10:05:00');
        $this->seedSale($db, $pidSnack, 1, 500.0, '2026-09-23 10:10:00');

        $result = $this->tools($db)->getTopCategories(['date_from' => '2026-09-23', 'date_to' => '2026-09-23']);

        $this->assertSame(2, $result['count']);
        $this->assertSame('Italok', $result['categories'][0]['category']);
        $this->assertSame(3000.0, $result['categories'][0]['revenue']);
        $this->assertSame(2, $result['categories'][0]['product_count']);
    }

    // ------------------------------------------------------------------
    // get_sales_by_hour
    // ------------------------------------------------------------------

    public function testSalesByHourAggregatesAndComputesBusiestHourDeterministically(): void
    {
        $db = tests_new_database();
        $pid = $this->seedProduct($db, 'Termék A');
        $this->seedSale($db, $pid, 1, 1000.0, '2026-09-23 08:15:00');
        $this->seedSale($db, $pid, 1, 5000.0, '2026-09-23 14:30:00');
        $this->seedSale($db, $pid, 1, 200.0, '2026-09-23 14:45:00');

        $result = $this->tools($db)->getSalesByHour(['date_from' => '2026-09-23', 'date_to' => '2026-09-23']);

        $this->assertCount(24, $result['hours']);
        $this->assertSame(8, $result['hours'][8]['hour']);
        $this->assertSame(1, $result['hours'][8]['count']);
        $this->assertSame(2, $result['hours'][14]['count']);
        $this->assertSame(5200.0, $result['hours'][14]['gross']);
        $this->assertNotNull($result['busiest_hour']);
        $this->assertSame(14, $result['busiest_hour']['hour']);
    }

    public function testSalesByHourWithNoDataReturnsAllZeroHoursNotError(): void
    {
        $db = tests_new_database();
        $result = $this->tools($db)->getSalesByHour(['date_from' => '2026-01-01', 'date_to' => '2026-01-01']);

        $this->assertCount(24, $result['hours']);
        foreach ($result['hours'] as $h) {
            $this->assertSame(0, $h['count']);
        }
    }

    // ------------------------------------------------------------------
    // get_product_sales_trend
    // ------------------------------------------------------------------

    public function testProductSalesTrendWithoutComparison(): void
    {
        $db = tests_new_database();
        $pid = $this->seedProduct($db, 'Termék A');
        $this->seedSale($db, $pid, 3, 1000.0, '2026-09-23 10:00:00');

        $result = $this->tools($db)->getProductSalesTrend(['product_id' => $pid, 'date_from' => '2026-09-23', 'date_to' => '2026-09-23']);

        $this->assertSame($pid, $result['product_id']);
        $this->assertSame(3, $result['current']['qty']);
        $this->assertArrayNotHasKey('previous', $result);
    }

    public function testProductSalesTrendWithComparisonComputesDeclinePercentage(): void
    {
        $db = tests_new_database();
        $pid = $this->seedProduct($db, 'Visszaeső termék');
        $this->seedSale($db, $pid, 10, 1000.0, '2026-08-15 10:00:00');
        $this->seedSale($db, $pid, 2, 1000.0, '2026-09-15 10:00:00');

        $result = $this->tools($db)->getProductSalesTrend([
            'product_id' => $pid,
            'date_from' => '2026-09-01', 'date_to' => '2026-09-30',
            'compare_date_from' => '2026-08-01', 'compare_date_to' => '2026-08-31',
        ]);

        $this->assertSame(2, $result['current']['qty']);
        $this->assertSame(10, $result['previous']['qty']);
        $this->assertSame(-8, $result['qty_change']);
        $this->assertSame(-80.0, $result['qty_change_pct']);
    }

    public function testProductSalesTrendInvalidProductThrows(): void
    {
        $db = tests_new_database();
        $this->expectException(InvalidArgumentException::class);
        $this->tools($db)->getProductSalesTrend(['product_id' => 999999, 'date_from' => '2026-09-01', 'date_to' => '2026-09-30']);
    }

    public function testProductSalesTrendMissingProductIdThrows(): void
    {
        $db = tests_new_database();
        $this->expectException(InvalidArgumentException::class);
        $this->tools($db)->getProductSalesTrend(['date_from' => '2026-09-01', 'date_to' => '2026-09-30']);
    }

    // ------------------------------------------------------------------
    // get_returns_summary
    // ------------------------------------------------------------------

    public function testReturnsSummaryComputesRatioAgainstPreReturnGross(): void
    {
        $db = tests_new_database();
        $pid = $this->seedProduct($db, 'Termék A');
        $saleId = $this->seedSale($db, $pid, 4, 1000.0, '2026-09-23 10:00:00'); // 4000 bruttó
        $this->seedReturn($db, $saleId, $pid, 1, 1000.0, '2026-09-23 11:00:00'); // 1000 visszáru

        $result = $this->tools($db)->getReturnsSummary(['date_from' => '2026-09-23', 'date_to' => '2026-09-23']);

        $this->assertSame(1000.0, $result['returns_amount']);
        $this->assertSame(1, $result['returns_count']);
        $this->assertSame(4000.0, $result['gross_sales_before_returns']);
        $this->assertSame(25.0, $result['return_ratio_pct']); // 1000 / 4000 * 100
    }

    public function testReturnsSummaryWithNoReturnsIsZeroRatioNotError(): void
    {
        $db = tests_new_database();
        $pid = $this->seedProduct($db, 'Termék A');
        $this->seedSale($db, $pid, 1, 1000.0, '2026-09-23 10:00:00');

        $result = $this->tools($db)->getReturnsSummary(['date_from' => '2026-09-23', 'date_to' => '2026-09-23']);

        $this->assertSame(0.0, $result['returns_amount']);
        $this->assertSame(0, $result['returns_count']);
        $this->assertSame(0.0, $result['return_ratio_pct']);
    }

    // ------------------------------------------------------------------
    // Dátumtartomány-validáció (kör 5. pontja) — MINDEN eszközre közös,
    // a resolveDateRange()-en/ReportPeriod-on keresztül, itt egy
    // reprezentatív eszközzel (get_sales_summary) bizonyítva.
    // ------------------------------------------------------------------

    public function testInvalidDateFormatIsRejected(): void
    {
        $db = tests_new_database();
        $this->expectException(InvalidArgumentException::class);
        $this->tools($db)->getSalesSummary(['date_from' => 'nem-datum', 'date_to' => '2026-09-23']);
    }

    public function testFromAfterToIsRejected(): void
    {
        $db = tests_new_database();
        $this->expectException(InvalidArgumentException::class);
        $this->tools($db)->getSalesSummary(['date_from' => '2026-09-23', 'date_to' => '2026-01-01']);
    }

    public function testMissingBothPeriodAndDatesIsRejected(): void
    {
        $db = tests_new_database();
        $this->expectException(InvalidArgumentException::class);
        $this->tools($db)->getSalesSummary([]);
    }

    public function testFullyFutureDateRangeIsRejectedWithClearMessage(): void
    {
        $db = tests_new_database();
        $farFuture = date('Y-m-d', strtotime('+30 days'));
        $farFuture2 = date('Y-m-d', strtotime('+40 days'));
        try {
            $this->tools($db)->getSalesSummary(['date_from' => $farFuture, 'date_to' => $farFuture2]);
            $this->fail('InvalidArgumentException várt volt.');
        } catch (InvalidArgumentException $e) {
            $this->assertStringContainsString('jövőben', $e->getMessage());
        }
    }

    public function testOverFiveYearRangeIsRejected(): void
    {
        $db = tests_new_database();
        $this->expectException(InvalidArgumentException::class);
        $this->tools($db)->getSalesSummary(['date_from' => '2000-01-01', 'date_to' => '2026-01-01']);
    }

    public function testInvalidPeriodKeywordIsRejected(): void
    {
        $db = tests_new_database();
        $this->expectException(InvalidArgumentException::class);
        $this->tools($db)->getSalesSummary(['period' => 'not_a_real_period']);
    }

    public function testUnknownProductForTrendIsRejectedNotSilentlyEmpty(): void
    {
        $db = tests_new_database();
        $this->expectException(InvalidArgumentException::class);
        $this->tools($db)->getProductSalesTrend(['product_id' => -1, 'date_from' => '2026-09-01', 'date_to' => '2026-09-30']);
    }
}
