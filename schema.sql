-- Stock Manager local database schema (SQLite)

CREATE TABLE IF NOT EXISTS products (
    id                  INTEGER PRIMARY KEY AUTOINCREMENT,
    wc_product_id       INTEGER,               -- matching WooCommerce product ID, if synced
    sku                 TEXT,
    barcode             TEXT UNIQUE,
    name                TEXT NOT NULL,
    unit                TEXT NOT NULL DEFAULT 'db',   -- mértékegység
    group_name          TEXT,                  -- csoport
    cikkszam            TEXT,                  -- cikkszám (internal item number)
    vtsz                TEXT,                  -- vtsz/szj (customs tariff number)
    currency            TEXT NOT NULL DEFAULT 'HUF',
    net_price           REAL NOT NULL DEFAULT 0,   -- nettó eladási egységár
    price               REAL NOT NULL DEFAULT 0,   -- bruttó (incl. VAT) eladási egységár
    vat_rate            TEXT NOT NULL DEFAULT '27',
    purchase_price_net  REAL NOT NULL DEFAULT 0,   -- utolsó ismert beszerzési (nettó) ár
    stock_qty           INTEGER NOT NULL DEFAULT 0,
    weight              REAL,                  -- tömeg (kg/db)
    volume              REAL,                  -- térfogat (m3/db)
    notes               TEXT,                  -- megjegyzés
    show_pricelist      INTEGER NOT NULL DEFAULT 1,  -- feltüntetve az árlistán
    show_webshop        INTEGER NOT NULL DEFAULT 1,  -- feltüntetve webáruházban
    is_deleted          INTEGER NOT NULL DEFAULT 0,  -- árucikk törölve
    low_stock_threshold INTEGER,               -- riasztási küszöb; NULL = globális alapérték használata
    preferred_supplier_id INTEGER REFERENCES suppliers(id), -- kitől szoktuk ezt beszerezni — a beszerzési javaslathoz
    short_description   TEXT,                  -- rövid termékleírás
    long_description    TEXT,                  -- hosszú termékleírás
    image_filename       TEXT,                  -- termékkép fájlneve (webroot/assets/products/)
    image_alt            TEXT,                  -- kép alt szövege (SEO)
    brand                TEXT,                  -- márka — a WooCommerce natív brand mezőjével szinkronban
    sync_to_woocommerce INTEGER NOT NULL DEFAULT 1, -- 0 = csak üzletben elérhető, ne szinkronizáljon
    updated_at          TEXT,                  -- last local change
    wc_synced_at        TEXT                   -- last successful sync with WooCommerce
);

CREATE INDEX IF NOT EXISTS idx_products_barcode ON products(barcode);
CREATE INDEX IF NOT EXISTS idx_products_wc_id   ON products(wc_product_id);
CREATE INDEX IF NOT EXISTS idx_products_group   ON products(group_name);
CREATE INDEX IF NOT EXISTS idx_products_deleted ON products(is_deleted);
CREATE INDEX IF NOT EXISTS idx_products_preferred_supplier ON products(preferred_supplier_id);

CREATE TABLE IF NOT EXISTS sales (
    id                       INTEGER PRIMARY KEY AUTOINCREMENT,
    total                    REAL NOT NULL,
    payment_method           TEXT NOT NULL DEFAULT 'Készpénz',
    buyer_name               TEXT,                  -- vevő neve/cégnév, ha számlát kért
    customer_id              INTEGER REFERENCES customers(id), -- törzsvásárló, ha be volt jelölve
    loyalty_points_earned    INTEGER NOT NULL DEFAULT 0,
    loyalty_points_redeemed  INTEGER NOT NULL DEFAULT 0,
    coupon_id                INTEGER REFERENCES coupons(id),
    coupon_discount          REAL NOT NULL DEFAULT 0,
    gift_card_redeemed       REAL NOT NULL DEFAULT 0,
    staff_id                 INTEGER REFERENCES staff(id),
    szamlazz_invoice_number  TEXT,
    szamlazz_pdf_path        TEXT,
    status                   TEXT NOT NULL DEFAULT 'completed', -- completed | invoice_failed | invoice_uncertain (P1-5: Számlázz.hu transport-hiba, admin feloldása szükséges)
    receipt_token            TEXT,                  -- kitalálhatatlan token a nyugta bejelentkezés nélküli megtekintéséhez (QR-kód)
    idempotency_key          TEXT,                  -- kliens-generált kulcs, duplikált eladás (dupla kattintás/újrapróbálkozás) elleni védelemhez — lásd Database::insertSale()
    idempotency_fingerprint  TEXT,                  -- a kérés üzletileg releváns mezőinek sha256-hash-e — ugyanaz a kulcs, de eltérő ujjlenyomat esetén 409 Conflict, lásd sale.php build_sale_fingerprint()
    invoice_claim_at         TEXT,                  -- atomikus "számla kiállítása folyamatban" foglalás időbélyege — lásd Database::tryClaimInvoiceIssuance()
    cash_session_id          INTEGER REFERENCES cash_sessions(id), -- melyik nyitott kasszaműszakhoz tartozik — lásd Database::openCashSession()
    created_at               TEXT NOT NULL DEFAULT (datetime('now'))
);

-- The daily zárás report and every "prune to keep N days" style query
-- filters by date, so this index is the difference between a table scan
-- and an index seek once there's a real sales history.
CREATE INDEX IF NOT EXISTS idx_sales_created_at ON sales(created_at);
CREATE INDEX IF NOT EXISTS idx_sales_customer_id ON sales(customer_id);
CREATE INDEX IF NOT EXISTS idx_sales_staff_id ON sales(staff_id);
CREATE UNIQUE INDEX IF NOT EXISTS idx_sales_idempotency_key ON sales(idempotency_key);
CREATE INDEX IF NOT EXISTS idx_sales_coupon_id ON sales(coupon_id);
CREATE INDEX IF NOT EXISTS idx_sales_cash_session_id ON sales(cash_session_id);

-- One row per day a "napi zárás" (daily closing) was run. Re-closing the
-- same date overwrites the row (INSERT OR REPLACE), useful if a late
-- invoice retry changed the numbers after the first closing.
CREATE TABLE IF NOT EXISTS closings (
    closing_date          TEXT PRIMARY KEY, -- YYYY-MM-DD
    sales_count           INTEGER NOT NULL,
    total_gross           REAL NOT NULL,
    total_net             REAL NOT NULL,
    total_vat             REAL NOT NULL,
    payment_breakdown_json TEXT,
    vat_breakdown_json     TEXT,
    closed_at             TEXT NOT NULL DEFAULT (datetime('now'))
);

CREATE TABLE IF NOT EXISTS sale_items (
    id           INTEGER PRIMARY KEY AUTOINCREMENT,
    sale_id      INTEGER NOT NULL REFERENCES sales(id),
    product_id   INTEGER REFERENCES products(id), -- NULL = kézzel hozzáadott tétel, nincs raktárkészlet mögötte
    name         TEXT NOT NULL,
    qty          INTEGER NOT NULL,
    unit_price   REAL NOT NULL,   -- gross unit price at time of sale
    vat_rate     TEXT NOT NULL
);

CREATE INDEX IF NOT EXISTS idx_sale_items_sale_id ON sale_items(sale_id);
CREATE INDEX IF NOT EXISTS idx_sale_items_product_id ON sale_items(product_id);

-- Beszerzés (incoming stock / purchases from suppliers)
CREATE TABLE IF NOT EXISTS purchases (
    id                    INTEGER PRIMARY KEY AUTOINCREMENT,
    supplier_id           INTEGER REFERENCES suppliers(id),
    supplier_name         TEXT,
    supplier_tax_number   TEXT,
    supplier_country      TEXT,
    supplier_zip          TEXT,
    supplier_city         TEXT,
    supplier_address      TEXT,
    payment_method        TEXT DEFAULT 'készpénz',
    currency              TEXT DEFAULT 'HUF',
    discount_percent      REAL DEFAULT 0,
    paid                  INTEGER DEFAULT 1,
    note                  TEXT,
    total_net             REAL NOT NULL DEFAULT 0,
    total_gross           REAL NOT NULL DEFAULT 0,
    idempotency_key         TEXT,
    idempotency_fingerprint TEXT,
    created_at            TEXT NOT NULL DEFAULT (datetime('now'))
);
CREATE UNIQUE INDEX IF NOT EXISTS idx_purchases_idempotency_key ON purchases(idempotency_key);

CREATE TABLE IF NOT EXISTS purchase_items (
    id               INTEGER PRIMARY KEY AUTOINCREMENT,
    purchase_id      INTEGER NOT NULL REFERENCES purchases(id),
    product_id       INTEGER NOT NULL REFERENCES products(id),
    name             TEXT NOT NULL,
    qty              INTEGER NOT NULL,
    vat_rate         TEXT NOT NULL,
    unit_cost_net    REAL NOT NULL,
    unit_cost_gross  REAL NOT NULL,
    line_net         REAL NOT NULL,
    line_gross       REAL NOT NULL
);

CREATE INDEX IF NOT EXISTS idx_purchase_items_purchase_id ON purchase_items(purchase_id);
CREATE INDEX IF NOT EXISTS idx_purchase_items_product_id ON purchase_items(product_id);
CREATE INDEX IF NOT EXISTS idx_purchases_created_at ON purchases(created_at);
CREATE INDEX IF NOT EXISTS idx_purchases_supplier_id ON purchases(supplier_id);

-- Records every stock movement caused by sync, for troubleshooting.
CREATE TABLE IF NOT EXISTS sync_log (
    id          INTEGER PRIMARY KEY AUTOINCREMENT,
    direction   TEXT NOT NULL,   -- 'pull' | 'push' | 'webhook' | 'purchase'
    product_id  INTEGER,
    message     TEXT,
    created_at  TEXT NOT NULL DEFAULT (datetime('now'))
);
CREATE INDEX IF NOT EXISTS idx_sync_log_created_at ON sync_log(created_at);

-- Unused (1.3.1) — the webhook dedup this was built for now lives on
-- webshop_orders.wc_order_id's UNIQUE index (see
-- Database::insertWebshopOrderDraft()); kept only because dropping a table
-- needs a migration, which isn't warranted on its own for a stability
-- release. No code reads or writes this table.
CREATE TABLE IF NOT EXISTS processed_webhook_orders (
    wc_order_id  INTEGER PRIMARY KEY,
    processed_at TEXT NOT NULL DEFAULT (datetime('now'))
);

-- Tracks which migrations have run — lets the app check "is this DB
-- current?" with one cheap SELECT instead of re-attempting every ALTER
-- TABLE on every request (see Database::ensureSchema).
CREATE TABLE IF NOT EXISTS schema_version (
    version INTEGER NOT NULL
);

-- Beszállító-törzs (supplier master data) — beszerzés still keeps its own
-- free-text supplier_* columns for one-off/unregistered suppliers, but
-- picking a saved supplier here auto-fills those and links supplier_id.
CREATE TABLE IF NOT EXISTS suppliers (
    id              INTEGER PRIMARY KEY AUTOINCREMENT,
    name            TEXT NOT NULL,
    tax_number      TEXT,
    country         TEXT,
    zip             TEXT,
    city            TEXT,
    address         TEXT,
    contact_name    TEXT,
    phone           TEXT,
    email           TEXT,
    payment_terms   TEXT,
    notes           TEXT,
    is_deleted      INTEGER NOT NULL DEFAULT 0,
    created_at      TEXT NOT NULL DEFAULT (datetime('now')),
    updated_at      TEXT
);
CREATE INDEX IF NOT EXISTS idx_suppliers_name ON suppliers(name);

-- Törzsvásárlói / hűségpont rendszer.
CREATE TABLE IF NOT EXISTS customers (
    id                INTEGER PRIMARY KEY AUTOINCREMENT,
    name              TEXT NOT NULL,
    phone             TEXT,
    email             TEXT,
    tax_number        TEXT,
    zip               TEXT,
    city              TEXT,
    address           TEXT,
    country           TEXT,
    notes             TEXT,
    loyalty_points    INTEGER NOT NULL DEFAULT 0,
    total_spent       REAL NOT NULL DEFAULT 0, -- élettartam-összeg, ez adja a hűségszintet
    is_deleted        INTEGER NOT NULL DEFAULT 0,
    created_at        TEXT NOT NULL DEFAULT (datetime('now')),
    updated_at        TEXT
);
CREATE INDEX IF NOT EXISTS idx_customers_name ON customers(name);
CREATE INDEX IF NOT EXISTS idx_customers_phone ON customers(phone);

-- One row per point change (earn on a sale, or manual/redeem adjustment) —
-- keeps a readable history instead of just the running total on customers.
CREATE TABLE IF NOT EXISTS loyalty_transactions (
    id            INTEGER PRIMARY KEY AUTOINCREMENT,
    customer_id   INTEGER NOT NULL REFERENCES customers(id),
    sale_id       INTEGER REFERENCES sales(id),
    points_delta  INTEGER NOT NULL,
    note          TEXT,
    created_at    TEXT NOT NULL DEFAULT (datetime('now'))
);
CREATE INDEX IF NOT EXISTS idx_loyalty_customer_id ON loyalty_transactions(customer_id);
-- Kedvezménykód / kupon
CREATE TABLE IF NOT EXISTS coupons (
    id              INTEGER PRIMARY KEY AUTOINCREMENT,
    code            TEXT NOT NULL,
    type            TEXT NOT NULL DEFAULT 'percent', -- 'percent' | 'fixed'
    value           REAL NOT NULL,
    is_active       INTEGER NOT NULL DEFAULT 1,
    expiry_date     TEXT,
    usage_limit     INTEGER,               -- NULL = korlátlan
    times_used      INTEGER NOT NULL DEFAULT 0,
    min_purchase    REAL NOT NULL DEFAULT 0,
    notes           TEXT,
    created_at      TEXT NOT NULL DEFAULT (datetime('now'))
);
CREATE UNIQUE INDEX IF NOT EXISTS idx_coupons_code ON coupons(code);

-- Ajándékutalvány (egyenleggel, több eladásban is elkölthető)
CREATE TABLE IF NOT EXISTS gift_cards (
    id                INTEGER PRIMARY KEY AUTOINCREMENT,
    code              TEXT NOT NULL,
    initial_balance   REAL NOT NULL,
    current_balance   REAL NOT NULL,
    is_active         INTEGER NOT NULL DEFAULT 1,
    expiry_date       TEXT,
    notes             TEXT,
    created_at        TEXT NOT NULL DEFAULT (datetime('now'))
);
CREATE UNIQUE INDEX IF NOT EXISTS idx_gift_cards_code ON gift_cards(code);

CREATE TABLE IF NOT EXISTS gift_card_transactions (
    id             INTEGER PRIMARY KEY AUTOINCREMENT,
    gift_card_id   INTEGER NOT NULL REFERENCES gift_cards(id),
    sale_id        INTEGER REFERENCES sales(id),
    amount_delta   REAL NOT NULL, -- negatív = beváltás, pozitív = kiállítás/feltöltés
    note           TEXT,
    created_at     TEXT NOT NULL DEFAULT (datetime('now'))
);
CREATE INDEX IF NOT EXISTS idx_gift_card_tx_card_id ON gift_card_transactions(gift_card_id);

-- Ártörténet — minden alkalommal egy sor, amikor egy termék ára megváltozik
CREATE TABLE IF NOT EXISTS price_history (
    id              INTEGER PRIMARY KEY AUTOINCREMENT,
    product_id      INTEGER NOT NULL REFERENCES products(id),
    old_net_price   REAL,
    old_price       REAL,
    new_net_price   REAL,
    new_price       REAL,
    changed_at      TEXT NOT NULL DEFAULT (datetime('now'))
);
CREATE INDEX IF NOT EXISTS idx_price_history_product_id ON price_history(product_id);

-- Dolgozók / PIN-kódos bejelentkezés a Kasszához
CREATE TABLE IF NOT EXISTS staff (
    id          INTEGER PRIMARY KEY AUTOINCREMENT,
    name        TEXT NOT NULL,
    pin_hash    TEXT NOT NULL,
    role        TEXT NOT NULL DEFAULT 'cashier', -- 'admin' | 'cashier'
    is_active   INTEGER NOT NULL DEFAULT 1,
    created_at  TEXT NOT NULL DEFAULT (datetime('now'))
);

-- Tevékenységnapló (audit log) — megőrzési idő a Beállításokban állítható (alapértelmezett 30 nap)
CREATE TABLE IF NOT EXISTS audit_log (
    id           INTEGER PRIMARY KEY AUTOINCREMENT,
    staff_id     INTEGER REFERENCES staff(id),
    action       TEXT NOT NULL,       -- pl. 'product_delete', 'settings_change', 'staff_create'
    entity_type  TEXT,
    entity_id    INTEGER,
    details      TEXT,
    created_at   TEXT NOT NULL DEFAULT (datetime('now'))
);
CREATE INDEX IF NOT EXISTS idx_audit_log_created_at ON audit_log(created_at);

-- Rendszeresemény-napló (1.4.0, "Operations & Reliability") — backup/
-- WooCommerce/NAV/updater/nyomtató/SMTP/auth események egy közös
-- idővonalon, az audit_log-tól (dolgozói cselekvés-napló) szándékosan
-- külön — megőrzési idő a Beállításokban állítható (alapértelmezett 14 nap).
CREATE TABLE IF NOT EXISTS system_events (
    id               INTEGER PRIMARY KEY AUTOINCREMENT,
    category         TEXT NOT NULL,     -- 'backup'|'woocommerce'|'nav'|'updater'|'printer'|'smtp'|'auth'|'database'
    event_type       TEXT NOT NULL,     -- pl. 'sync_completed', 'sync_failed', 'invoice_processed'
    severity         TEXT NOT NULL,     -- 'info'|'warning'|'error'
    status           TEXT NOT NULL,     -- 'started'|'success'|'failure'
    user_message     TEXT NOT NULL,     -- felhasználó-orientált, sose tartalmaz titkot/elérési utat/nyers kivételt
    technical_detail TEXT,              -- admin-only diagnosztika (szintén titok/elérési út nélkül)
    created_at       TEXT NOT NULL DEFAULT (datetime('now'))
);
CREATE INDEX IF NOT EXISTS idx_system_events_created_at ON system_events(created_at);
CREATE INDEX IF NOT EXISTS idx_system_events_category_severity ON system_events(category, severity);

-- Hűségszintek (loyalty tiers) — az összesített elköltés alapján
-- customers.total_spent már az eladásoknál frissül, a szint a beállított
-- küszöbök alapján számolódik ki futásidőben, nincs külön oszlop rá.
CREATE TABLE IF NOT EXISTS returns (
    id                     INTEGER PRIMARY KEY AUTOINCREMENT,
    sale_id                INTEGER NOT NULL REFERENCES sales(id),
    staff_id               INTEGER REFERENCES staff(id),
    total_refund           REAL NOT NULL,
    reason                 TEXT,
    credit_invoice_number  TEXT,
    cash_session_id        INTEGER REFERENCES cash_sessions(id), -- melyik (a visszatérítés PILLANATÁBAN nyitott) kasszaműszakhoz tartozik — NEM az eredeti eladáséhoz, lásd Database::computeExpectedCash()
    created_at             TEXT NOT NULL DEFAULT (datetime('now'))
);
CREATE INDEX IF NOT EXISTS idx_returns_sale_id ON returns(sale_id);
CREATE INDEX IF NOT EXISTS idx_returns_created_at ON returns(created_at);
CREATE INDEX IF NOT EXISTS idx_returns_cash_session_id ON returns(cash_session_id);

CREATE TABLE IF NOT EXISTS return_items (
    id              INTEGER PRIMARY KEY AUTOINCREMENT,
    return_id       INTEGER NOT NULL REFERENCES returns(id),
    sale_item_id    INTEGER REFERENCES sale_items(id),
    product_id      INTEGER REFERENCES products(id),
    name            TEXT NOT NULL,
    qty             INTEGER NOT NULL,
    unit_price      REAL NOT NULL,
    created_at      TEXT NOT NULL DEFAULT (datetime('now'))
);
CREATE INDEX IF NOT EXISTS idx_return_items_return_id ON return_items(return_id);
CREATE INDEX IF NOT EXISTS idx_return_items_product_id ON return_items(product_id);

-- Leltározás
CREATE TABLE IF NOT EXISTS stock_takes (
    id              INTEGER PRIMARY KEY AUTOINCREMENT,
    staff_id        INTEGER REFERENCES staff(id),
    notes           TEXT,
    started_at      TEXT NOT NULL DEFAULT (datetime('now')),
    completed_at    TEXT
);

CREATE TABLE IF NOT EXISTS stock_take_items (
    id              INTEGER PRIMARY KEY AUTOINCREMENT,
    stock_take_id   INTEGER NOT NULL REFERENCES stock_takes(id),
    product_id      INTEGER NOT NULL REFERENCES products(id),
    expected_qty    INTEGER NOT NULL,
    counted_qty     INTEGER,
    created_at      TEXT NOT NULL DEFAULT (datetime('now'))
);
CREATE INDEX IF NOT EXISTS idx_stock_take_items_take_id ON stock_take_items(stock_take_id);
CREATE INDEX IF NOT EXISTS idx_stock_take_items_product_id ON stock_take_items(product_id);

-- sales.staff_id — ki dolgozott a Kasszánál az eladáskor
-- (a saveProduct/insertSale metódusok a Database.php-ban kapják meg az ALTER-t meglévő adatbázisoknál)

-- Több telephely/raktár kezelése. A products.stock_qty marad az
-- ÖSSZESÍTETT (minden telephelyen lévő) mennyiség — ezt használja
-- változatlanul a WooCommerce szinkron, az alacsony készlet riasztás stb.
-- Ez a tábla a TELEPHELYENKÉNTI bontást tárolja azoknak, akik ezt a
-- funkciót használják; egy telephelyes boltoknál üresen maradhat, minden
-- változatlanul működik.
CREATE TABLE IF NOT EXISTS locations (
    id          INTEGER PRIMARY KEY AUTOINCREMENT,
    name        TEXT NOT NULL,
    address     TEXT,
    is_default  INTEGER NOT NULL DEFAULT 0,
    created_at  TEXT NOT NULL DEFAULT (datetime('now'))
);

CREATE TABLE IF NOT EXISTS location_stock (
    id           INTEGER PRIMARY KEY AUTOINCREMENT,
    product_id   INTEGER NOT NULL REFERENCES products(id),
    location_id  INTEGER NOT NULL REFERENCES locations(id),
    stock_qty    INTEGER NOT NULL DEFAULT 0
);
CREATE UNIQUE INDEX IF NOT EXISTS idx_location_stock_product_location ON location_stock(product_id, location_id);

CREATE TABLE IF NOT EXISTS stock_transfers (
    id                INTEGER PRIMARY KEY AUTOINCREMENT,
    product_id        INTEGER NOT NULL REFERENCES products(id),
    from_location_id  INTEGER REFERENCES locations(id),
    to_location_id    INTEGER NOT NULL REFERENCES locations(id),
    qty               INTEGER NOT NULL,
    staff_id          INTEGER REFERENCES staff(id),
    created_at        TEXT NOT NULL DEFAULT (datetime('now'))
);
CREATE INDEX IF NOT EXISTS idx_stock_transfers_product_id ON stock_transfers(product_id);

-- Kassza / műszakkezelés (kasszanyitás/kasszazárás). Egy telephelyen több
-- pénztárgép is lehet; egy pénztárgépnek legfeljebb EGY nyitott műszakja
-- lehet egyszerre — ezt az alkalmazás-réteg kényszeríti ki (lásd
-- Database::openCashSession()), nem egy DB-szintű megkötés, mert a
-- MySQL-sémában nincs portábilis partial unique index.
CREATE TABLE IF NOT EXISTS cash_registers (
    id          INTEGER PRIMARY KEY AUTOINCREMENT,
    location_id INTEGER NOT NULL REFERENCES locations(id),
    name        TEXT NOT NULL,
    code        TEXT NOT NULL,
    is_active   INTEGER NOT NULL DEFAULT 1,
    created_at  TEXT NOT NULL DEFAULT (datetime('now')),
    updated_at  TEXT NOT NULL DEFAULT (datetime('now'))
);
CREATE UNIQUE INDEX IF NOT EXISTS idx_cash_registers_code ON cash_registers(code);
CREATE INDEX IF NOT EXISTS idx_cash_registers_location_id ON cash_registers(location_id);

CREATE TABLE IF NOT EXISTS cash_sessions (
    id               INTEGER PRIMARY KEY AUTOINCREMENT,
    cash_register_id INTEGER NOT NULL REFERENCES cash_registers(id),
    staff_id         INTEGER REFERENCES staff(id),
    opening_amount   REAL NOT NULL,
    closing_amount   REAL,                 -- a záráskor beírt, ténylegesen megszámolt összeg
    expected_amount  REAL,                 -- szerver-oldalon számolt várható összeg záráskor — lásd Database::computeExpectedCash()
    variance         REAL,                 -- closing_amount - expected_amount
    status           TEXT NOT NULL DEFAULT 'open', -- 'open' | 'closed'
    idempotency_key         TEXT,
    idempotency_fingerprint TEXT,
    opened_at        TEXT NOT NULL DEFAULT (datetime('now')),
    closed_at        TEXT
);
CREATE INDEX IF NOT EXISTS idx_cash_sessions_register_id ON cash_sessions(cash_register_id);
CREATE INDEX IF NOT EXISTS idx_cash_sessions_status ON cash_sessions(status);
CREATE UNIQUE INDEX IF NOT EXISTS idx_cash_sessions_idempotency_key ON cash_sessions(idempotency_key);

CREATE TABLE IF NOT EXISTS cash_movements (
    id              INTEGER PRIMARY KEY AUTOINCREMENT,
    cash_session_id INTEGER NOT NULL REFERENCES cash_sessions(id),
    staff_id        INTEGER REFERENCES staff(id),
    type            TEXT NOT NULL,   -- 'cash_in' | 'cash_out'
    amount          REAL NOT NULL,   -- mindig pozitív; az előjelet a type adja
    reason          TEXT NOT NULL,
    idempotency_key TEXT,
    created_at      TEXT NOT NULL DEFAULT (datetime('now'))
);
CREATE INDEX IF NOT EXISTS idx_cash_movements_session_id ON cash_movements(cash_session_id);
CREATE UNIQUE INDEX IF NOT EXISTS idx_cash_movements_idempotency_key ON cash_movements(idempotency_key);

-- Kliens/szerver architektúra (Fázis 2) — regisztrált kliens gépek + a
-- proxyzott kérésekben azonosított dolgozói munkamenetek. Csak a Szerver/
-- Önálló gép szerepkör használja ténylegesen; egy Kliens szerepkörű gép
-- SOSE hoz létre saját helyi adatbázist, tehát nála ez üresen sem jön létre.
CREATE TABLE IF NOT EXISTS registered_clients (
    id           INTEGER PRIMARY KEY AUTOINCREMENT,
    client_id    TEXT NOT NULL,
    label        TEXT NOT NULL,
    secret_hash  TEXT NOT NULL,       -- sha256(client_secret) — a nyers titok sose kerül tárolásra
    is_active    INTEGER NOT NULL DEFAULT 1,
    revoked_at   TEXT,
    rotated_at   TEXT,
    last_seen_at TEXT,
    last_seen_version TEXT,  -- Fázis 2 Checkpoint 4 — diagnosztikai célra, SOSE biztonsági döntés forrása
    created_at   TEXT NOT NULL DEFAULT (datetime('now'))
);
CREATE UNIQUE INDEX IF NOT EXISTS idx_registered_clients_client_id ON registered_clients(client_id);

CREATE TABLE IF NOT EXISTS client_sessions (
    id                    INTEGER PRIMARY KEY AUTOINCREMENT,
    client_session_id     TEXT NOT NULL,             -- a Kliens csak ezt az átlátszatlan azonosítót tárolja, sose a staff_id-t
    registered_client_id  INTEGER NOT NULL REFERENCES registered_clients(id),
    staff_id              INTEGER NOT NULL REFERENCES staff(id),
    csrf_token_hash       TEXT NOT NULL,             -- sha256(csrf token) — a proxyzott forgalom CSRF-hídja, lásd Auth.php
    created_at            TEXT NOT NULL DEFAULT (datetime('now')),
    expires_at            TEXT NOT NULL
);
CREATE UNIQUE INDEX IF NOT EXISTS idx_client_sessions_session_id ON client_sessions(client_session_id);
CREATE INDEX IF NOT EXISTS idx_client_sessions_registered_client_id ON client_sessions(registered_client_id);
CREATE INDEX IF NOT EXISTS idx_client_sessions_staff_id ON client_sessions(staff_id);
CREATE INDEX IF NOT EXISTS idx_client_sessions_expires_at ON client_sessions(expires_at);

-- Beérkező webshop-rendelések (WooCommerce webhook) — piszkozatként várnak
-- emberi ellenőrzésre, mielőtt "leadásra" kerülnének (készletcsökkenés +
-- valódi eladás-rekord). Lásd api/webhook.php és api/webshop-order-*.php.
CREATE TABLE IF NOT EXISTS webshop_orders (
    id              INTEGER PRIMARY KEY AUTOINCREMENT,
    wc_order_id     INTEGER NOT NULL,
    order_number    TEXT,
    status          TEXT NOT NULL DEFAULT 'draft',   -- draft | confirmed | rejected
    wc_status       TEXT,                            -- a WooCommerce-beli rendelésstátusz (processing, completed, ...)
    customer_name   TEXT,
    customer_email  TEXT,
    billing_json    TEXT,                            -- SzamlazzClient buyer-alakban tárolt számlázási cím
    payment_method  TEXT,
    currency        TEXT,
    total           REAL NOT NULL DEFAULT 0,
    items_json      TEXT,
    customer_note   TEXT,
    sale_id         INTEGER REFERENCES sales(id),     -- leadás után az ebből létrejött eladás
    created_at      TEXT NOT NULL DEFAULT (datetime('now')),
    confirmed_at    TEXT
);
CREATE UNIQUE INDEX IF NOT EXISTS idx_webshop_orders_wc_order_id ON webshop_orders(wc_order_id);
CREATE INDEX IF NOT EXISTS idx_webshop_orders_status ON webshop_orders(status);

-- Egységes, szolgáltató-független kimenő számla-nyilvántartás (Számlázz.hu
-- ÉS a NAV Online Számla közös helye) — lásd Database::migrateV19Invoices()
-- docblockja a tervezési döntés indoklásáért (miért nincs külön "queue" tábla).
CREATE TABLE IF NOT EXISTS invoices (
    id              INTEGER PRIMARY KEY AUTOINCREMENT,
    sale_id         INTEGER NOT NULL REFERENCES sales(id),
    provider        TEXT NOT NULL,                    -- 'szamlazz' | 'nav'
    status          TEXT NOT NULL DEFAULT 'queued',    -- queued | processing | submitted | done | failed | dead_letter
    provider_ref    TEXT,                              -- NAV transactionId; NULL Számlázz.hu-nál
    invoice_number  TEXT,
    net_total       REAL,
    vat_total       REAL,
    gross_total     REAL,
    currency        TEXT NOT NULL DEFAULT 'HUF',
    issued_at       TEXT,
    pdf_path        TEXT,                              -- csak Számlázz.hu — a NAV API-nak nincs PDF-fogalma
    attempts        INTEGER NOT NULL DEFAULT 0,        -- csak NAV
    next_attempt_at TEXT,                               -- csak NAV — worker esedékesség-ellenőrzés
    locked_at       TEXT,                               -- feldolgozási foglalás, ugyanaz a minta, mint sales.invoice_claim_at
    last_error      TEXT,
    payload_json    TEXT,                               -- kosár/vevő pillanatkép (NAV: beütemezéskor; Számlázz.hu: 1.1.0 óta a CREATE-kísérletkor — MODIFY/STORNO kontextus-rekonstrukcióhoz, lásd InvoiceService::buildStornoContext())
    -- 1.1.0 MODIFY/STORNO adatmodell — lásd Database::migrateV22InvoiceOperationsBody() docblockja.
    invoice_type        TEXT NOT NULL DEFAULT 'normal',  -- normal | modification | storno
    original_invoice_id INTEGER REFERENCES invoices(id), -- NULL normal-nál; kötelező modification/storno-nál
    operation_key        TEXT,                            -- egyedi, determinisztikus VAGY kísérlet-kulcsolt művelet-azonosító (UNIQUE lent)
    modification_index   INTEGER,                          -- NAV modificationIndex — NULL normal-nál
    created_at      TEXT NOT NULL DEFAULT (datetime('now')),
    updated_at      TEXT NOT NULL DEFAULT (datetime('now'))
);
CREATE INDEX IF NOT EXISTS idx_invoices_sale_provider ON invoices(sale_id, provider);
CREATE INDEX IF NOT EXISTS idx_invoices_status_next_attempt ON invoices(status, next_attempt_at);
CREATE INDEX IF NOT EXISTS idx_invoices_provider ON invoices(provider);
CREATE UNIQUE INDEX IF NOT EXISTS idx_invoices_operation_key ON invoices(operation_key);
CREATE INDEX IF NOT EXISTS idx_invoices_original_invoice_id ON invoices(original_invoice_id);
CREATE INDEX IF NOT EXISTS idx_invoices_invoice_type ON invoices(invoice_type);

-- 1.1.0 — provider-kulcsolt, atomikusan növelt számlaszám-sorozat (lásd
-- Database::allocateInvoiceNumber() docblockja). Fresh installon a 'nav'
-- sor 0-ról indul (nincs örökölt id-alapú szám, amit el kellene kerülni).
CREATE TABLE IF NOT EXISTS invoice_sequences (
    provider               TEXT PRIMARY KEY,
    last_allocated_number  INTEGER NOT NULL DEFAULT 0,
    updated_at             TEXT NOT NULL DEFAULT (datetime('now'))
);

-- 1.1.0 — eredeti-számlánként atomikusan növelt NAV modificationIndex
-- (lásd Database::allocateModificationIndex() docblockja) — MODIFY és
-- STORNO KÖZÖS, folyamatos sorszáma.
CREATE TABLE IF NOT EXISTS invoice_modification_sequences (
    original_invoice_id   INTEGER PRIMARY KEY REFERENCES invoices(id),
    last_allocated_index  INTEGER NOT NULL DEFAULT 0,
    updated_at            TEXT NOT NULL DEFAULT (datetime('now'))
);

-- 1.1.1 — aszinkron WooCommerce készlet-push sor, lásd
-- Database::migrateV24WcPushQueue() docblockja.
CREATE TABLE IF NOT EXISTS wc_push_queue (
    id              INTEGER PRIMARY KEY AUTOINCREMENT,
    product_id      INTEGER NOT NULL REFERENCES products(id),
    wc_product_id   INTEGER NOT NULL,
    trigger_type    TEXT NOT NULL,
    trigger_id      INTEGER NOT NULL,
    operation_key   TEXT NOT NULL,
    status          TEXT NOT NULL DEFAULT 'queued',
    attempts        INTEGER NOT NULL DEFAULT 0,
    next_attempt_at TEXT,
    locked_at       TEXT,
    last_error      TEXT,
    created_at      TEXT NOT NULL DEFAULT (datetime('now')),
    updated_at      TEXT NOT NULL DEFAULT (datetime('now'))
);
CREATE UNIQUE INDEX IF NOT EXISTS idx_wc_push_queue_operation_key ON wc_push_queue(operation_key);
CREATE INDEX IF NOT EXISTS idx_wc_push_queue_status_next_attempt ON wc_push_queue(status, next_attempt_at);
CREATE INDEX IF NOT EXISTS idx_wc_push_queue_product_id ON wc_push_queue(product_id);

-- Beérkező (más adózók által kiállított) NAV számlák — SZÁNDÉKOSAN KÜLÖN
-- az `invoices` (kimenő) modelltől, lásd Database::migrateV20IncomingInvoices()
-- docblockja az indoklásért.
CREATE TABLE IF NOT EXISTS incoming_invoices (
    id                                INTEGER PRIMARY KEY AUTOINCREMENT,
    nav_transaction_id                TEXT,
    invoice_number                    TEXT NOT NULL,
    batch_index                       INTEGER NOT NULL DEFAULT 0,
    supplier_tax_number               TEXT NOT NULL,
    supplier_group_member_tax_number  TEXT,
    supplier_name                     TEXT NOT NULL,
    supplier_country                  TEXT,             -- csak részletnézet-lekérdezés (queryInvoiceData) után
    customer_tax_number               TEXT,
    customer_name                     TEXT,
    invoice_operation                 TEXT NOT NULL,     -- CREATE | MODIFY | STORNO
    invoice_category                  TEXT,              -- NORMAL | SIMPLIFIED | AGGREGATE
    original_invoice_number           TEXT,              -- csak MODIFY/STORNO esetén
    modification_index                TEXT,
    invoice_issue_date                TEXT,
    invoice_delivery_date             TEXT,
    payment_date                      TEXT,
    payment_method                    TEXT,
    currency                          TEXT NOT NULL DEFAULT 'HUF',
    net_total                         REAL,
    vat_total                         REAL,
    gross_total                       REAL,              -- helyben számolt (net+vat), NEM közvetlen NAV-mező
    nav_ins_date                      TEXT NOT NULL,      -- a NAV saját feldolgozási időbélyege — inkrementális sync magas-vízjel
    detail_fetched_at                 TEXT,               -- NULL amíg a queryInvoiceData részlet még nem történt meg
    first_seen_at                     TEXT NOT NULL DEFAULT (datetime('now')),
    last_synced_at                    TEXT NOT NULL DEFAULT (datetime('now')),
    created_at                        TEXT NOT NULL DEFAULT (datetime('now')),
    updated_at                        TEXT NOT NULL DEFAULT (datetime('now'))
);
CREATE UNIQUE INDEX IF NOT EXISTS idx_incoming_invoices_identity ON incoming_invoices(supplier_tax_number, invoice_number, batch_index);
CREATE INDEX IF NOT EXISTS idx_incoming_invoices_ins_date ON incoming_invoices(nav_ins_date);
CREATE INDEX IF NOT EXISTS idx_incoming_invoices_issue_date ON incoming_invoices(invoice_issue_date);
CREATE INDEX IF NOT EXISTS idx_incoming_invoices_supplier ON incoming_invoices(supplier_tax_number);

-- Tételsorok — LAZY módon, csak a részletnézet első megnyitásakor
-- (queryInvoiceData) töltve, lásd migrateV20IncomingInvoices() docblockja.
CREATE TABLE IF NOT EXISTS incoming_invoice_items (
    id                     INTEGER PRIMARY KEY AUTOINCREMENT,
    incoming_invoice_id    INTEGER NOT NULL REFERENCES incoming_invoices(id),
    line_number            INTEGER NOT NULL,
    description            TEXT,
    quantity                REAL,
    unit_of_measure         TEXT,
    unit_net_price          REAL,
    vat_rate                TEXT,
    net_amount               REAL,
    vat_amount               REAL,
    gross_amount             REAL,
    created_at               TEXT NOT NULL DEFAULT (datetime('now'))
);
CREATE UNIQUE INDEX IF NOT EXISTS idx_incoming_invoice_items_line ON incoming_invoice_items(incoming_invoice_id, line_number);

-- A bejövő-számla sync race-safe állapotgépe — KÜLÖN TÁBLA, nem
-- Settings-kulcs, mert atomikus claim kell (lásd
-- Database::claimIncomingInvoiceSync() docblockja).
CREATE TABLE IF NOT EXISTS incoming_invoice_sync (
    id                       INTEGER PRIMARY KEY AUTOINCREMENT,
    provider                 TEXT NOT NULL DEFAULT 'nav',
    status                   TEXT NOT NULL DEFAULT 'idle',   -- idle | running | success | retry | failed
    sync_cursor_ins_date     TEXT,
    last_requested_interval  TEXT,
    last_success_at          TEXT,
    last_attempt_at          TEXT,
    attempts                 INTEGER NOT NULL DEFAULT 0,
    next_attempt_at          TEXT,
    locked_at                TEXT,
    last_error               TEXT,
    created_at               TEXT NOT NULL DEFAULT (datetime('now')),
    updated_at               TEXT NOT NULL DEFAULT (datetime('now'))
);
CREATE UNIQUE INDEX IF NOT EXISTS idx_incoming_invoice_sync_provider ON incoming_invoice_sync(provider);
INSERT INTO incoming_invoice_sync (provider, status) SELECT 'nav', 'idle' WHERE NOT EXISTS (SELECT 1 FROM incoming_invoice_sync WHERE provider = 'nav');

-- FountainTrade önfrissítő rendszer — lásd Database::migrateV21Updates() docblockja.
CREATE TABLE IF NOT EXISTS update_state (
    id                              INTEGER PRIMARY KEY,
    state                           TEXT NOT NULL DEFAULT 'idle',
    current_version                 TEXT NOT NULL,
    latest_version                  TEXT,
    latest_release_tag              TEXT,
    latest_commit_sha               TEXT,
    latest_release_notes            TEXT,
    latest_published_at             TEXT,
    latest_checked_at               TEXT,
    last_check_error                TEXT,
    last_successful_update_at       TEXT,
    last_successful_update_version  TEXT,
    progress_message                TEXT,
    install_requested_by            TEXT,
    install_requested_at            TEXT,
    lock_token                      TEXT,
    lock_started_at                 TEXT,
    lock_hostname                   TEXT,
    created_at                      TEXT NOT NULL DEFAULT (datetime('now')),
    updated_at                      TEXT NOT NULL DEFAULT (datetime('now'))
);
INSERT INTO update_state (id, state, current_version) SELECT 1, 'idle', '1.0.0' WHERE NOT EXISTS (SELECT 1 FROM update_state WHERE id = 1);

CREATE TABLE IF NOT EXISTS update_history (
    id                 INTEGER PRIMARY KEY AUTOINCREMENT,
    from_version       TEXT NOT NULL,
    to_version         TEXT NOT NULL,
    release_tag        TEXT,
    commit_sha         TEXT,
    trigger_source     TEXT NOT NULL,
    actor              TEXT,
    started_at         TEXT NOT NULL DEFAULT (datetime('now')),
    finished_at        TEXT,
    state              TEXT NOT NULL,
    error              TEXT,
    backup_reference   TEXT,
    rollback_state     TEXT,
    created_at         TEXT NOT NULL DEFAULT (datetime('now'))
);
CREATE INDEX IF NOT EXISTS idx_update_history_started_at ON update_history(started_at);

-- Fázis 7 — AI Daily Intelligence perzisztens jelentés-tábla (lásd
-- Database::migrateV30AiDailyReports() docblokkja). Naponta LEGFELJEBB
-- EGY sor report_date-enként (egyedi index) — ez az idempotencia egyik
-- pillére: egy második ütemezett futás ugyanarra a napra sose hoz létre
-- második sort.
CREATE TABLE IF NOT EXISTS ai_daily_reports (
    id                        INTEGER PRIMARY KEY AUTOINCREMENT,
    report_date               TEXT NOT NULL,
    status                    TEXT NOT NULL DEFAULT 'pending',   -- pending|running|completed|failed
    provider                  TEXT,
    model                     TEXT,
    has_significant_findings  INTEGER NOT NULL DEFAULT 0,
    findings_count            INTEGER NOT NULL DEFAULT 0,
    findings_json             TEXT,
    report_text               TEXT,
    error                     TEXT,
    started_at                TEXT,
    completed_at              TEXT,
    created_at                TEXT NOT NULL DEFAULT (datetime('now')),
    updated_at                TEXT NOT NULL DEFAULT (datetime('now'))
);
CREATE UNIQUE INDEX IF NOT EXISTS idx_ai_daily_reports_date ON ai_daily_reports(report_date);

INSERT INTO schema_version (version) SELECT 16 WHERE NOT EXISTS (SELECT 1 FROM schema_version);
