<?php

declare(strict_types=1);
require __DIR__ . '/_bootstrap.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    send_json(['error' => 'POST only'], 405);
}

$input = json_input();

// Egy ajándékutalvány gyakorlatilag készpénzzel egyenértékű, tetszőleges
// jövőbeli eladáson beváltható egyenleget testesít meg — ugyanaz a
// "vezetői jogszint kell" szabály indokolt a létrehozására/módosítására
// (és az aktiválás-kapcsolóra is), mint a kuponnál, csak akkor
// kényszerítve, ha egyáltalán van dolgozói PIN-rendszer használatban.
$staffId = Auth::currentStaffId();
if ($db->listStaff(true) && !$db->isStaffAdmin($staffId)) {
    send_json(['error' => 'Ajándékutalvány kezeléséhez vezetői jogszint szükséges.'], 403);
}

if (isset($input['toggle_id'])) {
    $db->setGiftCardActive((int) $input['toggle_id'], !empty($input['active']));
    $db->logAudit(
        $staffId,
        !empty($input['active']) ? 'gift_card_activate' : 'gift_card_deactivate',
        'gift_card',
        (int) $input['toggle_id'],
        null,
        (int) ($appSettings['audit_log_retention_days'] ?? 30)
    );
    send_json(['ok' => true]);
}

$code = trim((string) ($input['code'] ?? ''));
$balance = (float) ($input['balance'] ?? 0);

if ($code === '') {
    send_json(['error' => 'A kód megadása kötelező.'], 400);
}
if ($balance <= 0) {
    send_json(['error' => 'Az egyenleg legyen nagyobb, mint 0.'], 400);
}
if ($balance > 10000000) {
    send_json(['error' => 'Az egyenleg túl nagy — ellenőrizd az összeget.'], 400);
}

try {
    $id = $db->issueGiftCard($code, $balance, $input['expiry_date'] ?? null, $input['notes'] ?? null);
} catch (Throwable $e) {
    send_json(['error' => 'Ez a kód már létezik, vagy hiba történt: ' . $e->getMessage()], 400);
}

$db->logAudit(
    $staffId,
    'gift_card_create',
    'gift_card',
    $id,
    $code . ' (' . $balance . ' Ft)',
    (int) ($appSettings['audit_log_retention_days'] ?? 30)
);

send_json(['id' => $id]);
