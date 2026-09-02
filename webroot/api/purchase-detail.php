<?php

declare(strict_types=1);
require __DIR__ . '/_bootstrap.php';

$id = (int) ($_GET['id'] ?? 0);
$purchase = $db->getPurchaseWithItems($id);

if (!$purchase) {
    send_json(['error' => 'A beszerzés nem található.'], 404);
}

send_json(['purchase' => $purchase]);
