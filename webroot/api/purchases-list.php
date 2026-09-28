<?php

declare(strict_types=1);
require __DIR__ . '/_bootstrap.php';

$filters = [
    'date'  => $_GET['date'] ?? '',
    'id'    => $_GET['id'] ?? '',
    'query' => $_GET['query'] ?? '',
];

$purchases = $db->listPurchases($filters);
// UX-06 (Phase 7 audit) — lásd sales-list.php-ban ugyanezt a mintát.
send_json(['purchases' => $purchases, 'total' => $db->countPurchases($filters)]);
