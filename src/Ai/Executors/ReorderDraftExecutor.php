<?php

declare(strict_types=1);

require_once __DIR__ . '/../ExecutableActionStrategy.php';
require_once __DIR__ . '/../ActionExecutionStaleException.php';

/**
 * Fázis 8B — a `reorder_draft` javaslat-típus EGYETLEN végrehajtási
 * stratégiája. Lásd Database::migrateV32ActionExecution() docblokkja,
 * miért egy ÚJ, KÜLÖN, minimális `purchase_order_drafts` táblába ír
 * (NEM a meglévő purchases/purchase_items-be, ami "ténylegesen
 * beérkezett" tranzakciót jelent) — ez a metódus:
 *  - NEM módosítja products.stock_qty-t.
 *  - NEM módosítja products.purchase_price_net-et.
 *  - NEM küld semmit beszállítónak/külső rendszernek.
 *  - NEM hoz létre WooCommerce-push sort.
 *  - Egyetlen, emberi felülvizsgálatra váró piszkozat-sort hoz létre.
 *
 * MENNYISÉG-BIZTONSÁG (a kör 9. pontja): a végrehajtási mennyiséget
 * KIZÁRÓLAG ez a metódus számítja, MOST, a MEGLÉVŐ
 * `PurchaseDecisionService::recommendedQuantity()`-vel (UGYANAZZAL a
 * képlettel, mint a Beszerzési javaslat oldal, lásd Database::
 * getPurchaseRecommendations()) — a javaslat evidence-ében esetlegesen
 * szereplő bármilyen mennyiség-jellegű adat FIGYELMEN KÍVÜL marad, a
 * kiszámított értéket egy admin által állítható felső korlát
 * (`ai_reorder_draft_max_quantity`) is véd.
 */
final class ReorderDraftExecutor implements ExecutableActionStrategy
{
    private const FORECAST_WINDOW_DAYS = 30;

    public function __construct(
        private readonly array $appSettings,
    ) {
    }

    public function execute(Database $db, array $proposal): array
    {
        if ($proposal['entity_type'] !== 'product') {
            throw new ActionExecutionStaleException('A javaslat entitás-típusa nem termék.');
        }
        $productId = (int) $proposal['entity_id'];

        // FRISS termékadat — SOSE a javaslat evidence-éből (a kör 6/9.
        // pontja: "Do NOT trust browser-supplied... product data" — itt
        // ez kiterjed a javaslat LÉTREHOZÁSAKORI, már elavulhatott
        // adatára is).
        $product = $db->findProductById($productId);
        if ($product === null || !empty($product['is_deleted'])) {
            throw new ActionExecutionStaleException('A termék időközben törölve lett.');
        }

        $defaultThreshold = (int) ($this->appSettings['low_stock_default_threshold'] ?? 5);
        $threshold = $product['low_stock_threshold'] !== null ? (int) $product['low_stock_threshold'] : $defaultThreshold;
        $currentStock = (int) $product['stock_qty'];

        $forecast = $db->getStockForecastBulk([$productId], self::FORECAST_WINDOW_DAYS)[$productId] ?? null;
        $avgDaily = ($forecast !== null && $forecast['status'] === 'ok') ? (float) $forecast['avg_daily_consumption'] : null;

        $safetyStock = PurchaseDecisionService::safetyStock($threshold);
        $quantity = PurchaseDecisionService::recommendedQuantity($safetyStock, $avgDaily, $currentStock);

        if ($quantity <= 0) {
            // A készlet időközben helyreállt (pl. egy már korábban
            // elindított, ehhez a javaslathoz nem kapcsolódó beszerzés
            // beérkezett) — a javasolt utánrendelés már nem indokolt.
            throw new ActionExecutionStaleException('A termék készlete időközben rendben van — utánrendelés jelenleg nem indokolt.');
        }

        $maxQuantity = max(1, (int) ($this->appSettings['ai_reorder_draft_max_quantity'] ?? 500));
        $quantity = min($quantity, $maxQuantity);

        $supplierId = $product['preferred_supplier_id'] !== null ? (int) $product['preferred_supplier_id'] : null;
        $unitCostNet = (float) ($product['purchase_price_net'] ?? 0);
        $vatRatePct = is_numeric($product['vat_rate'] ?? null) ? ((float) $product['vat_rate']) / 100 : 0.0;
        $unitCostGross = round($unitCostNet * (1 + $vatRatePct), 2);
        $estimatedTotalNet = round($unitCostNet * $quantity, 2);
        $estimatedTotalGross = round($unitCostGross * $quantity, 2);

        $draftId = $db->createPurchaseOrderDraft([
            'proposal_id' => (int) $proposal['id'],
            'product_id' => $productId,
            'product_name' => mb_substr((string) $product['name'], 0, 191),
            'supplier_id' => $supplierId,
            'quantity' => $quantity,
            'unit_cost_net' => $unitCostNet,
            'unit_cost_gross' => $unitCostGross,
            'estimated_total_net' => $estimatedTotalNet,
            'estimated_total_gross' => $estimatedTotalGross,
        ]);

        return [
            'action' => 'reorder_draft',
            'success' => true,
            'reference_type' => 'purchase_order_draft',
            'reference_id' => $draftId,
            'product_id' => $productId,
            'quantity' => $quantity,
            'supplier_id' => $supplierId,
            'estimated_total_net' => $estimatedTotalNet,
            'estimated_total_gross' => $estimatedTotalGross,
        ];
    }
}
