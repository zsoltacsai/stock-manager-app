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

        // Rendszeresemény-napló (1.4.0) — lásd migrateV26SystemEvents()
        // docblokkja. Rövidebb alapértelmezett megőrzés, mint az
        // audit_log-nál (30 nap), mert ez akár percenkénti cron-
        // eseményeket is rögzít — sokkal magasabb írási gyakoriság,
        // amit korlátlanul megőrizni felesleges adatbázis-növekedést
        // okozna (lásd a kör 17. pontja).
        'system_events_retention_days' => 14,

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
        // Kódlap az ékezetes (magyar) karakterekhez — lásd EscPosPrinter::CODEPAGES
        // docblockja: mindhárom lehetőség (cp852/cp1250/iso88592) hivatalosan
        // igazoltan támogatott az Epson TM-T20III-on, a tényleges alapértelmezés
        // (cp852) valódi hardveren ellenőrizve (lásd README).
        'printer_encoding'         => 'cp852',
        // Kasszaeladás után automatikus hálózati nyomtatás — alapból KIKAPCSOLVA,
        // hogy egy meglévő telepítésen a nyomtató beállítása nélkül sose próbáljon
        // váratlanul csatlakozni. A nyomtatási hiba SOSE rontja el magát az eladást
        // (lásd webroot/api/sale.php — a nyomtatás az eladás COMMIT-ja UTÁNI,
        // különálló, hibatűrő lépés).
        'printer_auto_print_enabled' => false,
        'printer_qr_enabled'         => false,
        // A nyomtatott nyugtára kerülő QR-kód linkje ehhez az alap URL-hez
        // fűzi hozzá a már meglévő digitális nyugta útvonalat
        // (receipt.html?sale_id=...&token=...) — ÜRESEN a QR-kód kihagyásra
        // kerül (nincs kitalált/nem működő link), lásd print-receipt.php.
        'receipt_public_base_url'    => '',

        // 1.4.0 — a nyomtatónak nincs ambiens (háttérben futó) állapot-
        // ellenőrzése, csak admin által kézzel indított teszt
        // (webroot/api/printer-test.php) — ennek EREDMÉNYÉT innentől
        // elmentjük, hogy a Rendszerállapot oldal "utoljára ismert"
        // státuszt tudjon mutatni, SOHA nem hamis "OK"-t egy sose
        // tesztelt nyomtatóra (lásd HealthMonitor::STATUS_NOT_CONFIGURED).
        'last_printer_test_at'      => null,
        'last_printer_test_status'  => null,  // 'success' | 'failure'
        'last_printer_test_message' => null,

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
        // Az 'is_cash' mező jelöli, melyik érték számít TÉNYLEGES
        // készpénznek a kasszazárás várható-összeg számításához (lásd
        // Database::computeExpectedCash()) — SOSE egy hardcodolt
        // 'Készpénz' string-összehasonlítás, mert egy bolt átnevezheti
        // vagy törölheti ezt az alapértelmezett bejegyzést. Egy régi
        // settings.json-ból betöltött, még is_cash mező NÉLKÜLI listát a
        // read() tölt fel utólag (lásd ott) — az array_merge() ugyanis
        // csak felső szintű kulcsokat cserél, a payment_methods egész
        // tömbjét egyben felülírná a tárolt (régi formátumú) tartalom.
        'payment_methods' => [
            ['value' => 'Készpénz', 'color' => '#16a34a', 'is_cash' => true],
            ['value' => 'Átutalás', 'color' => '#a855f7', 'is_cash' => false],
            ['value' => 'Bankkártya', 'color' => '#3b82f6', 'is_cash' => false],
            ['value' => 'PayPal', 'color' => '#14b8a6', 'is_cash' => false],
            ['value' => 'Utánvét', 'color' => '#f97316', 'is_cash' => false],
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

        // 1.4.0 — korábban (1.1.1 óta) wc-queue-run.php ÍRTA ezeket, de a
        // DEFAULTS-ban nem szerepeltek (csendes rés — friss telepítésen az
        // ELSŐ futásig hiányoztak). Most explicit deklarálva.
        'last_wc_queue_run_at'      => null,
        'last_wc_queue_run_summary' => null,

        // NAV Online Számla technikai felhasználó — for company-lookup.php's
        // adószám-alapú cégadat kitöltés, ÉS (Phase 5B óta) a tényleges
        // NAV számlaküldés hitelesítéséhez is. See README for how to obtain these.
        'nav_login'        => '',
        'nav_password'     => '',
        'nav_signer_key'   => '',
        'nav_exchange_key' => '',
        'nav_tax_number'   => '',
        'nav_test_mode'    => false,

        // 1.4.0 — lásd last_printer_test_* fenti indoklását, ugyanaz az
        // elv a NAV token-csere teszt (webroot/api/nav-test-connection.php)
        // eredményére.
        'last_nav_test_at'      => null,
        'last_nav_test_status'  => null,  // 'success' | 'failure'
        'last_nav_test_message' => null,

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

        // A NAV BEJÖVŐ számla-sync háttér-workere
        // (webroot/api/nav-incoming-sync-run.php) — ugyanaz az
        // "enabled-flag + cron hívja" minta, mint nav_queue_enabled fentebb,
        // teljesen FÜGGETLEN attól (a kimenő queue-t nem érinti, ha ez ki
        // van kapcsolva, és fordítva). Alapértelmezetten KIKAPCSOLVA.
        'nav_incoming_sync_enabled'          => false,
        'last_nav_incoming_sync_run_at'      => '',
        'last_nav_incoming_sync_run_summary' => '',

        // SMTP (Beállítások → Email fül) — lásd src/MailerService.php.
        // Üres host esetén a meglévő send-receipt-email.php a korábbi,
        // PHP mail()-alapú útra esik vissza (lásd ott).
        'smtp_host'       => '',
        'smtp_port'       => 587,
        'smtp_username'   => '',
        'smtp_password'   => '',
        'smtp_encryption' => 'starttls', // 'none' | 'ssl' | 'starttls'
        'smtp_from_name'  => '',
        'smtp_from_email' => '',
        // 1.4.0 — lásd last_printer_test_* fenti indoklását, ugyanaz az elv
        // az SMTP-teszt (webroot/api/smtp-test.php) eredményére.
        'last_smtp_test_at'      => null,
        'last_smtp_test_status'  => null,  // 'success' | 'failure'
        'last_smtp_test_message' => null,

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

        // Önfrissítés (Beállítások → Frissítések) — lásd README "Önfrissítés"
        // szakasza és src/UpdateService.php. Az automatikus ELLENŐRZÉS és a
        // (sokkal kockázatosabb) automatikus TELEPÍTÉS is alapból KIKAPCSOLT
        // — ugyanaz a minta, mint auto_sync_enabled/backup_enabled/
        // nav_queue_enabled: egy meglévő telepítésen frissítés után SOSE
        // induljon el váratlanul semmilyen új automatizmus.
        'update_auto_check_enabled'   => false,
        'update_auto_install_enabled' => false,
        'update_check_interval_hours' => 24,
        'update_channel'              => 'stable',

        // A karbantartási módot KIZÁRÓLAG az UpdateInstaller állítja be
        // belsőleg (lásd ott setMaintenanceMode()) — SOSE kerül be a
        // webroot/api/settings.php elfogadott mezői közé, tehát egy sima
        // admin POST erre sose tud közvetlenül hatni.
        'maintenance_mode_active'  => false,
        'maintenance_mode_message' => '',

        // AI asszisztens (első réteg — lásd src/Ai/) — alapból KIKAPCSOLT,
        // ugyanaz a minta, mint minden más opcionális automatizmusnál
        // fentebb. A helyi Ollama-t Kliens node SOSE hívja meg közvetlenül
        // — a webroot/api/_bootstrap.php node_role-elágazása Kliens módban
        // már ELŐBB a ClientProxy-hoz irányít, mielőtt ez a beállítás
        // egyáltalán számítana (lásd webroot/api/ai-inventory.php).
        'ai_enabled'            => false,
        'ai_local_base_url'     => 'http://127.0.0.1:11434',
        'ai_local_model'        => 'qwen3:8b',
        'ai_timeout_seconds'    => 30,
        'ai_max_iterations'     => 5,
        // NULL = a providerre/modellre bízott alapértelmezett — csak akkor
        // kerül ténylegesen a kérésbe, ha az admin explicit beállít egy
        // pozitív értéket (lásd LocalProvider — jelenleg nem korlátozza a
        // kimenetet, ez a mező a jövőbeli bővíthetőségért van előkészítve).
        // AnthropicProvider-nél a max_tokens KÖTELEZŐ mező (lásd az
        // Anthropic Messages API), ezért ott egy hardcodolt alapértelmezésre
        // esik vissza, ha ez üresen marad — lásd AnthropicProvider.php.
        'ai_max_output_tokens'  => null,

        // Fázis 2/3 — melyik providert használja az InventoryAgent, ha az
        // AI asszisztens be van kapcsolva. 'local' (Ollama, alapértelmezett,
        // az 1.5.0 Fázis 1 viselkedésével bit-azonos), 'anthropic' vagy
        // 'openai' — lásd src/Ai/AiProviderFactory.php. Szigorú whitelist,
        // sose felhasználó által megadott, tetszőleges osztálynév.
        'ai_provider' => 'local',

        // Anthropic (Claude) — az API-kulcs a MEGLÉVŐ titkos-mező mintát
        // használja (lásd SECRET_RESPONSE_FIELDS/maskSecretFields() lent,
        // és webroot/api/settings.php $secretFields tömbje): GET válaszban
        // sose megy ki nyersen, csak egy "_set" jelző; üres beküldött érték
        // NEM törli a meglévőt. Kliens node SOSE látja, SOSE hoz létre
        // AnthropicProvider-t — lásd webroot/api/_bootstrap.php node_role-
        // elágazása (ugyanaz a garancia, mint az Ollama-nál).
        'anthropic_api_key'         => '',
        'anthropic_model'           => 'claude-sonnet-5',
        'anthropic_base_url'        => 'https://api.anthropic.com',
        'anthropic_timeout_seconds' => 30,

        // Fázis 3 — OpenAI (Responses API), UGYANAZZAL a titkos-mező
        // mintával és Kliens/Szerver garanciával, mint az Anthropic
        // fentebb — lásd src/Ai/OpenAiProvider.php.
        'openai_api_key'         => '',
        'openai_model'           => 'gpt-6-sol',
        'openai_base_url'        => 'https://api.openai.com',
        'openai_timeout_seconds' => 30,

        // Fázis 7 — AI Daily Intelligence (napi AI-összefoglaló). Lásd a
        // kör 31. pontja explicit követelménye: "AI Daily Intelligence
        // must not silently become active just because AI is enabled" —
        // ezért KÜLÖN kapcsoló, alapból KIKAPCSOLVA, még akkor is, ha
        // 'ai_enabled' már igaz. 'ai_daily_intelligence_hour' — a
        // MEGLÉVŐ, percenkénti/30-percenkénti poll-mintát követő
        // cron-worker (ai-daily-intelligence-run.php) csak ETTŐL az
        // órától kezdve generál jelentést az adott napra (0-23,
        // Europe/Budapest) — a tényleges, kevés-perces pontosságot a
        // Feladatütemező ismétlési gyakorisága adja, ugyanúgy, mint az
        // update-check-run.php "esedékes-e" mintája.
        'ai_daily_intelligence_enabled'        => false,
        'ai_daily_intelligence_hour'           => 7,
        'ai_daily_intelligence_max_findings'   => 10,
        'ai_daily_intelligence_notify_enabled' => true,

        // Fázis 8A — AI Action Proposals + Human Approval. A kör 11.
        // pontja explicit követelménye: "AI Action Proposals must NOT
        // silently become active just because ai_enabled = true / daily_
        // intelligence = true" — ezért KÜLÖN kapcsoló, alapból KIKAPCSOLVA,
        // még akkor is, ha mindkét fenti már be van kapcsolva. A TTL
        // (a kör 13. pontja: "Add a configurable or centrally defined
        // TTL") KÖZPONTOSÍTVA, csak ActionProposalService olvassa.
        'ai_action_proposals_enabled'   => false,
        'ai_action_proposal_ttl_hours'  => 48,

        // Fázis 8B — Validated Action Execution. A ReorderDraftExecutor
        // a végrehajtási mennyiséget MINDIG frissen, a MEGLÉVŐ
        // PurchaseDecisionService::recommendedQuantity()-vel számolja
        // (SOSE a javaslat létrehozásakori adatból, lásd a kör 9. pontja:
        // "The LLM is never authoritative for quantity") — ez a beállítás
        // egy TOVÁBBI, admin által állítható felső korlát (biztonsági
        // "sapka") a kiszámított mennyiségre, arra az esetre, ha egy
        // szélsőséges bemenet (pl. hibásan rögzített, irreálisan magas
        // fogyás-adat) miatt a determinisztikus számítás túl nagy
        // mennyiséget adna.
        'ai_reorder_draft_max_quantity' => 500,
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
        return self::backfillPaymentMethodIsCash(array_merge(self::DEFAULTS, $data));
    }

    /**
     * array_merge(DEFAULTS, $data) csak felső szintű kulcsokat cserél — egy
     * 1.5.0 ELŐTTI settings.json-ban tárolt payment_methods tömb (ami MÁR
     * benne van $data-ban, ha valaha mentettek beállítást) egyben felülírja
     * a fenti, is_cash-t már tartalmazó alapértelmezést, mezőnkénti
     * egyesítés nélkül. Ez itt utólag pótolja a hiányzó is_cash kulcsot
     * minden bejegyzésnél — 'Készpénz' → true (a korábbi, hardcodolt
     * összehasonlítás alapja volt), minden más → false — hogy egy régi
     * telepítés frissítés után is helyesen számolja a kasszazárás várható
     * összegét, admin beavatkozás nélkül.
     */
    private static function backfillPaymentMethodIsCash(array $settings): array
    {
        if (!isset($settings['payment_methods']) || !is_array($settings['payment_methods'])) {
            return $settings;
        }
        foreach ($settings['payment_methods'] as &$method) {
            if (is_array($method) && !array_key_exists('is_cash', $method)) {
                $method['is_cash'] = (($method['value'] ?? '') === 'Készpénz');
            }
        }
        unset($method);
        return $settings;
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

    /**
     * A titkos/hitelesítő-adat mezők KÖZPONTI listája, amik SOSE mehetnek
     * ki nyers szövegként egy API-válaszban — bárki, aki be van jelentkezve
     * az appba (akár egy egyszerű pénztáros is), egyébként kiolvashatná a
     * WooCommerce/Számlázz.hu/NAV/felhő hitelesítő adatait. Regresszió
     * (1.3.1): korábban ez a lista/logika KÉTSZER, egymástól függetlenül
     * volt megírva (settings.php GET/POST válasza ÉS
     * security-settings-save.php válasza) — a második másolat lemaradt egy
     * korábbi bővítésről, és emiatt minden security-settings-save.php
     * hívás (pl. egy sima geo-blokkolás-váltás) az ÖSSZES titkot nyers
     * szövegben visszaküldte. Egyetlen közös forrás, mindkét hívó ezt
     * használja.
     */
    private const SECRET_RESPONSE_FIELDS = [
        'dropbox_access_token', 'google_client_secret', 'google_refresh_token',
        'szamlazz_agent_key', 'wc_consumer_key', 'wc_consumer_secret', 'wc_webhook_secret',
        'nav_password', 'nav_signer_key', 'nav_exchange_key', 'cron_secret',
        'low_stock_notify_webhook',
        'smtp_password',
        'anthropic_api_key',
        'openai_api_key',
    ];

    /**
     * @param array $data Egy Settings::save()/olvasás eredménye.
     * @return array Ugyanaz az adat, a titkos mezők nyers értéke ''-re
     *   cserélve, plusz egy "<mező>_set" boolean jelző, hogy a UI tudja:
     *   van már elmentett érték, csak nem mutatja.
     */
    public static function maskSecretFields(array $data): array
    {
        foreach (self::SECRET_RESPONSE_FIELDS as $field) {
            $data[$field . '_set'] = !empty($data[$field]);
            $data[$field] = '';
        }
        return $data;
    }
}
