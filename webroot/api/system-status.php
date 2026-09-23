<?php

declare(strict_types=1);
require __DIR__ . '/_bootstrap.php';
require_once __DIR__ . '/../../src/AppVersion.php';

$lowStockThreshold = (int) ($appSettings['low_stock_default_threshold'] ?? 5);

// Fázis 7 — a kör 20. pontja: az értesítési harang KIZÁRÓLAG akkor
// jelez, ha a LEGUTÓBB TÉNYLEGESEN elkészült ('completed') napi
// jelentés VALÓBAN tartalmaz jelentős megállapítást — sikertelen
// provider-kapcsolat, üres/nincs-találat jelentés, vagy ugyanannak a
// jelentésnek az ismételt lekérdezése SOSE jelez itt semmit (lásd
// Database::getLatestCompletedAiDailyReport() — 'failed'/'running'
// állapotú sorokat sose ad vissza).
$latestAiDailyReport = $db->getLatestCompletedAiDailyReport();
$aiDailyReportAvailable = $latestAiDailyReport !== null && !empty($latestAiDailyReport['has_significant_findings']);

send_json([
    'ai_daily_report_available' => $aiDailyReportAvailable,
    'ai_daily_report_date' => $aiDailyReportAvailable ? $latestAiDailyReport['report_date'] : null,
    'driver'   => $db->driver(),
    'products' => $db->countProducts(),
    'sales_today' => $db->countSalesToday(),
    'low_stock_count' => $db->countLowStockProducts($lowStockThreshold),
    'webshop_orders_draft_count' => $db->countDraftWebshopOrders(),
    'invoice_failures_7d' => $db->countRecentInvoiceFailures(7),
    'sync_failures_24h' => $db->countRecentSyncFailures(24),

    'wc_sync' => [
        'auto_enabled' => !empty($appSettings['auto_sync_enabled']),
        'last_run_at'  => $appSettings['last_auto_sync_at'] ?? null,
        'last_summary' => $appSettings['last_auto_sync_summary'] ?? null,
        'configured'   => !empty($config['woocommerce']['store_url']) && !empty($config['woocommerce']['consumer_key']),
    ],
    'backup' => [
        'auto_enabled' => !empty($appSettings['backup_enabled']),
        'last_run_at'  => $appSettings['last_backup_at'] ?? null,
        'last_summary' => $appSettings['last_backup_summary'] ?? null,
        'cloud_provider' => $appSettings['backup_provider'] ?? 'none',
    ],
    'printer' => [
        'enabled'  => !empty($appSettings['printer_enabled']),
        'ip_set'   => !empty($appSettings['printer_ip']),
    ],
    'szamlazz_configured' => !empty($appSettings['szamlazz_agent_key']) || !empty($config['szamlazz']['agent_key']),
    'loyalty_enabled'     => !empty($appSettings['loyalty_enabled']),

    'php_version' => PHP_VERSION,
    'app_version' => AppVersion::CURRENT,
    'recent_sync_log' => $db->getRecentSyncLog(20),
]);
