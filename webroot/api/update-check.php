<?php

declare(strict_types=1);
require __DIR__ . '/_bootstrap.php';
require_once __DIR__ . '/../../src/UpdateService.php';
require_once __DIR__ . '/../../src/Settings.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    send_json(['error' => 'POST only'], 405);
}
require_admin($db);

$settingsStore = new Settings(__DIR__ . '/../../data/settings.json');
$service = new UpdateService($db, $config, __DIR__ . '/../..', $settingsStore);

send_json($service->checkNow());
