<?php

declare(strict_types=1);
require __DIR__ . '/_bootstrap.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    send_json(['error' => 'POST only'], 405);
}

$input = json_input();
$id = (int) ($input['id'] ?? 0);
$applyCorrections = !empty($input['apply_corrections']);

if (!$id) {
    send_json(['error' => 'Hiányzó id.'], 400);
}

try {
    $db->completeStockTake($id, $applyCorrections);
} catch (Throwable $e) {
    send_json(['error' => 'A leltár lezárása sikertelen: ' . $e->getMessage()], 500);
}

send_json(['ok' => true]);
