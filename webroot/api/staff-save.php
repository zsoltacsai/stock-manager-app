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

// Admin szerepkör adása, egy meglévő dolgozó szerkesztése (pl. PIN
// visszaállítása), VAGY ÚJ dolgozó felvétele — MIND vezetői jogszintet
// kér, amint a PIN-rendszer ténylegesen üzembe lett helyezve (van már
// legalább egy felvett dolgozó, aktív vagy inaktív). Ha a PIN-rendszer
// még be sincs üzemelve (még egyetlen dolgozó sincs felvéve), az ELSŐ
// dolgozó felvétele szabadon engedélyezett — enélkül a bootstrap
// lehetetlen lenne (senki nem lenne admin, aki jóváhagyhatná az elsőt).
//
// P1-4 javítás: korábban ez a védelem CSAK admin-szerepkör adására és
// MEGLÉVŐ dolgozó szerkesztésére vonatkozott ("privileged" külön
// számítva) — egy ÚJ, sima "cashier" szerepkörű dolgozó létrehozása (a
// leggyakoribb eset) kimaradt a feltételből, tehát bármelyik
// bejelentkezett (nem admin) felhasználó korlátlanul fabrikálhatott új
// dolgozó-azonosítókat, akár a PIN-rendszer már működésben volt is.
if ($db->listStaff(true) && !$db->isStaffAdmin(Auth::currentStaffId())) {
    send_json(['error' => 'Ehhez vezetői jogszint szükséges.'], 403);
}

$id = $db->saveStaff($input);
send_json(['id' => $id]);
