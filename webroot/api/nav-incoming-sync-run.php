<?php

declare(strict_types=1);
require __DIR__ . '/_bootstrap.php';
require_once __DIR__ . '/../../src/Settings.php';
require_once __DIR__ . '/../../src/NavIncomingInvoiceSync.php';
require_once __DIR__ . '/../../src/NavIncomingInvoiceSyncWorker.php';

$settings = new Settings(__DIR__ . '/../../data/settings.json');
$current = $settings->read();

if (empty($current['nav_incoming_sync_enabled'])) {
    send_json(['skipped' => true, 'reason' => 'disabled']);
}

if (empty($current['nav_login']) || empty($current['nav_password']) || empty($current['nav_tax_number'])) {
    send_json(['skipped' => true, 'reason' => 'not_configured']);
}

try {
    $navConfig = [
        'nav_login' => $current['nav_login'],
        'nav_password' => $current['nav_password'],
        'nav_signer_key' => $current['nav_signer_key'],
        'nav_exchange_key' => $current['nav_exchange_key'],
        'nav_tax_number' => $current['nav_tax_number'],
        'nav_test_mode' => !empty($current['nav_test_mode']),
    ];

    $sync = new NavIncomingInvoiceSync($db, fn () => new NavClient($navConfig));
    $worker = new NavIncomingInvoiceSyncWorker($db, $sync);

    // Legfeljebb 3, egyenként ≤35-napos ablak egy cron-futáson belül — egy
    // erősen elmaradt sync (pl. hosszan kikapcsolt cron utáni első futás)
    // több cron-tick alatt éri utol magát, timeout-kockázat nélkül,
    // pontosan ugyanaz az elv, mint a kimenő queue processDueSubmissions()
    // limitjénél (lásd NavIncomingInvoiceSyncWorker docblockja).
    $result = $worker->processDueSync(3);

    $summary = match ($result['outcome']) {
        'not_due' => 'nem esedékes (backoff: ' . ($result['next_attempt_at'] ?? '?') . ')',
        'already_running' => 'már folyamatban (kihagyva)',
        'skipped' => 'kihagyva: ' . ($result['reason'] ?? '?'),
        'success' => sprintf('siker: %d ablak feldolgozva%s', $result['windows_processed'] ?? 0, !empty($result['has_more']) ? ' (van még hátra)' : ''),
        'retry' => 'átmeneti hiba, újrapróbálva: ' . ($result['error'] ?? '?'),
        'failed' => 'sikertelen: ' . ($result['error'] ?? '?'),
        default => 'ismeretlen kimenetel',
    };

    $settings->save([
        'last_nav_incoming_sync_run_at' => date('c'),
        'last_nav_incoming_sync_run_summary' => $summary,
    ]);

    send_json(['ran' => true, 'result' => $result]);
} catch (Throwable $e) {
    $settings->save([
        'last_nav_incoming_sync_run_at' => date('c'),
        'last_nav_incoming_sync_run_summary' => 'Hiba: ' . $e->getMessage(),
    ]);
    send_json(['ran' => true, 'error' => $e->getMessage()], 500);
}
