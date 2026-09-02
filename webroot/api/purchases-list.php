<?php

declare(strict_types=1);
require __DIR__ . '/_bootstrap.php';

$filters = [
    'date'  => $_GET['date'] ?? '',
    'id'    => $_GET['id'] ?? '',
    'query' => $_GET['query'] ?? '',
];

send_json(['purchases' => $db->listPurchases($filters)]);
