<?php

declare(strict_types=1);
require __DIR__ . '/_bootstrap.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    send_json(['error' => 'POST only'], 405);
}

$input = json_input();
$lines = $input['items'] ?? [];
$supplier = $input['supplier'] ?? [];

if (empty($lines)) {
    send_json(['error' => 'A beszerzési tételek listája üres'], 400);
}

$items = [];
foreach ($lines as $line) {
    $product = $db->findProductById((int) $line['product_id']);
    if (!$product) {
        send_json(['error' => "Termék #{$line['product_id']} nem található"], 404);
    }

    $qty = (int) $line['qty'];
    if ($qty <= 0) {
        send_json(['error' => "Érvénytelen mennyiség: {$product['name']}"], 400);
    }

    $vatRate = (string) ($line['vat_rate'] ?? $product['vat_rate']);
    $vatPct  = is_numeric($vatRate) ? ((float) $vatRate) / 100 : 0.0;

    $unitCostNet = (float) $line['unit_cost_net'];
    if ($unitCostNet < 0) {
        send_json(['error' => "Érvénytelen beszerzési ár: {$product['name']}"], 400);
    }
    $unitCostGross = isset($line['unit_cost_gross']) && $line['unit_cost_gross'] !== ''
        ? (float) $line['unit_cost_gross']
        : round($unitCostNet * (1 + $vatPct), 2);
    if ($unitCostGross < 0) {
        send_json(['error' => "Érvénytelen beszerzési ár: {$product['name']}"], 400);
    }

    $items[] = [
        'product_id'      => $product['id'],
        'wc_product_id'   => !empty($product['sync_to_woocommerce']) ? $product['wc_product_id'] : null,
        'name'            => $product['name'],
        'qty'             => $qty,
        'vat_rate'        => $vatRate,
        'unit_cost_net'   => $unitCostNet,
        'unit_cost_gross' => $unitCostGross,
    ];
}

$discountPercent = (float) ($input['discount_percent'] ?? 0);
if ($discountPercent < 0 || $discountPercent > 100) {
    send_json(['error' => 'Érvénytelen kedvezmény százalék.'], 400);
}

$purchase = [
    'supplier_id'         => !empty($input['supplier_id']) ? (int) $input['supplier_id'] : null,
    'supplier_name'       => $supplier['nev'] ?? null,
    'supplier_tax_number' => $supplier['adoszam'] ?? null,
    'supplier_country'    => $supplier['orszag'] ?? null,
    'supplier_zip'        => $supplier['irsz'] ?? null,
    'supplier_city'       => $supplier['telepules'] ?? null,
    'supplier_address'    => $supplier['cim'] ?? null,
    'payment_method'      => $input['payment_method'] ?? 'készpénz',
    'currency'            => $input['currency'] ?? 'HUF',
    'discount_percent'    => $discountPercent,
    'paid'                => $input['paid'] ?? true,
    'note'                => $input['note'] ?? null,
];

$result = $db->recordPurchase($purchase, $items);

$pushErrors = [];
try {
    $wc = new WooCommerceClient($config['woocommerce']);
    foreach ($items as $item) {
        if (empty($item['wc_product_id'])) {
            continue;
        }
        $updated = $result['updated_products'][$item['product_id']] ?? null;
        if (!$updated) {
            continue;
        }
        try {
            $wc->updateStock((int) $item['wc_product_id'], (int) $updated['stock_qty']);
            $db->logSync('push', $item['product_id'], 'Stock pushed after purchase #' . $result['purchase_id']);
        } catch (Throwable $e) {
            $pushErrors[] = $item['name'] . ': ' . $e->getMessage();
            $db->logSync('push', $item['product_id'], 'FAILED: ' . $e->getMessage());
        }
    }
} catch (Throwable $e) {
    $pushErrors[] = $e->getMessage();
}

send_json([
    'purchase_id'      => $result['purchase_id'],
    'total_net'        => $result['total_net'],
    'total_gross'      => $result['total_gross'],
    'updated_products' => array_values($result['updated_products']),
    'wc_push_errors'   => $pushErrors,
]);
