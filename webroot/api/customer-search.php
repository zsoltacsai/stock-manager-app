<?php

declare(strict_types=1);
require __DIR__ . '/_bootstrap.php';

$query = trim((string) ($_GET['query'] ?? ''));
if ($query === '') {
    send_json(['customers' => []]);
}

send_json(['customers' => $db->searchCustomersForTill($query)]);
