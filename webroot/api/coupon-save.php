<?php

declare(strict_types=1);
require __DIR__ . '/_bootstrap.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    send_json(['error' => 'POST only'], 405);
}

$input = json_input();
if (trim((string) ($input['code'] ?? '')) === '') {
    send_json(['error' => 'A kuponkód megadása kötelező.'], 400);
}
if (!isset($input['value']) || $input['value'] === '' || $input['value'] === null) {
    send_json(['error' => 'Az érték megadása kötelező.'], 400);
}
$couponValue = (float) $input['value'];
if ($couponValue < 0) {
    send_json(['error' => 'Az érték nem lehet negatív.'], 400);
}
// Ugyanaz a logika, mint amit saveCoupon() a tényleges elmentéskor
// alkalmaz (minden, ami nem pontosan 'fixed', százalékosnak számít) —
// egy hiányzó/elgépelt type mező itt korábban megkerülhette ezt a
// korlátot, mert csak a szó szerint 'percent' értékre illeszkedett.
$couponType = ($input['type'] ?? '') === 'fixed' ? 'fixed' : 'percent';
if ($couponType === 'percent' && $couponValue > 100) {
    send_json(['error' => 'Százalékos kupon értéke legfeljebb 100 lehet.'], 400);
}

// A kupon tetszőleges értékű/korlátlan kedvezményt jelenthet minden
// jövőbeli eladáson — ugyanaz a "vezetői jogszint kell" szabály indokolt
// rá, mint a termék-/vásárlótörlésnél, csak akkor kényszerítve, ha
// egyáltalán van dolgozói PIN-rendszer használatban.
$staffId = Auth::currentStaffId();
if ($db->listStaff(true) && !$db->isStaffAdmin($staffId)) {
    send_json(['error' => 'Kupon létrehozásához/módosításához vezetői jogszint szükséges.'], 403);
}

try {
    $id = $db->saveCoupon($input);
} catch (Throwable $e) {
    send_json(['error' => 'Ez a kuponkód már létezik, vagy hiba történt: ' . $e->getMessage()], 400);
}

$db->logAudit(
    $staffId,
    empty($input['id']) ? 'coupon_create' : 'coupon_update',
    'coupon',
    $id,
    strtoupper(trim((string) ($input['code'] ?? ''))) . ' (' . $couponType . ' ' . $couponValue . ')',
    (int) ($appSettings['audit_log_retention_days'] ?? 30)
);

send_json(['id' => $id]);
