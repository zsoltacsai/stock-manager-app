<?php

declare(strict_types=1);
require __DIR__ . '/_bootstrap.php';

// 1.3.0 — a korábbi (1.1.1/1.2.0-as) beszállító-csoportosított, küszöb-
// duplázós javaslatlistát felváltja a teljes döntéstámogató lista (lásd
// Database::getPurchaseRecommendations() docblockja: sürgősség, várható
// kifogyás, rendelési pont, javasolt mennyiség, indoklás) — a URL/fájlnév
// szándékosan változatlan marad (nincs törött link/könyvjelző), csak a
// válasz alakja bővült.

$threshold = (int) ($appSettings['low_stock_default_threshold'] ?? 5);
$windowDays = max(1, min(90, (int) ($_GET['window_days'] ?? 30)));

$allowedUrgency = ['urgent', 'soon', 'low'];
$urgency = $_GET['urgency'] ?? '';
if ($urgency !== '' && !in_array($urgency, $allowedUrgency, true)) {
    send_json(['error' => 'Érvénytelen urgency szűrő.'], 400);
}

$all = $db->getPurchaseRecommendations($threshold, $windowDays);
$counts = [
    'urgent' => count(array_filter($all, static fn ($r) => $r['urgency'] === 'urgent')),
    'soon'   => count(array_filter($all, static fn ($r) => $r['urgency'] === 'soon')),
    'low'    => count(array_filter($all, static fn ($r) => $r['urgency'] === 'low')),
];

$recommendations = $urgency !== ''
    ? array_values(array_filter($all, static fn ($r) => $r['urgency'] === $urgency))
    : $all;

send_json([
    'recommendations' => $recommendations,
    'counts' => $counts + ['all' => count($all)],
]);
