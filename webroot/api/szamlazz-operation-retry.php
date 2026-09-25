<?php

declare(strict_types=1);
require __DIR__ . '/_bootstrap.php';
require_once __DIR__ . '/../../src/InvoiceService.php';

// Admin-kezdeményezett kézi újrapróbálkozás EGY, korábban bizonytalan/
// sikertelen kimenetelű Számlázz.hu MODIFY/STORNO műveletre — a NAV-nál
// ehhez a MEGLÉVŐ nav-invoice-retry.php való (a NAV queue automatikusan
// felveszi a 'queued'-ra visszaállított sort), a Számlázz.hu viszont
// SZINKRON, tehát itt VALAKINEK ténylegesen újra is el kell indítania a
// kísérletet (lásd InvoiceService::retrySzamlazzOperation() docblockja).
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    send_json(['error' => 'POST only'], 405);
}
require_admin($db);

$input = json_input();
$id = (int) ($input['id'] ?? 0);
if ($id <= 0) {
    send_json(['error' => 'Érvénytelen id.'], 400);
}

$invoice = $db->getInvoiceById($id);
if (!$invoice || $invoice['provider'] !== 'szamlazz' || !in_array($invoice['invoice_type'], ['modification', 'storno'], true)) {
    send_json(['error' => 'A Számlázz.hu-s módosítás/sztornó bejegyzés nem található.'], 404);
}

// N-4: egy BIZONYTALAN kimenetelű műveletnél (a Számlázz.hu válasza
// elveszett, vagy a folyamat a hívás körül meghalt) a számla LÉTREJÖHETETT —
// vakon újrapróbálni egy második valódi számlát állíthatna ki. Az admin csak
// a Számlázz.hu felületén ellenőrzött tényleges állapot ismeretében léphet
// tovább: vagy rögzíti a megtalált számlaszámot (nincs új külső hívás), vagy
// kifejezetten megerősíti, hogy NEM készült számla (csak ekkor indul újra).
// Egy megerősítetten elutasított ('failed') művelet továbbra is egyszerűen
// újrapróbálható.
if ($invoice['status'] === 'uncertain_manual') {
    $foundInvoiceNumber = trim((string) ($input['found_invoice_number'] ?? ''));
    if ($foundInvoiceNumber !== '') {
        if (!$db->resolveUncertainInvoiceOperation($id, $foundInvoiceNumber)) {
            send_json(['error' => 'A művelet időközben már nem bizonytalan állapotú.'], 409);
        }
        $db->logAudit(Auth::currentStaffId(), 'invoice_operation_resolve_found', 'invoice', $id, 'Bizonytalan művelet feloldva a megtalált számlaszámmal: ' . $foundInvoiceNumber, (int) ($appSettings['audit_log_retention_days'] ?? 30));
        send_json(['ok' => true, 'resolved' => true, 'invoice' => $db->getInvoiceById($id)]);
    }
    if (($input['confirm_not_issued'] ?? false) !== true) {
        send_json(['error' => 'A művelet kimenetele bizonytalan — előbb ellenőrizd a Számlázz.hu felületén: add meg a megtalált számlaszámot, vagy erősítsd meg, hogy NEM készült számla.', 'requires_confirmation' => true], 409);
    }
}

$reset = $db->resetInvoiceForManualRetry($id);
if (!$reset) {
    send_json(['error' => 'Csak sikertelen (failed/dead_letter/uncertain/uncertain_manual) állapotú bejegyzés próbálható újra manuálisan — ez a bejegyzés jelenleg "' . $invoice['status'] . '" állapotban van.'], 409);
}

$staffId = Auth::currentStaffId();
$service = new InvoiceService($config, $appSettings);
$result = $service->retrySzamlazzOperation($db, $id);

$db->logAudit(
    $staffId,
    'invoice_operation_manual_retry',
    'invoice',
    $id,
    $result['success']
        ? 'Kézi újrapróbálkozás sikeres — invoice_number: ' . ($result['invoice_number'] ?? 'n/a')
        : 'Kézi újrapróbálkozás sikertelen: ' . ($result['error'] ?? 'ismeretlen hiba'),
    (int) ($appSettings['audit_log_retention_days'] ?? 30)
);

if (!$result['success']) {
    send_json(['error' => $result['error'] ?? 'A kézi újrapróbálkozás sikertelen.', 'result' => $result], 400);
}

send_json(['ok' => true, 'result' => $result, 'invoice' => $db->getInvoiceById($id)]);
