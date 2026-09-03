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
    status                   TEXT NOT NULL DEFAULT 'completed', -- completed | invoice_failed
    receipt_token            TEXT,                  -- kitalálhatatlan token a nyugta bejelentkezés nélküli megtekintéséhez (QR-kód)
    created_at               TEXT NOT NULL DEFAULT (datetime('now'))
);

-- The daily zárás report and every "prune to keep N days" style query
-- filters by date, so this index is the difference between a table scan
-- and an index seek once there's a real sales history.
CREATE INDEX IF NOT EXISTS idx_sales_created_at ON sales(created_at);
CREATE INDEX IF NOT EXISTS idx_sales_customer_id ON sales(customer_id);
CREATE INDEX IF NOT EXISTS idx_sales_staff_id ON sales(staff_id);
CREATE INDEX IF NOT EXISTS idx_sales_coupon_id ON sales(coupon_id);

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
    created_at            TEXT NOT NULL DEFAULT (datetime('now'))
);

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

-- Tracks which WooCommerce order IDs the webhook endpoint already acted on
-- (see api/webhook.php) — WooCommerce redelivers the same webhook on every
-- order save, so without this stock would be decremented again each time.
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
    created_at             TEXT NOT NULL DEFAULT (datetime('now'))
);
CREATE INDEX IF NOT EXISTS idx_returns_sale_id ON returns(sale_id);

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

INSERT INTO schema_version (version) SELECT 16 WHERE NOT EXISTS (SELECT 1 FROM schema_version);
