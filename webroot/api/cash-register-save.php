<?php

declare(strict_types=1);
require __DIR__ . '/_bootstrap.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    send_json(['error' => 'POST only'], 405);
}

// Pénztárgép létrehozása/szerkesztése strukturális beállítás — ugyanaz a
// "vezetői jogszint kell" szabály, mint a telephelyeknél (location-save.php).
require_admin($db);

$input = json_input();
if (trim((string) ($input['name'] ?? '')) === '') {
    send_json(['error' => 'A pénztárgép nevének megadása kötelező.'], 400);
}
if (trim((string) ($input['code'] ?? '')) === '') {
    send_json(['error' => 'A pénztárgép kódjának megadása kötelező.'], 400);
}
$locationId = (int) ($input['location_id'] ?? 0);
if (!$locationId) {
    send_json(['error' => 'A telephely megadása kötelező.'], 400);
}
$locationExists = false;
foreach ($db->listLocations() as $loc) {
    if ((int) $loc['id'] === $locationId) {
        $locationExists = true;
        break;
    }
}
if (!$locationExists) {
    send_json(['error' => 'A megadott telephely nem található.'], 400);
}

try {
    $id = $db->saveCashRegister([
        'id'          => $input['id'] ?? null,
        'location_id' => $locationId,
        'name'        => trim((string) $input['name']),
        'code'        => trim((string) $input['code']),
        'is_active'   => !empty($input['is_active']),
    ]);
} catch (PDOException $e) {
    if (str_contains(strtolower($e->getMessage()), 'unique') || str_contains(strtolower($e->getMessage()), 'duplicate')) {
        send_json(['error' => 'Ez a pénztárgép-kód már foglalt — válassz másikat.'], 409);
    }
    send_generic_error_response($e, 'cash-register-save.php pénztárgép mentése sikertelen');
}

$db->logAudit(
    Auth::currentStaffId(),
    !empty($input['id']) ? 'cash_register_update' : 'cash_register_create',
    'cash_register',
    $id,
    trim((string) $input['name']),
    (int) ($appSettings['audit_log_retention_days'] ?? 30)
);

send_json(['id' => $id]);
