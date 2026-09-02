<?php

declare(strict_types=1);
require __DIR__ . '/_bootstrap.php';

$input = json_input();
$pin = trim((string) ($input['pin'] ?? ''));

if ($pin === '') {
    send_json(['ok' => false, 'error' => 'Add meg a PIN-kódot.'], 400);
}

// Ugyanazokkal a limitekkel védve, mint az alkalmazás-jelszó — a PIN-ek
// rövidsége (4-8 számjegy) miatt brute-force elleni védelem itt is fontos.
require_once __DIR__ . '/../../src/GeoBlocker.php';
$maxAttempts = (int) ($appSettings['login_max_attempts'] ?? 5);
$lockoutMinutes = (int) ($appSettings['login_lockout_minutes'] ?? 15);
$rateLimitKey = 'staff-pin-' . GeoBlocker::resolveClientIp();

$limit = Auth::checkRateLimit($rateLimitKey, $maxAttempts, $lockoutMinutes);
if ($limit['locked']) {
    $minutes = (int) ceil($limit['remaining_seconds'] / 60);
    send_json(['ok' => false, 'error' => "Túl sok sikertelen próbálkozás. Próbáld újra kb. $minutes perc múlva."], 429);
}

$staff = $db->verifyStaffPin($pin);
if (!$staff) {
    Auth::recordFailedAttempt($rateLimitKey, $maxAttempts, $lockoutMinutes);
    send_json(['ok' => false, 'error' => 'Hibás PIN-kód.'], 401);
}

Auth::clearRateLimit($rateLimitKey);
send_json(['ok' => true, 'staff' => $staff]);
