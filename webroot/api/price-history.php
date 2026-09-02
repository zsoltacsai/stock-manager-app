<?php

declare(strict_types=1);
require __DIR__ . '/_bootstrap.php';

$productId = (int) ($_GET['product_id'] ?? 0);
if (!$productId) {
    send_json(['error' => 'Hiányzó product_id.'], 400);
}

send_json(['history' => $db->getPriceHistory($productId)]);
