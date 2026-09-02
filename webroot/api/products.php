<?php

declare(strict_types=1);
require __DIR__ . '/_bootstrap.php';

$includeDeleted = !empty($_GET['include_deleted']);
send_json(['products' => $db->listProducts(500, $includeDeleted)]);
