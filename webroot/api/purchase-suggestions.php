<?php

declare(strict_types=1);
require __DIR__ . '/_bootstrap.php';

$threshold = (int) ($appSettings['low_stock_default_threshold'] ?? 5);
$windowDays = max(1, min(90, (int) ($_GET['window_days'] ?? 30)));

$groups = $db->getPurchaseSuggestions($threshold);

// 1.2.0 — a kör 11. pontja: a MEGLÉVŐ beszerzési javaslat listát egészíti
// ki egy egyszerű készlet-előrejelzéssel (becsült kifogyás), a meglévő
// javasolt-mennyiség logikát NEM módosítja. Bulk lekérdezés (nem
// csoportonkénti/termékenkénti külön hívás), lásd getStockForecastBulk().
$allProductIds = [];
foreach ($groups as $group) {
    foreach ($group['products'] as $p) {
        $allProductIds[] = $p['id'];
    }
}
if ($allProductIds) {
    $forecast = $db->getStockForecastBulk($allProductIds, $windowDays);
    foreach ($groups as &$group) {
        foreach ($group['products'] as &$p) {
            $p['forecast'] = $forecast[$p['id']] ?? null;
        }
        unset($p);
    }
    unset($group);
}

send_json(['groups' => $groups]);
