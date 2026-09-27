<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Phase 5 remediáció — DB-07: az értékesítési riport minden blokkja (az
 * összesítő, az árrés-blokk, a Top termékek / kategóriák, a termékenkénti
 * forgalom) UGYANAZT a forgalom-definíciót használja: az eladás értékének
 * (kupon/pont/hűségszint-kedvezmény után, az utalványos résszel együtt)
 * VatAllocation-allokációja az eladáskori ÁFA-kulccsal, a visszáru a tárolt
 * allokált értékkel, ugyanazzal a dátum- és fizetésimód-szűréssel.
 */
final class ReportRevenueConsistencyTest extends TestCase
{
    private function product(Database $db, float $price, string $vat, float $cost = 0.0): int
    {
        $id = $db->saveProduct(['name' => 'DB07 ' . bin2hex(random_bytes(3)), 'barcode' => 'DB07-' . bin2hex(random_bytes(4)), 'price' => $price, 'net_price' => round($price / 1.27, 2), 'vat_rate' => $vat]);
        $db->setStock($id, 100);
        if ($cost > 0) {
            $db->pdo()->prepare('UPDATE products SET purchase_price_net = ? WHERE id = ?')->execute([$cost, $id]);
        }
        return $id;
    }

    /** @param list<array{0:?int,1:int,2:float,3:string}> $lines [productId|null, qty, unit, vat] */
    private function sale(Database $db, array $lines, float $paid, string $method, float $coupon = 0.0, float $gift = 0.0): int
    {
        $db->beginTransaction();
        $id = $db->insertSale($paid, $method, null, null, 0, 0, null, $coupon, $gift);
        foreach ($lines as [$pid, $qty, $unit, $vat]) {
            $db->insertSaleItem($id, ['product_id' => $pid, 'name' => 'DB07', 'qty' => $qty, 'unit_price' => $unit, 'vat_rate' => $vat]);
        }
        $db->commit();
        return $id;
    }

    private function scenario(Database $db): array
    {
        $p27 = $this->product($db, 10.0, '27', 5.0);
        $p5 = $this->product($db, 30.0, '5', 20.0);
        // 3 × 10 Ft (27%) + 1 × 30 Ft (5%), 20 Ft kupon, 5 Ft utalvány, készpénz
        $s1 = $this->sale($db, [[$p27, 3, 10.0, '27'], [$p5, 1, 30.0, '5']], 35.0, 'Készpénz', 20.0, 5.0);
        // 2 × 10 Ft kártyával, kedvezmény nélkül + egy kézi (termék nélküli) sor
        $s2 = $this->sale($db, [[$p27, 2, 10.0, '27'], [null, 1, 15.0, '27']], 35.0, 'Bankkártya');
        // részleges visszáru az 1. eladásból (1 db a 27%-os sorból)
        $sale = $db->getSaleWithItems($s1);
        $line = $sale['items'][0];
        $db->processReturn($s1, [['sale_item_id' => (int) $line['id'], 'product_id' => $p27, 'name' => 'DB07', 'qty' => 1, 'unit_price' => 10.0]], 'r', null, 0.0, $sale);
        return [$p27, $p5];
    }

    public function testAllReportBlocksAgreeOnNetRevenueForTheSamePeriodAndFilter(): void
    {
        $db = tests_new_database();
        $this->scenario($db);
        $today = date('Y-m-d');

        foreach ([null, 'Készpénz', 'Bankkártya'] as $method) {
            $summary = $db->getSalesReportSummary($today, $today, $method);
            $margin = $db->getSalesMarginSummary($today, $today, $method);
            $top = $db->getTopProductsReport($today, $today, null, -1000, 50, $method);
            // a kézi (termék nélküli) sor a kártyás eladásban van: kedvezmény nélkül, 15 Ft bruttó → nettó 11.81
            $manualNet = $method === 'Készpénz' ? 0.0 : round(15.0 / 1.27, 2);
            $productsNet = round(array_sum(array_column($top, 'revenue_net')), 2);

            $this->assertEqualsWithDelta($summary['total_net'], round($productsNet + $manualNet, 2), 0.011, "összesítő vs termékek ($method)");
            $this->assertEqualsWithDelta($productsNet, $margin['revenue_net'], 0.001, "árrés-blokk vs termékek ($method)");
        }
    }

    public function testPaymentMethodFilterNarrowsEveryBlock(): void
    {
        $db = tests_new_database();
        $this->scenario($db);
        $today = date('Y-m-d');
        $all = $db->getSalesMarginSummary($today, $today);
        $cash = $db->getSalesMarginSummary($today, $today, 'Készpénz');
        $card = $db->getSalesMarginSummary($today, $today, 'Bankkártya');

        $this->assertLessThan($all['revenue_net'], $cash['revenue_net']);
        $this->assertEqualsWithDelta($all['revenue_net'], $cash['revenue_net'] + $card['revenue_net'], 0.001);
    }

    public function testTopProductsRevenueIsTheDiscountedAllocatedValue(): void
    {
        $db = tests_new_database();
        $pid = $this->product($db, 10.0, '27');
        $this->sale($db, [[$pid, 3, 10.0, '27']], 10.0, 'Készpénz', 20.0); // 3 × 10, 20 Ft kupon
        $today = date('Y-m-d');

        $top = $db->getTopProductsReport($today, $today);
        $summary = $db->getSalesReportSummary($today, $today);

        $this->assertSame(10.0, $top[0]['revenue'], 'kedvezmény után, nem a 30 Ft listaár');
        $this->assertSame($summary['total_net'], $top[0]['revenue_net']);
        $this->assertSame(['date_from' => $today, 'date_to' => $today, 'qty' => 3, 'revenue' => 10.0], $db->getProductSalesInRange($pid, $today, $today));
    }

    public function testFullyReturnedSaleNetsToZeroInEveryBlockAndDateRangeIsRespected(): void
    {
        $db = tests_new_database();
        $pid = $this->product($db, 10.0, '27', 4.0);
        $saleId = $this->sale($db, [[$pid, 2, 10.0, '27']], 18.0, 'Készpénz', 2.0);
        $sale = $db->getSaleWithItems($saleId);
        $db->processReturn($saleId, [['sale_item_id' => (int) $sale['items'][0]['id'], 'product_id' => $pid, 'name' => 'DB07', 'qty' => 2, 'unit_price' => 10.0]], 'teljes', null, 0.0, $sale);
        $today = date('Y-m-d');

        $top = $db->getTopProductsReport($today, $today, null, -1000);
        $this->assertSame(0, $top[0]['qty']);
        $this->assertSame(0.0, $top[0]['revenue']);
        $this->assertSame(0.0, $db->getSalesMarginSummary($today, $today)['revenue_net']);
        $this->assertSame([], $db->getTopProductsReport('2000-01-01', '2000-01-31'));
    }
}
