<?php

declare(strict_types=1);
require __DIR__ . '/_bootstrap.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    send_json(['error' => 'POST only'], 405);
}

$input = json_input();
$action = (string) ($input['action'] ?? '');
$ids = array_values(array_unique(array_map('intval', $input['ids'] ?? [])));
$staffId = !empty($input['staff_id']) ? (int) $input['staff_id'] : null;

if (!$ids) {
    send_json(['error' => 'Nincs kijelölt vásárló.'], 400);
}
if (!in_array($action, ['delete', 'restore'], true)) {
    send_json(['error' => 'Ismeretlen művelet.'], 400);
}

// Ugyanaz a "törléshez vezetői jogszint kell" szabály, mint az egyedi
// customer-save.php-nál — csak akkor kényszerítve, ha egyáltalán van
// dolgozói PIN-rendszer használatban.
if ($action === 'delete' && $db->listStaff(true) && !$db->isStaffAdmin($staffId)) {
    send_json(['error' => 'Vásárló törléséhez vezetői jogszint szükséges.'], 403);
}

$db->bulkSetCustomersDeleted($ids, $action === 'delete');

$db->logAudit(
    $staffId,
    'customer_bulk_' . $action,
    'customer',
    null,
    count($ids) . ' vásárló: ' . implode(',', $ids),
    (int) ($appSettings['audit_log_retention_days'] ?? 30)
);

send_json(['ok' => true, 'affected' => count($ids)]);
