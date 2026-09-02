<?php

declare(strict_types=1);
require __DIR__ . '/_bootstrap.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    send_json(['error' => 'POST only'], 405);
}

$input = json_input();
if (trim((string) ($input['name'] ?? '')) === '') {
    send_json(['error' => 'A név megadása kötelező.'], 400);
}
if (empty($input['id']) && trim((string) ($input['pin'] ?? '')) === '') {
    send_json(['error' => 'Új dolgozónál a PIN-kód megadása kötelező.'], 400);
}
if (!empty($input['pin']) && !preg_match('/^\d{4,8}$/', (string) $input['pin'])) {
    send_json(['error' => 'A PIN-kód 4-8 számjegy legyen.'], 400);
}

// Admin szerepkör adása vagy egy meglévő dolgozó szerkesztése (pl. PIN
// visszaállítása) vezetői jogszintet kér — de csak akkor, ha már van
// felvéve dolgozó (ha a PIN-rendszer még be sincs üzemelve, az első
// dolgozó felvétele szabadon engedélyezett).
$requestedRole = ($input['role'] ?? 'cashier') === 'admin' ? 'admin' : 'cashier';
$privileged = $requestedRole === 'admin' || !empty($input['id']);
if ($privileged && $db->listStaff(true) && !$db->isStaffAdmin(!empty($input['staff_id']) ? (int) $input['staff_id'] : null)) {
    send_json(['error' => 'Ehhez vezetői jogszint szükséges.'], 403);
}

$id = $db->saveStaff($input);
send_json(['id' => $id]);
