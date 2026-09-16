<?php

declare(strict_types=1);
require __DIR__ . '/_bootstrap.php';

send_json([
    'queue' => $db->getWcQueueStatusSummary(50),
    'configured' => !empty($config['woocommerce']['store_url']) && !empty($config['woocommerce']['consumer_key']),
]);
