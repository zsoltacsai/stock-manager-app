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

$result = $db->getStockMovements([
    'date_from'  => $resolved['from'],
    'date_to'    => $resolved['to'],
    'type'       => $type !== '' ? $type : null,
    'product_id' => $productId,
], 10000, 0);

$typeLabels = ['sale' => 'Eladás', 'purchase' => 'Beszerzés', 'return' => 'Visszáru', 'stock_take' => 'Leltár', 'transfer' => 'Készlet hozzáadás'];

header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename="keszletmozgasok.csv"');
echo "\xEF\xBB\xBF";

$out = fopen('php://output', 'w');
fputcsv($out, ['Dátum', 'Termék', 'Típus', 'Mennyiség változás', 'Hivatkozás'], ';');
foreach ($result['movements'] as $m) {
    fputcsv($out, [
        $m['date'], csv_safe($m['product_name'] ?? ''), $typeLabels[$m['type']] ?? $m['type'],
        $m['qty_change'], $m['source_type'] . '#' . $m['source_ref'],
    ], ';');
}
fclose($out);
