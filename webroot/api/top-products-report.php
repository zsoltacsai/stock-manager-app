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

$groupName = trim((string) ($_GET['group'] ?? ''));
$minQty = max(0, (int) ($_GET['min_qty'] ?? 0));
$limit = max(1, min(200, (int) ($_GET['limit'] ?? 50)));

send_json([
    'products' => $db->getTopProductsReport($resolved['from'], $resolved['to'], $groupName !== '' ? $groupName : null, $minQty, $limit),
    'period'   => $resolved,
]);
