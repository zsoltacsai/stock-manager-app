<?php

declare(strict_types=1);
require __DIR__ . '/_bootstrap.php';

$status = (string) ($_GET['status'] ?? '');
if (!in_array($status, ['', 'draft', 'confirmed', 'rejected'], true)) {
    $status = '';
}

send_json(['orders' => $db->listWebshopOrders($status), 'draft_count' => $db->countDraftWebshopOrders()]);
