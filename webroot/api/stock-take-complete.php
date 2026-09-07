<?php

declare(strict_types=1);
require __DIR__ . '/_bootstrap.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    send_json(['error' => 'POST only'], 405);
}

$input = json_input();
$id = (int) ($input['id'] ?? 0);
$applyCorrections = !empty($input['apply_corrections']);

if (!$id) {
    send_json(['error' => 'Hiányzó id.'], 400);
}

try {
    $updatedProducts = $db->completeStockTake($id, $applyCorrections);
} catch (Throwable $e) {
    send_json(['error' => 'A leltár lezárása sikertelen: ' . $e->getMessage()], 500);
}

// A leltári korrekció önmagában csak a helyi készletet módosítja — a
// WC-vel szinkronban lévő termékeknél ki is kell küldeni az új
// mennyiséget, különben a webshop néma marad a leltár valós eredményéről,
// és a következő behúzás (pull) vissza is írhatná a helyi, épp
// helyesbített értéket a régi, elavult WC-értékkel.
$pushErrors = [];
if ($updatedProducts) {
    try {
        $wc = new WooCommerceClient($config['woocommerce']);
        foreach ($updatedProducts as $product) {
            try {
                $wc->updateStock($product['wc_product_id'], $product['stock_qty']);
                $db->touchWcSyncedAt($product['id']);
                $db->logSync('push', $product['id'], 'Stock pushed after stock-take #' . $id);
            } catch (Throwable $e) {
                $pushErrors[] = $product['name'] . ': ' . $e->getMessage();
                $db->logSync('push', $product['id'], 'FAILED: ' . $e->getMessage());
            }
        }
    } catch (Throwable $e) {
        $pushErrors[] = $e->getMessage();
    }
}

send_json(['ok' => true, 'wc_push_errors' => $pushErrors]);
