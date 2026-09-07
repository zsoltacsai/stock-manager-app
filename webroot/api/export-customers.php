<?php

declare(strict_types=1);
require __DIR__ . '/_bootstrap.php';
require_once __DIR__ . '/../../src/SimpleXlsWriter.php';

$format = ($_GET['format'] ?? 'csv') === 'xls' ? 'xls' : 'csv';
$idsParam = trim((string) ($_GET['ids'] ?? ''));
$ids = $idsParam !== '' ? array_values(array_unique(array_map('intval', explode(',', $idsParam)))) : [];

$customers = $ids ? array_values($db->findCustomersByIds($ids)) : $db->listCustomers(true);

$headers = [
    'Név', 'Telefon', 'Email', 'Irányítószám', 'Település', 'Cím', 'Ország', 'Adószám',
    'Hűségpontok', 'Összes költés', 'Megjegyzés', 'Törölve',
];
$rows = array_map(static fn(array $c): array => [
    $c['name'], $c['phone'] ?? '', $c['email'] ?? '', $c['zip'] ?? '', $c['city'] ?? '', $c['address'] ?? '',
    $c['country'] ?? '', $c['tax_number'] ?? '', (int) $c['loyalty_points'], (float) $c['total_spent'],
    $c['notes'] ?? '', !empty($c['is_deleted']) ? 'Igen' : 'Nem',
], $customers);

// A CSV-exportnál már régóta megvolt a képlet-injekció (CWE-1236) elleni
// védelem — az XLS-exportnak (más íróra, SimpleXlsWriter-re épül) eddig
// NEM volt. Mindkét formátumhoz UGYANAZT a szűrt sortömböt használjuk.
$safeRows = array_map(
    static fn($row) => array_map(static fn($v) => is_string($v) ? csv_safe($v) : $v, $row),
    $rows
);

if ($format === 'xls') {
    SimpleXlsWriter::output('vasarlok.xls', $headers, $safeRows);
    exit;
}

header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename="vasarlok.csv"');
echo "\xEF\xBB\xBF";
$out = fopen('php://output', 'w');
fputcsv($out, $headers, ';');
foreach ($safeRows as $row) {
    fputcsv($out, $row, ';');
}
fclose($out);
