<?php

declare(strict_types=1);
require __DIR__ . '/_bootstrap.php';
require_once __DIR__ . '/../../src/Settings.php';

$settings = new Settings(__DIR__ . '/../../data/settings.json');

function logo_url(?string $filename): string
{
    if ($filename && is_file(__DIR__ . '/../assets/' . $filename)) {
        return 'assets/' . $filename . '?v=' . filemtime(__DIR__ . '/../assets/' . $filename);
    }
    return 'assets/logo-default.svg';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $input = json_input();
    $update = [];

    // Egyszerű szöveges mezők — trim-elve, változtatás nélkül eltárolva.
    $stringFields = [
        'printer_ip', 'dropbox_access_token', 'dropbox_folder',
        'google_client_id', 'google_client_secret', 'google_refresh_token', 'google_folder_id',
        'szamlazz_agent_key', 'szamlazz_default_payment', 'szamlazz_default_vat',
        'wc_store_url', 'wc_consumer_key', 'wc_consumer_secret', 'wc_barcode_source', 'wc_barcode_meta_key', 'wc_webhook_secret',
        'nav_login', 'nav_password', 'nav_signer_key', 'nav_exchange_key', 'nav_tax_number',
        'low_stock_notify_webhook', 'low_stock_notify_email',
        'receipt_header_lines', 'receipt_footer_lines',
    ];
    foreach ($stringFields as $field) {
        if (isset($input[$field])) {
            $update[$field] = trim((string) $input[$field]);
        }
    }

    // Logikai (be/ki) mezők.
    $boolFields = ['auto_sync_enabled', 'printer_enabled', 'backup_enabled', 'szamlazz_send_email', 'nav_test_mode', 'receipt_show_logo', 'loyalty_enabled'];
    foreach ($boolFields as $field) {
        if (isset($input[$field])) {
            $update[$field] = (bool) $input[$field];
        }
    }

    // Ellenőrzést / korlátozást igénylő mezők.
    if (isset($input['auto_sync_interval_minutes'])) {
        $update['auto_sync_interval_minutes'] = max(1, (int) $input['auto_sync_interval_minutes']);
    }
    if (isset($input['printer_port'])) {
        $update['printer_port'] = max(1, (int) $input['printer_port']);
    }
    if (isset($input['printer_paper_width'])) {
        $update['printer_paper_width'] = max(20, (int) $input['printer_paper_width']);
    }
    if (isset($input['backup_time']) && preg_match('/^\d{2}:\d{2}$/', $input['backup_time'])) {
        $update['backup_time'] = $input['backup_time'];
    }
    if (isset($input['backup_retention_count'])) {
        $update['backup_retention_count'] = max(1, min(100, (int) $input['backup_retention_count']));
    }
    if (isset($input['backup_provider']) && in_array($input['backup_provider'], ['none', 'dropbox', 'googledrive'], true)) {
        $update['backup_provider'] = $input['backup_provider'];
    }
    if (isset($input['dropbox_folder'])) {
        $update['dropbox_folder'] = trim((string) $input['dropbox_folder']) ?: '/StockManagerBackups';
    }
    if (isset($input['low_stock_default_threshold'])) {
        $update['low_stock_default_threshold'] = max(0, (int) $input['low_stock_default_threshold']);
    }
    if (isset($input['theme']) && in_array($input['theme'], ['dark', 'light'], true)) {
        $update['theme'] = $input['theme'];
    }
    if (isset($input['loyalty_huf_per_point'])) {
        $update['loyalty_huf_per_point'] = max(1, (int) $input['loyalty_huf_per_point']);
    }
    if (isset($input['loyalty_point_value_huf'])) {
        $update['loyalty_point_value_huf'] = max(0, (int) $input['loyalty_point_value_huf']);
    }
    if (isset($input['loyalty_tier_silver_threshold'])) {
        $update['loyalty_tier_silver_threshold'] = max(0, (int) $input['loyalty_tier_silver_threshold']);
    }
    if (isset($input['loyalty_tier_silver_discount'])) {
        $update['loyalty_tier_silver_discount'] = max(0, min(100, (float) $input['loyalty_tier_silver_discount']));
    }
    if (isset($input['loyalty_tier_gold_threshold'])) {
        $update['loyalty_tier_gold_threshold'] = max(0, (int) $input['loyalty_tier_gold_threshold']);
    }
    if (isset($input['loyalty_tier_gold_discount'])) {
        $update['loyalty_tier_gold_discount'] = max(0, min(100, (float) $input['loyalty_tier_gold_discount']));
    }
    if (isset($input['audit_log_retention_days'])) {
        $update['audit_log_retention_days'] = max(1, (int) $input['audit_log_retention_days']);
    }

    $data = $settings->save($update);
} else {
    $data = $settings->read();
}

$data['logo_url'] = logo_url($data['logo_filename']);
$data['print_logo_url'] = logo_url($data['print_logo_filename'] ?? null);
unset($data['app_password_hash']); // a hash sose menjen ki a klienshez, semmilyen hívásnál
send_json($data);
