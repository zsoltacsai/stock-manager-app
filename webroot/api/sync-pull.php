<?php

declare(strict_types=1);
require __DIR__ . '/_bootstrap.php';

try {
    $wc = new WooCommerceClient($config['woocommerce']);
    $products = $wc->fetchAllProducts();

    $imported = 0;
    $skipped  = 0;

    $db->beginTransaction();
    foreach ($products as $p) {
        if (empty($p['barcode'])) {
            $skipped++;
            $db->logSync('pull', null, "Skipped '{$p['name']}' (no barcode/SKU)");
            continue;
        }
        $db->upsertProductFromWc($p);
        $imported++;
    }
    $db->commit();

    send_json(['imported' => $imported, 'skipped' => $skipped, 'total_from_wc' => count($products)]);
} catch (Throwable $e) {
    $db->rollBack();
    send_json(['error' => $e->getMessage()], 500);
}
