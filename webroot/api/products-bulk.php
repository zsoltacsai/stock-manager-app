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
    send_json(['error' => 'Nincs kijelölt árucikk.'], 400);
}
if (!in_array($action, ['delete', 'restore', 'set_group'], true)) {
    send_json(['error' => 'Ismeretlen művelet.'], 400);
}

// Ugyanaz a "törléshez vezetői jogszint kell" szabály, mint az egyedi
// product-save.php-nál — csak akkor kényszerítve, ha egyáltalán van
// dolgozói PIN-rendszer használatban.
if ($action === 'delete' && $db->listStaff(true) && !$db->isStaffAdmin($staffId)) {
    send_json(['error' => 'Termék törléséhez vezetői jogszint szükséges.'], 403);
}

if ($action === 'delete') {
    $db->bulkSetProductsDeleted($ids, true);
} elseif ($action === 'restore') {
    $db->bulkSetProductsDeleted($ids, false);
} else {
    $groupName = trim((string) ($input['group_name'] ?? ''));
    $db->bulkSetProductsGroup($ids, $groupName !== '' ? $groupName : null);
}

$db->logAudit(
    $staffId,
    'product_bulk_' . $action,
    'product',
    null,
    count($ids) . ' árucikk: ' . implode(',', $ids),
    (int) ($appSettings['audit_log_retention_days'] ?? 30)
);

send_json(['ok' => true, 'affected' => count($ids)]);
