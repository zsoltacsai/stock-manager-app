<?php

declare(strict_types=1);
require __DIR__ . '/_bootstrap.php';
require_once __DIR__ . '/../../src/Settings.php';

$settings = new Settings(__DIR__ . '/../../data/settings.json');
$current = $settings->read();

if (empty($current['auto_sync_enabled'])) {
    send_json(['skipped' => true, 'reason' => 'disabled']);
}

$intervalMinutes = max(1, (int) $current['auto_sync_interval_minutes']);
$lastRun = $current['last_auto_sync_at'] ? strtotime($current['last_auto_sync_at']) : 0;
$dueAt = $lastRun + ($intervalMinutes * 60);

if (time() < $dueAt) {
    send_json(['skipped' => true, 'reason' => 'not_due', 'next_run_at' => date('c', $dueAt)]);
}

try {
    $wc = new WooCommerceClient($config['woocommerce']);
    $products = $wc->fetchAllProducts();

    $imported = 0;
    $skipped = 0;
    $db->beginTransaction();
    foreach ($products as $p) {
        if (empty($p['barcode'])) {
            $skipped++;
            continue;
        }
        $db->upsertProductFromWc($p);
        $imported++;
    }
    $db->commit();

    $summary = "$imported termék frissítve, $skipped kihagyva";
    $settings->save([
        'last_auto_sync_at'      => date('c'),
        'last_auto_sync_summary' => $summary,
    ]);

    send_json(['ran' => true, 'imported' => $imported, 'skipped' => $skipped]);
} catch (Throwable $e) {
    $db->rollBack();
    $settings->save([
        'last_auto_sync_at'      => date('c'),
        'last_auto_sync_summary' => 'Hiba: ' . $e->getMessage(),
    ]);
    send_json(['ran' => true, 'error' => $e->getMessage()], 500);
}
