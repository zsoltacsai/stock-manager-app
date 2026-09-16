<?php

declare(strict_types=1);
require __DIR__ . '/_bootstrap.php';

$lowStockThreshold = (int) ($appSettings['low_stock_default_threshold'] ?? 5);
$overview = $db->getInventoryOverview($lowStockThreshold, 100000);

header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename="keszlet-ertek.csv"');
echo "\xEF\xBB\xBF";

$out = fopen('php://output', 'w');
fputcsv($out, ['Termék', 'Vonalkód', 'Készlet', 'Beszerzési ár (nettó)', 'Készletérték (nettó)'], ';');
foreach ($overview['top_by_value'] as $row) {
    fputcsv($out, [csv_safe($row['name']), csv_safe($row['barcode'] ?? ''), $row['stock_qty'], $row['purchase_price_net'], $row['value']], ';');
}
fputcsv($out, [], ';');
fputcsv($out, ['Összes termék', $overview['total_products']], ';');
fputcsv($out, ['Készleten', $overview['in_stock']], ';');
fputcsv($out, ['Nulla készlet', $overview['zero_stock']], ';');
fputcsv($out, ['Negatív készlet', $overview['negative_stock']], ';');
fputcsv($out, ['Alacsony készlet', $overview['low_stock']], ';');
fputcsv($out, ['Teljes készletérték (nettó)', $overview['stock_value_net']], ';');
fclose($out);
