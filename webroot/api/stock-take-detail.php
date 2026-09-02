<?php

declare(strict_types=1);
require __DIR__ . '/_bootstrap.php';

$id = (int) ($_GET['id'] ?? 0);
$take = $db->getStockTake($id);

if (!$take) {
    send_json(['error' => 'A leltár nem található.'], 404);
}

send_json(['stock_take' => $take]);
