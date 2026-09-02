<?php

declare(strict_types=1);
require __DIR__ . '/_bootstrap.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    send_json(['error' => 'POST only'], 405);
}

$input = json_input();
$staffId = !empty($input['staff_id']) ? (int) $input['staff_id'] : null;
$notes = trim((string) ($input['notes'] ?? ''));

$id = $db->startStockTake($staffId, $notes);
send_json(['id' => $id]);
