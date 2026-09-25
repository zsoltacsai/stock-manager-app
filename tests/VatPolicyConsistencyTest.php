<?php

declare(strict_types=1);

require_once __DIR__ . '/../src/Ai/Tools/SalesTools.php';

use PHPUnit\Framework\TestCase;

/**
 * B-13 (correctness audit) regresszió — egyetlen ÁFA-számítási szabály
 * (Database::vatBreakdown()) a napi zárásban és az értékesítési riportban
 * (→ Dashboard, AI SalesTools).
 *
 * Az audit reprodukciója: 3 × 10 Ft (27%), 20 Ft kupon, 10 Ft fizetve →
 * napi zárás ÁFA 2.13 (nettó+ÁFA = 9.99 a 10.00 bruttóhoz), riport ÁFA 2.14.
 * A közös szabály: az érték fillérre pontos szétosztása a sorokra, soronként
 * kerekített nettó, ÁFA = bruttó − nettó → mindkét riport bruttó 10.00,
 * nettó 7.87, ÁFA 2.13, és minden szinten nettó + ÁFA = bruttó.
 */
final class VatPolicyConsistencyTest extends TestCase
{
    private function today(): string
    {
        return date('Y-m-d');
    }

    /** @param list<array{0:float,1:int,2:string}> $lines [egységár, db, kulcs] */
    private function sale(Database $db, float $paid, array $lines, float $giftCard = 0.0, ?int $couponId = null, float $couponDiscount = 0.0, string $method = 'Készpénz'): int
    {
        $saleId = $db->insertSale($paid, $method, null, null, 0, 0, $couponId, $couponDiscount, $giftCard, null);
        foreach ($lines as [$price, $qty, $rate]) {
            $db->insertSaleItem($saleId, ['product_id' => null, 'name' => "Tétel $rate", 'qty' => $qty, 'unit_price' => $price, 'vat_rate' => $rate]);
        }
        return $saleId;
    }

    private function returnItems(Database $db, int $saleId, array $qtyByIndex, float $cashRefund): void
    {
        $sale = $db->getSaleWithItems($saleId);
        $items = [];
        foreach ($sale['items'] as $i => $si) {
            if (!empty($qtyByIndex[$i])) {
                $items[] = ['sale_item_id' => (int) $si['id'], 'product_id' => null, 'name' => $si['name'], 'qty' => $qtyByIndex[$i], 'unit_price' => (float) $si['unit_price']];
            }
        }
        $db->processReturn($saleId, $items, 'x', null, $cashRefund, $sale);
    }

    /** Mindkét riport bruttó/nettó/ÁFA egyezik, és nettó + ÁFA = bruttó fillérre. */
    private function assertReportsConsistent(Database $db): array
    {
        $close = $db->getDailySummary($this->today());
        $report = $db->getSalesReportSummary($this->today(), $this->today());
        foreach (['total_gross', 'total_net', 'total_vat', 'total_returns'] as $k) {
            $this->assertSame($close[$k], $report[$k], "$k: napi zárás vs. riport");
        }
        $this->assertSame(round($close['total_net'] + $close['total_vat'], 2), $close['total_gross'], 'nettó + ÁFA = bruttó');
        $byRate = ['gross' => 0.0, 'net' => 0.0, 'vat' => 0.0];
        foreach ($close['by_vat_rate'] as $rate => $row) {
            $this->assertSame(round($row['net'] + $row['vat'], 2), $row['gross'], "ÁFA-kulcs $rate: nettó + ÁFA = bruttó");
            foreach ($byRate as $k => $v) {
                $byRate[$k] += $row[$k];
            }
        }
        $this->assertSame($close['total_gross'], round($byRate['gross'], 2), 'a kulcsonkénti bontás összege a bruttó');
        $this->assertSame($close['total_net'], round($byRate['net'], 2));
        $this->assertSame($close['total_vat'], round($byRate['vat'], 2));
        $this->assertSame($report['total_net'], round(array_sum(array_column($report['by_day'], 'net')), 2), 'a napi bontás nettója');
        return $close;
    }

    public function testAuditReproductionThreeTimesTenWithTwentyCoupon(): void
    {
        $db = tests_new_database();
        $couponId = $db->saveCoupon(['code' => 'B13', 'type' => 'fixed', 'value' => 20, 'is_active' => true]);
        $this->sale($db, 10.0, [[10.0, 1, '27'], [10.0, 1, '27'], [10.0, 1, '27']], 0.0, $couponId, 20.0);

        $close = $this->assertReportsConsistent($db);
        $this->assertSame([10.0, 7.87, 2.13], [$close['total_gross'], $close['total_net'], $close['total_vat']]);
        $this->assertSame(['net' => 7.87, 'vat' => 2.13, 'gross' => 10.0], $close['by_vat_rate']['27']);
    }

    public function testVatBreakdownDistributesCentsExactlyAndDeterministically(): void
    {
        $b = Database::vatBreakdown(10.0, [
            ['unit_price' => 10, 'qty' => 1, 'vat_rate' => '27'],
            ['unit_price' => 10, 'qty' => 1, 'vat_rate' => '27'],
            ['unit_price' => 10, 'qty' => 1, 'vat_rate' => '27'],
        ]);
        $this->assertSame([3.34, 3.33, 3.33], array_column($b['lines'], 'gross'), 'A maradék fillér az első sorra kerül (determinisztikus).');
        $this->assertSame([10.0, 7.87, 2.13], [$b['gross'], $b['net'], $b['vat']]);
        $this->assertSame($b, Database::vatBreakdown(10.0, [
            ['unit_price' => 10, 'qty' => 1, 'vat_rate' => '27'],
            ['unit_price' => 10, 'qty' => 1, 'vat_rate' => '27'],
            ['unit_price' => 10, 'qty' => 1, 'vat_rate' => '27'],
        ]));
    }

    public function testManySameRateLinesAndMixedRatesIncludingExemption(): void
    {
        $db = tests_new_database();
        // 2033 Ft kedvezmény előtt, 533 Ft kedvezmény → 1500 Ft érték.
        $this->sale($db, 1500.0, [[333.0, 3, '27'], [500.0, 1, '5'], [333.0, 1, '18'], [100.0, 2, 'AAM']]);

        $close = $this->assertReportsConsistent($db);
        $this->assertSame(1500.0, $close['total_gross']);
        $this->assertSame($close['by_vat_rate']['AAM']['gross'], $close['by_vat_rate']['AAM']['net'], 'Adómentes kulcsnál nincs ÁFA.');
        $this->assertSame(0.0, $close['by_vat_rate']['AAM']['vat']);
        $this->assertEqualsCanonicalizing(['27', '5', '18', 'AAM'], array_map('strval', array_keys($close['by_vat_rate'])));
    }

    public function testCouponPlusGiftCardUsesTheFullSaleValue(): void
    {
        $db = tests_new_database();
        $couponId = $db->saveCoupon(['code' => 'B13G', 'type' => 'fixed', 'value' => 70, 'is_active' => true]);
        // 3 × 333 = 999, 70 kupon → 929 érték; 500 utalvány + 429 készpénz.
        $this->sale($db, 429.0, [[333.0, 3, '27']], 500.0, $couponId, 70.0);

        $close = $this->assertReportsConsistent($db);
        $this->assertSame(929.0, $close['total_gross']);
        $this->assertSame(429.0, $close['by_payment_method']['Készpénz']['total']);
        $this->assertSame(500.0, $close['by_payment_method'][Database::GIFT_CARD_PAYMENT_LABEL]['total']);
    }

    public function testFullReturnNetsEverythingToZero(): void
    {
        $db = tests_new_database();
        $saleId = $this->sale($db, 10.0, [[10.0, 1, '27'], [10.0, 1, '27'], [10.0, 1, '27']]);
        $this->returnItems($db, $saleId, [0 => 1, 1 => 1, 2 => 1], 10.0);

        $close = $this->assertReportsConsistent($db);
        $this->assertSame([0.0, 0.0, 0.0], [$close['total_gross'], $close['total_net'], $close['total_vat']]);
        $this->assertSame(['net' => 0.0, 'vat' => 0.0, 'gross' => 0.0], $close['by_vat_rate']['27']);
    }

    public function testPartialReturnWithMixedRatesStaysConsistent(): void
    {
        $db = tests_new_database();
        $saleId = $this->sale($db, 900.0, [[500.0, 1, '27'], [500.0, 1, '5']]); // 100 kedvezmény
        $this->returnItems($db, $saleId, [1 => 1], 450.0);                       // az 5%-os sor vissza

        $close = $this->assertReportsConsistent($db);
        $this->assertSame(450.0, $close['total_gross']);
        $this->assertSame(0.0, $close['by_vat_rate']['5']['gross']);
        $this->assertSame(0.0, $close['by_vat_rate']['5']['vat']);
    }

    public function testManySalesDailyAggregationEqualsTheSumOfPerSaleBreakdowns(): void
    {
        $db = tests_new_database();
        mt_srand(1313);
        $rates = ['27', '18', '5', '0', 'AAM', 'TAM'];
        $expected = ['gross' => 0.0, 'net' => 0.0, 'vat' => 0.0];
        for ($i = 0; $i < 40; $i++) {
            $lines = [];
            $subtotal = 0.0;
            for ($l = 0, $n = mt_rand(1, 4); $l < $n; $l++) {
                $price = mt_rand(1, 50000) / 100;
                $qty = mt_rand(1, 5);
                $lines[] = [$price, $qty, $rates[mt_rand(0, 5)]];
                $subtotal += $price * $qty;
            }
            $value = round($subtotal * mt_rand(50, 100) / 100, 2);
            $giftCard = mt_rand(0, 3) === 0 ? round($value * 0.4, 2) : 0.0;
            $this->sale($db, round($value - $giftCard, 2), $lines, $giftCard);
            $b = Database::vatBreakdown($value, array_map(fn ($x) => ['unit_price' => $x[0], 'qty' => $x[1], 'vat_rate' => $x[2]], $lines));
            $this->assertSame($value, $b['gross'], 'A sorok bruttója pontosan az érték.');
            foreach ($expected as $k => $v) {
                $expected[$k] += $b[$k];
            }
        }

        $close = $this->assertReportsConsistent($db);
        $this->assertSame(round($expected['gross'], 2), $close['total_gross']);
        $this->assertSame(round($expected['net'], 2), $close['total_net']);
        $this->assertSame(round($expected['vat'], 2), $close['total_vat']);
    }

    public function testDashboardAndAiUseTheSameNumbersAsTheDailyClose(): void
    {
        $db = tests_new_database();
        $this->sale($db, 10.0, [[10.0, 1, '27'], [10.0, 1, '27'], [10.0, 1, '27']]);
        $close = $db->getDailySummary($this->today());

        // Dashboard: dashboard-summary.php → getSalesReportSummary(); AI: SalesTools.
        $ai = (new SalesTools($db, []))->getSalesSummary(['date_from' => $this->today(), 'date_to' => $this->today()]);
        $this->assertSame([$close['total_gross'], $close['total_net']], [$ai['gross_sales'], $ai['net_sales']]);
    }

    public function testSaleWithoutItemsIsReportedWithoutInventedVat(): void
    {
        $db = tests_new_database();
        $db->insertSale(500.0, 'Készpénz');

        $close = $this->assertReportsConsistent($db);
        $this->assertSame([500.0, 500.0, 0.0], [$close['total_gross'], $close['total_net'], $close['total_vat']]);
    }
}
