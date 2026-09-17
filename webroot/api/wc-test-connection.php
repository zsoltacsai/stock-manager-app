<?php

declare(strict_types=1);
require __DIR__ . '/_bootstrap.php';
require_once __DIR__ . '/../../src/UrlSafety.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    send_json(['error' => 'POST only'], 405);
}

// Ez a végpont egy kliens által (mentés előtt, tesztelésképp) megadott
// URL-re indít szerver-oldali kérést — vezetői jogszint nélkül ez SSRF-re
// (belső hálózat feltérképezésére) lenne visszaélhető.
require_admin($db);

// A mentés előtti teszteléshez az űrlapon éppen begépelt (még el nem
// mentett) értékeket is elfogadja — ha ezek üresek, a már elmentett
// (Beállításokból/config.php-ból származó) értékekre esik vissza.
$input = json_input();
$wcConfig = $config['woocommerce'];
foreach (['store_url', 'consumer_key', 'consumer_secret'] as $field) {
    if (!empty($input[$field])) {
        $wcConfig[$field] = $input[$field];
    }
}

if (empty($wcConfig['store_url']) || empty($wcConfig['consumer_key']) || empty($wcConfig['consumer_secret'])) {
    send_json(['success' => false, 'error' => 'Az Áruház URL, a Consumer key és a Consumer secret mind kötelező a teszteléshez.']);
}

// Csak nyilvános http(s) host felé engedjük a tesztkérést — enélkül a
// mező (akár egy vezetői fióknál is) belső hálózati címek (127.0.0.1,
// 192.168.x.x, felhő metadata-végpontok stb.) elérhetőségének
// feltérképezésére lenne felhasználható. Ugyanaz a UrlSafety-ellenőrzés,
// mint amit settings.php a mentéskor, és WooCommerceClient minden
// ténylegesen kimenő híváskor (védelmi mélységként) is elvégez.
[$urlOk, $urlError] = UrlSafety::check($wcConfig['store_url']);
if (!$urlOk) {
    // Regresszió (1.4.0, élő böngészős teszteléssel felfedezve): ez az
    // elutasítási ág korábban a try/catch ELŐTT tért vissza, emiatt egy
    // ide eső hiba (pl. fel nem oldható host — a leggyakoribb valódi
    // teszt-kudarc) SOSE került az eseménynaplóba, holott a felhasználó
    // felé helyesen jelent meg a hibaüzenet.
    $db->logSystemEvent('woocommerce', 'test_failed', 'error', 'failure', 'A WooCommerce kapcsolat teszt sikertelen volt.', $urlError, system_event_retention_days($appSettings));
    send_json(['success' => false, 'error' => $urlError]);
}

try {
    $wc = new WooCommerceClient($wcConfig);
    $wc->testConnection();
    $db->logSystemEvent('woocommerce', 'test_success', 'info', 'success', 'A WooCommerce kapcsolat teszt sikeres volt.', null, system_event_retention_days($appSettings));
    send_json(['success' => true]);
} catch (Throwable $e) {
    $db->logSystemEvent('woocommerce', 'test_failed', 'error', 'failure', 'A WooCommerce kapcsolat teszt sikertelen volt.', $e->getMessage(), system_event_retention_days($appSettings));
    send_json(['success' => false, 'error' => $e->getMessage()]);
}
