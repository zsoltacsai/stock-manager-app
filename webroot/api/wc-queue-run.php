<?php

declare(strict_types=1);
require __DIR__ . '/_bootstrap.php';
require_once __DIR__ . '/../../src/WooCommerceClient.php';
require_once __DIR__ . '/../../src/WcPushQueueWorker.php';

if (empty($config['woocommerce']['store_url']) || empty($config['woocommerce']['consumer_key']) || empty($config['woocommerce']['consumer_secret'])) {
    send_json(['skipped' => true, 'reason' => 'not_configured']);
}

$settings = new Settings(__DIR__ . '/../../data/settings.json');

try {
    $client = new WooCommerceClient($config['woocommerce']);
    $worker = new WcPushQueueWorker($db, $client);

    $result = $worker->processDuePushes(20);

    $summary = sprintf(
        '%d claim, %d push, %d retry, %d dead_letter, %d végleges hiba, %d kihagyva',
        $result['claimed'], $result['done'], $result['retried'], $result['dead_letters'], $result['permanent_failures'], $result['skipped']
    );

    $settings->save([
        'last_wc_queue_run_at' => date('c'),
        'last_wc_queue_run_summary' => $summary,
    ]);
    // "queue drained" — ha a claim-elt tételek mindegyike véglegesen
    // lezárult (nincs retry-ra visszatett elem) ebben a körben, a
    // várólista ezen szegmense kiürült. "queue item failed" —
    // dead_letter/végleges hiba esetén warning/error severity.
    $hasFailures = $result['dead_letters'] > 0 || $result['permanent_failures'] > 0;
    $db->logSystemEvent(
        'woocommerce',
        $hasFailures ? 'queue_item_failed' : ($result['claimed'] > 0 ? 'queue_drained' : 'queue_checked'),
        $hasFailures ? 'warning' : 'info',
        'success',
        $summary,
        null,
        system_event_retention_days($settings->read())
    );

    send_json(['ran' => true, 'result' => $result]);
} catch (Throwable $e) {
    $settings->save([
        'last_wc_queue_run_at' => date('c'),
        'last_wc_queue_run_summary' => 'Hiba: ' . $e->getMessage(),
    ]);
    $db->logSystemEvent('woocommerce', 'queue_run_failed', 'error', 'failure', 'A WooCommerce várólista feldolgozása sikertelen volt.', $e->getMessage(), system_event_retention_days($settings->read()));
    send_json(['ran' => true, 'error' => $e->getMessage()], 500);
}
