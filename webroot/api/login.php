<?php

declare(strict_types=1);
require __DIR__ . '/_bootstrap.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    send_json(['error' => 'POST only'], 405);
}

if (!Auth::isEnabled($appSettings)) {
    send_json(['ok' => true]); // a jelszavas védelem ki van kapcsolva — nincs mit ellenőrizni
}

$input = json_input();
$password = (string) ($input['password'] ?? '');

$maxAttempts = (int) ($appSettings['login_max_attempts'] ?? 5);
$lockoutMinutes = (int) ($appSettings['login_lockout_minutes'] ?? 15);
$rateLimitKey = 'app-login-' . ($_SERVER['REMOTE_ADDR'] ?? 'unknown');

$limit = Auth::checkRateLimit($rateLimitKey, $maxAttempts, $lockoutMinutes);
if ($limit['locked']) {
    $minutes = (int) ceil($limit['remaining_seconds'] / 60);
    send_json(['error' => "Túl sok sikertelen próbálkozás. Próbáld újra kb. $minutes perc múlva."], 429);
}

if ($password === '' || !Auth::login($password, $appSettings)) {
    Auth::recordFailedAttempt($rateLimitKey, $maxAttempts, $lockoutMinutes);
    $left = max(0, $limit['attempts_left'] - 1);
    send_json(['error' => "Hibás jelszó." . ($left > 0 ? " Még $left próbálkozás." : ' Ez volt az utolsó próbálkozás — a fiók zárolva lesz.')], 401);
}

Auth::clearRateLimit($rateLimitKey);
send_json(['ok' => true, 'csrf_token' => Auth::csrfToken()]);
