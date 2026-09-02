<?php

declare(strict_types=1);
require __DIR__ . '/_bootstrap.php';

$date = $_GET['date'] ?? date('Y-m-d');
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
    send_json(['error' => 'Érvénytelen dátum formátum (YYYY-MM-DD szükséges).'], 400);
}

$summary = $db->getDailySummary($date);
$closing = $db->getClosing($date);

send_json([
    'summary' => $summary,
    'closing' => $closing,
]);
