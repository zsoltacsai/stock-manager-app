<?php

declare(strict_types=1);
require __DIR__ . '/_bootstrap.php';

$query = trim((string) ($_GET['query'] ?? ''));
if (strlen($query) < 2) {
    send_json(['products' => [], 'customers' => [], 'sales' => []]);
}

send_json($db->globalSearch($query));
