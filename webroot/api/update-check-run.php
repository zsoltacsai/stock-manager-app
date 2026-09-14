<?php

declare(strict_types=1);
require __DIR__ . '/_bootstrap.php';
require_once __DIR__ . '/../../src/UpdateService.php';
require_once __DIR__ . '/../../src/Settings.php';

// Cron-hitelesített (X-Cron-Token), OLCSÓ, csak-ellenőrző végpont — ugyanaz
// a minta, mint auto-backup-run.php/nav-queue-run.php: a tényleges
// TELEPÍTÉST szándékosan NEM ez végzi (lásd tools/update-install-cli.php
// docblockja) — ez csak azt dönti el, esedékes-e egyáltalán egy
// ellenőrzés, majd frissíti az update_state `latest_*` mezőit.
$settingsStore = new Settings(__DIR__ . '/../../data/settings.json');
$current = $settingsStore->read();

if (empty($current['update_auto_check_enabled'])) {
    send_json(['skipped' => true, 'reason' => 'disabled']);
}

$intervalHours = (int) ($current['update_check_interval_hours'] ?? 24);
$state = $db->getUpdateState();
$lastCheckAt = $state['latest_checked_at'] ?? null;
$dueAt = $lastCheckAt ? strtotime($lastCheckAt) + $intervalHours * 3600 : 0;

if (time() < $dueAt) {
    send_json(['skipped' => true, 'reason' => 'not_due', 'due_at' => date('c', $dueAt)]);
}

$service = new UpdateService($db, $config, __DIR__ . '/../..', $settingsStore);
$result = $service->checkNow();

send_json(['ran' => true, 'result' => $result]);
