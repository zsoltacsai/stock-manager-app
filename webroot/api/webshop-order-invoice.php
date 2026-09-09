<?php

declare(strict_types=1);
require __DIR__ . '/_bootstrap.php';
require_once __DIR__ . '/../../src/InvoiceService.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    send_json(['error' => 'POST only'], 405);
}

$input = json_input();
$id = (int) ($input['id'] ?? 0);

$order = $id ? $db->getWebshopOrder($id) : null;
if (!$order) {
    send_json(['error' => 'A rendelés nem található.'], 404);
}
if ($order['status'] !== 'confirmed' || !$order['sale_id']) {
    send_json(['error' => 'Ehhez a rendeléshez csak leadás után állítható ki számla.'], 400);
}

$buyer = $order['billing'] ?? [];
if (empty($buyer['nev']) || empty($buyer['irsz']) || empty($buyer['telepules']) || empty($buyer['cim'])) {
    send_json(['error' => 'A rendelés számlázási címe hiányos (név/irányítószám/település/cím szükséges).'], 400);
}

$sale = $db->getSaleWithItems((int) $order['sale_id']);
if (!$sale) {
    send_json(['error' => 'A rendeléshez tartozó eladás nem található.'], 404);
}
// Ha az eladáshoz már tartozik sikeresen kiállított számla, ne állítsunk ki
// egy másodikat. Ez a sima SELECT-ellenőrzés önmagában versenyhelyzetes
// (két majdnem egyidejű kérés mindkettő "nincs még számla"-t olvashatna,
// mielőtt bármelyik írna) — a TÉNYLEGES, atomikus védelmet a kiválasztott
// provider (jelenleg: SzamlazzInvoiceProvider) belül hívott
// tryClaimInvoiceIssuance() adja (UPDATE ... WHERE feltétellel, lásd
// Database.php docblockja), ami garantálja, hogy két egyidejű kérés közül
// csak EGYIK hívhatja ténylegesen a szolgáltatót. Egy korábban SIKERTELEN
// (invoice_failed) kísérlet után szándékosan engedjük az újrapróbálkozást.
if (!empty($sale['szamlazz_invoice_number'])) {
    send_json(['error' => 'Ehhez az eladáshoz már tartozik számla (' . $sale['szamlazz_invoice_number'] . ').'], 409);
}

$invoiceItems = array_map(fn($i) => [
    'name'             => $i['name'],
    'qty'              => $i['qty'],
    'unit_price_gross' => $i['unit_price'],
    'vat_rate'         => $i['vat_rate'],
], $sale['items']);

$netTotal = 0.0;
foreach ($invoiceItems as $ii) {
    $vatPct = is_numeric($ii['vat_rate']) ? ((float) $ii['vat_rate']) / 100 : 0.0;
    $lineGross = (float) $ii['unit_price_gross'] * (float) $ii['qty'];
    $netTotal += is_numeric($ii['vat_rate']) ? round($lineGross / (1 + $vatPct), 2) : $lineGross;
}
$grossTotal = round((float) $sale['total'], 2);
$vatTotal = round($grossTotal - $netTotal, 2);

$invoiceService = new InvoiceService($config, $appSettings);
$invoiceResult = $invoiceService->processInvoice([
    'db'             => $db,
    'sale_id'        => (int) $sale['id'],
    'buyer'          => $buyer,
    'items'          => $invoiceItems,
    'language'       => null,
    'payment_method' => $sale['payment_method'],
    'totals'         => [
        'net' => $netTotal, 'vat' => $vatTotal, 'gross' => $grossTotal,
        'currency' => $config['szamlazz']['currency'] ?? 'HUF',
    ],
]);

// A "már folyamatban van" eset a provider belső
// (tryClaimInvoiceIssuance-alapú) foglalásán keresztül érkezik vissza —
// de a válasz HTTP-szintje VÁLTOZATLAN marad a refaktor előttihez képest
// (409), a SzamlazzInvoiceProvider által jelzett 'already_in_progress'
// jelző alapján, nem a hibaüzenet szövegére támaszkodva.
if (!empty($invoiceResult['already_in_progress'])) {
    send_json(['error' => $invoiceResult['error']], 409);
}

send_json(['ok' => true, 'invoice' => $invoiceResult]);
