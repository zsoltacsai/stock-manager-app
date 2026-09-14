<?php

declare(strict_types=1);
require __DIR__ . '/_bootstrap.php';
require_once __DIR__ . '/../../src/UpdateService.php';
require_once __DIR__ . '/../../src/Settings.php';

$settingsStore = new Settings(__DIR__ . '/../../data/settings.json');
$service = new UpdateService($db, $config, __DIR__ . '/../..', $settingsStore);

$limit = isset($_GET['limit']) ? max(1, min(200, (int) $_GET['limit'])) : 50;

send_json(['history' => $service->getHistory($limit)]);
