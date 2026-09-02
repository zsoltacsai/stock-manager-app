<?php
/**
 * Central configuration for the Stock Manager app.
 * Fill in your real values below. Keep this file out of version control
 * if it ever contains real API keys (see .gitignore).
 *
 * 'shop' and 'db' can also be set through the first-run installer
 * (install.php) instead of editing this file by hand — it writes its
 * choices to installer-generated.php, which is merged in below if present.
 */

$installerConfig = is_file(__DIR__ . '/installer-generated.php')
    ? require __DIR__ . '/installer-generated.php'
    : [];

return [

    // ------------------------------------------------------------------
    // Shop info — shown on the printed receipt / test print
    // ------------------------------------------------------------------
    'shop' => $installerConfig['shop'] ?? [
        'name'    => 'Fountainbridge Bolt',
        'address' => 'Szeged',
    ],

    // ------------------------------------------------------------------
    // Database — SQLite (default, zero-config) or MySQL 8 (for larger
    // installs with a lot of sales history; better concurrent-write
    // behaviour than SQLite under real load).
    // ------------------------------------------------------------------
    'db' => $installerConfig['db'] ?? [
        'driver' => 'sqlite', // 'sqlite' | 'mysql'

        'sqlite' => [
            // Must be writable by the PHP process.
            'path' => __DIR__ . '/../data/stock.sqlite',
        ],

        'mysql' => [
            'host'     => '127.0.0.1',
            'port'     => 3306,
            'database' => 'stock_manager',
            'username' => 'stock_manager',
            'password' => '',
            'charset'  => 'utf8mb4',
        ],
    ],

    // ------------------------------------------------------------------
    // WooCommerce REST API (Woo > Settings > Advanced > REST API)
    // Needs Read/Write permissions.
    // ------------------------------------------------------------------
    'woocommerce' => [
        'store_url'       => 'https://your-shop.example.com',
        'consumer_key'    => 'ck_xxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx',
        'consumer_secret' => 'cs_xxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx',

        // WooCommerce doesn't have a native "barcode" field. Most stores
        // either reuse the SKU as the barcode, or store the barcode in a
        // custom/meta field (e.g. from a barcode plugin). Set which one
        // you use here so the sync code reads the right field.
        // Options: 'sku'  -> use the WooCommerce SKU as barcode
        //          'meta' -> read a custom meta_data key (set below)
        'barcode_source'  => 'sku',
        'barcode_meta_key'=> '_barcode',

        // Shared secret used to validate incoming WooCommerce webhooks
        // (Woo > Settings > Advanced > Webhooks > Secret).
        'webhook_secret'  => 'change-me-webhook-secret',
    ],

    // ------------------------------------------------------------------
    // Szamlazz.hu Számla Agent
    // https://docs.szamlazz.hu/agent/
    // ------------------------------------------------------------------
    'szamlazz' => [
        'agent_key'   => 'PASTE-YOUR-SZAMLAAGENT-KULCS-HERE',
        'endpoint'    => 'https://www.szamlazz.hu/szamla/',

        'e_invoice'        => true,   // eszamla
        'download_pdf'     => true,   // szamlaLetoltes
        'send_email'       => false,  // vevo/sendEmail - email the buyer automatically
        'payment_method'   => 'Készpénz', // fizmod: e.g. "Készpénz", "Átutalás", "Bankkártya"
        'currency'         => 'HUF',
        'language'         => 'hu',
        'default_vat_rate' => '27',   // afakulcs, percentage as string, e.g. "27", "AAM", "TAM"
        'unit_label'       => 'db',   // mennyisegiEgyseg

        // No longer used for till sales: when a customer doesn't ask for an
        // invoice, the till skips Szamlazz.hu entirely (no call is made at
        // all — only WooCommerce gets the stock update). Kept here only in
        // case you want to wire up a "always invoice, even without asking"
        // mode yourself later.
        'default_buyer' => [
            'nev'       => 'Készpénzes vevő',
            'irsz'      => '0000',
            'telepules' => 'N/A',
            'cim'       => 'N/A',
        ],

        // Where to save downloaded invoice PDFs.
        'pdf_dir' => __DIR__ . '/../invoices',
    ],
];
