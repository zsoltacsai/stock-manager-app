<?php

declare(strict_types=1);
require __DIR__ . '/_bootstrap.php';

$date = $_GET['date'] ?? date('Y-m-d');
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
    send_json(['error' => 'Érvénytelen dátum formátum (YYYY-MM-DD szükséges).'], 400);
}

$summary = $db->getDailySummary($date);

header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename="napi-zaras-' . $date . '.csv"');
echo "\xEF\xBB\xBF";

$out = fopen('php://output', 'w');
// Az "Összeg" a fizetési módon befizetett rész; az utolsó oszlop az
// ajándékutalvánnyal fedezett rész — a kettő összege az eladás értéke,
// amiből az "Összesen (bruttó)" sor is számol (Database::saleGrossValue()).
fputcsv($out, ['Azonosító', 'Időpont', 'Összeg', 'Fizetési mód', 'Vevő', 'Számlaszám', 'Ajándékutalvány'], ';');
foreach ($summary['sales'] as $s) {
    fputcsv($out, [
        $s['id'], $s['created_at'], $s['total'], csv_safe($s['payment_method']),
        csv_safe($s['buyer_name'] ?? ''), csv_safe($s['szamlazz_invoice_number'] ?? ''),
        $s['gift_card_redeemed'] ?? 0,
    ], ';');
}
fputcsv($out, [], ';');
fputcsv($out, ['Összesen (bruttó)', $summary['total_gross']], ';');
fputcsv($out, ['Összesen (nettó)', $summary['total_net']], ';');
fputcsv($out, ['ÁFA összesen', $summary['total_vat']], ';');
fclose($out);
