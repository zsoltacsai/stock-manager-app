<?php

declare(strict_types=1);
require __DIR__ . '/_bootstrap.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    send_json(['error' => 'POST only'], 405);
}

$input = json_input();

if (isset($input['toggle_id'])) {
    $db->setGiftCardActive((int) $input['toggle_id'], !empty($input['active']));
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

try {
    $id = $db->issueGiftCard($code, $balance, $input['expiry_date'] ?? null, $input['notes'] ?? null);
} catch (Throwable $e) {
    send_json(['error' => 'Ez a kód már létezik, vagy hiba történt: ' . $e->getMessage()], 400);
}

send_json(['id' => $id]);
