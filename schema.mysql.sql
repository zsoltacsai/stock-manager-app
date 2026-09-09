-- Stock Manager database schema (MySQL 8)
-- InnoDB throughout (row-level locking — matters once there's real
-- concurrent write traffic, unlike SQLite's whole-database lock).

SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS products (
    id                  INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    wc_product_id       INT UNSIGNED NULL,
    sku                 VARCHAR(191) NULL,
    barcode             VARCHAR(191) NULL,
    name                VARCHAR(255) NOT NULL,
    unit                VARCHAR(32) NOT NULL DEFAULT 'db',
    group_name          VARCHAR(191) NULL,
    cikkszam            VARCHAR(191) NULL,
    vtsz                VARCHAR(64) NULL,
    currency            VARCHAR(8) NOT NULL DEFAULT 'HUF',
    net_price           DECIMAL(12,2) NOT NULL DEFAULT 0,
    price               DECIMAL(12,2) NOT NULL DEFAULT 0,
    vat_rate            VARCHAR(8) NOT NULL DEFAULT '27',
    purchase_price_net  DECIMAL(12,2) NOT NULL DEFAULT 0,
    stock_qty           INT NOT NULL DEFAULT 0,          -- signed: purchase corrections can legitimately go negative
    weight              DECIMAL(10,3) NULL,
    volume              DECIMAL(10,3) NULL,
    notes               TEXT NULL,
    show_pricelist      TINYINT(1) NOT NULL DEFAULT 1,
    show_webshop        TINYINT(1) NOT NULL DEFAULT 1,
    is_deleted          TINYINT(1) NOT NULL DEFAULT 0,
    low_stock_threshold INT NULL,               -- NULL = use global default
    preferred_supplier_id INT UNSIGNED NULL,    -- kitől szoktuk ezt beszerezni — a beszerzési javaslathoz
    short_description   TEXT NULL,
    long_description    TEXT NULL,
    image_filename       VARCHAR(191) NULL,
    image_alt            VARCHAR(191) NULL,
    brand                VARCHAR(191) NULL,
    sync_to_woocommerce TINYINT(1) NOT NULL DEFAULT 1,
    updated_at          DATETIME NULL,
    wc_synced_at        DATETIME NULL,
    UNIQUE KEY uq_products_barcode (barcode),
    KEY idx_products_wc_id (wc_product_id),
    KEY idx_products_group (group_name),
    KEY idx_products_deleted (is_deleted),
    KEY idx_products_preferred_supplier (preferred_supplier_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Beszállító-törzs (supplier master data) — beszerzés still keeps its own
-- free-text supplier_* columns for one-off/unregistered suppliers, but
-- picking a saved supplier auto-fills those and links supplier_id.
CREATE TABLE IF NOT EXISTS suppliers (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name            VARCHAR(255) NOT NULL,
    tax_number      VARCHAR(64) NULL,
    country         VARCHAR(128) NULL,
    zip             VARCHAR(16) NULL,
    city            VARCHAR(191) NULL,
    address         VARCHAR(255) NULL,
    contact_name    VARCHAR(191) NULL,
    phone           VARCHAR(64) NULL,
    email           VARCHAR(191) NULL,
    payment_terms   VARCHAR(191) NULL,
    notes           TEXT NULL,
    is_deleted      TINYINT(1) NOT NULL DEFAULT 0,
    created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at      DATETIME NULL,
    KEY idx_suppliers_name (name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE products ADD CONSTRAINT fk_products_preferred_supplier
    FOREIGN KEY (preferred_supplier_id) REFERENCES suppliers(id);

-- Törzsvásárlói / hűségpont rendszer.
CREATE TABLE IF NOT EXISTS customers (
    id                INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name              VARCHAR(255) NOT NULL,
    phone             VARCHAR(64) NULL,
    email             VARCHAR(191) NULL,
    tax_number        VARCHAR(64) NULL,
    zip               VARCHAR(16) NULL,
    city              VARCHAR(191) NULL,
    address           VARCHAR(255) NULL,
    country           VARCHAR(128) NULL,
    notes             TEXT NULL,
    loyalty_points    INT NOT NULL DEFAULT 0,
    total_spent       DECIMAL(12,2) NOT NULL DEFAULT 0,
    is_deleted        TINYINT(1) NOT NULL DEFAULT 0,
    created_at        DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at        DATETIME NULL,
    KEY idx_customers_name (name),
    KEY idx_customers_phone (phone)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Dolgozók / PIN-kódos bejelentkezés a Kasszához
CREATE TABLE IF NOT EXISTS staff (
    id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name        VARCHAR(191) NOT NULL,
    pin_hash    VARCHAR(255) NOT NULL,
    role        VARCHAR(16) NOT NULL DEFAULT 'cashier',
    is_active   TINYINT(1) NOT NULL DEFAULT 1,
    created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Tevékenységnapló (audit log)
CREATE TABLE IF NOT EXISTS audit_log (
    id           INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    staff_id     INT UNSIGNED NULL,
    action       VARCHAR(64) NOT NULL,
    entity_type  VARCHAR(64) NULL,
    entity_id    INT UNSIGNED NULL,
    details      TEXT NULL,
    created_at   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_audit_log_created_at (created_at),
    CONSTRAINT fk_audit_log_staff FOREIGN KEY (staff_id) REFERENCES staff(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Kedvezménykód / kupon
CREATE TABLE IF NOT EXISTS coupons (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    code            VARCHAR(64) NOT NULL,
    type            VARCHAR(16) NOT NULL DEFAULT 'percent', -- 'percent' | 'fixed'
    value           DECIMAL(12,2) NOT NULL,
    is_active       TINYINT(1) NOT NULL DEFAULT 1,
    expiry_date     DATE NULL,
    usage_limit     INT NULL,               -- NULL = korlátlan
    times_used      INT NOT NULL DEFAULT 0,
    min_purchase    DECIMAL(12,2) NOT NULL DEFAULT 0,
    notes           TEXT NULL,
    created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_coupons_code (code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS sales (
    id                       INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    total                    DECIMAL(12,2) NOT NULL,
    payment_method           VARCHAR(64) NOT NULL DEFAULT 'Készpénz',
    buyer_name               VARCHAR(255) NULL,
    customer_id              INT UNSIGNED NULL,
    loyalty_points_earned    INT NOT NULL DEFAULT 0,
    loyalty_points_redeemed  INT NOT NULL DEFAULT 0,
    coupon_id                INT UNSIGNED NULL,
    coupon_discount          DECIMAL(12,2) NOT NULL DEFAULT 0,
    gift_card_redeemed       DECIMAL(12,2) NOT NULL DEFAULT 0,
    staff_id                 INT UNSIGNED NULL,
    szamlazz_invoice_number  VARCHAR(64) NULL,
    szamlazz_pdf_path        VARCHAR(255) NULL,
    status                   VARCHAR(32) NOT NULL DEFAULT 'completed',
    receipt_token            VARCHAR(64) NULL,
    created_at               DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    -- The daily zárás report filters by date on every load — this index is
    -- the difference between a table scan and an index range seek once
    -- there's a real sales history (thousands+ of rows).
    KEY idx_sales_created_at (created_at),
    KEY idx_sales_customer_id (customer_id),
    KEY idx_sales_coupon_id (coupon_id),
    KEY idx_sales_staff_id (staff_id),
    CONSTRAINT fk_sales_customer FOREIGN KEY (customer_id) REFERENCES customers(id),
    CONSTRAINT fk_sales_coupon FOREIGN KEY (coupon_id) REFERENCES coupons(id),
    CONSTRAINT fk_sales_staff FOREIGN KEY (staff_id) REFERENCES staff(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS closings (
    closing_date            DATE PRIMARY KEY,
    sales_count             INT NOT NULL,
    total_gross             DECIMAL(12,2) NOT NULL,
    total_net               DECIMAL(12,2) NOT NULL,
    total_vat               DECIMAL(12,2) NOT NULL,
    payment_breakdown_json  JSON NULL,
    vat_breakdown_json      JSON NULL,
    closed_at               DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS sale_items (
    id           INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    sale_id      INT UNSIGNED NOT NULL,
    product_id   INT UNSIGNED NULL, -- NULL = kézzel hozzáadott tétel, nincs raktárkészlet mögötte
    name         VARCHAR(255) NOT NULL,
    qty          INT NOT NULL,
    unit_price   DECIMAL(12,2) NOT NULL,
    vat_rate     VARCHAR(8) NOT NULL,
    KEY idx_sale_items_sale_id (sale_id),
    KEY idx_sale_items_product_id (product_id),
    CONSTRAINT fk_sale_items_sale FOREIGN KEY (sale_id) REFERENCES sales(id) ON DELETE CASCADE,
    CONSTRAINT fk_sale_items_product FOREIGN KEY (product_id) REFERENCES products(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS purchases (
    id                    INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    supplier_id           INT UNSIGNED NULL,
    supplier_name         VARCHAR(255) NULL,
    supplier_tax_number   VARCHAR(64) NULL,
    supplier_country      VARCHAR(128) NULL,
    supplier_zip          VARCHAR(16) NULL,
    supplier_city         VARCHAR(191) NULL,
    supplier_address      VARCHAR(255) NULL,
    payment_method        VARCHAR(64) DEFAULT 'készpénz',
    currency              VARCHAR(8) DEFAULT 'HUF',
    discount_percent      DECIMAL(5,2) DEFAULT 0,
    paid                  TINYINT(1) DEFAULT 1,
    note                  TEXT NULL,
    total_net             DECIMAL(12,2) NOT NULL DEFAULT 0,
    total_gross           DECIMAL(12,2) NOT NULL DEFAULT 0,
    created_at            DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_purchases_created_at (created_at),
    KEY idx_purchases_supplier_id (supplier_id),
    CONSTRAINT fk_purchases_supplier FOREIGN KEY (supplier_id) REFERENCES suppliers(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS purchase_items (
    id               INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    purchase_id      INT UNSIGNED NOT NULL,
    product_id       INT UNSIGNED NOT NULL,
    name             VARCHAR(255) NOT NULL,
    qty              INT NOT NULL,
    vat_rate         VARCHAR(8) NOT NULL,
    unit_cost_net    DECIMAL(12,2) NOT NULL,
    unit_cost_gross  DECIMAL(12,2) NOT NULL,
    line_net         DECIMAL(12,2) NOT NULL,
    line_gross       DECIMAL(12,2) NOT NULL,
    KEY idx_purchase_items_purchase_id (purchase_id),
    KEY idx_purchase_items_product_id (product_id),
    CONSTRAINT fk_purchase_items_purchase FOREIGN KEY (purchase_id) REFERENCES purchases(id) ON DELETE CASCADE,
    CONSTRAINT fk_purchase_items_product FOREIGN KEY (product_id) REFERENCES products(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Records every stock movement caused by sync, for troubleshooting.
CREATE TABLE IF NOT EXISTS sync_log (
    id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    direction   VARCHAR(32) NOT NULL,
    product_id  INT UNSIGNED NULL,
    message     TEXT NULL,
    created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_sync_log_created_at (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Tracks which WooCommerce order IDs the webhook endpoint already acted on
-- (see api/webhook.php) — WooCommerce redelivers the same webhook on every
-- order save, so without this stock would be decremented again each time.
CREATE TABLE IF NOT EXISTS processed_webhook_orders (
    wc_order_id  INT UNSIGNED PRIMARY KEY,
    processed_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Tracks which migrations have run — lets the app check "is this DB
-- current?" with one cheap SELECT instead of re-attempting every ALTER
-- TABLE on every request (see Database::ensureSchema).
CREATE TABLE IF NOT EXISTS schema_version (
    version INT NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- One row per point change (earn on a sale, or manual/redeem adjustment) —
-- keeps a readable history instead of just the running total on customers.
CREATE TABLE IF NOT EXISTS loyalty_transactions (
    id            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    customer_id   INT UNSIGNED NOT NULL,
    sale_id       INT UNSIGNED NULL,
    points_delta  INT NOT NULL,
    note          VARCHAR(255) NULL,
    created_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_loyalty_customer_id (customer_id),
    CONSTRAINT fk_loyalty_customer FOREIGN KEY (customer_id) REFERENCES customers(id),
    CONSTRAINT fk_loyalty_sale FOREIGN KEY (sale_id) REFERENCES sales(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Ajándékutalvány (egyenleggel, több eladásban is elkölthető)
CREATE TABLE IF NOT EXISTS gift_cards (
    id                INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    code              VARCHAR(64) NOT NULL,
    initial_balance   DECIMAL(12,2) NOT NULL,
    current_balance   DECIMAL(12,2) NOT NULL,
    is_active         TINYINT(1) NOT NULL DEFAULT 1,
    expiry_date       DATE NULL,
    notes             TEXT NULL,
    created_at        DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_gift_cards_code (code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS gift_card_transactions (
    id             INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    gift_card_id   INT UNSIGNED NOT NULL,
    sale_id        INT UNSIGNED NULL,
    amount_delta   DECIMAL(12,2) NOT NULL,
    note           VARCHAR(255) NULL,
    created_at     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_gift_card_tx_card_id (gift_card_id),
    CONSTRAINT fk_gift_card_tx_card FOREIGN KEY (gift_card_id) REFERENCES gift_cards(id),
    CONSTRAINT fk_gift_card_tx_sale FOREIGN KEY (sale_id) REFERENCES sales(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Ártörténet — minden alkalommal egy sor, amikor egy termék ára megváltozik
CREATE TABLE IF NOT EXISTS price_history (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    product_id      INT UNSIGNED NOT NULL,
    old_net_price   DECIMAL(12,2) NULL,
    old_price       DECIMAL(12,2) NULL,
    new_net_price   DECIMAL(12,2) NULL,
    new_price       DECIMAL(12,2) NULL,
    changed_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_price_history_product_id (product_id),
    CONSTRAINT fk_price_history_product FOREIGN KEY (product_id) REFERENCES products(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Részleges visszáru / sztornó
CREATE TABLE IF NOT EXISTS returns (
    id                     INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    sale_id                INT UNSIGNED NOT NULL,
    staff_id               INT UNSIGNED NULL,
    total_refund           DECIMAL(12,2) NOT NULL,
    reason                 VARCHAR(255) NULL,
    credit_invoice_number  VARCHAR(64) NULL,
    created_at             DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_returns_sale_id (sale_id),
    CONSTRAINT fk_returns_sale FOREIGN KEY (sale_id) REFERENCES sales(id),
    CONSTRAINT fk_returns_staff FOREIGN KEY (staff_id) REFERENCES staff(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS return_items (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    return_id       INT UNSIGNED NOT NULL,
    sale_item_id    INT UNSIGNED NULL,
    product_id      INT UNSIGNED NULL,
    name            VARCHAR(255) NOT NULL,
    qty             INT NOT NULL,
    unit_price      DECIMAL(12,2) NOT NULL,
    created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_return_items_return_id (return_id),
    CONSTRAINT fk_return_items_return FOREIGN KEY (return_id) REFERENCES returns(id),
    CONSTRAINT fk_return_items_product FOREIGN KEY (product_id) REFERENCES products(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Leltározás
CREATE TABLE IF NOT EXISTS stock_takes (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    staff_id        INT UNSIGNED NULL,
    notes           VARCHAR(255) NULL,
    started_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    completed_at    DATETIME NULL,
    CONSTRAINT fk_stock_takes_staff FOREIGN KEY (staff_id) REFERENCES staff(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS stock_take_items (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    stock_take_id   INT UNSIGNED NOT NULL,
    product_id      INT UNSIGNED NOT NULL,
    expected_qty    INT NOT NULL,
    counted_qty     INT NULL,
    created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_stock_take_items_take_id (stock_take_id),
    CONSTRAINT fk_stock_take_items_take FOREIGN KEY (stock_take_id) REFERENCES stock_takes(id),
    CONSTRAINT fk_stock_take_items_product FOREIGN KEY (product_id) REFERENCES products(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Több telephely/raktár kezelése. A products.stock_qty marad az
-- ÖSSZESÍTETT (minden telephelyen lévő) mennyiség — ezt használja
-- változatlanul a WooCommerce szinkron, az alacsony készlet riasztás stb.
CREATE TABLE IF NOT EXISTS locations (
    id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name        VARCHAR(191) NOT NULL,
    address     VARCHAR(255) NULL,
    is_default  TINYINT(1) NOT NULL DEFAULT 0,
    created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS location_stock (
    id           INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    product_id   INT UNSIGNED NOT NULL,
    location_id  INT UNSIGNED NOT NULL,
    stock_qty    INT NOT NULL DEFAULT 0,
    UNIQUE KEY uq_location_stock_product_location (product_id, location_id),
    CONSTRAINT fk_location_stock_product FOREIGN KEY (product_id) REFERENCES products(id),
    CONSTRAINT fk_location_stock_location FOREIGN KEY (location_id) REFERENCES locations(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS stock_transfers (
    id                INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    product_id        INT UNSIGNED NOT NULL,
    from_location_id  INT UNSIGNED NULL,
    to_location_id    INT UNSIGNED NOT NULL,
    qty               INT NOT NULL,
    staff_id          INT UNSIGNED NULL,
    created_at        DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_stock_transfers_product_id (product_id),
    CONSTRAINT fk_stock_transfers_product FOREIGN KEY (product_id) REFERENCES products(id),
    CONSTRAINT fk_stock_transfers_from FOREIGN KEY (from_location_id) REFERENCES locations(id),
    CONSTRAINT fk_stock_transfers_to FOREIGN KEY (to_location_id) REFERENCES locations(id),
    CONSTRAINT fk_stock_transfers_staff FOREIGN KEY (staff_id) REFERENCES staff(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS webshop_orders (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    wc_order_id     INT UNSIGNED NOT NULL,
    order_number    VARCHAR(64),
    status          VARCHAR(16) NOT NULL DEFAULT 'draft',
    wc_status       VARCHAR(32),
    customer_name   VARCHAR(191),
    customer_email  VARCHAR(191),
    billing_json    TEXT,
    payment_method  VARCHAR(64),
    currency        VARCHAR(8),
    total           DECIMAL(12,2) NOT NULL DEFAULT 0,
    items_json      TEXT,
    customer_note   TEXT,
    sale_id         INT UNSIGNED NULL,
    created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    confirmed_at    DATETIME NULL,
    UNIQUE KEY uq_webshop_orders_wc_order_id (wc_order_id),
    KEY idx_webshop_orders_status (status),
    CONSTRAINT fk_webshop_orders_sale FOREIGN KEY (sale_id) REFERENCES sales(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Egységes, szolgáltató-független kimenő számla-nyilvántartás (Számlázz.hu
-- ÉS a NAV Online Számla közös helye) — lásd Database::migrateV19Invoices()
-- docblockja a tervezési döntés indoklásáért (miért nincs külön "queue" tábla).
CREATE TABLE IF NOT EXISTS invoices (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    sale_id         INT UNSIGNED NOT NULL,
    provider        VARCHAR(16) NOT NULL,
    status          VARCHAR(20) NOT NULL DEFAULT 'queued',
    provider_ref    VARCHAR(64),
    invoice_number  VARCHAR(64),
    net_total       DECIMAL(12,2),
    vat_total       DECIMAL(12,2),
    gross_total     DECIMAL(12,2),
    currency        VARCHAR(8) NOT NULL DEFAULT 'HUF',
    issued_at       DATETIME NULL,
    pdf_path        TEXT,
    attempts        INT UNSIGNED NOT NULL DEFAULT 0,
    next_attempt_at DATETIME NULL,
    locked_at       DATETIME NULL,
    last_error      TEXT,
    payload_json    TEXT,
    created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_invoices_sale_provider (sale_id, provider),
    KEY idx_invoices_status_next_attempt (status, next_attempt_at),
    KEY idx_invoices_provider (provider),
    CONSTRAINT fk_invoices_sale FOREIGN KEY (sale_id) REFERENCES sales(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Beérkező (más adózók által kiállított) NAV számlák — SZÁNDÉKOSAN KÜLÖN
-- az `invoices` (kimenő) modelltől, lásd Database::migrateV20IncomingInvoices()
-- docblockja az indoklásért.
CREATE TABLE IF NOT EXISTS incoming_invoices (
    id                                INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    nav_transaction_id                VARCHAR(64),
    invoice_number                    VARCHAR(64) NOT NULL,
    batch_index                       INT UNSIGNED NOT NULL DEFAULT 0,
    supplier_tax_number               VARCHAR(16) NOT NULL,
    supplier_group_member_tax_number  VARCHAR(16),
    supplier_name                     VARCHAR(512) NOT NULL,
    supplier_country                  VARCHAR(8),
    customer_tax_number               VARCHAR(16),
    customer_name                     VARCHAR(512),
    invoice_operation                 VARCHAR(16) NOT NULL,
    invoice_category                  VARCHAR(16),
    original_invoice_number           VARCHAR(64),
    modification_index                VARCHAR(32),
    invoice_issue_date                VARCHAR(16),
    invoice_delivery_date             VARCHAR(16),
    payment_date                      VARCHAR(16),
    payment_method                    VARCHAR(16),
    currency                          VARCHAR(8) NOT NULL DEFAULT 'HUF',
    net_total                         DECIMAL(14,4),
    vat_total                         DECIMAL(14,4),
    gross_total                       DECIMAL(14,4),
    nav_ins_date                      VARCHAR(32) NOT NULL,
    detail_fetched_at                 DATETIME NULL,
    first_seen_at                     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    last_synced_at                    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    created_at                        DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at                        DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_incoming_invoices_identity (supplier_tax_number, invoice_number, batch_index),
    KEY idx_incoming_invoices_ins_date (nav_ins_date),
    KEY idx_incoming_invoices_issue_date (invoice_issue_date),
    KEY idx_incoming_invoices_supplier (supplier_tax_number)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Tételsorok — LAZY módon, csak a részletnézet első megnyitásakor
-- (queryInvoiceData) töltve.
CREATE TABLE IF NOT EXISTS incoming_invoice_items (
    id                     INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    incoming_invoice_id    INT UNSIGNED NOT NULL,
    line_number            INT UNSIGNED NOT NULL,
    description            TEXT,
    quantity                DECIMAL(14,4),
    unit_of_measure         VARCHAR(32),
    unit_net_price          DECIMAL(14,4),
    vat_rate                VARCHAR(8),
    net_amount               DECIMAL(14,4),
    vat_amount               DECIMAL(14,4),
    gross_amount             DECIMAL(14,4),
    created_at               DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_incoming_invoice_items_line (incoming_invoice_id, line_number),
    CONSTRAINT fk_incoming_invoice_items_invoice FOREIGN KEY (incoming_invoice_id) REFERENCES incoming_invoices(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- A bejövő-számla sync race-safe állapotgépe — KÜLÖN TÁBLA, nem
-- Settings-kulcs (atomikus claim kell).
CREATE TABLE IF NOT EXISTS incoming_invoice_sync (
    id                       INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    provider                 VARCHAR(16) NOT NULL DEFAULT 'nav',
    status                   VARCHAR(16) NOT NULL DEFAULT 'idle',
    sync_cursor_ins_date     VARCHAR(32),
    last_requested_interval  VARCHAR(64),
    last_success_at          DATETIME NULL,
    last_attempt_at          DATETIME NULL,
    attempts                 INT UNSIGNED NOT NULL DEFAULT 0,
    next_attempt_at          DATETIME NULL,
    locked_at                DATETIME NULL,
    last_error               TEXT,
    created_at                DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at                DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_incoming_invoice_sync_provider (provider)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
INSERT INTO incoming_invoice_sync (provider, status)
SELECT 'nav', 'idle' FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM incoming_invoice_sync WHERE provider = 'nav');

INSERT INTO schema_version (version)
SELECT 16 FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM schema_version);