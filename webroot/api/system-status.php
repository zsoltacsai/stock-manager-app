<?php

declare(strict_types=1);
require __DIR__ . '/_bootstrap.php';

$lowStockThreshold = (int) ($appSettings['low_stock_default_threshold'] ?? 5);

send_json([
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
    'app_version' => '1.0 beta 9',
    'recent_sync_log' => $db->getRecentSyncLog(20),
]);
