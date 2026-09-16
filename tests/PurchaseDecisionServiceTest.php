<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * A src/PurchaseDecisionService.php DB-független, tisztán a képleteket
 * ellenőrző tesztjei — lásd a kör 12. pontja ("reorder point, recommended
 * quantity, zero stock, low stock, no sales history" stb.).
 */
final class PurchaseDecisionServiceTest extends TestCase
{
    // ---- safetyStock ----

    public function testSafetyStockIsTheGivenThresholdNeverNegative(): void
    {
        $this->assertSame(5, PurchaseDecisionService::safetyStock(5));
        $this->assertSame(0, PurchaseDecisionService::safetyStock(-3), 'Negatív küszöb sose adhat negatív biztonsági készletet.');
    }

    // ---- reorderPoint ----

    public function testReorderPointAddsReviewPeriodConsumptionToSafetyStock(): void
    {
        // safety=5, napi fogyás=2 -> 5 + 2*7 = 19.
        $this->assertSame(19, PurchaseDecisionService::reorderPoint(5, 2.0));
    }

    public function testReorderPointFallsBackToSafetyStockWithoutReliableConsumption(): void
    {
        $this->assertSame(5, PurchaseDecisionService::reorderPoint(5, null));
        $this->assertSame(5, PurchaseDecisionService::reorderPoint(5, 0.0));
    }

    // ---- recommendedQuantity ----

    public function testRecommendedQuantityUsesConsumptionBasedTargetWhenAvailable(): void
    {
        // safety=5, napi fogyás=2, jelenlegi készlet=4
        // target = 5 + 2*14 = 33; javasolt = 33-4 = 29.
        $this->assertSame(29, PurchaseDecisionService::recommendedQuantity(5, 2.0, 4));
    }

    public function testRecommendedQuantityNeverNegativeWhenStockAlreadyAboveTarget(): void
    {
        $this->assertSame(0, PurchaseDecisionService::recommendedQuantity(5, 1.0, 1000));
    }

    public function testRecommendedQuantityFallsBackToDoubleThresholdHeuristicWithoutConsumptionData(): void
    {
        // Nincs megbízható napi fogyás (pl. "no sales history") -> a
        // MEGLÉVŐ (1.1.1-es) ökölszabályra esik vissza: threshold*2 - stock.
        $this->assertSame(6, PurchaseDecisionService::recommendedQuantity(5, null, 4));
        $this->assertSame(1, PurchaseDecisionService::recommendedQuantity(5, null, 20), 'Sose adhat 0-t vagy negatívat, legalább 1-et javasol.');
    }

    public function testRecommendedQuantityForZeroStockWithNoConsumptionHistory(): void
    {
        // "zero stock" + "no sales history" együtt — még mindig a régi
        // ökölszabály, de POZITÍV javaslat kell (ne ess 0-ra/alá).
        $qty = PurchaseDecisionService::recommendedQuantity(5, null, 0);
        $this->assertGreaterThan(0, $qty);
    }

    // ---- classifyUrgency ----

    public function testClassifyUrgencyForZeroStockIsAlwaysUrgentRegardlessOfForecast(): void
    {
        $this->assertSame('urgent', PurchaseDecisionService::classifyUrgency(null, 0, 5));
        $this->assertSame('urgent', PurchaseDecisionService::classifyUrgency(['status' => 'zero_consumption', 'estimated_days_remaining' => null], 0, 5));
    }

    public function testClassifyUrgencyUsesForecastDaysWhenReliable(): void
    {
        $this->assertSame('urgent', PurchaseDecisionService::classifyUrgency(['status' => 'ok', 'estimated_days_remaining' => 2], 4, 5));
        $this->assertSame('soon', PurchaseDecisionService::classifyUrgency(['status' => 'ok', 'estimated_days_remaining' => 6], 4, 5));
        $this->assertSame('low', PurchaseDecisionService::classifyUrgency(['status' => 'ok', 'estimated_days_remaining' => 20], 4, 5), 'Alacsony küszöb alatti készlet, de a forecast szerint messze van a kifogyás -> csak "low", nem "soon"/"urgent".');
    }

    public function testClassifyUrgencyFallsBackToLowWithoutReliableForecast(): void
    {
        // "no sales history" (insufficient_data) — objektíven alacsony a
        // készlet, de nem tudjuk MIKOR fogy el.
        $this->assertSame('low', PurchaseDecisionService::classifyUrgency(['status' => 'insufficient_data', 'estimated_days_remaining' => null], 4, 5));
        $this->assertSame('low', PurchaseDecisionService::classifyUrgency(null, 4, 5));
    }

    // ---- computeMargin ----

    public function testMarginCalculationWithReliableCost(): void
    {
        $margin = PurchaseDecisionService::computeMargin(1000.0, 600.0, true);
        $this->assertSame(400.0, $margin['margin_ft']);
        $this->assertSame(40.0, $margin['margin_pct']);
    }

    public function testMarginIsNullWhenCostIsUnreliableEvenIfCostFieldIsZero(): void
    {
        // A klasszikus csapda: purchase_price_net = 0, mert a terméket
        // SOSE szerezték még be — ez NEM azt jelenti, hogy 100% az árrés.
        $this->assertNull(PurchaseDecisionService::computeMargin(1000.0, 0.0, false));
    }

    public function testMarginIsComputedWhenCostIsGenuinelyZeroAndReliable(): void
    {
        // Ha VAN valódi beszerzési előzmény és az ténylegesen 0-t mutat
        // (ritka, de lehetséges, pl. promóciós beszerzés), a 100%-os
        // árrés helyénvaló, nem hamis.
        $margin = PurchaseDecisionService::computeMargin(1000.0, 0.0, true);
        $this->assertSame(1000.0, $margin['margin_ft']);
        $this->assertSame(100.0, $margin['margin_pct']);
    }

    public function testMarginIsNullForZeroOrNegativeSellPrice(): void
    {
        $this->assertNull(PurchaseDecisionService::computeMargin(0.0, 500.0, true), '0 Ft-os eladási árhoz nem értelmezhető %-os árrés (nullával osztás).');
        $this->assertNull(PurchaseDecisionService::computeMargin(-10.0, 500.0, true));
    }

    public function testMarginIsNullForNegativeCost(): void
    {
        $this->assertNull(PurchaseDecisionService::computeMargin(1000.0, -1.0, true));
    }

    public function testMarginCanBeNegativeWhenSellingBelowCost(): void
    {
        $margin = PurchaseDecisionService::computeMargin(500.0, 800.0, true);
        $this->assertSame(-300.0, $margin['margin_ft']);
        $this->assertSame(-60.0, $margin['margin_pct']);
    }
}
