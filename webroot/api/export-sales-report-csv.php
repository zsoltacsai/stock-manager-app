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
$report = $db->getSalesReportSummary($resolved['from'], $resolved['to'], $paymentMethod !== '' ? $paymentMethod : null);

header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename="forgalmi-riport.csv"');
echo "\xEF\xBB\xBF";

$out = fopen('php://output', 'w');
fputcsv($out, ['Dátum', 'Bruttó forgalom', 'Nettó forgalom', 'Eladások száma'], ';');
foreach ($report['by_day'] as $row) {
    fputcsv($out, [$row['date'], $row['gross'], $row['net'], $row['count']], ';');
}
fputcsv($out, [], ';');
fputcsv($out, ['Fizetési mód', 'Összeg', 'Darabszám', 'Százalék'], ';');
foreach ($report['by_payment_method'] as $method => $row) {
    fputcsv($out, [csv_safe($method), $row['total'], $row['count'], $row['percent']], ';');
}
fputcsv($out, [], ';');
fputcsv($out, ['Összesen bruttó', $report['total_gross']], ';');
fputcsv($out, ['Összesen nettó', $report['total_net']], ';');
fputcsv($out, ['Összesen ÁFA', $report['total_vat']], ';');
fputcsv($out, ['Visszáru összesen', $report['total_returns']], ';');
fclose($out);
