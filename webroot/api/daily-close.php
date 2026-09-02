<?php

declare(strict_types=1);
require __DIR__ . '/_bootstrap.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    send_json(['error' => 'POST only'], 405);
}

$input = json_input();
$date = $input['date'] ?? date('Y-m-d');
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
    send_json(['error' => 'Érvénytelen dátum formátum (YYYY-MM-DD szükséges).'], 400);
}

$summary = $db->getDailySummary($date);
$db->recordClosing($date, $summary);
$closing = $db->getClosing($date);

send_json(['closing' => $closing]);
