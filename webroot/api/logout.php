<?php

declare(strict_types=1);
require __DIR__ . '/_bootstrap.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    send_json(['error' => 'POST only'], 405);
}

Auth::logout();
$db->logSystemEvent('auth', 'logout', 'info', 'success', 'Kijelentkezés.', null, system_event_retention_days($appSettings));
send_json(['ok' => true]);
