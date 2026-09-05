<?php

declare(strict_types=1);
require __DIR__ . '/_bootstrap.php';
require_once __DIR__ . '/../../src/SimpleXlsWriter.php';

$format = ($_GET['format'] ?? 'csv') === 'xls' ? 'xls' : 'csv';
$idsParam = trim((string) ($_GET['ids'] ?? ''));
$ids = $idsParam !== '' ? array_values(array_unique(array_map('intval', explode(',', $idsParam)))) : [];

$customers = $ids ? array_values($db->findCustomersByIds($ids)) : $db->listCustomers(true);

$headers = [
    'Név', 'Telefon', 'Email', 'Irányítószám', 'Település', 'Cím', 'Adószám',
    'Hűségpontok', 'Összes költés', 'Törölve',
];
$rows = array_map(static fn(array $c): array => [
    $c['name'], $c['phone'] ?? '', $c['email'] ?? '', $c['zip'] ?? '', $c['city'] ?? '', $c['address'] ?? '',
    $c['tax_number'] ?? '', (int) $c['loyalty_points'], (float) $c['total_spent'],
    !empty($c['is_deleted']) ? 'Igen' : 'Nem',
], $customers);

if ($format === 'xls') {
    SimpleXlsWriter::output('vasarlok.xls', $headers, $rows);
    exit;
}

header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename="vasarlok.csv"');
echo "\xEF\xBB\xBF";
$out = fopen('php://output', 'w');
fputcsv($out, $headers, ';');
foreach ($rows as $row) {
    fputcsv($out, $row, ';');
}
fclose($out);
