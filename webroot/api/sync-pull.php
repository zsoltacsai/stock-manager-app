<?php

declare(strict_types=1);
require __DIR__ . '/_bootstrap.php';

try {
    $wc = new WooCommerceClient($config['woocommerce']);
    $result = $wc->fetchAllProducts();
    $products = $result['products'];

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

    if ($result['truncated']) {
        $db->logSync('pull', null, 'FIGYELEM: a WooCommerce termékkatalógus nagyobb, mint 5000 tétel — a szinkron nem dolgozta fel a teljeset.');
    }

    send_json([
        'imported' => $imported, 'skipped' => $skipped, 'total_from_wc' => count($products),
        'truncated' => $result['truncated'],
    ]);
} catch (Throwable $e) {
    $db->rollBack();
    send_json(['error' => $e->getMessage()], 500);
}
