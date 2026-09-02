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
if (($input['type'] ?? '') === 'percent' && $couponValue > 100) {
    send_json(['error' => 'Százalékos kupon értéke legfeljebb 100 lehet.'], 400);
}

try {
    $id = $db->saveCoupon($input);
} catch (Throwable $e) {
    send_json(['error' => 'Ez a kuponkód már létezik, vagy hiba történt: ' . $e->getMessage()], 400);
}

send_json(['id' => $id]);
