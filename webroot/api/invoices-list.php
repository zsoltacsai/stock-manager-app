<?php

declare(strict_types=1);
require __DIR__ . '/_bootstrap.php';

$filters = [
    'date'     => $_GET['date'] ?? '',
    'id'       => $_GET['id'] ?? '',
    'provider' => $_GET['provider'] ?? '',
    'status'   => $_GET['status'] ?? '',
    'query'    => $_GET['query'] ?? '',
];

send_json(['invoices' => $db->listInvoices($filters)]);
