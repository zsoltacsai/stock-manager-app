<?php

declare(strict_types=1);
require __DIR__ . '/_bootstrap.php';

$filters = [
    'date'  => $_GET['date'] ?? null,
    'id'    => $_GET['id'] ?? null,
    'query' => $_GET['query'] ?? null,
];
$filters = array_filter($filters, static fn ($v) => $v !== null && $v !== '');

$sales = $db->listSales($filters, 10000);

header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename="eladasok.csv"');

echo "\xEF\xBB\xBF";

$out = fopen('php://output', 'w');
fputcsv($out, ['Azonosító', 'Dátum', 'Összeg', 'Fizetési mód', 'Vevő', 'Számlaszám', 'Állapot'], ';');
foreach ($sales as $s) {
    fputcsv($out, [
        $s['id'], $s['created_at'], $s['total'], $s['payment_method'],
        $s['buyer_name'] ?? '', $s['szamlazz_invoice_number'] ?? '', $s['status'],
    ], ';');
}
fclose($out);
