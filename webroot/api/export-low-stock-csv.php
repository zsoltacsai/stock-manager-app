<?php

declare(strict_types=1);
require __DIR__ . '/_bootstrap.php';

$filter = ($_GET['filter'] ?? 'low') === 'out' ? 'out' : 'low';
$lowStockThreshold = (int) ($appSettings['low_stock_default_threshold'] ?? 5);
$rows = $db->getLowStockReport($lowStockThreshold, $filter);

header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename="alacsony-keszlet.csv"');
echo "\xEF\xBB\xBF";

$out = fopen('php://output', 'w');
fputcsv($out, ['Termék', 'Vonalkód', 'Csoport', 'Készlet', 'Minimum készlet', 'Javasolt rendelési mennyiség', 'Beszállító'], ';');
foreach ($rows as $row) {
    fputcsv($out, [
        csv_safe($row['name']), csv_safe($row['barcode'] ?? ''), csv_safe($row['group_name'] ?? ''),
        $row['stock_qty'], $row['threshold'], $row['suggested_qty'], csv_safe($row['supplier_name'] ?? ''),
    ], ';');
}
fclose($out);
