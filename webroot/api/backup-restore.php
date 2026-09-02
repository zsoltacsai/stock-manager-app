<?php

declare(strict_types=1);
require __DIR__ . '/_bootstrap.php';
require_once __DIR__ . '/../../src/Settings.php';
require_once __DIR__ . '/../../src/BackupManager.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    send_json(['error' => 'POST only'], 405);
}

$backupDir = __DIR__ . '/../../data/backups';
$manager = new BackupManager($config['db'], $backupDir);
$settings = new Settings(__DIR__ . '/../../data/settings.json');

// Kétféleképp indulhat: egy meglévő helyi mentés kiválasztásával fájlnév
// alapján, vagy egy feltöltött fájllal.
$sourcePath = null;

if (!empty($_FILES['file']['tmp_name']) && is_uploaded_file($_FILES['file']['tmp_name'])) {
    $sourcePath = $_FILES['file']['tmp_name'];
} elseif (!empty($_POST['filename'])) {
    $filename = basename((string) $_POST['filename']); // strip any path — filenames only, no traversal
    $candidate = $backupDir . '/' . $filename;
    if (!is_file($candidate)) {
        send_json(['error' => 'A megadott mentési fájl nem található.'], 404);
    }
    $sourcePath = $candidate;
} else {
    send_json(['error' => 'Válassz egy meglévő mentést, vagy tölts fel egy fájlt.'], 400);
}

try {
    $result = $manager->restoreFromFile($sourcePath);

    $summary = 'Visszaállítva innen: ' . basename($sourcePath) . '. Biztonsági mentés a visszaállítás előtti állapotról: ' . $result['safety_backup'];
    $settings->save(['last_backup_summary' => $summary]);

    send_json(['success' => true, 'safety_backup' => $result['safety_backup']]);
} catch (Throwable $e) {
    send_json(['error' => 'A visszaállítás sikertelen: ' . $e->getMessage()], 500);
}
