<?php

declare(strict_types=1);
require __DIR__ . '/_bootstrap.php';
require_once __DIR__ . '/../../src/UpdateService.php';
require_once __DIR__ . '/../../src/Settings.php';

// Olvasás — bármely bejelentkezett felhasználó láthatja (nem érzékeny
// adat, ugyanaz a szint, mint a többi Beállítások-olvasás), csak a
// telepítés INDÍTÁSA admin-only (lásd update-install.php).
$settingsStore = new Settings(__DIR__ . '/../../data/settings.json');
$service = new UpdateService($db, $config, __DIR__ . '/../..', $settingsStore);

send_json($service->getStatus());
