<?php

declare(strict_types=1);
require __DIR__ . '/_bootstrap.php';

$filters = [
    'location_id'      => $_GET['location_id'] ?? null,
    'cash_register_id' => $_GET['cash_register_id'] ?? null,
    'staff_id'         => $_GET['staff_id'] ?? null,
    'status'           => $_GET['status'] ?? null,
    'date_from'        => $_GET['date_from'] ?? null,
    'date_to'          => $_GET['date_to'] ?? null,
];
$filters = array_filter($filters, static fn ($v) => $v !== null && $v !== '');

$sessions = $db->listCashSessions($filters);

header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename="kassza-riport.csv"');

echo "\xEF\xBB\xBF";

$out = fopen('php://output', 'w');
fputcsv($out, [
    'Azonosító', 'Telephely', 'Pénztárgép', 'Kasszás', 'Állapot',
    'Nyitás', 'Zárás', 'Nyitó összeg', 'Számolt összeg', 'Várható összeg', 'Eltérés',
], ';');
foreach ($sessions as $s) {
    fputcsv($out, [
        $s['id'],
        csv_safe($s['location_name'] ?? ''),
        csv_safe($s['register_name'] ?? ''),
        csv_safe($s['staff_name'] ?? ''),
        $s['status'] === 'open' ? 'Nyitva' : 'Zárva',
        $s['opened_at'],
        $s['closed_at'] ?? '',
        $s['opening_amount'],
        $s['closing_amount'] ?? '',
        $s['expected_amount'] ?? '',
        $s['variance'] ?? '',
    ], ';');
}
fclose($out);
