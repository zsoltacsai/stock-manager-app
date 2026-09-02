<?php

declare(strict_types=1);
require __DIR__ . '/_bootstrap.php';

if (empty($config['woocommerce']['store_url']) || empty($config['woocommerce']['consumer_key'])) {
    send_json(['error' => 'A WooCommerce nincs beállítva (Beállítások → WooCommerce).'], 400);
}

try {
    $wc = new WooCommerceClient($config['woocommerce']);
    send_json(['brands' => $wc->fetchBrands()]);
} catch (Throwable $e) {
    send_json(['error' => $e->getMessage()], 500);
}
