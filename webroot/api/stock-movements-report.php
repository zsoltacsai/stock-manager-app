<?php

declare(strict_types=1);
require __DIR__ . '/_bootstrap.php';
require_once __DIR__ . '/../../src/ReportPeriod.php';

$period = (string) ($_GET['period'] ?? 'last_30_days');
try {
    $resolved = ReportPeriod::resolve($period, $_GET['date_from'] ?? null, $_GET['date_to'] ?? null);
} catch (InvalidArgumentException $e) {
    send_json(['error' => $e->getMessage()], 400);
}

$allowedTypes = ['sale', 'purchase', 'return', 'stock_take', 'transfer'];
$type = $_GET['type'] ?? '';
if ($type !== '' && !in_array($type, $allowedTypes, true)) {
    send_json(['error' => 'Érvénytelen mozgástípus.'], 400);
}

$productId = isset($_GET['product_id']) && $_GET['product_id'] !== '' ? (int) $_GET['product_id'] : null;
$limit = max(1, min(500, (int) ($_GET['limit'] ?? 100)));
$offset = max(0, (int) ($_GET['offset'] ?? 0));

$filters = [
    'date_from'  => $resolved['from'],
    'date_to'    => $resolved['to'],
    'type'       => $type !== '' ? $type : null,
    'product_id' => $productId,
];

send_json($db->getStockMovements($filters, $limit, $offset) + ['period' => $resolved]);
