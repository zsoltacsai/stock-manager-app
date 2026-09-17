<?php

declare(strict_types=1);
require __DIR__ . '/_bootstrap.php';
require_once __DIR__ . '/../../src/HealthMonitor.php';

// 1.4.0 — "Operations & Reliability": EGYETLEN aggregált végpont a teljes
// rendszerállapothoz (komponensek + "Figyelmet igényel" + összesített
// jelző) — sem a Rendszerállapot oldal, sem a Dashboard kompakt widgetje
// NEM tesz emiatt 10-20 külön kérést (lásd a kör 16. pontja). Ugyanazokat
// a MÁR meglévő, bulk lekérdezéseket használja, mint dashboard-summary.php
// (lásd ott a $healthSignals docblokkja) — nincs itt sem extra
// lekérdezés-duplikáció.

try {
    $db->pdo()->query('SELECT 1');
    $dbOk = true;
} catch (Throwable $e) {
    $dbOk = false;
}

$invoiceProvider = (string) ($appSettings['invoice_provider'] ?? 'szamlazz');
$lowStockThreshold = (int) ($appSettings['low_stock_default_threshold'] ?? 5);
$purchaseRecommendations = $db->getPurchaseRecommendations($lowStockThreshold, 30);

$healthSignals = [
    'settings' => $appSettings,
    'db_ok' => $dbOk,
    'woocommerce_configured' => !empty($config['woocommerce']['store_url']) && !empty($config['woocommerce']['consumer_key']),
    'wc_queue' => $db->getWcQueueStatusSummary(5),
    'sync_failures_24h' => $db->countRecentSyncFailures(24),
    'nav_configured' => $invoiceProvider === 'nav' && !empty($appSettings['nav_login']),
    'nav_queue' => $db->getInvoiceQueueStatusSummary('nav'),
    'invoice_provider' => $invoiceProvider,
    'invoice_failures_7d' => $db->countRecentInvoiceFailures(7),
    'draft_webshop_orders' => $db->countDraftWebshopOrders(),
    'urgent_purchase_count' => count(array_filter($purchaseRecommendations, static fn ($r) => $r['urgency'] === 'urgent')),
    'soon_purchase_count' => count(array_filter($purchaseRecommendations, static fn ($r) => $r['urgency'] === 'soon')),
    'other_low_purchase_count' => count(array_filter($purchaseRecommendations, static fn ($r) => $r['urgency'] === 'low')),
    'update_state' => $db->getUpdateState(),
];

$components = HealthMonitor::computeComponentStatuses($healthSignals);
$attention = HealthMonitor::computeAttentionItems($healthSignals);
$overall = HealthMonitor::computeOverallStatus($components);

// A technikai diagnosztikát (system_events technical_detail mezője) csak
// az "effektíve admin" session látja — lásd invoice-detail.php ugyanezen
// mintáját. A komponens-státuszok user_message-e MINDIG mindenkinek
// látszik (sose technikai/nyers szöveg, lásd HealthMonitor).
$isEffectiveAdmin = !$db->listStaff(true) || $db->isStaffAdmin(Auth::currentStaffId());

$recentEvents = $db->getSystemEvents([], 20);
if (!$isEffectiveAdmin) {
    foreach ($recentEvents as &$event) {
        unset($event['technical_detail']);
    }
    unset($event);
}

send_json([
    'overall' => $overall,
    'components' => $components,
    'attention' => $attention,
    'recent_events' => $recentEvents,
    'event_counts_24h' => $db->countRecentSystemEventsBySeverity(24),
    'is_admin' => $isEffectiveAdmin,
]);