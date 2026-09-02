<?php

declare(strict_types=1);
require __DIR__ . '/_bootstrap.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    send_json(['error' => 'POST only'], 405);
}

$input = json_input();
$stockTakeId = (int) ($input['stock_take_id'] ?? 0);
$productId = (int) ($input['product_id'] ?? 0);
$countedQty = $input['counted_qty'] === null || $input['counted_qty'] === '' ? null : (int) $input['counted_qty'];

if (!$stockTakeId || !$productId) {
    send_json(['error' => 'Hiányzó stock_take_id vagy product_id.'], 400);
}

$db->updateStockTakeCount($stockTakeId, $productId, $countedQty);
send_json(['ok' => true]);
