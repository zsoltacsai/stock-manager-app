<?php

declare(strict_types=1);
require __DIR__ . '/_bootstrap.php';
require_once __DIR__ . '/../../src/ReportPeriod.php';

// 1.2.0 — Dashboard fő KPI-összesítő. Két réteg kombinálódik:
//  - "Mai" adatok MINDIG a naptári mai napra vonatkoznak (nem a period
//    paraméterre) — ezek a kasszás/üzletvezető számára a "mi történt ma"
//    gyors áttekintést adják, függetlenül attól, hogy éppen milyen
//    időszakot néz a bevétel-trendhez.
//  - "Időszak" adatok a kliens által választott period/date_from/date_to
//    alapján (lásd ReportPeriod::resolve() — backend-authoritative).
// Csak azok a KPI-k szerepelnek, amikhez ténylegesen van megbízható adat
// (lásd a kör 1. pontja) — nincs kitalált/becsült mező.

$period = (string) ($_GET['period'] ?? 'today');
try {
    $resolved = ReportPeriod::resolve($period, $_GET['date_from'] ?? null, $_GET['date_to'] ?? null);
} catch (InvalidArgumentException $e) {
    send_json(['error' => $e->getMessage()], 400);
}

$today = date('Y-m-d');
$todaySummary = $db->getSalesReportSummary($today, $today);
$todayPurchase = $db->getPeriodPurchaseTotal($today, $today);

$isToday = $resolved['from'] === $today && $resolved['to'] === $today;
$periodSummary = $isToday ? $todaySummary : $db->getSalesReportSummary($resolved['from'], $resolved['to']);

$lowStockThreshold = (int) ($appSettings['low_stock_default_threshold'] ?? 5);
$inventory = $db->getInventoryOverview($lowStockThreshold, 5);
$wcQueue = $db->getWcQueueStatusSummary(5);
$navQueue = $db->getInvoiceQueueStatusSummary('nav');

send_json([
    'period' => $resolved,
    'today' => [
        'revenue_gross'   => $todaySummary['total_gross'],
        'sales_count'     => $todaySummary['sales_count'],
        'avg_sale_gross'  => $todaySummary['avg_sale_gross'],
        'purchase_gross'  => $todayPurchase['total_gross'],
        'purchase_count'  => $todayPurchase['count'],
    ],
    'period_summary' => [
        'revenue_gross'   => $periodSummary['total_gross'],
        'revenue_net'     => $periodSummary['total_net'],
        'sales_count'     => $periodSummary['sales_count'],
        'avg_sale_gross'  => $periodSummary['avg_sale_gross'],
    ],
    'inventory' => [
        'total_products'  => $inventory['total_products'],
        'zero_stock'      => $inventory['zero_stock'],
        'low_stock'       => $inventory['low_stock'],
        'negative_stock'  => $inventory['negative_stock'],
        'stock_value_net' => $inventory['stock_value_net'],
    ],
    'woocommerce' => [
        'queued'      => $wcQueue['counts']['queued'],
        'processing'  => $wcQueue['counts']['processing'],
        'failed'      => $wcQueue['counts']['failed'] + $wcQueue['counts']['dead_letter'],
    ],
    'nav_invoices' => [
        'pending'  => $navQueue['buckets']['pending'],
        'failed'   => $navQueue['buckets']['failed'],
        'provider' => (string) ($appSettings['invoice_provider'] ?? 'szamlazz'),
    ],
    'woocommerce_configured' => !empty($config['woocommerce']['store_url']) && !empty($config['woocommerce']['consumer_key']),
]);
