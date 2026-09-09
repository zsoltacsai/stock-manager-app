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
        // 'local'   — a telepítő/üzemeltető KIFEJEZETTEN úgy nyilatkozott,
        //             hogy ez a telepítés csak a helyi gépről/hálózatról
        //             érhető el, jelszó nélkül is (ez az alapértelmezés,
        //             visszafelé kompatibilis a korábbi viselkedéssel).
        // 'network' — a telepítő/üzemeltető KIFEJEZETTEN úgy nyilatkozott,
        //             hogy ez internetről/nyilvános hálózatról is elérhető
        //             lesz — ebben a módban a jelszavas védelem NEM
        //             kapcsolható ki (lásd Auth::isLoggedIn() és
        //             security-settings-save.php).
        // Ez a mező SOSE automatikusan, "kitalálva" áll be — sem az
        // alkalmazás nem tudja megbízhatóan eldönteni magától, hogy egy
        // adott kérés "csak helyi"-e (proxy/NAT mögött ez nem
        // megállapítható a szerver oldaláról), sem induláskor nincs
        // biztonságos alapértelmezés, ami mindkét esetre jó lenne —
        // ehelyett a telepítő kifejezetten megkérdezi.
        'deployment_mode'         => 'local',
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

        // Fizetési módok listája (kassza + beérkező webshop-rendelések
        // fizetésimód-választója) — bővíthető a Beállítások alatt, hogy pl.
        // egy webshopban használt "Stripe" is választható legyen helyben.
        'payment_methods' => [
            ['value' => 'Készpénz', 'color' => '#16a34a'],
            ['value' => 'Átutalás', 'color' => '#a855f7'],
            ['value' => 'Bankkártya', 'color' => '#3b82f6'],
            ['value' => 'PayPal', 'color' => '#14b8a6'],
            ['value' => 'Utánvét', 'color' => '#f97316'],
        ],

        // Melyik szolgáltató állítja ki a számlákat — 'szamlazz' (a
        // korábbi, egyetlen, változatlan viselkedés) vagy 'nav' (NAV
        // Online Számla). Az alapérték SZÁNDÉKOSAN 'szamlazz', hogy a
        // meglévő telepítéseknél frissítés után SEMMI ne változzon
        // automatikusan — lásd InvoiceService.
        'invoice_provider'         => 'szamlazz',

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
        // Termékkép feltöltéskor ekkora négyzet méretre (px) vágja/skálázza
        // a szerver a képet.
        'product_image_size'  => 1200,

        // NAV Online Számla technikai felhasználó — for company-lookup.php's
        // adószám-alapú cégadat kitöltés, ÉS (Phase 5B óta) a tényleges
        // NAV számlaküldés hitelesítéséhez is. See README for how to obtain these.
        'nav_login'        => '',
        'nav_password'     => '',
        'nav_signer_key'   => '',
        'nav_exchange_key' => '',
        'nav_tax_number'   => '',
        'nav_test_mode'    => false,

        // A NAV invoiceData minden egyes számlán megköveteli a kiállító
        // (eladó) teljes nevét/címét — a Számlázz.hu-val ellentétben
        // (ahol ez a Számlázz.hu-fiók oldalán van eltárolva) a NAV API-nak
        // NINCS "cégprofil"-fogalma, ezért ezt itt, explicit módon kell
        // tárolni. Csak akkor kötelező, ha invoice_provider='nav'.
        'nav_supplier_name'         => '',
        'nav_supplier_zip'          => '',
        'nav_supplier_city'         => '',
        'nav_supplier_address'      => '',
        'nav_supplier_bank_account' => '',

        // A NAV invoice queue háttér-workere (webroot/api/nav-queue-run.php)
        // — ugyanaz az "enabled-flag + cron hívja" minta, mint
        // auto_sync_enabled/backup_enabled. Alapértelmezetten KIKAPCSOLVA,
        // hogy egy meglévő telepítésen a cron beállítása nélkül sose
        // fusson le váratlanul.
        'nav_queue_enabled'          => false,
        'last_nav_queue_run_at'      => '',
        'last_nav_queue_run_summary' => '',

        'low_stock_default_threshold' => 5,
        'low_stock_notify_webhook'    => '',
        'low_stock_notify_email'      => '',

        // IP-cím / ország alapú hozzáférés-korlátozás (Beállítások → Biztonság)
        'geo_block_enabled'   => false,
        'geo_block_countries' => '',
        'geo_block_allow_ips' => '',

        // Cron-hitelesítés (auto-backup-run.php / auto-sync-run.php) — lásd
        // _bootstrap.php. Sose kerül URL-be, csak az X-Cron-Token fejlécbe.
        'cron_secret' => '',
    ];

    public function __construct(string $path)
    {
        $this->path = $path;
    }

    /**
     * @throws RuntimeException ha a settings.json fájl LÉTEZIK, de nem
     *         olvasható vagy nem érvényes JSON. Ez SZÁNDÉKOSAN nem esik
     *         vissza csendben self::DEFAULTS-ra: a DEFAULTS tartalmazza az
     *         'app_password_enabled' => false értéket, ami egy már
     *         konfigurált (jelszóval védett) telepítésen egy átmeneti
     *         olvasási hiba (lemez, jogosultság, egyidejű írás, sérülés)
     *         idejére KIKAPCSOLNÁ a bejelentkezés-kényszert minden
     *         kérésre — ez egy hitelesítés-megkerülés lenne. A hívónak
     *         (elsősorban _bootstrap.php) ezt a kivételt "fail closed"
     *         módon kell kezelnie: a kérést el kell utasítani, NEM
     *         folytatni úgy, mintha nem lenne jelszó beállítva.
     *         Ha a fájl egyáltalán nem létezik (valódi első-futtatás,
     *         még sose lett semmi elmentve), a DEFAULTS visszaadása
     *         helyes és biztonságos.
     */
    public function read(): array
    {
        if (!is_file($this->path)) {
            return self::DEFAULTS;
        }
        $raw = @file_get_contents($this->path);
        if ($raw === false || trim($raw) === '') {
            throw new RuntimeException('A beállítások fájlja nem olvasható vagy üres: ' . $this->path);
        }
        $data = json_decode($raw, true);
        if (!is_array($data)) {
            throw new RuntimeException('A beállítások fájlja sérült (érvénytelen JSON): ' . $this->path);
        }
        return array_merge(self::DEFAULTS, $data);
    }

    /**
     * Atomikus mentés: az új teljes tartalom egy ideiglenes fájlba íródik,
     * majd egyetlen rename()-nel kerül a végleges hely fölé. A rename()
     * ugyanazon a fájlrendszeren atomikus (a régi tartalom egyszerre,
     * egyben cserélődik az újra) — egy egyidejű OLVASÓ (read(), ami
     * szándékosan NEM szerez zárolást, hogy ne lassítsa a normál
     * kéréseket) emiatt SOSE láthat üres vagy félig-írt fájlt, csak a
     * teljes régi, vagy a teljes új tartalmat. Ez zárja ki azt a
     * versenyhelyzetet, amit a korábbi (zárolt, de a fájlt helyben
     * truncate-elő) megvalósítás nem: a truncate() és a tényleges write()
     * közötti pillanatban egy másik kérés üres fájlt olvashatott volna, és
     * a self::DEFAULTS-ra esett volna vissza — lásd read() docblockja.
     *
     * A konkurens ÍRÓK egy külön zárolási fájlon (.lock) keresztül
     * sorosítva vannak, hogy két majdnem egyidejű mentés ne veszítse el
     * egymás változásait (olvasás-módosítás-írás versenyhelyzet).
     *
     * @throws RuntimeException ha a meglévő fájl sérült, vagy az írás
     *         bármely lépése sikertelen — ilyenkor a MEGLÉVŐ (korábbi,
     *         érvényes) settings.json fájlhoz NEM nyúlunk, változatlanul
     *         megmarad.
     */
    public function save(array $partial): array
    {
        @mkdir(dirname($this->path), 0775, true);

        $lockHandle = fopen($this->path . '.lock', 'c');
        if ($lockHandle === false) {
            throw new RuntimeException('A beállítások mentéséhez szükséges zárolási fájl nem hozható létre.');
        }

        flock($lockHandle, LOCK_EX);
        try {
            $currentData = [];
            if (is_file($this->path)) {
                $current = @file_get_contents($this->path);
                if ($current === false) {
                    throw new RuntimeException('A meglévő beállítások fájlja nem olvasható — a mentés megszakítva.');
                }
                if (trim($current) !== '') {
                    $decoded = json_decode($current, true);
                    if (!is_array($decoded)) {
                        // A meglévő fájl LÉTEZIK, de nem érvényes JSON — sose
                        // írjuk felül csendben egy "DEFAULTS + új mező"
                        // tartalommal, mert az pl. egy korábban bekapcsolt
                        // jelszót észrevétlenül visszaállíthatna kikapcsoltra.
                        throw new RuntimeException('A meglévő beállítások fájlja sérült (érvénytelen JSON) — a mentés megszakítva, kézi ellenőrzés szükséges.');
                    }
                    $currentData = $decoded;
                }
            }

            $merged = array_merge(self::DEFAULTS, $currentData, $partial);

            $json = json_encode($merged, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
            if ($json === false) {
                throw new RuntimeException('Az új beállítások nem alakíthatók JSON-ná.');
            }

            $tmpPath = $this->path . '.tmp-' . bin2hex(random_bytes(8));
            if (file_put_contents($tmpPath, $json) === false) {
                @unlink($tmpPath);
                throw new RuntimeException('Nem sikerült az új beállításokat ideiglenes fájlba írni.');
            }
            @chmod($tmpPath, 0640);

            if (!rename($tmpPath, $this->path)) {
                @unlink($tmpPath);
                throw new RuntimeException('Nem sikerült a beállítások fájlját atomikusan cserélni.');
            }

            return $merged;
        } finally {
            flock($lockHandle, LOCK_UN);
            fclose($lockHandle);
        }
    }
}
