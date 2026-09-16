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

$paymentMethod = trim((string) ($_GET['payment_method'] ?? ''));

send_json([
    'report' => $db->getSalesReportSummary($resolved['from'], $resolved['to'], $paymentMethod !== '' ? $paymentMethod : null),
]);
