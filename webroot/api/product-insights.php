<?php

declare(strict_types=1);
require __DIR__ . '/_bootstrap.php';

// 1.3.0 — a termék mini-dashboard (termékek.php "Áttekintés" fül)
// EGYETLEN adatforrása — szándékosan egy hívás ad mindent (státusz/árrés,
// 30/90 napos forgalom, forecast, beszerzési előzmény, ártrend), hogy a
// modal megnyitása ne indítson 6-8 külön kérést (lásd a kör 11. pontja).

$productId = (int) ($_GET['product_id'] ?? 0);
if ($productId <= 0) {
    send_json(['error' => 'Érvénytelen product_id.'], 400);
}

$threshold = (int) ($appSettings['low_stock_default_threshold'] ?? 5);
$insights = $db->getProductInsights($productId, $threshold);
if (!$insights) {
    send_json(['error' => 'A termék nem található.'], 404);
}

send_json($insights);
