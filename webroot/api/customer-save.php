<?php

declare(strict_types=1);
require __DIR__ . '/_bootstrap.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    send_json(['error' => 'POST only'], 405);
}

$input = json_input();
if (trim((string) ($input['name'] ?? '')) === '') {
    send_json(['error' => 'A vásárló neve kötelező.'], 400);
}

$wasDeleted = false;
if (!empty($input['id'])) {
    $existing = $db->findCustomerById((int) $input['id']);
    $wasDeleted = $existing && !empty($existing['is_deleted']);
}
$settingToDeleted = !empty($input['is_deleted']) && !$wasDeleted;
if ($settingToDeleted && $db->listStaff(true) && !$db->isStaffAdmin(Auth::currentStaffId())) {
    send_json(['error' => 'Vásárló törléséhez vezetői jogszint szükséges.'], 403);
}

$id = $db->saveCustomer($input);

if ($settingToDeleted) {
    $db->logAudit(
        Auth::currentStaffId(),
        'customer_delete',
        'customer',
        $id,
        $input['name'],
        (int) ($appSettings['audit_log_retention_days'] ?? 30)
    );
}

send_json(['id' => $id, 'customer' => $db->findCustomerById($id)]);
