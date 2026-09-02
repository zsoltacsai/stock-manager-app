<?php

declare(strict_types=1);
require __DIR__ . '/_bootstrap.php';
require_once __DIR__ . '/../../src/Settings.php';
require_once __DIR__ . '/../../src/BackupManager.php';

$settings = new Settings(__DIR__ . '/../../data/settings.json');
$s = $settings->read();

if (empty($s['backup_enabled'])) {
    send_json(['skipped' => true, 'reason' => 'disabled']);
}

$today = date('Y-m-d');
$alreadyRanToday = !empty($s['last_backup_at']) && substr($s['last_backup_at'], 0, 10) === $today;

if ($alreadyRanToday) {
    send_json(['skipped' => true, 'reason' => 'already_ran_today']);
}

$backupTime = $s['backup_time'] ?? '23:30';
$dueAt = strtotime($today . ' ' . $backupTime);

if (time() < $dueAt) {
    send_json(['skipped' => true, 'reason' => 'not_due', 'due_at' => date('c', $dueAt)]);
}

$manager = new BackupManager($config['db'], __DIR__ . '/../../data/backups');

try {
    $result = $manager->run($s);

    $summary = 'Automatikus mentés kész: ' . $result['filename'];
    if ($result['cloud']) {
        $summary .= ' (feltöltve: ' . $result['cloud']['provider'] . ')';
    }

    $settings->save([
        'last_backup_at'      => date('c'),
        'last_backup_summary' => $summary,
    ]);

    send_json(['ran' => true, 'result' => $result]);
} catch (Throwable $e) {
    $settings->save([
        'last_backup_at'      => date('c'),
        'last_backup_summary' => 'Hiba: ' . $e->getMessage(),
    ]);
    send_json(['ran' => true, 'error' => $e->getMessage()], 500);
}
