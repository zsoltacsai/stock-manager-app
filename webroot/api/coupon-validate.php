<?php

declare(strict_types=1);
require __DIR__ . '/_bootstrap.php';

$input = json_input();
$code = trim((string) ($input['code'] ?? ''));
$total = (float) ($input['total'] ?? 0);

if ($code === '') {
    send_json(['ok' => false, 'error' => 'Add meg a kuponkódot.'], 400);
}

send_json($db->validateCoupon($code, $total));
