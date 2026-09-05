<?php

declare(strict_types=1);
require __DIR__ . '/_bootstrap.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    send_json(['error' => 'POST only'], 405);
}

// A mentés előtti teszteléshez az űrlapon éppen begépelt (még el nem
// mentett) értékeket is elfogadja — ha ezek üresek, a már elmentett
// (Beállításokból/config.php-ból származó) értékekre esik vissza.
$input = json_input();
$wcConfig = $config['woocommerce'];
foreach (['store_url', 'consumer_key', 'consumer_secret'] as $field) {
    if (!empty($input[$field])) {
        $wcConfig[$field] = $input[$field];
    }
}

if (empty($wcConfig['store_url']) || empty($wcConfig['consumer_key']) || empty($wcConfig['consumer_secret'])) {
    send_json(['success' => false, 'error' => 'Az Áruház URL, a Consumer key és a Consumer secret mind kötelező a teszteléshez.']);
}

try {
    $wc = new WooCommerceClient($wcConfig);
    $wc->testConnection();
    send_json(['success' => true]);
} catch (Throwable $e) {
    send_json(['success' => false, 'error' => $e->getMessage()]);
}
