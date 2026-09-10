<?php

declare(strict_types=1);
require __DIR__ . '/_bootstrap.php';

// P1-5 (Számlázz.hu bizonytalan kimenetel) kézi feloldása — csak admin
// jogszinttel, ugyanúgy, mint a NAV-oldali nav-invoice-retry.php. Ez a
// végpont EXPLICIT admin-döntést rögzít arról, hogy egy korábbi,
// transport-hiba miatt bizonytalan Számlázz.hu-kísérlet valójában
// létrehozott-e számlát (a Számlázz.hu felületén ellenőrizve) — sose
// indít újra automatikusan egy Számlázz.hu-hívást saját maga, csak a
// helyi állapotot rendezi (lásd Database::resolveUncertainSzamlazzInvoice()).
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    send_json(['error' => 'POST only'], 405);
}
require_admin($db);

$input = json_input();
$saleId = (int) ($input['sale_id'] ?? 0);
if ($saleId <= 0) {
    send_json(['error' => 'Érvénytelen sale_id.'], 400);
}

$sale = $db->getSaleWithItems($saleId);
if (!$sale) {
    send_json(['error' => 'Az eladás nem található.'], 404);
}
if (($sale['status'] ?? '') !== 'invoice_uncertain') {
    send_json(['error' => 'Ez az eladás jelenleg nincs "bizonytalan" számlázási állapotban.'], 409);
}

$foundInvoiceNumber = isset($input['invoice_number']) ? trim((string) $input['invoice_number']) : '';
$resolved = $db->resolveUncertainSzamlazzInvoice($saleId, $foundInvoiceNumber !== '' ? $foundInvoiceNumber : null);
if (!$resolved) {
    send_json(['error' => 'A feloldás sikertelen (időközben megváltozott az állapot).'], 409);
}

send_json(['ok' => true, 'sale' => $db->getSaleWithItems($saleId)]);
