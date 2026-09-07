<?php

declare(strict_types=1);
require __DIR__ . '/_bootstrap.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    send_json(['error' => 'POST only'], 405);
}

$input = json_input();
$staffId = Auth::currentStaffId();
$notes = trim((string) ($input['notes'] ?? ''));

try {
    $id = $db->startStockTake($staffId, $notes);
} catch (Throwable $e) {
    send_json(['error' => $e->getMessage()], 409);
}
send_json(['id' => $id]);
