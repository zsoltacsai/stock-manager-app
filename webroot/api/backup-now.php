<?php

declare(strict_types=1);
require __DIR__ . '/_bootstrap.php';
require_once __DIR__ . '/../../src/Settings.php';
require_once __DIR__ . '/../../src/BackupManager.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    send_json(['error' => 'POST only'], 405);
}

// Egy teljes adatbázis-mentés (a data/settings.json API-kulcsokkal együtt)
// futtatása és felhőbe feltöltése — ugyanaz a "vezetői jogszint kell"
// szabály indokolt rá, mint a visszaállításnál (backup-restore.php).
require_admin($db);

$settings = new Settings(__DIR__ . '/../../data/settings.json');
$s = $settings->read();

$manager = new BackupManager($config['db'], __DIR__ . '/../../data/backups');
$retentionDays = system_event_retention_days($s);
$db->logSystemEvent('backup', 'backup_started', 'info', 'started', 'Kézi biztonsági mentés indítva.', null, $retentionDays);

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
    $db->logSystemEvent('backup', 'backup_completed', 'info', 'success', $summary, null, $retentionDays);

    send_json(['success' => true, 'result' => $result, 'summary' => $summary]);
} catch (Throwable $e) {
    $settings->save([
        'last_backup_at'      => date('c'),
        'last_backup_summary' => 'Hiba: ' . $e->getMessage(),
    ]);
    $db->logSystemEvent('backup', 'backup_failed', 'error', 'failure', 'A kézi biztonsági mentés sikertelen volt.', $e->getMessage(), $retentionDays);
    send_json(['error' => $e->getMessage()], 500);
}
