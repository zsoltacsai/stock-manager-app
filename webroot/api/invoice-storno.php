<?php

declare(strict_types=1);
require __DIR__ . '/_bootstrap.php';
require_once __DIR__ . '/../../src/InvoiceService.php';

// A sztornó egy meglévő számla TELJES, önálló pénzügyi bizonylattal
// történő érvénytelenítése — ugyanaz a "vezetői jogszint" szabály
// indokolt, mint invoice-modify.php-nál.
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    send_json(['error' => 'POST only'], 405);
}
require_admin($db);

$input = json_input();
$originalInvoiceId = (int) ($input['original_invoice_id'] ?? 0);

if ($originalInvoiceId <= 0) {
    send_json(['error' => 'Érvénytelen original_invoice_id.'], 400);
}

$staffId = Auth::currentStaffId();
$service = new InvoiceService($config, $appSettings);
$result = $service->requestStorno($db, $originalInvoiceId, true);

$db->logAudit(
    $staffId,
    'invoice_storno_request',
    'invoice',
    $originalInvoiceId,
    ($result['success'] || ($result['pending'] ?? false))
        ? 'Sztornó számla kezdeményezve — invoice_number: ' . ($result['invoice_number'] ?? 'n/a')
        : 'Sztornó számla kezdeményezése sikertelen: ' . ($result['error'] ?? 'ismeretlen hiba'),
    (int) ($appSettings['audit_log_retention_days'] ?? 30)
);

if (!$result['success'] && !($result['pending'] ?? false)) {
    send_json(['error' => $result['error'] ?? 'A sztornó számla kezdeményezése sikertelen.', 'result' => $result], 400);
}

send_json(['ok' => true, 'result' => $result]);
