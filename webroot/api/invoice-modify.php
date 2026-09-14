<?php

declare(strict_types=1);
require __DIR__ . '/_bootstrap.php';
require_once __DIR__ . '/../../src/InvoiceService.php';

// Egy meglévő, kiállított számla helyesbítése ÚJ pénzügyi bizonylatot hoz
// létre — ugyanaz a "vezetői jogszint" szabály indokolt, mint bármely más
// érzékeny/pénzügyi hatású műveletnél (lásd gift-card-save.php mintája).
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    send_json(['error' => 'POST only'], 405);
}
require_admin($db);

$input = json_input();
$originalInvoiceId = (int) ($input['original_invoice_id'] ?? 0);
$items = $input['items'] ?? null;
$buyer = $input['buyer'] ?? null;
$paymentMethod = isset($input['payment_method']) ? (string) $input['payment_method'] : null;
$operationUuid = isset($input['operation_uuid']) ? (string) $input['operation_uuid'] : null;

if ($originalInvoiceId <= 0) {
    send_json(['error' => 'Érvénytelen original_invoice_id.'], 400);
}
if (!is_array($items) || count($items) === 0) {
    send_json(['error' => 'Legalább egy tétel megadása kötelező.'], 400);
}
if (!is_array($buyer)) {
    send_json(['error' => 'A vevő adatai kötelezők.'], 400);
}
if ($operationUuid !== null && strlen($operationUuid) > 100) {
    // operation_uuid az operation_key ('modify:{id}:{uuid}') része, ami
    // egy UNIQUE VARCHAR(191) oszlopba kerül — egy indokolatlanul hosszú
    // érték (a kliens-generált crypto.randomUUID() kb. 36 karakter)
    // MySQL-en csonkolódhatna, ami két KÜLÖNBÖZŐ hosszú uuid-t hamisan
    // egyformává tehetne az index szintjén.
    send_json(['error' => 'Az operation_uuid túl hosszú.'], 400);
}

$staffId = Auth::currentStaffId();
$service = new InvoiceService($config, $appSettings);
$result = $service->requestModification($db, $originalInvoiceId, $items, $buyer, $paymentMethod, $operationUuid, true);

$db->logAudit(
    $staffId,
    'invoice_modify_request',
    'invoice',
    $originalInvoiceId,
    ($result['success'] || ($result['pending'] ?? false))
        ? 'Helyesbítő számla kezdeményezve — invoice_number: ' . ($result['invoice_number'] ?? 'n/a')
        : 'Helyesbítő számla kezdeményezése sikertelen: ' . ($result['error'] ?? 'ismeretlen hiba'),
    (int) ($appSettings['audit_log_retention_days'] ?? 30)
);

if (!$result['success'] && !($result['pending'] ?? false)) {
    send_json(['error' => $result['error'] ?? 'A helyesbítő számla kezdeményezése sikertelen.', 'result' => $result], 400);
}

send_json(['ok' => true, 'result' => $result]);
