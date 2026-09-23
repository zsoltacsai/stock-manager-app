<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * AnomalyDetector — tisztán determinisztikus, NINCS LLM/provider/adatbázis
 * ebben a fájlban (lásd az osztály docblokkja). A kör 16. pontjának 20
 * tesztelendő esete közül az 1-15. és a 20. itt, detektor-szinten
 * bizonyított; a 16-19. (több anomália egyszerre, darabszám-limitálás,
 * determinisztikus sorrend, duplikátum-elnyomás) inherenten a kandidátum-
 * kiválasztást/összefésülést végző AnomalyTools szintjén értelmezhető
 * csak — lásd tests/AiAnomalyToolsTest.php.
 */
final class AnomalyDetectorTest extends TestCase
{
    private function detector(): AnomalyDetector
    {
        return new AnomalyDetector();
    }

    // ------------------------------------------------------------------
    // 1. Normál adat
    // ------------------------------------------------------------------

    public function test01NormalDataIsNotFlagged(): void
    {
        $result = $this->detector()->detectSalesChange(10.0, 10.0, 'product', 1, 'Termék A');
        $this->assertSame('normal', $result['status']);
        $this->assertNull($result['record']);
    }

    // ------------------------------------------------------------------
    // 2. Eladás-visszaesés anomália
    // ------------------------------------------------------------------

    public function test02SalesDeclineAnomaly(): void
    {
        $result = $this->detector()->detectSalesChange(5.0, 10.0, 'product', 1, 'Termék A');
        $this->assertSame('anomaly', $result['status']);
        $this->assertSame('sales_decline', $result['record']['type']);
        $this->assertSame(-50.0, $result['record']['change_percent']);
        $this->assertSame('sales_drop_above_threshold', $result['record']['reason_code']);
        $this->assertSame(1, $result['record']['entity_id']);
        $this->assertSame('Termék A', $result['record']['entity_name']);
    }

    // ------------------------------------------------------------------
    // 3. Eladás-megugrás anomália
    // ------------------------------------------------------------------

    public function test03SalesSpikeAnomaly(): void
    {
        $result = $this->detector()->detectSalesChange(20.0, 10.0, 'product', 1, 'Termék A');
        $this->assertSame('anomaly', $result['status']);
        $this->assertSame('sales_spike', $result['record']['type']);
        $this->assertSame(100.0, $result['record']['change_percent']);
    }

    // ------------------------------------------------------------------
    // 4. Készlet/eladás divergencia
    // ------------------------------------------------------------------

    public function test04StockSalesDivergenceAnomaly(): void
    {
        $result = $this->detector()->detectStockSalesDivergence(5.0, 10.0, 'ok', 90, 'product', 1, 'Termék A');
        $this->assertSame('anomaly', $result['status']);
        $this->assertSame('stock_sales_divergence', $result['record']['type']);
        $this->assertSame(90.0, $result['record']['current_value']);
        $this->assertSame(-50.0, $result['record']['change_percent']);
    }

    public function testStockSalesDivergenceNormalWhenNoDecline(): void
    {
        $result = $this->detector()->detectStockSalesDivergence(10.0, 10.0, 'ok', 90, 'product', 1, 'Termék A');
        $this->assertSame('normal', $result['status']);
    }

    public function testStockSalesDivergenceNormalWhenDaysRemainingBelowThreshold(): void
    {
        $result = $this->detector()->detectStockSalesDivergence(5.0, 10.0, 'ok', 10, 'product', 1, 'Termék A');
        $this->assertSame('normal', $result['status']);
    }

    public function testStockSalesDivergenceInsufficientDataWhenForecastUnavailable(): void
    {
        $result = $this->detector()->detectStockSalesDivergence(5.0, 10.0, 'insufficient_data', null, 'product', 1, 'Termék A');
        $this->assertSame('insufficient_data', $result['status']);
        $this->assertSame('forecast_unavailable', $result['reason']);
    }

    // ------------------------------------------------------------------
    // 5. Visszáru-arány anomália
    // ------------------------------------------------------------------

    public function test05ReturnRateAnomaly(): void
    {
        $result = $this->detector()->detectReturnRateAnomaly(20.0, 10, 5.0, 10, 'shop', 0, 'FountainTrade');
        $this->assertSame('anomaly', $result['status']);
        $this->assertSame('return_rate_anomaly', $result['record']['type']);
        $this->assertSame(15.0, $result['record']['change_percent']);
    }

    public function testReturnRateNormalBelowThreshold(): void
    {
        $result = $this->detector()->detectReturnRateAnomaly(10.0, 10, 5.0, 10, 'shop', 0, 'FountainTrade');
        $this->assertSame('normal', $result['status']);
    }

    // ------------------------------------------------------------------
    // 6. Lassan mozgó készlet
    // ------------------------------------------------------------------

    public function test06SlowMovingStockAnomaly(): void
    {
        $result = $this->detector()->detectSlowMovingStock(20, 0, 30, 'product', 1, 'Termék A');
        $this->assertSame('anomaly', $result['status']);
        $this->assertSame('slow_moving_stock', $result['record']['type']);
        $this->assertSame('medium', $result['record']['severity']);
    }

    public function testSlowMovingStockNormalWhenSoldEnough(): void
    {
        $result = $this->detector()->detectSlowMovingStock(20, 2, 30, 'product', 1, 'Termék A');
        $this->assertSame('normal', $result['status']);
    }

    public function testSlowMovingStockNormalWhenNoStock(): void
    {
        $result = $this->detector()->detectSlowMovingStock(0, 0, 30, 'product', 1, 'Termék A');
        $this->assertSame('normal', $result['status'], 'Készlethiány NEM "lassan mozgó" — azt a MEGLÉVŐ InventoryTools fedi le.');
    }

    // ------------------------------------------------------------------
    // 7. Alacsony készlet + megugrott eladás
    // ------------------------------------------------------------------

    public function test07LowStockElevatedSalesAnomaly(): void
    {
        $result = $this->detector()->detectLowStockElevatedSales(16.0, 10.0, true, 'product', 1, 'Termék A');
        $this->assertSame('anomaly', $result['status']);
        $this->assertSame('low_stock_elevated_sales', $result['record']['type']);
        $this->assertSame(60.0, $result['record']['change_percent']);
    }

    public function testLowStockElevatedSalesNormalWhenNotLowStock(): void
    {
        $result = $this->detector()->detectLowStockElevatedSales(16.0, 10.0, false, 'product', 1, 'Termék A');
        $this->assertSame('normal', $result['status']);
    }

    // ------------------------------------------------------------------
    // 8. Elégtelen adat (kis minta)
    // ------------------------------------------------------------------

    public function test08InsufficientDataWhenBaselineTooSmall(): void
    {
        $result = $this->detector()->detectSalesChange(10.0, 3.0, 'product', 1, 'Termék A');
        $this->assertSame('insufficient_data', $result['status']);
        $this->assertSame('baseline_too_small', $result['reason']);
        $this->assertArrayNotHasKey('record', $result);
    }

    // ------------------------------------------------------------------
    // 9. Nulla alap
    // ------------------------------------------------------------------

    public function test09ZeroBaselineIsInsufficientDataNotAutomaticAnomaly(): void
    {
        $result = $this->detector()->detectSalesChange(10.0, 0.0, 'product', 1, 'Termék A');
        $this->assertSame('insufficient_data', $result['status'], 'Egy vadonatúj termék (0 -> N) sose "anomália", mert nincs értelmezhető alap.');
    }

    // ------------------------------------------------------------------
    // 10. Nulla jelenlegi eladás
    // ------------------------------------------------------------------

    public function test10ZeroCurrentSalesIsCriticalDecline(): void
    {
        $result = $this->detector()->detectSalesChange(0.0, 10.0, 'product', 1, 'Termék A');
        $this->assertSame('anomaly', $result['status']);
        $this->assertSame(-100.0, $result['record']['change_percent']);
        $this->assertSame('critical', $result['record']['severity']);
    }

    // ------------------------------------------------------------------
    // 11. Negatív/érvénytelen értékek elutasítva
    // ------------------------------------------------------------------

    public function test11NegativeCurrentQtyIsRejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->detector()->detectSalesChange(-1.0, 10.0, 'product', 1, 'Termék A');
    }

    public function test11NegativePreviousQtyIsRejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->detector()->detectSalesChange(10.0, -1.0, 'product', 1, 'Termék A');
    }

    public function test11NegativeReturnRatioIsRejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->detector()->detectReturnRateAnomaly(-5.0, 10, 5.0, 10, 'shop', 0, 'FountainTrade');
    }

    public function test11NegativeStockQtyForSlowMovingIsRejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->detector()->detectSlowMovingStock(-1, 0, 30, 'product', 1, 'Termék A');
    }

    public function test11NegativeQtyForLowStockElevatedSalesIsRejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->detector()->detectLowStockElevatedSales(-1.0, 10.0, true, 'product', 1, 'Termék A');
    }

    // ------------------------------------------------------------------
    // 12-14. Küszöb-határérték — pontosan, épp alatta, épp fölötte
    // ------------------------------------------------------------------

    public function test12ThresholdBoundaryExactlyIsAnomaly(): void
    {
        // -30.0% pontosan — a szabály "<=", tehát INKLUZÍV.
        $result = $this->detector()->detectSalesChange(7.0, 10.0, 'product', 1, 'Termék A');
        $this->assertSame(-30.0, $result['record']['change_percent'] ?? null);
        $this->assertSame('anomaly', $result['status']);
    }

    public function test13JustBelowThresholdIsNormal(): void
    {
        // -29% — a küszöb MAGNITÚDÓJA alatt marad.
        $result = $this->detector()->detectSalesChange(7.1, 10.0, 'product', 1, 'Termék A');
        $this->assertSame('normal', $result['status']);
    }

    public function test14JustAboveThresholdIsAnomaly(): void
    {
        // -31% — meghaladja a küszöb magnitúdóját.
        $result = $this->detector()->detectSalesChange(6.9, 10.0, 'product', 1, 'Termék A');
        $this->assertSame('anomaly', $result['status']);
    }

    // ------------------------------------------------------------------
    // 15. Súlyossági határértékek
    // ------------------------------------------------------------------

    public function test15SeverityIsMediumJustAboveBaseThreshold(): void
    {
        $result = $this->detector()->detectSalesChange(6.9, 10.0, 'product', 1, 'Termék A'); // -31%
        $this->assertSame('medium', $result['record']['severity']);
    }

    public function test15SeverityIsHighAtExactlyFiftyPercent(): void
    {
        $result = $this->detector()->detectSalesChange(5.0, 10.0, 'product', 1, 'Termék A'); // -50%
        $this->assertSame('high', $result['record']['severity']);
    }

    public function test15SeverityIsMediumJustBelowFiftyPercent(): void
    {
        $result = $this->detector()->detectSalesChange(5.01, 10.0, 'product', 1, 'Termék A'); // -49.9%
        $this->assertSame('medium', $result['record']['severity']);
    }

    public function test15SeverityIsCriticalAtExactlySeventyPercent(): void
    {
        $result = $this->detector()->detectSalesChange(3.0, 10.0, 'product', 1, 'Termék A'); // -70%
        $this->assertSame('critical', $result['record']['severity']);
    }

    public function test15SeverityIsHighJustBelowSeventyPercent(): void
    {
        $result = $this->detector()->detectSalesChange(3.01, 10.0, 'product', 1, 'Termék A'); // -69.9%
        $this->assertSame('high', $result['record']['severity']);
    }

    public function test15ReturnRateSeverityBoundaries(): void
    {
        // trigger = 10pp; medium: 10-19.99, high: 20-29.99, critical: >=30
        $medium = $this->detector()->detectReturnRateAnomaly(20.0, 10, 10.0, 10, 'shop', 0, 'X'); // diff=10
        $this->assertSame('medium', $medium['record']['severity']);

        $high = $this->detector()->detectReturnRateAnomaly(30.0, 10, 10.0, 10, 'shop', 0, 'X'); // diff=20
        $this->assertSame('high', $high['record']['severity']);

        $critical = $this->detector()->detectReturnRateAnomaly(40.0, 10, 10.0, 10, 'shop', 0, 'X'); // diff=30
        $this->assertSame('critical', $critical['record']['severity']);
    }

    // ------------------------------------------------------------------
    // 16. "Több anomália" — a detektor maga állapotmentes: két EGYMÁST
    // KÖVETŐ hívás nem befolyásolja egymást (a kandidátumok-feletti
    // iterálást/összefésülést lásd AnomalyTools-nál).
    // ------------------------------------------------------------------

    public function test16DetectorCallsAreIndependentAndStateless(): void
    {
        $detector = $this->detector();
        $first = $detector->detectSalesChange(5.0, 10.0, 'product', 1, 'A'); // decline
        $second = $detector->detectSalesChange(20.0, 10.0, 'product', 2, 'B'); // spike
        $third = $detector->detectSalesChange(10.0, 10.0, 'product', 3, 'C'); // normal

        $this->assertSame('sales_decline', $first['record']['type']);
        $this->assertSame('sales_spike', $second['record']['type']);
        $this->assertSame('normal', $third['status']);
    }

    // ------------------------------------------------------------------
    // 20. Jövőbeli/adat nélküli időszak — a detektor szintjén ez az
    // "ablak túl rövid"/"nincs elég megfigyelés" esetekben jelenik meg.
    // ------------------------------------------------------------------

    public function test20TooShortWindowIsInsufficientDataNotNormal(): void
    {
        $result = $this->detector()->detectSlowMovingStock(20, 0, 5, 'product', 1, 'Termék A');
        $this->assertSame('insufficient_data', $result['status']);
        $this->assertSame('window_too_short', $result['reason']);
    }

    // ------------------------------------------------------------------
    // Kiegészítő: a rekord-séma pontosan megfelel a kör 3. pontjában
    // megadott alaknak.
    // ------------------------------------------------------------------

    public function testRecordSchemaMatchesSpecification(): void
    {
        $result = $this->detector()->detectSalesChange(5.0, 10.0, 'product', 123, 'Kóla');
        $record = $result['record'];
        foreach (['type', 'severity', 'entity_type', 'entity_id', 'entity_name', 'metric', 'current_value', 'baseline_value', 'change_percent', 'evidence', 'reason_code'] as $key) {
            $this->assertArrayHasKey($key, $record, "hiányzó mező: $key");
        }
        $this->assertSame('product', $record['entity_type']);
        $this->assertSame(123, $record['entity_id']);
        $this->assertIsArray($record['evidence']);
    }
}
