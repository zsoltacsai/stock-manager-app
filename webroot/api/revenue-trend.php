<?php

declare(strict_types=1);
require __DIR__ . '/_bootstrap.php';

$days = (int) ($_GET['days'] ?? 30);
$days = max(7, min(90, $days));

send_json(['trend' => $db->getDailyRevenueTrend($days)]);
