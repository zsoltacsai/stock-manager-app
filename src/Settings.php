<?php

class Settings
{
    private string $path;

    private const DEFAULTS = [
        'auto_sync_enabled'          => false,
        'auto_sync_interval_minutes' => 15,
        'last_auto_sync_at'          => null,
        'last_auto_sync_summary'     => null,
        'logo_filename'              => null,
        // Nyomtatáshoz külön logó — a nyugtán (böngésző-print) ezt használjuk,
        // ha be van állítva, mert a felület sidebar-logója nem mindig
        // nyomtat/olvasható jól papíron (pl. szín/kontraszt miatt). Ha nincs
        // beállítva, a rendes logo_filename-re esik vissza.
        'print_logo_filename'        => null,
        'theme'                      => 'dark',

        'receipt_header_lines' => "Fountainbridge Bolt\nSzeged",
        'receipt_footer_lines' => "Köszönjük a vásárlást!",
        'receipt_show_logo'    => false,

        // Törzsvásárlói / hűségpont rendszer
        'loyalty_enabled'         => false,
        'loyalty_huf_per_point'   => 100,  // ennyi Ft költés után jár 1 pont
        'loyalty_point_value_huf' => 5,    // 1 pont ennyi Ft kedvezményt ér beváltáskor

        // Tevékenységnapló (audit log)
        'audit_log_retention_days' => 30,

        // Biztonság
        'app_password_hash'      => null,  // ha be van állítva, minden oldal/API bejelentkezést kér
        'app_password_enabled'   => false,
        'session_timeout_minutes' => 240,  // 4 óra inaktivitás után automatikus kijelentkezés
        'login_max_attempts'     => 5,     // ennyi hibás próbálkozás után zárolás
        'login_lockout_minutes'  => 15,

        // Hűségszintek (loyalty tiers) — élettartam-elköltés alapján, plusz kedvezmény %-ban
        'loyalty_tier_silver_threshold' => 50000,
        'loyalty_tier_silver_discount'  => 5,
        'loyalty_tier_gold_threshold'   => 150000,
        'loyalty_tier_gold_discount'    => 10,

        'printer_enabled'     => false,
        'printer_ip'          => '',
        'printer_port'        => 9100,
        'printer_paper_width' => 42,

        'backup_enabled'          => false,
        'backup_time'             => '23:30',
        'backup_retention_count'  => 7,
        'backup_provider'         => 'none',
        'last_backup_at'          => null,
        'last_backup_summary'     => null,

        'dropbox_access_token' => '',
        'dropbox_folder'       => '/StockManagerBackups',

        'google_client_id'     => '',
        'google_client_secret' => '',
        'google_refresh_token' => '',
        'google_folder_id'     => '',

        'szamlazz_agent_key'       => '',
        'szamlazz_default_payment' => 'Készpénz',
        'szamlazz_default_vat'     => '27',
        'szamlazz_send_email'      => false,

        'wc_store_url'        => '',
        'wc_consumer_key'     => '',
        'wc_consumer_secret'  => '',
        'wc_barcode_source'   => 'sku',
        'wc_barcode_meta_key' => '_barcode',
        'wc_webhook_secret'   => '',
        // Kívülről elérhető alap URL (pl. https://kassza.pelda.hu) — enélkül
        // a termékkép feltöltésekor a kép csak helyben menthető, a
        // WooCommerce-nek kiküldött szinkron nem tudja csatolni, mert a
        // WooCommerce szervere nem éri el a fájlt letöltésre.
        'wc_public_base_url'  => '',
        // Helyi márkanév → WooCommerce márka neve megfeleltetés (objektum),
        // hogy a szinkron-kiküldéskor a helyi elnevezés eltérése esetén se
        // jöjjön létre felesleges duplikált márka a WooCommerce-ben.
        'brand_mapping'       => [],

        // NAV Online Számla technikai felhasználó — for company-lookup.php's
        // adószám-alapú cégadat kitöltés. See README for how to obtain these.
        'nav_login'        => '',
        'nav_password'     => '',
        'nav_signer_key'   => '',
        'nav_exchange_key' => '',
        'nav_tax_number'   => '',
        'nav_test_mode'    => false,

        'low_stock_default_threshold' => 5,
        'low_stock_notify_webhook'    => '',
        'low_stock_notify_email'      => '',

        // IP-cím / ország alapú hozzáférés-korlátozás (Beállítások → Biztonság)
        'geo_block_enabled'   => false,
        'geo_block_countries' => '',
        'geo_block_allow_ips' => '',
    ];

    public function __construct(string $path)
    {
        $this->path = $path;
    }

    public function read(): array
    {
        if (!is_file($this->path)) {
            return self::DEFAULTS;
        }
        $data = json_decode((string) file_get_contents($this->path), true);
        return is_array($data) ? array_merge(self::DEFAULTS, $data) : self::DEFAULTS;
    }

    public function save(array $partial): array
    {
        $current = $this->read();
        $merged = array_merge($current, $partial);
        @mkdir(dirname($this->path), 0775, true);
        file_put_contents($this->path, json_encode($merged, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
        return $merged;
    }
}
