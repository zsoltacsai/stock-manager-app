<?php

declare(strict_types=1);
require __DIR__ . '/_bootstrap.php';

$filters = [
    'date'  => $_GET['date'] ?? null,
    'id'    => $_GET['id'] ?? null,
    'query' => $_GET['query'] ?? null,
];
$filters = array_filter($filters, static fn ($v) => $v !== null && $v !== '');

$purchases = $db->listPurchases($filters, 10000);

header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename="beszerzesek.csv"');
echo "\xEF\xBB\xBF";

$out = fopen('php://output', 'w');
fputcsv($out, ['Azonosító', 'Dátum', 'Beszállító', 'Nettó összeg', 'Bruttó összeg', 'Fizetési mód', 'Fizetve'], ';');
foreach ($purchases as $p) {
    fputcsv($out, [
        $p['id'], $p['created_at'], $p['supplier_name'] ?? '',
        $p['total_net'], $p['total_gross'], $p['payment_method'], $p['paid'] ? 'Igen' : 'Nem',
    ], ';');
}
fclose($out);
