<?php

declare(strict_types=1);
require __DIR__ . '/_bootstrap.php';
require_once __DIR__ . '/../../src/Settings.php';
require_once __DIR__ . '/../../src/NavIncomingInvoiceSync.php';

$id = (int) ($_GET['id'] ?? 0);
$invoice = $id ? $db->getIncomingInvoiceById($id) : null;

if (!$invoice) {
    send_json(['error' => 'A bejövő számla nem található.'], 404);
}

// LAZY részletnézet-lekérdezés: az ELSŐ megnyitáskor (detail_fetched_at
// még NULL) egy queryInvoiceData hívást indít a NAV felé, és elmenti a
// tételsorokat/szállító-országot — minden KÖVETKEZŐ megnyitás már csak a
// helyi adatokat adja vissza, nem terheli feleslegesen a NAV-ot (lásd
// Phase 6 terv 2b. pont indoklása).
$detailError = null;
if (empty($invoice['detail_fetched_at'])) {
    $settings = new Settings(__DIR__ . '/../../data/settings.json');
    $current = $settings->read();

    if (empty($current['nav_login']) || empty($current['nav_password']) || empty($current['nav_tax_number'])) {
        $detailError = 'A NAV Online Számla integráció nincs beállítva — a tételsorok nem lekérdezhetők.';
    } else {
        $navConfig = [
            'nav_login' => $current['nav_login'],
            'nav_password' => $current['nav_password'],
            'nav_signer_key' => $current['nav_signer_key'],
            'nav_exchange_key' => $current['nav_exchange_key'],
            'nav_tax_number' => $current['nav_tax_number'],
            'nav_test_mode' => !empty($current['nav_test_mode']),
        ];
        $sync = new NavIncomingInvoiceSync($db, fn () => new NavClient($navConfig));
        $detailResult = $sync->fetchAndStoreDetail($invoice);

        if ($detailResult['outcome'] === 'success') {
            $invoice = $db->getIncomingInvoiceById($id);
        } elseif ($detailResult['outcome'] === 'not_found') {
            // Nem hiba — a NAV-nak egyszerűen nincs (még) tétel-szintű
            // adata ehhez a számlához (pl. AGGREGATE kategória). A meglévő
            // digest-szintű adatokat továbbra is visszaadjuk.
            $detailError = $detailResult['error'];
        } else {
            $detailError = 'A tételsorok lekérdezése sikertelen: ' . ($detailResult['error'] ?? 'ismeretlen hiba.');
        }
    }
}

$items = $db->getIncomingInvoiceItems($id);

send_json([
    'invoice' => $invoice,
    'items' => $items,
    'detail_error' => $detailError,
]);
