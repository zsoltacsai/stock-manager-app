<?php

declare(strict_types=1);
require __DIR__ . '/_bootstrap.php';

$lowStockThreshold = (int) ($appSettings['low_stock_default_threshold'] ?? 5);
send_json([
    'overview' => $db->getInventoryOverview($lowStockThreshold, 15),
    // 1.3.0 — készletérték nettó beszerzési ÉS eladási áron + potenciális
    // árrés-érték, lásd Database::getInventoryValuationSummary() docblockja.
    'valuation' => $db->getInventoryValuationSummary(),
]);
