<?php

declare(strict_types=1);
require __DIR__ . '/_bootstrap.php';
require_once __DIR__ . '/../../src/Settings.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    send_json(['error' => 'POST only'], 405);
}

foreach (glob(__DIR__ . '/../assets/print-logo.*') as $old) {
    @unlink($old);
}

$settings = new Settings(__DIR__ . '/../../data/settings.json');
$settings->save(['print_logo_filename' => null]);

send_json(['ok' => true]);
