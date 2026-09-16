<?php

declare(strict_types=1);
require __DIR__ . '/_bootstrap.php';

$filter = ($_GET['filter'] ?? 'low') === 'out' ? 'out' : 'low';
$lowStockThreshold = (int) ($appSettings['low_stock_default_threshold'] ?? 5);

$rows = $db->getLowStockReport($lowStockThreshold, $filter);

$windowDays = max(1, min(90, (int) ($_GET['window_days'] ?? 30)));
if ($rows) {
    $forecast = $db->getStockForecastBulk(array_column($rows, 'id'), $windowDays);
    foreach ($rows as &$row) {
        $row['forecast'] = $forecast[$row['id']] ?? null;
    }
    unset($row);
}

send_json(['products' => $rows, 'filter' => $filter]);
