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

    send_json(['ran' => true, 'result' => $result]);
} catch (Throwable $e) {
    $settings->save([
        'last_wc_queue_run_at' => date('c'),
        'last_wc_queue_run_summary' => 'Hiba: ' . $e->getMessage(),
    ]);
    send_json(['ran' => true, 'error' => $e->getMessage()], 500);
}
