<?php

declare(strict_types=1);
require __DIR__ . '/_bootstrap.php';
require_once __DIR__ . '/../../src/Settings.php';

$saleId = (int) ($_GET['sale_id'] ?? 0);
$sale = $db->getSaleWithItems($saleId);

if (!$sale) {
    send_json(['error' => 'Az eladás nem található.'], 404);
}
unset($sale['receipt_token']); // titkos token — sose menjen ki, még bejelentkezett dolgozónak sem

$settings = new Settings(__DIR__ . '/../../data/settings.json');
$s = $settings->read();

function logo_url_for_receipt(?string $filename): string
{
    if ($filename && is_file(__DIR__ . '/../assets/' . $filename)) {
        return 'assets/' . $filename . '?v=' . filemtime(__DIR__ . '/../assets/' . $filename);
    }
    return 'assets/logo-default.svg';
}

send_json([
    'sale' => $sale,
    'shop' => $config['shop'],
    'printer_enabled' => !empty($s['printer_enabled']) && !empty($s['printer_ip']),
    'receipt' => [
        'header_lines' => array_values(array_filter(array_map('trim', explode("\n", $s['receipt_header_lines'] ?? '')))),
        'footer_lines' => array_values(array_filter(array_map('trim', explode("\n", $s['receipt_footer_lines'] ?? '')))),
        'show_logo'    => !empty($s['receipt_show_logo']),
        'logo_url'     => logo_url_for_receipt($s['logo_filename'] ?? null),
    ],
]);
