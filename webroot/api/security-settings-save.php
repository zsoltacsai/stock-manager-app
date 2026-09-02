<?php

declare(strict_types=1);
require __DIR__ . '/_bootstrap.php';
require_once __DIR__ . '/../../src/Settings.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    send_json(['error' => 'POST only'], 405);
}

$input = json_input();
$settings = new Settings(__DIR__ . '/../../data/settings.json');
$update = [];

if (isset($input['app_password_enabled'])) {
    $enabled = !empty($input['app_password_enabled']);
    if ($enabled && empty($appSettings['app_password_hash']) && empty($input['new_password'])) {
        send_json(['error' => 'A bekapcsoláshoz előbb állíts be egy jelszót.'], 400);
    }
    $update['app_password_enabled'] = $enabled;
}

if (!empty($input['new_password'])) {
    $newPassword = (string) $input['new_password'];
    if (strlen($newPassword) < 8) {
        send_json(['error' => 'A jelszó legalább 8 karakter legyen.'], 400);
    }
    if (($input['new_password_confirm'] ?? '') !== $newPassword) {
        send_json(['error' => 'A két jelszó nem egyezik.'], 400);
    }
    $update['app_password_hash'] = password_hash($newPassword, PASSWORD_DEFAULT);
}

if (isset($input['session_timeout_minutes'])) {
    $update['session_timeout_minutes'] = max(0, (int) $input['session_timeout_minutes']);
}
if (isset($input['login_max_attempts'])) {
    $update['login_max_attempts'] = max(1, (int) $input['login_max_attempts']);
}
if (isset($input['login_lockout_minutes'])) {
    $update['login_lockout_minutes'] = max(1, (int) $input['login_lockout_minutes']);
}

$data = $settings->save($update);
unset($data['app_password_hash']); // a hash sose menjen vissza a kliensnek

send_json($data);
