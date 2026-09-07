<?php

declare(strict_types=1);
require __DIR__ . '/_bootstrap.php';

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
// mielőtt bármelyik írna) — a TÉNYLEGES, atomikus védelmet az alábbi
// tryClaimInvoiceIssuance() adja (UPDATE ... WHERE feltétellel, lásd
// Database.php docblockja), ami garantálja, hogy két egyidejű kérés közül
// csak EGYIK hívhatja ténylegesen a Számlázz.hu-t. Egy korábban SIKERTELEN
// (invoice_failed) kísérlet után szándékosan engedjük az újrapróbálkozást.
if (!empty($sale['szamlazz_invoice_number'])) {
    send_json(['error' => 'Ehhez az eladáshoz már tartozik számla (' . $sale['szamlazz_invoice_number'] . ').'], 409);
}
if (!$db->tryClaimInvoiceIssuance((int) $sale['id'])) {
    send_json(['error' => 'A számla kiállítása már folyamatban van (egy másik kérés éppen most dolgozza fel).'], 409);
}

$invoiceItems = array_map(fn($i) => [
    'name'             => $i['name'],
    'qty'              => $i['qty'],
    'unit_price_gross' => $i['unit_price'],
    'vat_rate'         => $i['vat_rate'],
], $sale['items']);

$szamlazz = new SzamlazzClient($config['szamlazz']);
try {
    $invoiceResult = $szamlazz->createInvoice($buyer, $invoiceItems, (string) $sale['id'], null, $sale['payment_method']);
} catch (Throwable $e) {
    $invoiceResult = ['success' => false, 'invoice_number' => null, 'pdf_path' => null, 'error' => $e->getMessage()];
}

// attachInvoiceToSale() sikertelenség esetén is felszabadítja a fenti
// foglalást, engedve egy azonnali manuális újrapróbálkozást.
$db->attachInvoiceToSale(
    (int) $sale['id'],
    $invoiceResult['invoice_number'] ?? null,
    $invoiceResult['pdf_path'] ?? null,
    $invoiceResult['success'] ? 'completed' : 'invoice_failed'
);

send_json(['ok' => true, 'invoice' => $invoiceResult]);
