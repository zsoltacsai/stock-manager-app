<?php

declare(strict_types=1);
require __DIR__ . '/_bootstrap.php';
require_once __DIR__ . '/../../src/Settings.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    send_json(['error' => 'POST only'], 405);
}

$input = json_input();
$saleId = (int) ($input['sale_id'] ?? 0);
$email = trim((string) ($input['email'] ?? ''));

if (!$saleId) {
    send_json(['error' => 'Hiányzó sale_id.'], 400);
}
if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    send_json(['error' => 'Érvénytelen email cím.'], 400);
}

$sale = $db->getSaleWithItems($saleId);
if (!$sale) {
    send_json(['error' => 'Az eladás nem található.'], 404);
}

$settings = (new Settings(__DIR__ . '/../../data/settings.json'))->read();
$shop = $config['shop'];

$rows = '';
foreach ($sale['items'] as $item) {
    $lineTotal = number_format($item['unit_price'] * $item['qty'], 0, ',', ' ');
    $rows .= '<tr>'
        . '<td style="padding:4px 8px;">' . htmlspecialchars($item['name']) . '</td>'
        . '<td style="padding:4px 8px; text-align:right;">' . (int) $item['qty'] . '</td>'
        . '<td style="padding:4px 8px; text-align:right;">' . number_format((float) $item['unit_price'], 0, ',', ' ') . ' Ft</td>'
        . '<td style="padding:4px 8px; text-align:right;">' . $lineTotal . ' Ft</td>'
        . '</tr>';
}

$total = number_format((float) $sale['total'], 0, ',', ' ');
$footerLines = array_values(array_filter(array_map('trim', explode("\n", $settings['receipt_footer_lines'] ?? ''))));
$footerHtml = $footerLines ? '<p style="color:#666; font-size:13px;">' . implode('<br>', array_map('htmlspecialchars', $footerLines)) . '</p>' : '';

$html = '<div style="font-family:Arial,sans-serif; max-width:480px; margin:0 auto;">'
    . '<h2 style="margin-bottom:0;">' . htmlspecialchars($shop['name']) . '</h2>'
    . '<p style="color:#666; margin-top:4px;">' . htmlspecialchars($shop['address']) . '</p>'
    . '<hr>'
    . '<p style="color:#666;">Nyugta #' . $sale['id'] . ' &middot; ' . htmlspecialchars($sale['created_at'])
        . ($sale['szamlazz_invoice_number'] ? ' &middot; Számla: ' . htmlspecialchars($sale['szamlazz_invoice_number']) : '') . '</p>'
    . '<table style="width:100%; border-collapse:collapse;">'
    . '<thead><tr style="text-align:left; border-bottom:1px solid #ddd;"><th style="padding:4px 8px;">Termék</th><th style="padding:4px 8px; text-align:right;">Menny.</th><th style="padding:4px 8px; text-align:right;">Egységár</th><th style="padding:4px 8px; text-align:right;">Össz.</th></tr></thead>'
    . '<tbody>' . $rows . '</tbody>'
    . '</table>'
    . '<p style="text-align:right; font-weight:bold; font-size:18px; margin-top:12px;">Összesen: ' . $total . ' Ft</p>'
    . $footerHtml
    . '</div>';

$subject = '=?UTF-8?B?' . base64_encode('Nyugta #' . $sale['id'] . ' — ' . $shop['name']) . '?=';
$fromName = '=?UTF-8?B?' . base64_encode($shop['name']) . '?=';
$headers = "MIME-Version: 1.0\r\n"
    . "Content-Type: text/html; charset=UTF-8\r\n"
    . "From: $fromName <no-reply@" . ($_SERVER['SERVER_NAME'] ?? 'localhost') . ">\r\n";

$sent = @mail($email, $subject, $html, $headers);

if (!$sent) {
    send_json([
        'error' => 'A levél küldése sikertelen. Ez a szerver PHP mail() funkcióját használja, aminek működéséhez '
            . 'egy konfigurált levelezőszerverre (pl. sendmail/postfix) van szükség — sok helyi/fejlesztői '
            . 'környezetben ez alapból nincs beállítva.',
    ], 500);
}

send_json(['success' => true]);
