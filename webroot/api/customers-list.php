<?php

declare(strict_types=1);
require __DIR__ . '/_bootstrap.php';

$includeDeleted = !empty($_GET['include_deleted']);
$query = trim((string) ($_GET['query'] ?? ''));

send_json(['customers' => $db->listCustomers($includeDeleted, $query)]);
