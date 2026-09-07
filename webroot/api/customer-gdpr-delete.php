<?php

declare(strict_types=1);
require __DIR__ . '/_bootstrap.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    send_json(['error' => 'POST only'], 405);
}

$input = json_input();
$id = (int) ($input['id'] ?? 0);
$staffId = Auth::currentStaffId();

$customer = $id ? $db->findCustomerById($id) : null;
if (!$customer) {
    send_json(['error' => 'A vásárló nem található.'], 404);
}

// Ugyanaz a "vezetői jogszint kell" szabály, mint a sima törlésnél — ez
// a GDPR-törlés visszavonhatatlan, indokolt a szigorúbb kapu.
if ($db->listStaff(true) && !$db->isStaffAdmin($staffId)) {
    send_json(['error' => 'A GDPR-törléshez vezetői jogszint szükséges.'], 403);
}

$db->anonymizeCustomer($id);

// SZÁNDÉKOSAN nem a vásárló (törlés előtti) nevét írjuk ide — az pont azt
// az adatot pörgetné vissza az audit naplóba, amit ez a művelet törölni
// hivatott (az audit_log-ot anonymizeCustomer() nem érinti, és a
// megőrzési idő lejártáig itt olvasható maradna).
$db->logAudit(
    $staffId,
    'customer_gdpr_delete',
    'customer',
    $id,
    'Vásárló #' . $id,
    (int) ($appSettings['audit_log_retention_days'] ?? 30)
);

send_json(['ok' => true]);
