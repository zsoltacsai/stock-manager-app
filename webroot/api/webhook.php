<?php

declare(strict_types=1);
require __DIR__ . '/_bootstrap.php';

$rawBody = file_get_contents('php://input');
$signature = $_SERVER['HTTP_X_WC_WEBHOOK_SIGNATURE'] ?? '';

$expected = base64_encode(hash_hmac('sha256', $rawBody, $config['woocommerce']['webhook_secret'], true));

if (!$signature || !hash_equals($expected, $signature)) {
    send_json(['error' => 'Invalid webhook signature'], 401);
}

$order = json_decode($rawBody, true);
if (!is_array($order) || empty($order['line_items'])) {
    send_json(['ignored' => true]);
}

$status = $order['status'] ?? '';
$relevant = in_array($status, ['processing', 'completed'], true);

if (!$relevant) {
    send_json(['ignored' => true, 'status' => $status]);
}

$wcOrderId = (int) ($order['id'] ?? 0);
if ($wcOrderId && !$db->markWebhookOrderProcessed($wcOrderId)) {
    send_json(['ignored' => true, 'reason' => 'already processed', 'order_id' => $wcOrderId]);
}

$updated = [];
foreach ($order['line_items'] as $li) {
    $wcProductId = (int) ($li['product_id'] ?? 0);
    $qty = (int) ($li['quantity'] ?? 0);
    if (!$wcProductId || !$qty) {
        continue;
    }

    $product = $db->findProductByWcId($wcProductId);
    if (!$product) {
        $db->logSync('webhook', null, "Order #{$order['id']}: unknown wc_product_id $wcProductId");
        continue;
    }

    $newQty = (int) $product['stock_qty'] - $qty;
    $db->decrementStock($product['id'], $qty);
    $db->logSync('webhook', $product['id'], "Order #{$order['id']}: -$qty (now $newQty)");
    $updated[] = ['product_id' => $product['id'], 'name' => $product['name'], 'new_stock' => $newQty];
}

send_json(['ok' => true, 'updated' => $updated]);
