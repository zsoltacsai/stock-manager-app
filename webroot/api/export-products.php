<?php

declare(strict_types=1);
require __DIR__ . '/_bootstrap.php';
require_once __DIR__ . '/../../src/SimpleXlsWriter.php';

$format = ($_GET['format'] ?? 'csv') === 'xls' ? 'xls' : 'csv';
$idsParam = trim((string) ($_GET['ids'] ?? ''));
$ids = $idsParam !== '' ? array_values(array_unique(array_map('intval', explode(',', $idsParam)))) : [];

$products = $ids ? array_values($db->findProductsByIds($ids)) : $db->listProducts(100000, true);

// A rövid/hosszú leírás a TinyMCE szerkesztőből HTML-ként van tárolva —
// exportba egyszerű, olvasható szövegként kerül (tageket eltávolítva,
// a whitespace összevonva), nem nyers HTML-ként.
$plainText = static function (?string $html): string {
    if (!$html) {
        return '';
    }
    $text = html_entity_decode(strip_tags(str_replace(['<br>', '<br/>', '<br />', '</p>'], "\n", $html)), ENT_QUOTES | ENT_HTML5, 'UTF-8');
    return trim(preg_replace('/[ \t]+/', ' ', $text));
};

$headers = [
    'Megnevezés', 'Cikkszám', 'Csoport', 'Vonalkód', 'Mértékegység', 'Készlet',
    'Nettó Beszerzési ár', 'Nettó Eladási ár', 'Bruttó Eladási ár', 'Áfa', 'Márka',
    'Rövid leírás', 'Hosszú leírás', 'Kép alt szövege',
    'Webshopban feltüntetve', 'Törölve',
];
$rows = array_map(static fn(array $p): array => [
    $p['name'], $p['cikkszam'] ?? '', $p['group_name'] ?? '', $p['barcode'] ?? '', $p['unit'] ?? '',
    (int) $p['stock_qty'], (float) $p['purchase_price_net'], (float) $p['net_price'], (float) $p['price'],
    (string) $p['vat_rate'], $p['brand'] ?? '',
    $plainText($p['short_description'] ?? null), $plainText($p['long_description'] ?? null), $p['image_alt'] ?? '',
    !empty($p['show_webshop']) ? 'Igen' : 'Nem', !empty($p['is_deleted']) ? 'Igen' : 'Nem',
], $products);

if ($format === 'xls') {
    SimpleXlsWriter::output('arucikkek.xls', $headers, $rows);
    exit;
}

header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename="arucikkek.csv"');
echo "\xEF\xBB\xBF";
$out = fopen('php://output', 'w');
fputcsv($out, $headers, ';');
foreach ($rows as $row) {
    fputcsv($out, $row, ';');
}
fclose($out);
