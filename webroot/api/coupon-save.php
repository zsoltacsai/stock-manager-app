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
if (empty($input['value']) && $input['value'] !== '0') {
    send_json(['error' => 'Az érték megadása kötelező.'], 400);
}

try {
    $id = $db->saveCoupon($input);
} catch (Throwable $e) {
    send_json(['error' => 'Ez a kuponkód már létezik, vagy hiba történt: ' . $e->getMessage()], 400);
}

send_json(['id' => $id]);
