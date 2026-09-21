<?php

declare(strict_types=1);
require __DIR__ . '/_bootstrap.php';

$filters = [
    'location_id'      => $_GET['location_id'] ?? null,
    'cash_register_id' => $_GET['cash_register_id'] ?? null,
    'staff_id'         => $_GET['staff_id'] ?? null,
    'status'           => $_GET['status'] ?? null,
    'date_from'        => $_GET['date_from'] ?? null,
    'date_to'          => $_GET['date_to'] ?? null,
];
$filters = array_filter($filters, static fn ($v) => $v !== null && $v !== '');

send_json(['sessions' => $db->listCashSessions($filters)]);
