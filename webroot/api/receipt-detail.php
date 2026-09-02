<?php

declare(strict_types=1);
require __DIR__ . '/_bootstrap.php';
require_once __DIR__ . '/../../src/Settings.php';

$saleId = (int) ($_GET['sale_id'] ?? 0);
$token = (string) ($_GET['token'] ?? '');

if (!$saleId) {
    send_json(['error' => 'Hiányzó sale_id.'], 400);
}

// Bejelentkezett dolgozó bármelyik eladást megnézheti token nélkül is —
// a token csak azoknak kell, akik a nyomtatott nyugta QR-kódján keresztül,
// bejelentkezés nélkül érkeznek.
if (Auth::isLoggedIn($appSettings)) {
    $sale = $db->getSaleWithItems($saleId);
} else {
    if ($token === '') {
        send_json(['error' => 'Hiányzó token.'], 401);
    }
    $sale = $db->getSaleWithItemsByToken($saleId, $token);
}

if (!$sale) {
    send_json(['error' => 'A nyugta nem található, vagy a link érvénytelen.'], 404);
}

unset($sale['receipt_token']); // sose menjen ki a klienshez, még sikeres egyezés esetén se

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
    'is_staff' => Auth::isLoggedIn($appSettings),
    'printer_enabled' => Auth::isLoggedIn($appSettings) && !empty($s['printer_enabled']) && !empty($s['printer_ip']),
    'receipt' => [
        'header_lines' => array_values(array_filter(array_map('trim', explode("\n", $s['receipt_header_lines'] ?? '')))),
        'footer_lines' => array_values(array_filter(array_map('trim', explode("\n", $s['receipt_footer_lines'] ?? '')))),
        'show_logo'    => !empty($s['receipt_show_logo']),
        'logo_url'     => logo_url_for_receipt(($s['print_logo_filename'] ?? null) ?: ($s['logo_filename'] ?? null)),
    ],
]);
