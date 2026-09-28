<?php

declare(strict_types=1);
require __DIR__ . '/_bootstrap.php';

$filters = [
    'date'  => $_GET['date'] ?? '',
    'id'    => $_GET['id'] ?? '',
    'query' => $_GET['query'] ?? '',
];

$sales = $db->listSales($filters);
// UX-06 (Phase 7 audit) — a `total` mező teszi lehetővé a UI-nak, hogy
// jelezze, ha a lista csonkolt (a listSales() alapértelmezett limitje miatt).
send_json(['sales' => $sales, 'total' => $db->countSales($filters)]);
