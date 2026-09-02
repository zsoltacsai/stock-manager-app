<?php

declare(strict_types=1);

date_default_timezone_set('Europe/Budapest');

// Biztonsági HTTP-fejlécek — minden API-válaszra vonatkoznak.
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
header('Referrer-Policy: same-origin');
header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../../src/Database.php';
require_once __DIR__ . '/../../src/WooCommerceClient.php';
require_once __DIR__ . '/../../src/SzamlazzClient.php';
require_once __DIR__ . '/../../src/Settings.php';
require_once __DIR__ . '/../../src/Auth.php';

$config = require __DIR__ . '/../../config/config.php';

// A Beállítások alatt mentett értékek (Számlázz.hu / WooCommerce fülek)
// felülírják a config.php statikus értékeit, ha be vannak állítva, így
// minden végpont, ami SzamlazzClient/WooCommerceClient-et épít, automatikusan
// ezeket kapja meg.
$appSettings = (new Settings(__DIR__ . '/../../data/settings.json'))->read();

// Bejelentkezés-ellenőrzés — minden API-végpontra vonatkozik, kivéve a
// bejelentkezéshez és a telepítő-állapot lekérdezéséhez szükséges pár
// végpontot (ezeknek működniük kell MIELŐTT valaki be van jelentkezve).
// Ez a VALÓDI védelmi réteg: mivel minden adat és minden művelet
// kizárólag ezen az API-n keresztül érhető el, a statikus HTML-oldalak
// megtekintése önmagában nem tesz elérhetővé semmilyen valós adatot.
$authWhitelist = ['login.php', 'logout.php', 'auth-status.php', 'install-status.php', 'receipt-detail.php'];
$currentScript = basename($_SERVER['SCRIPT_NAME'] ?? '');
if (!in_array($currentScript, $authWhitelist, true) && !Auth::isLoggedIn($appSettings)) {
    http_response_code(401);
    echo json_encode(['error' => 'Bejelentkezés szükséges.', 'auth_required' => true], JSON_UNESCAPED_UNICODE);
    exit;
}

if (!empty($appSettings['szamlazz_agent_key'])) {
    $config['szamlazz']['agent_key'] = $appSettings['szamlazz_agent_key'];
}
if (!empty($appSettings['szamlazz_default_payment'])) {
    $config['szamlazz']['payment_method'] = $appSettings['szamlazz_default_payment'];
}
if (!empty($appSettings['szamlazz_default_vat'])) {
    $config['szamlazz']['default_vat_rate'] = $appSettings['szamlazz_default_vat'];
}
$config['szamlazz']['send_email'] = !empty($appSettings['szamlazz_send_email']);

if (!empty($appSettings['wc_store_url'])) {
    $config['woocommerce']['store_url'] = $appSettings['wc_store_url'];
}
if (!empty($appSettings['wc_consumer_key'])) {
    $config['woocommerce']['consumer_key'] = $appSettings['wc_consumer_key'];
}
if (!empty($appSettings['wc_consumer_secret'])) {
    $config['woocommerce']['consumer_secret'] = $appSettings['wc_consumer_secret'];
}
if (!empty($appSettings['wc_barcode_source'])) {
    $config['woocommerce']['barcode_source'] = $appSettings['wc_barcode_source'];
}
if (!empty($appSettings['wc_barcode_meta_key'])) {
    $config['woocommerce']['barcode_meta_key'] = $appSettings['wc_barcode_meta_key'];
}
if (!empty($appSettings['wc_webhook_secret'])) {
    $config['woocommerce']['webhook_secret'] = $appSettings['wc_webhook_secret'];
}

$db = new Database($config['db'], __DIR__ . '/../..');

function json_input(): array
{
    $raw = file_get_contents('php://input');
    $data = json_decode($raw, true);
    return is_array($data) ? $data : [];
}

function send_json($data, int $status = 200): void
{
    http_response_code($status);
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}
