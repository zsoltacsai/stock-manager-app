<?php

declare(strict_types=1);
require __DIR__ . '/_bootstrap.php';

$productId = (int) ($_GET['product_id'] ?? 0);
if ($productId <= 0) {
    send_json(['error' => 'Érvénytelen product_id.'], 400);
}
if (!$db->findProductById($productId)) {
    send_json(['error' => 'A termék nem található.'], 404);
}
$windowDays = max(1, min(90, (int) ($_GET['window_days'] ?? 30)));

$result = $db->getStockForecastBulk([$productId], $windowDays);
send_json(['forecast' => $result[$productId] ?? null]);
