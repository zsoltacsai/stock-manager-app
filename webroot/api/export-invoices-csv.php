<?php

declare(strict_types=1);
require __DIR__ . '/_bootstrap.php';

$filters = [
    'date'     => $_GET['date'] ?? null,
    'id'       => $_GET['id'] ?? null,
    'provider' => $_GET['provider'] ?? null,
    'status'   => $_GET['status'] ?? null,
    'query'    => $_GET['query'] ?? null,
];
$filters = array_filter($filters, static fn ($v) => $v !== null && $v !== '');

$invoices = $db->listInvoices($filters, 10000);

header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename="kimeno-szamlak.csv"');

echo "\xEF\xBB\xBF";

$out = fopen('php://output', 'w');
fputcsv($out, ['Azonosító', 'Dátum', 'Számlaszám', 'Szolgáltató', 'Vevő', 'Nettó', 'ÁFA', 'Bruttó', 'Állapot', 'NAV tranzakcióazonosító'], ';');
foreach ($invoices as $i) {
    fputcsv($out, [
        $i['id'], $i['created_at'], csv_safe($i['invoice_number'] ?? ''),
        $i['provider'] === 'nav' ? 'NAV' : 'Számlázz.hu', csv_safe($i['sale_buyer_name'] ?? ''),
        $i['net_total'], $i['vat_total'], $i['gross_total'], $i['status'],
        csv_safe($i['provider_ref'] ?? ''),
    ], ';');
}
fclose($out);
