<?php

declare(strict_types=1);
require __DIR__ . '/_bootstrap.php';
require_once __DIR__ . '/../../src/Settings.php';
require_once __DIR__ . '/../../src/BackupManager.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    send_json(['error' => 'POST only'], 405);
}

$settings = new Settings(__DIR__ . '/../../data/settings.json');
$s = $settings->read();

$manager = new BackupManager($config['db'], __DIR__ . '/../../data/backups');

try {
    $result = $manager->run($s);

    $summary = 'Mentés kész: ' . $result['filename'];
    if ($result['cloud']) {
        $summary .= ' (feltöltve: ' . $result['cloud']['provider'] . ')';
    }

    $settings->save([
        'last_backup_at'      => date('c'),
        'last_backup_summary' => $summary,
    ]);

    send_json(['success' => true, 'result' => $result, 'summary' => $summary]);
} catch (Throwable $e) {
    $settings->save([
        'last_backup_at'      => date('c'),
        'last_backup_summary' => 'Hiba: ' . $e->getMessage(),
    ]);
    send_json(['error' => $e->getMessage()], 500);
}
