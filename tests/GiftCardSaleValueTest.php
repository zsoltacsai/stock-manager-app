<?php

declare(strict_types=1);

require_once __DIR__ . '/../src/Ai/Tools/SalesTools.php';

use PHPUnit\Framework\TestCase;

/**
 * B-06 (correctness audit) regresszió — az ajándékutalvány fizetési eszköz,
 * nem kedvezmény.
 *
 * Értékdefiníció (Database::saleGrossValue()): az eladás értéke =
 * sales.total (a fizetési módon befizetett rész) + sales.gift_card_redeemed.
 * A visszáru értéke (returnGrossValue()) = returns.total_refund +
 * returns.gift_card_refund. Ezt használja a napi zárás, az értékesítési
 * riport (és rajta keresztül a Dashboard és az AI SalesTools), a trend és
 * az órás bontás. A kasszaegyenleg (computeExpectedCash()) továbbra is
 * csak a fizetési módon mozgott pénzt nézi.
 *
 * A sales-sorokat itt úgy rögzítjük, ahogy sale.php teszi: total = a
 * kedvezmények és az utalvány UTÁN fizetendő, gift_card_redeemed = az
 * utalvánnyal fedezett rész.
 */
final class GiftCardSaleValueTest extends TestCase
{
    private function sale(Database $db, float $paid, float $giftCard, float $unitPrice = 1270.0, int $qty = 1, ?int $couponId = null, float $couponDiscount = 0.0, string $method = 'Készpénz', ?int $registerId = null, string $vat = '27'): int
    {
        $saleId = $db->insertSale($paid, $method, null, null, 0, 0, $couponId, $couponDiscount, $giftCard, null, null, null, $registerId);
        $db->insertSaleItem($saleId, ['product_id' => null, 'name' => 'Tétel', 'qty' => $qty, 'unit_price' => $unitPrice, 'vat_rate' => $vat]);
        return $saleId;
    }

    private function today(): string
    {
        return date('Y-m-d');
    }

    public function testNormalCashSaleIsUnchanged(): void
    {
        $db = tests_new_database();
        $this->sale($db, 1270.0, 0.0);

        $s = $db->getDailySummary($this->today());
        $this->assertSame(1270.0, $s['total_gross']);
        $this->assertEqualsWithDelta(1000.0, $s['total_net'], 0.01);
        $this->assertEqualsWithDelta(270.0, $s['total_vat'], 0.01);
        $this->assertSame(['Készpénz'], array_keys($s['by_payment_method']));
    }

    public function testPartiallyGiftCardPaidSaleCountsFullValue(): void
    {
        $db = tests_new_database();
        $this->sale($db, 770.0, 500.0);

        $s = $db->getDailySummary($this->today());
        $this->assertSame(1270.0, $s['total_gross']);
        $this->assertEqualsWithDelta(270.0, $s['total_vat'], 0.01, 'Az ÁFA a teljes eladási értékre számol, nem a befizetett részre.');
        $this->assertSame(770.0, $s['by_payment_method']['Készpénz']['total']);
        $this->assertSame(500.0, $s['by_payment_method'][Database::GIFT_CARD_PAYMENT_LABEL]['total']);
        $this->assertSame(1270.0, array_sum(array_column($s['by_payment_method'], 'total')), 'A fizetési bontás összege az eladás értéke.');
    }

    public function testFullyGiftCardPaidSaleIsNoLongerZeroRevenue(): void
    {
        $db = tests_new_database();
        $this->sale($db, 0.0, 1000.0, 1000.0);

        $s = $db->getDailySummary($this->today());
        // Az audit reprodukciója: korábban gross 0, 27% nettó 0 / ÁFA 0.
        $this->assertSame(1000.0, $s['total_gross']);
        $this->assertEqualsWithDelta(787.40, $s['by_vat_rate']['27']['net'], 0.01);
        $this->assertEqualsWithDelta(212.60, $s['by_vat_rate']['27']['vat'], 0.01);
    }

    public function testDiscountPlusGiftCardUsesPostDiscountValue(): void
    {
        // 1270-es termék, 270 kedvezmény (pl. hűségpont/hűségszint), 500 utalvány,
        // 500 készpénz → az eladás értéke 1000 (a kedvezmény csökkenti, az utalvány nem).
        $db = tests_new_database();
        $this->sale($db, 500.0, 500.0);

        $s = $db->getDailySummary($this->today());
        $this->assertSame(1000.0, $s['total_gross']);
        $this->assertEqualsWithDelta(787.40, $s['total_net'], 0.01);
    }

    public function testCouponPlusGiftCard(): void
    {
        $db = tests_new_database();
        $couponId = $db->saveCoupon(['code' => 'B06', 'type' => 'fixed', 'value' => 270, 'is_active' => true]);
        $db->incrementCouponUsage($couponId);
        $this->sale($db, 300.0, 700.0, 1270.0, 1, $couponId, 270.0);

        $r = $db->getSalesReportSummary($this->today(), $this->today());
        $this->assertSame(1000.0, $r['total_gross']);
        $this->assertEqualsWithDelta(787.40, $r['total_net'], 0.01);
        $this->assertSame(300.0, $r['by_payment_method']['Készpénz']['total']);
        $this->assertSame(700.0, $r['by_payment_method'][Database::GIFT_CARD_PAYMENT_LABEL]['total']);
    }

    public function testFullReturnOfGiftCardPaidSaleNetsToZeroAndRecordsTheCardRefund(): void
    {
        $db = tests_new_database();
        $cardId = $db->issueGiftCard('B06CARD', 2000.0, null, null);
        $saleId = $this->sale($db, 270.0, 1000.0);
        $db->redeemGiftCard($cardId, 1000.0, $saleId);
        $sale = $db->getSaleWithItems($saleId);
        $returnId = $db->processReturn($saleId, [['sale_item_id' => (int) $sale['items'][0]['id'], 'product_id' => null, 'name' => 'Tétel', 'qty' => 1, 'unit_price' => 1270]], 'x', null, 270.0, $sale);

        $row = $db->pdo()->query("SELECT total_refund, gift_card_refund FROM returns WHERE id = $returnId")->fetch(PDO::FETCH_ASSOC);
        $this->assertSame([270.0, 1000.0], [(float) $row['total_refund'], (float) $row['gift_card_refund']]);
        $this->assertSame(2000.0, (float) $db->findGiftCardByCode('B06CARD')['current_balance']);

        foreach ([$db->getDailySummary($this->today()), $db->getSalesReportSummary($this->today(), $this->today())] as $summary) {
            $this->assertSame(0.0, $summary['total_gross']);
            $this->assertEqualsWithDelta(0.0, $summary['total_net'], 0.02);
            $this->assertEqualsWithDelta(0.0, $summary['total_vat'], 0.02);
            $this->assertSame(1270.0, $summary['total_returns']);
            $this->assertSame(0.0, $summary['by_payment_method']['Készpénz']['total']);
            $this->assertSame(0.0, $summary['by_payment_method'][Database::GIFT_CARD_PAYMENT_LABEL]['total']);
        }
    }

    public function testPartialReturnOfGiftCardSaleKeepsExistingRefundPolicy(): void
    {
        // Dokumentált, változatlan szabály: az utalvány csak a TELJES
        // visszavételkor íródik vissza — részleges visszárunál a fizetési
        // módon arányos rész jár vissza (return-create.php).
        $db = tests_new_database();
        $cardId = $db->issueGiftCard('B06P', 1000.0, null, null);
        $saleId = $this->sale($db, 1540.0, 1000.0, 1270.0, 2);
        $db->redeemGiftCard($cardId, 1000.0, $saleId);
        $sale = $db->getSaleWithItems($saleId);
        $returnId = $db->processReturn($saleId, [['sale_item_id' => (int) $sale['items'][0]['id'], 'product_id' => null, 'name' => 'Tétel', 'qty' => 1, 'unit_price' => 1270]], 'x', null, 770.0, $sale);

        $this->assertSame(0.0, (float) $db->pdo()->query("SELECT gift_card_refund FROM returns WHERE id = $returnId")->fetchColumn());
        $this->assertSame(0.0, (float) $db->findGiftCardByCode('B06P')['current_balance']);
    }

    public function testCashSessionExpectedCashCountsOnlyTheCashPart(): void
    {
        $db = tests_new_database();
        $loc = $db->saveLocation(['name' => 'Bolt']);
        $registerId = $db->saveCashRegister(['location_id' => $loc, 'name' => 'Kassza', 'code' => 'K1']);
        $sessionId = $db->openCashSession($registerId, null, 10000.0);
        $this->sale($db, 770.0, 500.0, 1270.0, 1, null, 0.0, 'Készpénz', $registerId);

        $this->assertSame(10770.0, $db->computeExpectedCash($db->getCashSession($sessionId), ['Készpénz']));
    }

    public function testDailyCloseAndSalesReportUseTheSameValueDefinition(): void
    {
        $db = tests_new_database();
        $this->sale($db, 1270.0, 0.0);
        $this->sale($db, 0.0, 635.0, 635.0);
        $this->sale($db, 400.0, 870.0, 1270.0, 1, null, 0.0, 'Bankkártya');

        $close = $db->getDailySummary($this->today());
        $report = $db->getSalesReportSummary($this->today(), $this->today());
        $this->assertSame(3175.0, $close['total_gross']);
        $this->assertSame($close['total_gross'], $report['total_gross']);
        $this->assertEqualsWithDelta($close['total_net'], $report['total_net'], 0.02);
        $this->assertSame($close['by_payment_method'][Database::GIFT_CARD_PAYMENT_LABEL]['total'], $report['by_payment_method'][Database::GIFT_CARD_PAYMENT_LABEL]['total']);

        $trend = array_column($db->getDailyRevenueTrend(1), 'total', 'date');
        $this->assertSame(3175.0, (float) ($trend[$this->today()] ?? 0));
        $byHour = $db->getSalesByHourReport($this->today(), $this->today());
        $this->assertSame(3175.0, round(array_sum(array_map(fn ($h) => $h['gross'], $byHour['hours'] ?? $byHour)), 2));
    }

    public function testAiSalesMetricUsesTheSameValue(): void
    {
        $db = tests_new_database();
        $this->sale($db, 0.0, 1000.0, 1000.0);
        $this->sale($db, 270.0, 1000.0);

        $tools = new SalesTools($db, []);
        $summary = $tools->getSalesSummary(['date_from' => $this->today(), 'date_to' => $this->today()]);
        $this->assertSame(2270.0, $summary['gross_sales']);
        $this->assertSame($db->getSalesReportSummary($this->today(), $this->today())['total_gross'], $summary['gross_sales']);
    }

    public function testCustomerAverageBasketUsesSaleValue(): void
    {
        $db = tests_new_database();
        $customerId = $db->saveCustomer(['name' => 'Utalványos Ulla']);
        $saleId = $db->insertSale(0.0, 'Készpénz', null, $customerId, 0, 0, null, 0.0, 1000.0, null);
        $db->insertSaleItem($saleId, ['product_id' => null, 'name' => 'Tétel', 'qty' => 1, 'unit_price' => 1000, 'vat_rate' => '27']);

        $this->assertEqualsWithDelta(1000.0, (float) $db->getCustomerStats($customerId)['avg_purchase'], 0.01);
    }
}
