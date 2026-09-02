<?php

class Database
{
    private const SCHEMA_VERSION = 13;

    private PDO $pdo;
    private string $driver;

    public function __construct(array $dbConfig, string $schemaDir)
    {
        $this->driver = $dbConfig['driver'] ?? 'sqlite';

        if ($this->driver === 'mysql') {
            $m = $dbConfig['mysql'];
            $dsn = sprintf(
                'mysql:host=%s;port=%d;dbname=%s;charset=%s',
                $m['host'],
                $m['port'] ?? 3306,
                $m['database'],
                $m['charset'] ?? 'utf8mb4'
            );
            $this->pdo = new PDO($dsn, $m['username'], $m['password'], [
                PDO::ATTR_ERRMODE                  => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_EMULATE_PREPARES         => false,
                PDO::MYSQL_ATTR_INIT_COMMAND       => "SET NAMES {$m['charset']}",
            ]);
            $schemaPath = rtrim($schemaDir, '/') . '/schema.mysql.sql';
        } else {
            $sqlitePath = $dbConfig['sqlite']['path'];
            $this->pdo = new PDO('sqlite:' . $sqlitePath);
            $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
            $this->pdo->exec('PRAGMA foreign_keys = ON;');
            $this->pdo->exec('PRAGMA journal_mode = WAL;');
            $this->pdo->exec('PRAGMA busy_timeout = 5000;');
            $this->pdo->exec('PRAGMA synchronous = NORMAL;');
            $schemaPath = rtrim($schemaDir, '/') . '/schema.sql';
        }

        $this->ensureSchema($schemaPath);
    }

    public function pdo(): PDO
    {
        return $this->pdo;
    }

    public function driver(): string
    {
        return $this->driver;
    }

    public function beginTransaction(): void
    {
        $this->pdo->beginTransaction();
    }

    public function commit(): void
    {
        $this->pdo->commit();
    }

    public function rollBack(): void
    {
        if ($this->pdo->inTransaction()) {
            $this->pdo->rollBack();
        }
    }

    private function ensureSchema(string $schemaPath): void
    {
        try {
            $version = (int) $this->pdo->query('SELECT version FROM schema_version LIMIT 1')->fetchColumn();
        } catch (PDOException $e) {
            $version = $this->hasTable('products') ? 1 : 0;
        }

        if ($version >= self::SCHEMA_VERSION) {
            return;
        }

        if ($version === 0) {
            $this->runSchemaFile($schemaPath);
        } else {
            $this->migrateProductColumns();
            $this->migrateSalesColumns();
            if ($version < 4) {
                $this->migrateV4SuppliersAndLoyalty();
            }
            if ($version < 5) {
                $this->migrateV5CustomerBillingFields();
            }
            if ($version < 6) {
                $this->migrateV6ManualSaleItems();
            }
            if ($version < 7) {
                $this->migrateV7CouponsGiftCardsPriceHistory();
            }
            if ($version < 8) {
                $this->migrateV8StaffReturnsStockTakes();
            }
            if ($version < 9) {
                $this->migrateV9RolesAuditLoyaltyTiers();
            }
            if ($version < 10) {
                $this->migrateV10PreferredSupplierAndLocations();
            }
            if ($version < 11) {
                $this->migrateV11MissingIndexes();
            }
            if ($version < 12) {
                $this->migrateV12ReceiptToken();
            }
            if ($version < 13) {
                $this->migrateV13WebhookIdempotency();
            }
        }

        $this->setSchemaVersion(self::SCHEMA_VERSION);
    }

    private function hasTable(string $table): bool
    {
        try {
            $this->pdo->query("SELECT 1 FROM $table LIMIT 1");
            return true;
        } catch (PDOException $e) {
            return false;
        }
    }

    private function runSchemaFile(string $schemaPath): void
    {
        $schema = file_get_contents($schemaPath);
        foreach (array_filter(array_map('trim', explode(";\n", str_replace(";\r\n", ";\n", $schema)))) as $statement) {
            $statement = rtrim(trim($statement), ';');
            if ($statement !== '') {
                $this->pdo->exec($statement);
            }
        }
    }

    private function setSchemaVersion(int $version): void
    {
        try {
            $this->pdo->exec('CREATE TABLE IF NOT EXISTS schema_version (version INTEGER NOT NULL)');
            $count = (int) $this->pdo->query('SELECT COUNT(*) FROM schema_version')->fetchColumn();
            if ($count === 0) {
                $this->pdo->prepare('INSERT INTO schema_version (version) VALUES (?)')->execute([$version]);
            } else {
                $this->pdo->prepare('UPDATE schema_version SET version = ?')->execute([$version]);
            }
        } catch (PDOException $e) {
        }
    }

    private function migrateProductColumns(): void
    {
        $columns = [
            'unit'               => "VARCHAR(32) NOT NULL DEFAULT 'db'",
            'group_name'         => 'VARCHAR(191)',
            'cikkszam'           => 'VARCHAR(191)',
            'vtsz'               => 'VARCHAR(64)',
            'currency'           => "VARCHAR(8) NOT NULL DEFAULT 'HUF'",
            'net_price'          => 'DECIMAL(12,2) NOT NULL DEFAULT 0',
            'purchase_price_net' => 'DECIMAL(12,2) NOT NULL DEFAULT 0',
            'weight'             => 'DECIMAL(10,3)',
            'volume'             => 'DECIMAL(10,3)',
            'notes'              => 'TEXT',
            'show_pricelist'     => 'TINYINT(1) NOT NULL DEFAULT 1',
            'show_webshop'       => 'TINYINT(1) NOT NULL DEFAULT 1',
            'is_deleted'         => 'TINYINT(1) NOT NULL DEFAULT 0',
            'low_stock_threshold' => 'INT NULL',
        ];
        if ($this->driver !== 'mysql') {
            $columns = array_map(
                fn($def) => str_replace(
                    ['VARCHAR(191)', 'VARCHAR(64)', 'VARCHAR(32)', 'VARCHAR(8)', 'DECIMAL(12,2)', 'DECIMAL(10,3)', 'TINYINT(1)', 'INT NULL'],
                    ['TEXT', 'TEXT', 'TEXT', 'TEXT', 'REAL', 'REAL', 'INTEGER', 'INTEGER'],
                    $def
                ),
                $columns
            );
        }
        $this->migrateColumns('products', $columns);
    }

    private function migrateSalesColumns(): void
    {
        $textType = $this->driver === 'mysql' ? 'VARCHAR(255) NULL' : 'TEXT';
        $this->migrateColumns('sales', [
            'payment_method' => $this->driver === 'mysql' ? "VARCHAR(64) NOT NULL DEFAULT 'Készpénz'" : "TEXT NOT NULL DEFAULT 'Készpénz'",
            'buyer_name'     => $textType,
        ]);
    }

    private function migrateColumns(string $table, array $columns): void
    {
        foreach ($columns as $name => $definition) {
            try {
                $this->pdo->exec("ALTER TABLE $table ADD COLUMN $name $definition");
            } catch (PDOException $e) {
            }
        }
    }

    private function migrateV4SuppliersAndLoyalty(): void
    {
        $isMysql = $this->driver === 'mysql';
        $pk = $isMysql ? 'INT UNSIGNED AUTO_INCREMENT PRIMARY KEY' : 'INTEGER PRIMARY KEY AUTOINCREMENT';
        $intCol = $isMysql ? 'INT UNSIGNED NULL' : 'INTEGER';
        $ts = $isMysql ? 'DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP' : "TEXT NOT NULL DEFAULT (datetime('now'))";
        $tsNull = $isMysql ? 'DATETIME NULL' : 'TEXT';
        $engine = $isMysql ? ' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci' : '';

        try {
            $this->pdo->exec("CREATE TABLE IF NOT EXISTS suppliers (
                id $pk, name VARCHAR(255) NOT NULL, tax_number VARCHAR(64), country VARCHAR(128),
                zip VARCHAR(16), city VARCHAR(191), address VARCHAR(255), contact_name VARCHAR(191),
                phone VARCHAR(64), email VARCHAR(191), payment_terms VARCHAR(191), notes TEXT,
                is_deleted INTEGER NOT NULL DEFAULT 0, created_at $ts, updated_at $tsNull
            )$engine");
        } catch (PDOException $e) { /* already exists */ }

        try {
            $this->pdo->exec("CREATE TABLE IF NOT EXISTS customers (
                id $pk, name VARCHAR(255) NOT NULL, phone VARCHAR(64), email VARCHAR(191),
                tax_number VARCHAR(64), notes TEXT, loyalty_points INTEGER NOT NULL DEFAULT 0,
                is_deleted INTEGER NOT NULL DEFAULT 0, created_at $ts, updated_at $tsNull
            )$engine");
        } catch (PDOException $e) { /* already exists */ }

        try {
            $this->pdo->exec("CREATE TABLE IF NOT EXISTS loyalty_transactions (
                id $pk, customer_id $intCol, sale_id $intCol, points_delta INTEGER NOT NULL,
                note VARCHAR(255), created_at $ts
            )$engine");
        } catch (PDOException $e) { /* already exists */ }

        $this->migrateColumns('purchases', ['supplier_id' => $intCol]);
        $this->migrateColumns('sales', [
            'customer_id'             => $intCol,
            'loyalty_points_earned'   => 'INTEGER NOT NULL DEFAULT 0',
            'loyalty_points_redeemed' => 'INTEGER NOT NULL DEFAULT 0',
        ]);

        try {
            $this->pdo->exec('CREATE INDEX idx_suppliers_name ON suppliers(name)');
        } catch (PDOException $e) { /* already exists */ }
        try {
            $this->pdo->exec('CREATE INDEX idx_customers_name ON customers(name)');
        } catch (PDOException $e) { /* already exists */ }
        try {
            $this->pdo->exec('CREATE INDEX idx_loyalty_customer_id ON loyalty_transactions(customer_id)');
        } catch (PDOException $e) { /* already exists */ }
    }

    /** Adds the billing/invoice fields to customers, so "vásárlói törzs" entries can also autofill the Kassza invoice form. */
    private function migrateV5CustomerBillingFields(): void
    {
        $textType = $this->driver === 'mysql' ? 'VARCHAR(255) NULL' : 'TEXT';
        $this->migrateColumns('customers', [
            'zip'     => $this->driver === 'mysql' ? 'VARCHAR(16) NULL' : 'TEXT',
            'city'    => $textType,
            'address' => $textType,
            'country' => $textType,
        ]);
    }

    private function migrateV6ManualSaleItems(): void
    {
        if ($this->driver === 'mysql') {
            try {
                $this->pdo->exec('ALTER TABLE sale_items MODIFY COLUMN product_id INT UNSIGNED NULL');
            } catch (PDOException $e) {
            }
            return;
        }

        $columns = $this->pdo->query("PRAGMA table_info(sale_items)")->fetchAll(PDO::FETCH_ASSOC);
        $productIdCol = null;
        foreach ($columns as $col) {
            if ($col['name'] === 'product_id') {
                $productIdCol = $col;
                break;
            }
        }
        if ($productIdCol === null || (int) $productIdCol['notnull'] === 0) {
            return;
        }

        $wasInTransaction = $this->pdo->inTransaction();
        try {
            $this->pdo->exec('PRAGMA foreign_keys = OFF');
            if (!$wasInTransaction) {
                $this->pdo->beginTransaction();
            }
            $this->pdo->exec("
                CREATE TABLE sale_items_new (
                    id           INTEGER PRIMARY KEY AUTOINCREMENT,
                    sale_id      INTEGER NOT NULL REFERENCES sales(id),
                    product_id   INTEGER REFERENCES products(id),
                    name         TEXT NOT NULL,
                    qty          INTEGER NOT NULL,
                    unit_price   REAL NOT NULL,
                    vat_rate     TEXT NOT NULL
                )
            ");
            $this->pdo->exec('
                INSERT INTO sale_items_new (id, sale_id, product_id, name, qty, unit_price, vat_rate)
                SELECT id, sale_id, product_id, name, qty, unit_price, vat_rate FROM sale_items
            ');
            $this->pdo->exec('DROP TABLE sale_items');
            $this->pdo->exec('ALTER TABLE sale_items_new RENAME TO sale_items');
            $this->pdo->exec('CREATE INDEX IF NOT EXISTS idx_sale_items_sale_id ON sale_items(sale_id)');
            if (!$wasInTransaction) {
                $this->pdo->commit();
            }
        } catch (PDOException $e) {
            if (!$wasInTransaction && $this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
        } finally {
            $this->pdo->exec('PRAGMA foreign_keys = ON');
        }
    }

    private function migrateV7CouponsGiftCardsPriceHistory(): void
    {
        $isMysql = $this->driver === 'mysql';
        $pk = $isMysql ? 'INT UNSIGNED AUTO_INCREMENT PRIMARY KEY' : 'INTEGER PRIMARY KEY AUTOINCREMENT';
        $intCol = $isMysql ? 'INT UNSIGNED NULL' : 'INTEGER';
        $moneyCol = $isMysql ? 'DECIMAL(12,2)' : 'REAL';
        $ts = $isMysql ? 'DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP' : "TEXT NOT NULL DEFAULT (datetime('now'))";
        $dateCol = $isMysql ? 'DATE NULL' : 'TEXT';
        $engine = $isMysql ? ' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci' : '';
        $codeCol = $isMysql ? 'VARCHAR(64) NOT NULL' : 'TEXT NOT NULL';

        try {
            $this->pdo->exec("CREATE TABLE IF NOT EXISTS coupons (
                id $pk, code $codeCol, type VARCHAR(16) NOT NULL DEFAULT 'percent',
                value $moneyCol NOT NULL, is_active INTEGER NOT NULL DEFAULT 1, expiry_date $dateCol,
                usage_limit INTEGER, times_used INTEGER NOT NULL DEFAULT 0,
                min_purchase $moneyCol NOT NULL DEFAULT 0, notes TEXT, created_at $ts
            )$engine");
        } catch (PDOException $e) { /* already exists */ }
        try {
            $this->pdo->exec($isMysql
                ? 'ALTER TABLE coupons ADD UNIQUE KEY uq_coupons_code (code)'
                : 'CREATE UNIQUE INDEX IF NOT EXISTS idx_coupons_code ON coupons(code)');
        } catch (PDOException $e) { /* already exists */ }

        try {
            $this->pdo->exec("CREATE TABLE IF NOT EXISTS gift_cards (
                id $pk, code $codeCol, initial_balance $moneyCol NOT NULL, current_balance $moneyCol NOT NULL,
                is_active INTEGER NOT NULL DEFAULT 1, expiry_date $dateCol, notes TEXT, created_at $ts
            )$engine");
        } catch (PDOException $e) { /* already exists */ }
        try {
            $this->pdo->exec($isMysql
                ? 'ALTER TABLE gift_cards ADD UNIQUE KEY uq_gift_cards_code (code)'
                : 'CREATE UNIQUE INDEX IF NOT EXISTS idx_gift_cards_code ON gift_cards(code)');
        } catch (PDOException $e) { /* already exists */ }

        try {
            $this->pdo->exec("CREATE TABLE IF NOT EXISTS gift_card_transactions (
                id $pk, gift_card_id $intCol, sale_id $intCol, amount_delta $moneyCol NOT NULL,
                note VARCHAR(255), created_at $ts
            )$engine");
        } catch (PDOException $e) { /* already exists */ }

        try {
            $this->pdo->exec("CREATE TABLE IF NOT EXISTS price_history (
                id $pk, product_id $intCol, old_net_price $moneyCol, old_price $moneyCol,
                new_net_price $moneyCol, new_price $moneyCol, changed_at $ts
            )$engine");
        } catch (PDOException $e) { /* already exists */ }

        $this->migrateColumns('sales', [
            'coupon_id'          => $intCol,
            'coupon_discount'    => "$moneyCol NOT NULL DEFAULT 0",
            'gift_card_redeemed' => "$moneyCol NOT NULL DEFAULT 0",
        ]);

        foreach ([
            'CREATE INDEX idx_gift_card_tx_card_id ON gift_card_transactions(gift_card_id)',
            'CREATE INDEX idx_price_history_product_id ON price_history(product_id)',
        ] as $sql) {
            try {
                $this->pdo->exec($sql);
            } catch (PDOException $e) { /* already exists */ }
        }
    }

    /** Adds staff (PIN login), returns/sztornó, and stock-take tables — plus the sales.staff_id link. */
    private function migrateV8StaffReturnsStockTakes(): void
    {
        $isMysql = $this->driver === 'mysql';
        $pk = $isMysql ? 'INT UNSIGNED AUTO_INCREMENT PRIMARY KEY' : 'INTEGER PRIMARY KEY AUTOINCREMENT';
        $intCol = $isMysql ? 'INT UNSIGNED NULL' : 'INTEGER';
        $intColRequired = $isMysql ? 'INT UNSIGNED NOT NULL' : 'INTEGER NOT NULL';
        $moneyCol = $isMysql ? 'DECIMAL(12,2)' : 'REAL';
        $ts = $isMysql ? 'DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP' : "TEXT NOT NULL DEFAULT (datetime('now'))";
        $tsNull = $isMysql ? 'DATETIME NULL' : 'TEXT';
        $engine = $isMysql ? ' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci' : '';

        try {
            $this->pdo->exec("CREATE TABLE IF NOT EXISTS staff (
                id $pk, name VARCHAR(191) NOT NULL, pin_hash VARCHAR(255) NOT NULL,
                is_active INTEGER NOT NULL DEFAULT 1, created_at $ts
            )$engine");
        } catch (PDOException $e) { /* already exists */ }

        try {
            $this->pdo->exec("CREATE TABLE IF NOT EXISTS returns (
                id $pk, sale_id $intColRequired, staff_id $intCol, total_refund $moneyCol NOT NULL,
                reason VARCHAR(255), credit_invoice_number VARCHAR(64), created_at $ts
            )$engine");
        } catch (PDOException $e) { /* already exists */ }

        try {
            $this->pdo->exec("CREATE TABLE IF NOT EXISTS return_items (
                id $pk, return_id $intColRequired, sale_item_id $intCol, product_id $intCol,
                name VARCHAR(255) NOT NULL, qty INTEGER NOT NULL, unit_price $moneyCol NOT NULL, created_at $ts
            )$engine");
        } catch (PDOException $e) { /* already exists */ }

        try {
            $this->pdo->exec("CREATE TABLE IF NOT EXISTS stock_takes (
                id $pk, staff_id $intCol, notes VARCHAR(255), started_at $ts, completed_at $tsNull
            )$engine");
        } catch (PDOException $e) { /* already exists */ }

        try {
            $this->pdo->exec("CREATE TABLE IF NOT EXISTS stock_take_items (
                id $pk, stock_take_id $intColRequired, product_id $intColRequired,
                expected_qty INTEGER NOT NULL, counted_qty INTEGER, created_at $ts
            )$engine");
        } catch (PDOException $e) { /* already exists */ }

        $this->migrateColumns('sales', ['staff_id' => $intCol]);

        foreach ([
            'CREATE INDEX idx_returns_sale_id ON returns(sale_id)',
            'CREATE INDEX idx_return_items_return_id ON return_items(return_id)',
            'CREATE INDEX idx_stock_take_items_take_id ON stock_take_items(stock_take_id)',
        ] as $sql) {
            try {
                $this->pdo->exec($sql);
            } catch (PDOException $e) { /* already exists */ }
        }
    }

    private function migrateV9RolesAuditLoyaltyTiers(): void
    {
        $isMysql = $this->driver === 'mysql';
        $pk = $isMysql ? 'INT UNSIGNED AUTO_INCREMENT PRIMARY KEY' : 'INTEGER PRIMARY KEY AUTOINCREMENT';
        $intCol = $isMysql ? 'INT UNSIGNED NULL' : 'INTEGER';
        $moneyCol = $isMysql ? 'DECIMAL(12,2)' : 'REAL';
        $ts = $isMysql ? 'DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP' : "TEXT NOT NULL DEFAULT (datetime('now'))";
        $engine = $isMysql ? ' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci' : '';

        $this->migrateColumns('staff', ["role" => "VARCHAR(16) NOT NULL DEFAULT 'cashier'"]);
        $this->migrateColumns('customers', ['total_spent' => "$moneyCol NOT NULL DEFAULT 0"]);

        try {
            $this->pdo->exec("CREATE TABLE IF NOT EXISTS audit_log (
                id $pk, staff_id $intCol, action VARCHAR(64) NOT NULL, entity_type VARCHAR(64),
                entity_id INTEGER, details TEXT, created_at $ts
            )$engine");
        } catch (PDOException $e) { /* already exists */ }

        try {
            $this->pdo->exec('CREATE INDEX idx_audit_log_created_at ON audit_log(created_at)');
        } catch (PDOException $e) { /* already exists */ }

        try {
            $this->pdo->exec('
                UPDATE customers SET total_spent = (
                    SELECT COALESCE(SUM(total), 0) FROM sales WHERE sales.customer_id = customers.id
                )
            ');
        } catch (PDOException $e) { /* non-fatal — tiers just start at 0 if this fails */ }
    }

    private function migrateV10PreferredSupplierAndLocations(): void
    {
        $isMysql = $this->driver === 'mysql';
        $pk = $isMysql ? 'INT UNSIGNED AUTO_INCREMENT PRIMARY KEY' : 'INTEGER PRIMARY KEY AUTOINCREMENT';
        $intCol = $isMysql ? 'INT UNSIGNED NULL' : 'INTEGER';
        $intColRequired = $isMysql ? 'INT UNSIGNED NOT NULL' : 'INTEGER NOT NULL';
        $ts = $isMysql ? 'DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP' : "TEXT NOT NULL DEFAULT (datetime('now'))";
        $engine = $isMysql ? ' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci' : '';

        $this->migrateColumns('products', ['preferred_supplier_id' => $intCol]);

        try {
            $this->pdo->exec("CREATE TABLE IF NOT EXISTS locations (
                id $pk, name VARCHAR(191) NOT NULL, address VARCHAR(255), is_default INTEGER NOT NULL DEFAULT 0, created_at $ts
            )$engine");
        } catch (PDOException $e) { /* already exists */ }

        try {
            $this->pdo->exec("CREATE TABLE IF NOT EXISTS location_stock (
                id $pk, product_id $intColRequired, location_id $intColRequired, stock_qty INTEGER NOT NULL DEFAULT 0
            )$engine");
        } catch (PDOException $e) { /* already exists */ }

        try {
            $this->pdo->exec("CREATE TABLE IF NOT EXISTS stock_transfers (
                id $pk, product_id $intColRequired, from_location_id $intCol, to_location_id $intColRequired,
                qty INTEGER NOT NULL, staff_id $intCol, created_at $ts
            )$engine");
        } catch (PDOException $e) { /* already exists */ }

        foreach ([
            $isMysql
                ? 'ALTER TABLE location_stock ADD UNIQUE KEY uq_location_stock_product_location (product_id, location_id)'
                : 'CREATE UNIQUE INDEX IF NOT EXISTS idx_location_stock_product_location ON location_stock(product_id, location_id)',
            'CREATE INDEX idx_stock_transfers_product_id ON stock_transfers(product_id)',
        ] as $sql) {
            try {
                $this->pdo->exec($sql);
            } catch (PDOException $e) { /* already exists */ }
        }
    }

    private function migrateV11MissingIndexes(): void
    {
        foreach ([
            'CREATE INDEX idx_products_deleted ON products(is_deleted)',
            'CREATE INDEX idx_products_preferred_supplier ON products(preferred_supplier_id)',
            'CREATE INDEX idx_sales_customer_id ON sales(customer_id)',
            'CREATE INDEX idx_sales_staff_id ON sales(staff_id)',
            'CREATE INDEX idx_sales_coupon_id ON sales(coupon_id)',
            'CREATE INDEX idx_sale_items_product_id ON sale_items(product_id)',
            'CREATE INDEX idx_purchases_created_at ON purchases(created_at)',
            'CREATE INDEX idx_purchases_supplier_id ON purchases(supplier_id)',
            'CREATE INDEX idx_purchase_items_product_id ON purchase_items(product_id)',
            'CREATE INDEX idx_sync_log_created_at ON sync_log(created_at)',
        ] as $sql) {
            try {
                $this->pdo->exec($sql);
            } catch (PDOException $e) { /* already exists (e.g. MySQL, or a re-run) */ }
        }
    }

    private function migrateV12ReceiptToken(): void
    {
        $this->migrateColumns('sales', ['receipt_token' => 'VARCHAR(64)']);

        $stmt = $this->pdo->query('SELECT id FROM sales WHERE receipt_token IS NULL');
        $updateStmt = $this->pdo->prepare('UPDATE sales SET receipt_token = ? WHERE id = ?');
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $updateStmt->execute([bin2hex(random_bytes(24)), $row['id']]);
        }
    }

    private function migrateV13WebhookIdempotency(): void
    {
        $this->pdo->exec('CREATE TABLE IF NOT EXISTS processed_webhook_orders (
            wc_order_id INTEGER PRIMARY KEY,
            processed_at TEXT NOT NULL
        )');
    }

    public function findProductByBarcode(string $barcode): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM products WHERE barcode = ?');
        $stmt->execute([$barcode]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    public function findProductById(int $id): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM products WHERE id = ?');
        $stmt->execute([$id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    public function findProductByWcId(int $wcId): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM products WHERE wc_product_id = ?');
        $stmt->execute([$wcId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    public function listBarcodeIndex(): array
    {
        $stmt = $this->pdo->query("SELECT id, barcode FROM products WHERE barcode IS NOT NULL AND barcode != ''");
        $index = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $index[$row['barcode']] = (int) $row['id'];
        }
        return $index;
    }

    public function listProducts(int $limit = 200, bool $includeDeleted = false): array
    {
        $sql = 'SELECT * FROM products';
        if (!$includeDeleted) {
            $sql .= ' WHERE is_deleted = 0';
        }
        $sql .= ' ORDER BY name LIMIT ?';

        $stmt = $this->pdo->prepare($sql);
        $stmt->bindValue(1, $limit, PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Létrehoz vagy frissít egy árucikk törzsadatot (az "Árucikk
     * módosítása" űrlap használja, önállóan és a Beszerzés oldal
     * "Új termék hozzáadása" gombjáról is).
     *
     * @return int az árucikk azonosítója (új vagy meglévő)
     */
    public function saveProduct(array $p): int
    {
        $now = date('c');

        if (!empty($p['id'])) {
            $existing = $this->findProductById((int) $p['id']);
            $stmt = $this->pdo->prepare('
                UPDATE products SET
                    name = :name, unit = :unit, group_name = :group_name,
                    cikkszam = :cikkszam, vtsz = :vtsz, barcode = :barcode,
                    currency = :currency, vat_rate = :vat_rate,
                    net_price = :net_price, price = :price,
                    weight = :weight, volume = :volume, notes = :notes,
                    show_pricelist = :show_pricelist, show_webshop = :show_webshop,
                    is_deleted = :is_deleted, low_stock_threshold = :low_stock_threshold,
                    preferred_supplier_id = :preferred_supplier_id, updated_at = :now
                WHERE id = :id
            ');
            $stmt->execute($this->productParams($p, $now) + [':id' => $p['id']]);

            if ($existing && (
                round((float) $existing['net_price'], 2) !== round((float) $p['net_price'], 2)
                || round((float) $existing['price'], 2) !== round((float) $p['price'], 2)
            )) {
                $this->logPriceChange(
                    (int) $p['id'],
                    (float) $existing['net_price'],
                    (float) $existing['price'],
                    (float) $p['net_price'],
                    (float) $p['price']
                );
            }

            return (int) $p['id'];
        }

        $stmt = $this->pdo->prepare('
            INSERT INTO products (
                name, unit, group_name, cikkszam, vtsz, barcode, currency,
                vat_rate, net_price, price, purchase_price_net, stock_qty,
                weight, volume, notes, show_pricelist, show_webshop, is_deleted, low_stock_threshold, preferred_supplier_id, updated_at
            ) VALUES (
                :name, :unit, :group_name, :cikkszam, :vtsz, :barcode, :currency,
                :vat_rate, :net_price, :price, 0, 0,
                :weight, :volume, :notes, :show_pricelist, :show_webshop, :is_deleted, :low_stock_threshold, :preferred_supplier_id, :now
            )
        ');
        $stmt->execute($this->productParams($p, $now));
        return (int) $this->pdo->lastInsertId();
    }

    private function productParams(array $p, string $now): array
    {
        return [
            ':name'           => $p['name'],
            ':unit'           => $p['unit'] ?: 'db',
            ':group_name'     => $p['group_name'] ?? null,
            ':cikkszam'       => $p['cikkszam'] ?? null,
            ':vtsz'           => $p['vtsz'] ?? null,
            ':barcode'        => $p['barcode'] ?: null,
            ':currency'       => $p['currency'] ?: 'HUF',
            ':vat_rate'       => (string) $p['vat_rate'],
            ':net_price'      => (float) $p['net_price'],
            ':price'          => (float) $p['price'],
            ':weight'         => $p['weight'] !== '' && $p['weight'] !== null ? (float) $p['weight'] : null,
            ':volume'         => $p['volume'] !== '' && $p['volume'] !== null ? (float) $p['volume'] : null,
            ':notes'          => $p['notes'] ?? null,
            ':show_pricelist' => !empty($p['show_pricelist']) ? 1 : 0,
            ':show_webshop'   => !empty($p['show_webshop']) ? 1 : 0,
            ':is_deleted'     => !empty($p['is_deleted']) ? 1 : 0,
            ':low_stock_threshold' => ($p['low_stock_threshold'] ?? '') !== '' ? (int) $p['low_stock_threshold'] : null,
            ':preferred_supplier_id' => !empty($p['preferred_supplier_id']) ? (int) $p['preferred_supplier_id'] : null,
            ':now'            => $now,
        ];
    }

    public function importUpsertProduct(array $p): array
    {
        $existing = !empty($p['barcode']) ? $this->findProductByBarcode($p['barcode']) : null;

        $id = $this->saveProduct([
            'id'             => $existing['id'] ?? null,
            'name'           => $p['name'],
            'unit'           => $p['unit'] ?: 'db',
            'group_name'     => $p['group_name'] ?: null,
            'cikkszam'       => $p['cikkszam'] ?: null,
            'vtsz'           => $existing['vtsz'] ?? null,
            'barcode'        => $p['barcode'] ?: null,
            'currency'       => $p['currency'] ?: 'HUF',
            'vat_rate'       => $p['vat_rate'],
            'net_price'      => $p['net_price'],
            'price'          => $p['price'],
            'weight'         => $existing['weight'] ?? null,
            'volume'         => $existing['volume'] ?? null,
            'notes'          => $p['notes'] ?: null,
            'show_pricelist' => $existing['show_pricelist'] ?? true,
            'show_webshop'   => $existing['show_webshop'] ?? true,
            'is_deleted'     => $existing['is_deleted'] ?? false,
            'low_stock_threshold' => $existing['low_stock_threshold'] ?? '',
        ]);

        $stmt = $this->pdo->prepare('
            UPDATE products SET stock_qty = :qty, purchase_price_net = :cost, updated_at = :now WHERE id = :id
        ');
        $stmt->execute([
            ':qty'  => $p['stock_qty'],
            ':cost' => $p['purchase_price_net'],
            ':now'  => date('c'),
            ':id'   => $id,
        ]);

        return ['action' => $existing ? 'updated' : 'inserted', 'id' => $id];
    }

    public function incrementStock(int $productId, int $qty): void
    {
        $stmt = $this->pdo->prepare('
            UPDATE products SET stock_qty = stock_qty + :qty, updated_at = :now WHERE id = :id
        ');
        $stmt->execute([':qty' => $qty, ':now' => date('c'), ':id' => $productId]);
    }

    public function applyPurchaseLine(int $productId, int $qtyDelta, float $newCost): void
    {
        $stmt = $this->pdo->prepare('
            UPDATE products
            SET stock_qty = stock_qty + :qty, purchase_price_net = :cost, updated_at = :now
            WHERE id = :id
        ');
        $stmt->execute([':qty' => $qtyDelta, ':cost' => $newCost, ':now' => date('c'), ':id' => $productId]);
    }

    public function upsertProductFromWc(array $p): void
    {
        $existing = $this->findProductByWcId((int) $p['wc_product_id']);
        if (!$existing && !empty($p['barcode'])) {
            $existing = $this->findProductByBarcode($p['barcode']);
        }

        $now = date('c');

        if ($existing) {
            $stmt = $this->pdo->prepare('
                UPDATE products
                SET wc_product_id = :wc_product_id,
                    sku = :sku,
                    barcode = :barcode,
                    name = :name,
                    price = :price,
                    stock_qty = :stock_qty,
                    wc_synced_at = :now
                WHERE id = :id
            ');
            $stmt->execute([
                ':wc_product_id' => $p['wc_product_id'],
                ':sku'           => $p['sku'],
                ':barcode'       => $p['barcode'],
                ':name'          => $p['name'],
                ':price'         => $p['price'],
                ':stock_qty'     => $p['stock_qty'],
                ':now'           => $now,
                ':id'            => $existing['id'],
            ]);
        } else {
            $stmt = $this->pdo->prepare('
                INSERT INTO products (wc_product_id, sku, barcode, name, price, stock_qty, updated_at, wc_synced_at)
                VALUES (:wc_product_id, :sku, :barcode, :name, :price, :stock_qty, :now, :now)
            ');
            $stmt->execute([
                ':wc_product_id' => $p['wc_product_id'],
                ':sku'           => $p['sku'],
                ':barcode'       => $p['barcode'],
                ':name'          => $p['name'],
                ':price'         => $p['price'],
                ':stock_qty'     => $p['stock_qty'],
                ':now'           => $now,
            ]);
        }
    }

    public function decrementStock(int $productId, int $qty): void
    {
        $stmt = $this->pdo->prepare('
            UPDATE products
            SET stock_qty = stock_qty - :qty, updated_at = :now
            WHERE id = :id
        ');
        $stmt->execute([':qty' => $qty, ':now' => date('c'), ':id' => $productId]);
    }

    public function setStock(int $productId, int $qty): void
    {
        $stmt = $this->pdo->prepare('
            UPDATE products SET stock_qty = :qty, updated_at = :now, wc_synced_at = :now WHERE id = :id
        ');
        $stmt->execute([':qty' => $qty, ':now' => date('c'), ':id' => $productId]);
    }

    public function insertSale(
        float $total,
        string $paymentMethod = 'Készpénz',
        ?string $buyerName = null,
        ?int $customerId = null,
        int $loyaltyPointsEarned = 0,
        int $loyaltyPointsRedeemed = 0,
        ?int $couponId = null,
        float $couponDiscount = 0.0,
        float $giftCardRedeemed = 0.0,
        ?int $staffId = null
    ): int {
        // A token a nyugta bejelentkezés nélküli (QR-kódos) megtekintéséhez
        // kell — kitalálhatatlan, ellentétben magával a sorszámozott
        // eladás-azonosítóval.
        $receiptToken = bin2hex(random_bytes(24));

        $stmt = $this->pdo->prepare('
            INSERT INTO sales (total, payment_method, buyer_name, customer_id, loyalty_points_earned, loyalty_points_redeemed, coupon_id, coupon_discount, gift_card_redeemed, staff_id, status, receipt_token, created_at)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ');
        $stmt->execute([
            $total, $paymentMethod, $buyerName, $customerId, $loyaltyPointsEarned, $loyaltyPointsRedeemed,
            $couponId, $couponDiscount, $giftCardRedeemed, $staffId, 'completed', $receiptToken, date('Y-m-d H:i:s'),
        ]);
        return (int) $this->pdo->lastInsertId();
    }

    public function insertSaleItem(int $saleId, array $item): void
    {
        $stmt = $this->pdo->prepare('
            INSERT INTO sale_items (sale_id, product_id, name, qty, unit_price, vat_rate)
            VALUES (:sale_id, :product_id, :name, :qty, :unit_price, :vat_rate)
        ');
        $stmt->execute([
            ':sale_id'    => $saleId,
            ':product_id' => $item['product_id'] ?? null,
            ':name'       => $item['name'],
            ':qty'        => $item['qty'],
            ':unit_price' => $item['unit_price'],
            ':vat_rate'   => $item['vat_rate'],
        ]);
    }

    public function attachInvoiceToSale(int $saleId, ?string $invoiceNumber, ?string $pdfPath, string $status): void
    {
        $stmt = $this->pdo->prepare('
            UPDATE sales SET szamlazz_invoice_number = ?, szamlazz_pdf_path = ?, status = ? WHERE id = ?
        ');
        $stmt->execute([$invoiceNumber, $pdfPath, $status, $saleId]);
    }

    public function getSaleReceiptToken(int $saleId): ?string
    {
        $stmt = $this->pdo->prepare('SELECT receipt_token FROM sales WHERE id = ?');
        $stmt->execute([$saleId]);
        $token = $stmt->fetchColumn();
        return $token !== false ? $token : null;
    }

    public function getSaleWithItemsByToken(int $saleId, string $token): ?array
    {
        $sale = $this->getSaleWithItems($saleId);
        if (!$sale || empty($sale['receipt_token']) || !hash_equals($sale['receipt_token'], $token)) {
            return null;
        }
        return $sale;
    }

    public function getSaleWithItems(int $saleId): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM sales WHERE id = ?');
        $stmt->execute([$saleId]);
        $sale = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$sale) {
            return null;
        }

        $stmt = $this->pdo->prepare('SELECT * FROM sale_items WHERE sale_id = ?');
        $stmt->execute([$saleId]);
        $sale['items'] = $stmt->fetchAll(PDO::FETCH_ASSOC);

        return $sale;
    }

    /**
     * Eladások listája — szűrhető lista a kereső oldalhoz.
     * @param array $filters opcionális: date (ÉÉÉÉ-HH-NN), id (int), query (buyer_name-re illeszkedik)
     */
    public function listSales(array $filters = [], int $limit = 300): array
    {
        $where = [];
        $params = [];

        if (!empty($filters['date'])) {
            $where[] = ($this->driver === 'mysql' ? 'DATE(created_at)' : "substr(created_at, 1, 10)") . ' = ?';
            $params[] = $filters['date'];
        }
        if (!empty($filters['id'])) {
            $where[] = 'id = ?';
            $params[] = (int) $filters['id'];
        }
        if (!empty($filters['query'])) {
            $where[] = 'buyer_name LIKE ?';
            $params[] = '%' . $filters['query'] . '%';
        }

        $sql = 'SELECT * FROM sales';
        if ($where) {
            $sql .= ' WHERE ' . implode(' AND ', $where);
        }
        $sql .= ' ORDER BY created_at DESC LIMIT ' . (int) $limit;

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        foreach ($rows as &$row) {
            unset($row['receipt_token']); // titkos token — listanézetben sose kell, sose menjen ki
        }
        return $rows;
    }

    /**
     * Beszerzések listája — szűrhető lista a kereső oldalhoz.
     * @param array $filters opcionális: date (ÉÉÉÉ-HH-NN), id (int), query (supplier_name-re illeszkedik)
     */
    public function listPurchases(array $filters = [], int $limit = 300): array
    {
        $where = [];
        $params = [];

        if (!empty($filters['date'])) {
            $where[] = ($this->driver === 'mysql' ? 'DATE(created_at)' : "substr(created_at, 1, 10)") . ' = ?';
            $params[] = $filters['date'];
        }
        if (!empty($filters['id'])) {
            $where[] = 'id = ?';
            $params[] = (int) $filters['id'];
        }
        if (!empty($filters['query'])) {
            $where[] = 'supplier_name LIKE ?';
            $params[] = '%' . $filters['query'] . '%';
        }

        $sql = 'SELECT * FROM purchases';
        if ($where) {
            $sql .= ' WHERE ' . implode(' AND ', $where);
        }
        $sql .= ' ORDER BY created_at DESC LIMIT ' . (int) $limit;

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function getPurchaseWithItems(int $purchaseId): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM purchases WHERE id = ?');
        $stmt->execute([$purchaseId]);
        $purchase = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$purchase) {
            return null;
        }

        $stmt = $this->pdo->prepare('SELECT * FROM purchase_items WHERE purchase_id = ?');
        $stmt->execute([$purchaseId]);
        $purchase['items'] = $stmt->fetchAll(PDO::FETCH_ASSOC);

        return $purchase;
    }

    // ---------------------------------------------------------------
    // Napi zárás (daily closing / sales summary)
    // ---------------------------------------------------------------

    /**
     * Összegzi egy adott nap (ÉÉÉÉ-HH-NN, a created_at dátumrésze alapján)
     * összes eladását: végösszegek, fizetési mód szerinti bontás, ÁFA-kulcs
     * szerinti bontás — minden, amire a "Napi zárás" oldalnak és egy
     * zárás-rekordnak szüksége van.
     */
    public function getDailySummary(string $date): array
    {
        $dateExpr = $this->driver === 'mysql' ? 'DATE(created_at)' : "substr(created_at, 1, 10)";
        $stmt = $this->pdo->prepare("SELECT * FROM sales WHERE $dateExpr = ? ORDER BY created_at");
        $stmt->execute([$date]);
        $sales = $stmt->fetchAll(PDO::FETCH_ASSOC);
        foreach ($sales as &$saleRow) {
            unset($saleRow['receipt_token']); // titkos token — a napi összesítőben sose kell, sose menjen ki
        }
        unset($saleRow);

        $itemsBySale = [];
        if ($sales) {
            $saleIds = array_column($sales, 'id');
            $placeholders = implode(',', array_fill(0, count($saleIds), '?'));
            $itemsStmt = $this->pdo->prepare("SELECT * FROM sale_items WHERE sale_id IN ($placeholders)");
            $itemsStmt->execute($saleIds);
            foreach ($itemsStmt->fetchAll(PDO::FETCH_ASSOC) as $item) {
                $itemsBySale[$item['sale_id']][] = $item;
            }
        }

        $totalGross = 0.0;
        $totalNet = 0.0;
        $totalVat = 0.0;
        $byPayment = [];
        $byVatRate = [];

        foreach ($sales as &$sale) {
            $sale['items'] = $itemsBySale[$sale['id']] ?? [];

            $totalGross += $sale['total'];

            $method = $sale['payment_method'] ?: 'Készpénz';
            $byPayment[$method]['count'] = ($byPayment[$method]['count'] ?? 0) + 1;
            $byPayment[$method]['total'] = ($byPayment[$method]['total'] ?? 0) + $sale['total'];

            foreach ($sale['items'] as $item) {
                $vatRate = (string) $item['vat_rate'];
                $vatPct = is_numeric($vatRate) ? ((float) $vatRate) / 100 : 0.0;
                $lineGross = (float) $item['unit_price'] * (int) $item['qty'];
                $lineNet = is_numeric($vatRate) ? round($lineGross / (1 + $vatPct), 2) : $lineGross;
                $lineVat = round($lineGross - $lineNet, 2);

                $totalNet += $lineNet;
                $totalVat += $lineVat;

                $byVatRate[$vatRate]['net'] = ($byVatRate[$vatRate]['net'] ?? 0) + $lineNet;
                $byVatRate[$vatRate]['vat'] = ($byVatRate[$vatRate]['vat'] ?? 0) + $lineVat;
                $byVatRate[$vatRate]['gross'] = ($byVatRate[$vatRate]['gross'] ?? 0) + $lineGross;
            }
        }
        unset($sale);

        foreach ($byPayment as &$row) {
            $row['total'] = round($row['total'], 2);
        }
        unset($row);
        foreach ($byVatRate as &$row) {
            $row['net'] = round($row['net'], 2);
            $row['vat'] = round($row['vat'], 2);
            $row['gross'] = round($row['gross'], 2);
        }
        unset($row);

        return [
            'date'              => $date,
            'sales_count'       => count($sales),
            'total_gross'       => round($totalGross, 2),
            'total_net'         => round($totalNet, 2),
            'total_vat'         => round($totalVat, 2),
            'by_payment_method' => $byPayment,
            'by_vat_rate'       => $byVatRate,
            'sales'             => $sales,
        ];
    }

    public function recordClosing(string $date, array $summary): void
    {
        $params = [
            ':date'     => $date,
            ':count'    => $summary['sales_count'],
            ':gross'    => $summary['total_gross'],
            ':net'      => $summary['total_net'],
            ':vat'      => $summary['total_vat'],
            ':pay_json' => json_encode($summary['by_payment_method'], JSON_UNESCAPED_UNICODE),
            ':vat_json' => json_encode($summary['by_vat_rate'], JSON_UNESCAPED_UNICODE),
            ':now'      => date('Y-m-d H:i:s'),
        ];

        if ($this->driver === 'mysql') {
            $sql = '
                INSERT INTO closings (
                    closing_date, sales_count, total_gross, total_net, total_vat,
                    payment_breakdown_json, vat_breakdown_json, closed_at
                ) VALUES (
                    :date, :count, :gross, :net, :vat, :pay_json, :vat_json, :now
                )
                ON DUPLICATE KEY UPDATE
                    sales_count = VALUES(sales_count),
                    total_gross = VALUES(total_gross),
                    total_net = VALUES(total_net),
                    total_vat = VALUES(total_vat),
                    payment_breakdown_json = VALUES(payment_breakdown_json),
                    vat_breakdown_json = VALUES(vat_breakdown_json),
                    closed_at = VALUES(closed_at)
            ';
        } else {
            $sql = '
                INSERT INTO closings (
                    closing_date, sales_count, total_gross, total_net, total_vat,
                    payment_breakdown_json, vat_breakdown_json, closed_at
                ) VALUES (
                    :date, :count, :gross, :net, :vat, :pay_json, :vat_json, :now
                )
                ON CONFLICT(closing_date) DO UPDATE SET
                    sales_count = excluded.sales_count,
                    total_gross = excluded.total_gross,
                    total_net = excluded.total_net,
                    total_vat = excluded.total_vat,
                    payment_breakdown_json = excluded.payment_breakdown_json,
                    vat_breakdown_json = excluded.vat_breakdown_json,
                    closed_at = excluded.closed_at
            ';
        }

        $this->pdo->prepare($sql)->execute($params);
    }

    public function getClosing(string $date): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM closings WHERE closing_date = ?');
        $stmt->execute([$date]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            return null;
        }
        $row['payment_breakdown'] = json_decode($row['payment_breakdown_json'] ?? '[]', true) ?: [];
        $row['vat_breakdown'] = json_decode($row['vat_breakdown_json'] ?? '[]', true) ?: [];
        return $row;
    }

    // ---------------------------------------------------------------
    // Purchases (beszerzés — incoming stock)
    // ---------------------------------------------------------------

    public function recordPurchase(array $purchase, array $items): array
    {
        $totalNet = 0.0;
        $totalGross = 0.0;
        foreach ($items as $item) {
            $totalNet += $item['unit_cost_net'] * $item['qty'];
            $totalGross += $item['unit_cost_gross'] * $item['qty'];
        }

        $now = date('Y-m-d H:i:s');

        $this->beginTransaction();
        try {
            $stmt = $this->pdo->prepare('
                INSERT INTO purchases (
                    supplier_id, supplier_name, supplier_tax_number, supplier_country, supplier_zip,
                    supplier_city, supplier_address, payment_method, currency,
                    discount_percent, paid, note, total_net, total_gross, created_at
                ) VALUES (
                    :supplier_id, :supplier_name, :supplier_tax_number, :supplier_country, :supplier_zip,
                    :supplier_city, :supplier_address, :payment_method, :currency,
                    :discount_percent, :paid, :note, :total_net, :total_gross, :now
                )
            ');
            $stmt->execute([
                ':supplier_id'         => $purchase['supplier_id'] ?? null,
                ':supplier_name'       => $purchase['supplier_name'] ?? null,
                ':supplier_tax_number' => $purchase['supplier_tax_number'] ?? null,
                ':supplier_country'    => $purchase['supplier_country'] ?? null,
                ':supplier_zip'        => $purchase['supplier_zip'] ?? null,
                ':supplier_city'       => $purchase['supplier_city'] ?? null,
                ':supplier_address'    => $purchase['supplier_address'] ?? null,
                ':payment_method'      => $purchase['payment_method'] ?? 'készpénz',
                ':currency'            => $purchase['currency'] ?? 'HUF',
                ':discount_percent'    => $purchase['discount_percent'] ?? 0,
                ':paid'                => !empty($purchase['paid']) ? 1 : 0,
                ':note'                => $purchase['note'] ?? null,
                ':total_net'           => round($totalNet, 2),
                ':total_gross'         => round($totalGross, 2),
                ':now'                 => $now,
            ]);
            $purchaseId = (int) $this->pdo->lastInsertId();

            $itemStmt = $this->pdo->prepare('
                INSERT INTO purchase_items (
                    purchase_id, product_id, name, qty, vat_rate,
                    unit_cost_net, unit_cost_gross, line_net, line_gross
                ) VALUES (
                    :purchase_id, :product_id, :name, :qty, :vat_rate,
                    :unit_cost_net, :unit_cost_gross, :line_net, :line_gross
                )
            ');
            $syncStmt = $this->pdo->prepare('INSERT INTO sync_log (direction, product_id, message, created_at) VALUES (?, ?, ?, ?)');

            foreach ($items as $item) {
                $lineNet = round($item['unit_cost_net'] * $item['qty'], 2);
                $lineGross = round($item['unit_cost_gross'] * $item['qty'], 2);

                $itemStmt->execute([
                    ':purchase_id'     => $purchaseId,
                    ':product_id'      => $item['product_id'],
                    ':name'            => $item['name'],
                    ':qty'             => $item['qty'],
                    ':vat_rate'        => $item['vat_rate'],
                    ':unit_cost_net'   => $item['unit_cost_net'],
                    ':unit_cost_gross' => $item['unit_cost_gross'],
                    ':line_net'        => $lineNet,
                    ':line_gross'      => $lineGross,
                ]);

                $this->applyPurchaseLine($item['product_id'], $item['qty'], $item['unit_cost_net']);
                $syncStmt->execute(['purchase', $item['product_id'], "Purchase #$purchaseId: +{$item['qty']}", $now]);
            }

            $this->commit();
        } catch (Throwable $e) {
            $this->rollBack();
            throw $e;
        }

        $productIds = array_unique(array_column($items, 'product_id'));
        $updatedProducts = $productIds ? $this->findProductsByIds($productIds) : [];

        return [
            'purchase_id'      => $purchaseId,
            'total_net'        => round($totalNet, 2),
            'total_gross'      => round($totalGross, 2),
            'updated_products' => $updatedProducts,
        ];
    }

    public function findProductsByIds(array $ids): array
    {
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $stmt = $this->pdo->prepare("SELECT * FROM products WHERE id IN ($placeholders)");
        $stmt->execute(array_values($ids));

        $byId = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $byId[(int) $row['id']] = $row;
        }
        return $byId;
    }

    public function logSync(string $direction, ?int $productId, string $message): void
    {
        $stmt = $this->pdo->prepare('
            INSERT INTO sync_log (direction, product_id, message, created_at) VALUES (?, ?, ?, ?)
        ');
        $stmt->execute([$direction, $productId, $message, date('Y-m-d H:i:s')]);
    }

    public function isWebhookOrderProcessed(int $wcOrderId): bool
    {
        $stmt = $this->pdo->prepare('SELECT 1 FROM processed_webhook_orders WHERE wc_order_id = ?');
        $stmt->execute([$wcOrderId]);
        return (bool) $stmt->fetchColumn();
    }

    public function markWebhookOrderProcessed(int $wcOrderId): bool
    {
        try {
            $stmt = $this->pdo->prepare('INSERT INTO processed_webhook_orders (wc_order_id, processed_at) VALUES (?, ?)');
            $stmt->execute([$wcOrderId, date('Y-m-d H:i:s')]);
            return true;
        } catch (PDOException $e) {
            return false;
        }
    }

    // ---------------------------------------------------------------
    // Suppliers (beszállító-törzs)
    // ---------------------------------------------------------------

    public function listSuppliers(bool $includeDeleted = false, string $query = ''): array
    {
        $where = $includeDeleted ? [] : ['is_deleted = 0'];
        $params = [];
        if ($query !== '') {
            $where[] = '(name LIKE ? OR phone LIKE ? OR email LIKE ?)';
            $params = ["%$query%", "%$query%", "%$query%"];
        }
        $sql = 'SELECT * FROM suppliers' . ($where ? ' WHERE ' . implode(' AND ', $where) : '') . ' ORDER BY name';
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function findSupplierById(int $id): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM suppliers WHERE id = ?');
        $stmt->execute([$id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    public function saveSupplier(array $s): int
    {
        $now = date('Y-m-d H:i:s');
        $params = [
            ':name'           => $s['name'],
            ':tax_number'     => $s['tax_number'] ?? null,
            ':country'        => $s['country'] ?? null,
            ':zip'            => $s['zip'] ?? null,
            ':city'           => $s['city'] ?? null,
            ':address'        => $s['address'] ?? null,
            ':contact_name'   => $s['contact_name'] ?? null,
            ':phone'          => $s['phone'] ?? null,
            ':email'          => $s['email'] ?? null,
            ':payment_terms'  => $s['payment_terms'] ?? null,
            ':notes'          => $s['notes'] ?? null,
            ':is_deleted'     => !empty($s['is_deleted']) ? 1 : 0,
            ':now'            => $now,
        ];
        if (!empty($s['id'])) {
            $stmt = $this->pdo->prepare('
                UPDATE suppliers SET name=:name, tax_number=:tax_number, country=:country, zip=:zip,
                    city=:city, address=:address, contact_name=:contact_name, phone=:phone, email=:email,
                    payment_terms=:payment_terms, notes=:notes, is_deleted=:is_deleted, updated_at=:now
                WHERE id = :id
            ');
            $stmt->execute($params + [':id' => $s['id']]);
            return (int) $s['id'];
        }
        $stmt = $this->pdo->prepare('
            INSERT INTO suppliers (name, tax_number, country, zip, city, address, contact_name, phone, email, payment_terms, notes, is_deleted, created_at)
            VALUES (:name, :tax_number, :country, :zip, :city, :address, :contact_name, :phone, :email, :payment_terms, :notes, :is_deleted, :now)
        ');
        $stmt->execute($params);
        return (int) $this->pdo->lastInsertId();
    }

    // ---------------------------------------------------------------
    // Customers / loyalty points (törzsvásárlók, hűségpontok)
    // ---------------------------------------------------------------

    public function listCustomers(bool $includeDeleted = false, string $query = ''): array
    {
        $where = $includeDeleted ? [] : ['is_deleted = 0'];
        $params = [];
        if ($query !== '') {
            $where[] = '(name LIKE ? OR phone LIKE ? OR email LIKE ?)';
            $params = ["%$query%", "%$query%", "%$query%"];
        }
        $sql = 'SELECT * FROM customers' . ($where ? ' WHERE ' . implode(' AND ', $where) : '') . ' ORDER BY name';
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function findCustomerById(int $id): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM customers WHERE id = ?');
        $stmt->execute([$id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    public function saveCustomer(array $c): int
    {
        $now = date('Y-m-d H:i:s');
        $params = [
            ':name'       => $c['name'],
            ':phone'      => $c['phone'] ?? null,
            ':email'      => $c['email'] ?? null,
            ':tax_number' => $c['tax_number'] ?? null,
            ':zip'        => $c['zip'] ?? null,
            ':city'       => $c['city'] ?? null,
            ':address'    => $c['address'] ?? null,
            ':country'    => $c['country'] ?? null,
            ':notes'      => $c['notes'] ?? null,
            ':is_deleted' => !empty($c['is_deleted']) ? 1 : 0,
            ':now'        => $now,
        ];
        if (!empty($c['id'])) {
            $stmt = $this->pdo->prepare('
                UPDATE customers SET name=:name, phone=:phone, email=:email, tax_number=:tax_number,
                    zip=:zip, city=:city, address=:address, country=:country,
                    notes=:notes, is_deleted=:is_deleted, updated_at=:now
                WHERE id = :id
            ');
            $stmt->execute($params + [':id' => $c['id']]);
            return (int) $c['id'];
        }
        $stmt = $this->pdo->prepare('
            INSERT INTO customers (name, phone, email, tax_number, zip, city, address, country, notes, is_deleted, loyalty_points, created_at)
            VALUES (:name, :phone, :email, :tax_number, :zip, :city, :address, :country, :notes, :is_deleted, 0, :now)
        ');
        $stmt->execute($params);
        return (int) $this->pdo->lastInsertId();
    }

    public function searchCustomersForTill(string $query): array
    {
        $stmt = $this->pdo->prepare("
            SELECT id, name, phone, email, tax_number, zip, city, address, country, loyalty_points FROM customers
            WHERE is_deleted = 0 AND (name LIKE ? OR phone LIKE ?)
            ORDER BY name LIMIT 10
        ");
        $stmt->execute(["%$query%", "%$query%"]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function applyLoyaltyPoints(int $customerId, int $delta, ?int $saleId, string $note): int
    {
        $customer = $this->findCustomerById($customerId);
        if (!$customer) {
            return 0;
        }
        $newBalance = max(0, (int) $customer['loyalty_points'] + $delta);
        $actualDelta = $newBalance - (int) $customer['loyalty_points'];

        $this->pdo->prepare('UPDATE customers SET loyalty_points = ?, updated_at = ? WHERE id = ?')
            ->execute([$newBalance, date('Y-m-d H:i:s'), $customerId]);

        if ($actualDelta !== 0) {
            $this->pdo->prepare('
                INSERT INTO loyalty_transactions (customer_id, sale_id, points_delta, note, created_at)
                VALUES (?, ?, ?, ?, ?)
            ')->execute([$customerId, $saleId, $actualDelta, $note, date('Y-m-d H:i:s')]);
        }

        return $newBalance;
    }

    public function getLoyaltyHistory(int $customerId, int $limit = 100): array
    {
        $stmt = $this->pdo->prepare('
            SELECT * FROM loyalty_transactions WHERE customer_id = ? ORDER BY created_at DESC LIMIT ?
        ');
        $stmt->bindValue(1, $customerId, PDO::PARAM_INT);
        $stmt->bindValue(2, $limit, PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    // ---------------------------------------------------------------
    // Rendszerállapot (system status)
    // ---------------------------------------------------------------

    public function countLowStockProducts(int $defaultThreshold): int
    {
        $stmt = $this->pdo->prepare('
            SELECT COUNT(*) FROM products
            WHERE is_deleted = 0
              AND stock_qty <= COALESCE(low_stock_threshold, ?)
        ');
        $stmt->execute([$defaultThreshold]);
        return (int) $stmt->fetchColumn();
    }

    public function countRecentInvoiceFailures(int $days = 7): int
    {
        $cutoff = date('Y-m-d H:i:s', strtotime("-$days days"));
        $stmt = $this->pdo->prepare("SELECT COUNT(*) FROM sales WHERE status = 'invoice_failed' AND created_at >= ?");
        $stmt->execute([$cutoff]);
        return (int) $stmt->fetchColumn();
    }

    public function getRecentSyncLog(int $limit = 20): array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM sync_log ORDER BY created_at DESC LIMIT ?');
        $stmt->bindValue(1, $limit, PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function countRecentSyncFailures(int $hours = 24): int
    {
        $cutoff = date('Y-m-d H:i:s', strtotime("-$hours hours"));
        $stmt = $this->pdo->prepare("SELECT COUNT(*) FROM sync_log WHERE message LIKE 'FAILED%' AND created_at >= ?");
        $stmt->execute([$cutoff]);
        return (int) $stmt->fetchColumn();
    }

    public function countProducts(): int
    {
        return (int) $this->pdo->query('SELECT COUNT(*) FROM products WHERE is_deleted = 0')->fetchColumn();
    }

    public function countSalesToday(): int
    {
        $dateExpr = $this->driver === 'mysql' ? 'DATE(created_at)' : "substr(created_at, 1, 10)";
        $stmt = $this->pdo->prepare("SELECT COUNT(*) FROM sales WHERE $dateExpr = ?");
        $stmt->execute([date('Y-m-d')]);
        return (int) $stmt->fetchColumn();
    }

    // ---------------------------------------------------------------
    // Ártörténet (price history)
    // ---------------------------------------------------------------

    public function logPriceChange(int $productId, float $oldNet, float $oldGross, float $newNet, float $newGross): void
    {
        $stmt = $this->pdo->prepare('
            INSERT INTO price_history (product_id, old_net_price, old_price, new_net_price, new_price, changed_at)
            VALUES (?, ?, ?, ?, ?, ?)
        ');
        $stmt->execute([$productId, $oldNet, $oldGross, $newNet, $newGross, date('Y-m-d H:i:s')]);
    }

    public function getPriceHistory(int $productId, int $limit = 50): array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM price_history WHERE product_id = ? ORDER BY changed_at DESC LIMIT ?');
        $stmt->bindValue(1, $productId, PDO::PARAM_INT);
        $stmt->bindValue(2, $limit, PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    // ---------------------------------------------------------------
    // Kedvezménykód / kupon (coupons)
    // ---------------------------------------------------------------

    public function listCoupons(): array
    {
        return $this->pdo->query('SELECT * FROM coupons ORDER BY created_at DESC')->fetchAll(PDO::FETCH_ASSOC);
    }

    public function findCouponByCode(string $code): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM coupons WHERE code = ?');
        $stmt->execute([strtoupper(trim($code))]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    public function saveCoupon(array $c): int
    {
        $now = date('Y-m-d H:i:s');
        $params = [
            ':code'         => strtoupper(trim($c['code'])),
            ':type'         => $c['type'] === 'fixed' ? 'fixed' : 'percent',
            ':value'        => (float) $c['value'],
            ':is_active'    => !empty($c['is_active']) ? 1 : 0,
            ':expiry_date'  => $c['expiry_date'] ?: null,
            ':usage_limit'  => $c['usage_limit'] !== '' && $c['usage_limit'] !== null ? (int) $c['usage_limit'] : null,
            ':min_purchase' => (float) ($c['min_purchase'] ?? 0),
            ':notes'        => $c['notes'] ?? null,
        ];
        if (!empty($c['id'])) {
            $stmt = $this->pdo->prepare('
                UPDATE coupons SET code=:code, type=:type, value=:value, is_active=:is_active,
                    expiry_date=:expiry_date, usage_limit=:usage_limit, min_purchase=:min_purchase, notes=:notes
                WHERE id = :id
            ');
            $stmt->execute($params + [':id' => $c['id']]);
            return (int) $c['id'];
        }
        $stmt = $this->pdo->prepare('
            INSERT INTO coupons (code, type, value, is_active, expiry_date, usage_limit, min_purchase, notes, created_at)
            VALUES (:code, :type, :value, :is_active, :expiry_date, :usage_limit, :min_purchase, :notes, :now)
        ');
        $stmt->execute($params + [':now' => $now]);
        return (int) $this->pdo->lastInsertId();
    }

    public function validateCoupon(string $code, float $purchaseTotal): array
    {
        $coupon = $this->findCouponByCode($code);
        if (!$coupon) {
            return ['ok' => false, 'error' => 'Nincs ilyen kuponkód.'];
        }
        if (!(int) $coupon['is_active']) {
            return ['ok' => false, 'error' => 'Ez a kupon már nem aktív.'];
        }
        if (!empty($coupon['expiry_date']) && $coupon['expiry_date'] < date('Y-m-d')) {
            return ['ok' => false, 'error' => 'Ez a kupon lejárt.'];
        }
        if ($coupon['usage_limit'] !== null && (int) $coupon['times_used'] >= (int) $coupon['usage_limit']) {
            return ['ok' => false, 'error' => 'Ezt a kupont már elérte a felhasználási korlát.'];
        }
        if ($purchaseTotal < (float) $coupon['min_purchase']) {
            return ['ok' => false, 'error' => 'A minimum vásárlási összeg ehhez a kuponhoz: ' . number_format((float) $coupon['min_purchase'], 0, ',', ' ') . ' Ft.'];
        }

        $discount = $coupon['type'] === 'fixed'
            ? min((float) $coupon['value'], $purchaseTotal)
            : round($purchaseTotal * ((float) $coupon['value'] / 100), 2);

        return ['ok' => true, 'coupon' => $coupon, 'discount' => $discount];
    }

    public function incrementCouponUsage(int $couponId): void
    {
        $this->pdo->prepare('UPDATE coupons SET times_used = times_used + 1 WHERE id = ?')->execute([$couponId]);
    }

    // ---------------------------------------------------------------
    // Ajándékutalvány (gift cards)
    // ---------------------------------------------------------------

    public function listGiftCards(): array
    {
        return $this->pdo->query('SELECT * FROM gift_cards ORDER BY created_at DESC')->fetchAll(PDO::FETCH_ASSOC);
    }

    public function findGiftCardByCode(string $code): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM gift_cards WHERE code = ?');
        $stmt->execute([strtoupper(trim($code))]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    public function issueGiftCard(string $code, float $balance, ?string $expiryDate, ?string $notes): int
    {
        $now = date('Y-m-d H:i:s');
        $stmt = $this->pdo->prepare('
            INSERT INTO gift_cards (code, initial_balance, current_balance, is_active, expiry_date, notes, created_at)
            VALUES (?, ?, ?, 1, ?, ?, ?)
        ');
        $stmt->execute([strtoupper(trim($code)), $balance, $balance, $expiryDate ?: null, $notes, $now]);
        $id = (int) $this->pdo->lastInsertId();

        $this->pdo->prepare('INSERT INTO gift_card_transactions (gift_card_id, sale_id, amount_delta, note, created_at) VALUES (?, NULL, ?, ?, ?)')
            ->execute([$id, $balance, 'Kiállítva', $now]);

        return $id;
    }

    public function setGiftCardActive(int $id, bool $active): void
    {
        $this->pdo->prepare('UPDATE gift_cards SET is_active = ? WHERE id = ?')->execute([$active ? 1 : 0, $id]);
    }

    public function validateGiftCard(string $code, float $purchaseTotal): array
    {
        $card = $this->findGiftCardByCode($code);
        if (!$card) {
            return ['ok' => false, 'error' => 'Nincs ilyen ajándékutalvány-kód.'];
        }
        if (!(int) $card['is_active']) {
            return ['ok' => false, 'error' => 'Ez az utalvány inaktív.'];
        }
        if (!empty($card['expiry_date']) && $card['expiry_date'] < date('Y-m-d')) {
            return ['ok' => false, 'error' => 'Ez az utalvány lejárt.'];
        }
        if ((float) $card['current_balance'] <= 0) {
            return ['ok' => false, 'error' => 'Ezen az utalványon nincs elkölthető egyenleg.'];
        }

        $redeemable = min((float) $card['current_balance'], $purchaseTotal);
        return ['ok' => true, 'gift_card' => $card, 'redeemable' => $redeemable];
    }

    public function redeemGiftCard(int $giftCardId, float $amount, ?int $saleId): float
    {
        $card = $this->pdo->prepare('SELECT current_balance FROM gift_cards WHERE id = ?');
        $card->execute([$giftCardId]);
        $current = (float) $card->fetchColumn();
        $newBalance = max(0, round($current - $amount, 2));
        $actualDelta = $newBalance - $current;

        $this->pdo->prepare('UPDATE gift_cards SET current_balance = ? WHERE id = ?')->execute([$newBalance, $giftCardId]);
        $this->pdo->prepare('INSERT INTO gift_card_transactions (gift_card_id, sale_id, amount_delta, note, created_at) VALUES (?, ?, ?, ?, ?)')
            ->execute([$giftCardId, $saleId, $actualDelta, 'Beváltva' . ($saleId ? " eladás #$saleId-nél" : ''), date('Y-m-d H:i:s')]);

        return $newBalance;
    }

    public function getGiftCardHistory(int $giftCardId, int $limit = 50): array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM gift_card_transactions WHERE gift_card_id = ? ORDER BY created_at DESC LIMIT ?');
        $stmt->bindValue(1, $giftCardId, PDO::PARAM_INT);
        $stmt->bindValue(2, $limit, PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    // ---------------------------------------------------------------
    // Vonalkód-generálás (barcode generation)
    // ---------------------------------------------------------------

    public function generateUniqueBarcode(): string
    {
        do {
            $twelve = '20' . str_pad((string) random_int(0, 9999999999), 10, '0', STR_PAD_LEFT);
            $candidate = $twelve . $this->ean13CheckDigit($twelve);
        } while ($this->findProductByBarcode($candidate) !== null);

        return $candidate;
    }

    private function ean13CheckDigit(string $twelveDigits): int
    {
        $sum = 0;
        for ($i = 0; $i < 12; $i++) {
            $digit = (int) $twelveDigits[$i];
            $sum += ($i % 2 === 0) ? $digit : $digit * 3;
        }
        return (10 - ($sum % 10)) % 10;
    }

    // ---------------------------------------------------------------
    // Dolgozók / PIN-kód (staff)
    // ---------------------------------------------------------------

    public function listStaff(bool $includeInactive = false): array
    {
        $sql = 'SELECT id, name, role, is_active, created_at FROM staff';
        if (!$includeInactive) {
            $sql .= ' WHERE is_active = 1';
        }
        return $this->pdo->query($sql . ' ORDER BY name')->fetchAll(PDO::FETCH_ASSOC);
    }

    public function saveStaff(array $s): int
    {
        $now = date('Y-m-d H:i:s');
        $role = ($s['role'] ?? 'cashier') === 'admin' ? 'admin' : 'cashier';
        if (!empty($s['id'])) {
            if (!empty($s['pin'])) {
                $this->pdo->prepare('UPDATE staff SET name = ?, pin_hash = ?, role = ?, is_active = ? WHERE id = ?')
                    ->execute([$s['name'], password_hash((string) $s['pin'], PASSWORD_DEFAULT), $role, !empty($s['is_active']) ? 1 : 0, $s['id']]);
            } else {
                $this->pdo->prepare('UPDATE staff SET name = ?, role = ?, is_active = ? WHERE id = ?')
                    ->execute([$s['name'], $role, !empty($s['is_active']) ? 1 : 0, $s['id']]);
            }
            return (int) $s['id'];
        }
        $stmt = $this->pdo->prepare('INSERT INTO staff (name, pin_hash, role, is_active, created_at) VALUES (?, ?, ?, 1, ?)');
        $stmt->execute([$s['name'], password_hash((string) $s['pin'], PASSWORD_DEFAULT), $role, $now]);
        return (int) $this->pdo->lastInsertId();
    }

    public function verifyStaffPin(string $pin): ?array
    {
        $stmt = $this->pdo->query('SELECT id, name, role, pin_hash FROM staff WHERE is_active = 1');
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $staff) {
            if (password_verify($pin, $staff['pin_hash'])) {
                unset($staff['pin_hash']);
                return $staff;
            }
        }
        return null;
    }

    public function findStaffById(int $id): ?array
    {
        $stmt = $this->pdo->prepare('SELECT id, name, role, is_active FROM staff WHERE id = ?');
        $stmt->execute([$id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    public function isStaffAdmin(?int $staffId): bool
    {
        if (!$staffId) {
            return false;
        }
        $staff = $this->findStaffById($staffId);
        return $staff !== null && $staff['role'] === 'admin';
    }

    // ---------------------------------------------------------------
    // Részleges visszáru / sztornó (returns)
    // ---------------------------------------------------------------

    public function processReturn(int $saleId, array $items, string $reason, ?int $staffId, float $totalRefund): int
    {
        $this->beginTransaction();
        try {
            $stmt = $this->pdo->prepare('
                INSERT INTO returns (sale_id, staff_id, total_refund, reason, created_at)
                VALUES (?, ?, ?, ?, ?)
            ');
            $stmt->execute([$saleId, $staffId, $totalRefund, $reason, date('Y-m-d H:i:s')]);
            $returnId = (int) $this->pdo->lastInsertId();

            foreach ($items as $item) {
                $this->pdo->prepare('
                    INSERT INTO return_items (return_id, sale_item_id, product_id, name, qty, unit_price, created_at)
                    VALUES (?, ?, ?, ?, ?, ?, ?)
                ')->execute([
                    $returnId, $item['sale_item_id'] ?? null, $item['product_id'] ?? null,
                    $item['name'], $item['qty'], $item['unit_price'], date('Y-m-d H:i:s'),
                ]);

                if (!empty($item['product_id'])) {
                    $this->incrementStock((int) $item['product_id'], (int) $item['qty']);
                }
            }

            $this->commit();
            return $returnId;
        } catch (Throwable $e) {
            $this->rollBack();
            throw $e;
        }
    }

    public function setReturnCreditInvoice(int $returnId, string $invoiceNumber): void
    {
        $this->pdo->prepare('UPDATE returns SET credit_invoice_number = ? WHERE id = ?')->execute([$invoiceNumber, $returnId]);
    }

    public function getReturnsForSale(int $saleId): array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM returns WHERE sale_id = ? ORDER BY created_at DESC');
        $stmt->execute([$saleId]);
        $returns = $stmt->fetchAll(PDO::FETCH_ASSOC);

        foreach ($returns as &$return) {
            $itemStmt = $this->pdo->prepare('SELECT * FROM return_items WHERE return_id = ?');
            $itemStmt->execute([$return['id']]);
            $return['items'] = $itemStmt->fetchAll(PDO::FETCH_ASSOC);
        }

        return $returns;
    }

    public function getReturnedQuantitiesForSale(int $saleId): array
    {
        $stmt = $this->pdo->prepare('
            SELECT ri.sale_item_id, SUM(ri.qty) AS returned_qty
            FROM return_items ri
            JOIN returns r ON r.id = ri.return_id
            WHERE r.sale_id = ?
            GROUP BY ri.sale_item_id
        ');
        $stmt->execute([$saleId]);
        $result = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $result[(int) $row['sale_item_id']] = (int) $row['returned_qty'];
        }
        return $result;
    }

    // ---------------------------------------------------------------
    // Leltározás (stock take)
    // ---------------------------------------------------------------

    public function startStockTake(?int $staffId, string $notes): int
    {
        $stmt = $this->pdo->prepare('INSERT INTO stock_takes (staff_id, notes, started_at) VALUES (?, ?, ?)');
        $stmt->execute([$staffId, $notes, date('Y-m-d H:i:s')]);
        $takeId = (int) $this->pdo->lastInsertId();

        $products = $this->pdo->query('SELECT id, stock_qty FROM products WHERE is_deleted = 0')->fetchAll(PDO::FETCH_ASSOC);
        $itemStmt = $this->pdo->prepare('INSERT INTO stock_take_items (stock_take_id, product_id, expected_qty, created_at) VALUES (?, ?, ?, ?)');
        $now = date('Y-m-d H:i:s');
        foreach ($products as $p) {
            $itemStmt->execute([$takeId, $p['id'], $p['stock_qty'], $now]);
        }

        return $takeId;
    }

    public function listStockTakes(): array
    {
        return $this->pdo->query('SELECT * FROM stock_takes ORDER BY started_at DESC')->fetchAll(PDO::FETCH_ASSOC);
    }

    public function getStockTake(int $id): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM stock_takes WHERE id = ?');
        $stmt->execute([$id]);
        $take = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$take) {
            return null;
        }

        $stmt = $this->pdo->prepare('
            SELECT sti.*, p.name, p.barcode, p.cikkszam
            FROM stock_take_items sti
            JOIN products p ON p.id = sti.product_id
            WHERE sti.stock_take_id = ?
            ORDER BY p.name
        ');
        $stmt->execute([$id]);
        $take['items'] = $stmt->fetchAll(PDO::FETCH_ASSOC);

        return $take;
    }

    public function updateStockTakeCount(int $stockTakeId, int $productId, ?int $countedQty): void
    {
        $this->pdo->prepare('UPDATE stock_take_items SET counted_qty = ? WHERE stock_take_id = ? AND product_id = ?')
            ->execute([$countedQty, $stockTakeId, $productId]);
    }

    public function completeStockTake(int $id, bool $applyCorrections): void
    {
        $this->beginTransaction();
        try {
            if ($applyCorrections) {
                $stmt = $this->pdo->prepare('SELECT product_id, counted_qty FROM stock_take_items WHERE stock_take_id = ? AND counted_qty IS NOT NULL');
                $stmt->execute([$id]);
                $updateStmt = $this->pdo->prepare('UPDATE products SET stock_qty = ? WHERE id = ?');
                foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
                    $updateStmt->execute([$row['counted_qty'], $row['product_id']]);
                }
            }
            $this->pdo->prepare('UPDATE stock_takes SET completed_at = ? WHERE id = ?')->execute([date('Y-m-d H:i:s'), $id]);
            $this->commit();
        } catch (Throwable $e) {
            $this->rollBack();
            throw $e;
        }
    }

    public function getDailyRevenueTrend(int $days = 30): array
    {
        $dateExpr = $this->driver === 'mysql' ? 'DATE(created_at)' : "substr(created_at, 1, 10)";
        $since = date('Y-m-d', strtotime("-" . ($days - 1) . " days"));

        $stmt = $this->pdo->prepare("
            SELECT $dateExpr AS day, SUM(total) AS total, COUNT(*) AS cnt
            FROM sales
            WHERE $dateExpr >= ?
            GROUP BY $dateExpr
        ");
        $stmt->execute([$since]);
        $byDate = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $byDate[$row['day']] = ['total' => (float) $row['total'], 'count' => (int) $row['cnt']];
        }

        $result = [];
        for ($i = $days - 1; $i >= 0; $i--) {
            $day = date('Y-m-d', strtotime("-$i days"));
            $result[] = [
                'date'  => $day,
                'total' => $byDate[$day]['total'] ?? 0.0,
                'count' => $byDate[$day]['count'] ?? 0,
            ];
        }

        return $result;
    }

    // ---------------------------------------------------------------
    // Tevékenységnapló (audit log)
    // ---------------------------------------------------------------

    public function logAudit(?int $staffId, string $action, ?string $entityType, ?int $entityId, ?string $details, int $retentionDays): void
    {
        $this->pdo->prepare('
            INSERT INTO audit_log (staff_id, action, entity_type, entity_id, details, created_at)
            VALUES (?, ?, ?, ?, ?, ?)
        ')->execute([$staffId, $action, $entityType, $entityId, $details, date('Y-m-d H:i:s')]);

        $cutoff = date('Y-m-d H:i:s', strtotime("-$retentionDays days"));
        $this->pdo->prepare('DELETE FROM audit_log WHERE created_at < ?')->execute([$cutoff]);
    }

    public function getAuditLog(int $limit = 200): array
    {
        $stmt = $this->pdo->prepare('
            SELECT al.*, s.name AS staff_name
            FROM audit_log al
            LEFT JOIN staff s ON s.id = al.staff_id
            ORDER BY al.created_at DESC
            LIMIT ?
        ');
        $stmt->bindValue(1, $limit, PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    // ---------------------------------------------------------------
    // Hűségszintek (loyalty tiers)
    // ---------------------------------------------------------------

    public function addCustomerSpend(int $customerId, float $amount): void
    {
        $this->pdo->prepare('UPDATE customers SET total_spent = total_spent + ? WHERE id = ?')->execute([$amount, $customerId]);
    }

    // ---------------------------------------------------------------
    // Globális kereső (global search)
    // ---------------------------------------------------------------

    public function globalSearch(string $query, int $limitEach = 5): array
    {
        $like = "%$query%";

        $productsStmt = $this->pdo->prepare('
            SELECT id, name, barcode, cikkszam, price, stock_qty FROM products
            WHERE is_deleted = 0 AND (name LIKE ? OR barcode LIKE ? OR cikkszam LIKE ?)
            ORDER BY name LIMIT ?
        ');
        $productsStmt->bindValue(1, $like);
        $productsStmt->bindValue(2, $like);
        $productsStmt->bindValue(3, $like);
        $productsStmt->bindValue(4, $limitEach, PDO::PARAM_INT);
        $productsStmt->execute();

        $customersStmt = $this->pdo->prepare('
            SELECT id, name, phone, email FROM customers
            WHERE is_deleted = 0 AND (name LIKE ? OR phone LIKE ?)
            ORDER BY name LIMIT ?
        ');
        $customersStmt->bindValue(1, $like);
        $customersStmt->bindValue(2, $like);
        $customersStmt->bindValue(3, $limitEach, PDO::PARAM_INT);
        $customersStmt->execute();

        $salesResults = [];
        if (ctype_digit($query)) {
            $salesStmt = $this->pdo->prepare('SELECT id, total, created_at, buyer_name FROM sales WHERE id = ? LIMIT 1');
            $salesStmt->execute([(int) $query]);
            $salesResults = $salesStmt->fetchAll(PDO::FETCH_ASSOC);
        }

        return [
            'products'  => $productsStmt->fetchAll(PDO::FETCH_ASSOC),
            'customers' => $customersStmt->fetchAll(PDO::FETCH_ASSOC),
            'sales'     => $salesResults,
        ];
    }

    // ---------------------------------------------------------------
    // Automatikus beszerzési javaslat (purchase suggestions)
    // ---------------------------------------------------------------

    /**
     * Alacsony készletű termékek, preferált beszállító szerint csoportosítva
     * (aminek nincs preferált beszállítója, az a "nincs beszállító"
     * csoportba kerül). A javasolt mennyiség egyszerű ökölszabály — vissza
     * a küszöbérték duplájára — nem valódi kereslet-előrejelzés, csak
     * egy ésszerű kiindulópont, amit a beszerzést végző módosíthat.
     */
    public function getPurchaseSuggestions(int $defaultThreshold): array
    {
        $stmt = $this->pdo->prepare('
            SELECT p.id, p.name, p.barcode, p.stock_qty, p.low_stock_threshold, p.preferred_supplier_id,
                   s.name AS supplier_name
            FROM products p
            LEFT JOIN suppliers s ON s.id = p.preferred_supplier_id
            WHERE p.is_deleted = 0
              AND p.stock_qty <= COALESCE(p.low_stock_threshold, ?)
            ORDER BY s.name IS NULL, s.name, p.name
        ');
        $stmt->execute([$defaultThreshold]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $groups = [];
        foreach ($rows as $row) {
            $threshold = $row['low_stock_threshold'] !== null ? (int) $row['low_stock_threshold'] : $defaultThreshold;
            $suggestedQty = max(1, ($threshold * 2) - (int) $row['stock_qty']);

            $key = $row['preferred_supplier_id'] ?? 'none';
            if (!isset($groups[$key])) {
                $groups[$key] = [
                    'supplier_id'   => $row['preferred_supplier_id'] ? (int) $row['preferred_supplier_id'] : null,
                    'supplier_name' => $row['supplier_name'] ?? 'Nincs beszállító beállítva',
                    'products'      => [],
                ];
            }
            $groups[$key]['products'][] = [
                'id' => (int) $row['id'], 'name' => $row['name'], 'barcode' => $row['barcode'],
                'stock_qty' => (int) $row['stock_qty'], 'threshold' => $threshold, 'suggested_qty' => $suggestedQty,
            ];
        }

        return array_values($groups);
    }

    // ---------------------------------------------------------------
    // Több telephely / raktár (multi-location)
    // ---------------------------------------------------------------

    public function listLocations(): array
    {
        return $this->pdo->query('SELECT * FROM locations ORDER BY is_default DESC, name')->fetchAll(PDO::FETCH_ASSOC);
    }

    public function saveLocation(array $l): int
    {
        $now = date('Y-m-d H:i:s');
        if (!empty($l['is_default'])) {
            $this->pdo->exec('UPDATE locations SET is_default = 0');
        }
        if (!empty($l['id'])) {
            $this->pdo->prepare('UPDATE locations SET name = ?, address = ?, is_default = ? WHERE id = ?')
                ->execute([$l['name'], $l['address'] ?? null, !empty($l['is_default']) ? 1 : 0, $l['id']]);
            return (int) $l['id'];
        }
        $stmt = $this->pdo->prepare('INSERT INTO locations (name, address, is_default, created_at) VALUES (?, ?, ?, ?)');
        $stmt->execute([$l['name'], $l['address'] ?? null, !empty($l['is_default']) ? 1 : 0, $now]);
        return (int) $this->pdo->lastInsertId();
    }

    public function getLocationStockForProduct(int $productId): array
    {
        $stmt = $this->pdo->prepare('
            SELECT l.id AS location_id, l.name AS location_name, COALESCE(ls.stock_qty, 0) AS stock_qty
            FROM locations l
            LEFT JOIN location_stock ls ON ls.location_id = l.id AND ls.product_id = ?
            ORDER BY l.is_default DESC, l.name
        ');
        $stmt->execute([$productId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function transferStock(int $productId, ?int $fromLocationId, int $toLocationId, int $qty, ?int $staffId): void
    {
        $this->beginTransaction();
        try {
            if ($fromLocationId) {
                $this->adjustLocationStock($productId, $fromLocationId, -$qty);
            }
            $this->adjustLocationStock($productId, $toLocationId, $qty);

            $this->pdo->prepare('
                INSERT INTO stock_transfers (product_id, from_location_id, to_location_id, qty, staff_id, created_at)
                VALUES (?, ?, ?, ?, ?, ?)
            ')->execute([$productId, $fromLocationId, $toLocationId, $qty, $staffId, date('Y-m-d H:i:s')]);

            $this->commit();
        } catch (Throwable $e) {
            $this->rollBack();
            throw $e;
        }
    }

    private function adjustLocationStock(int $productId, int $locationId, int $delta): void
    {
        $stmt = $this->pdo->prepare('SELECT stock_qty FROM location_stock WHERE product_id = ? AND location_id = ?');
        $stmt->execute([$productId, $locationId]);
        $current = $stmt->fetchColumn();

        if ($current === false) {
            $this->pdo->prepare('INSERT INTO location_stock (product_id, location_id, stock_qty) VALUES (?, ?, ?)')
                ->execute([$productId, $locationId, $delta]);
            return;
        }

        $this->pdo->prepare('UPDATE location_stock SET stock_qty = ? WHERE product_id = ? AND location_id = ?')
            ->execute([(int) $current + $delta, $productId, $locationId]);
    }

    public function decrementLocationStock(int $productId, int $locationId, int $qty): void
    {
        $this->adjustLocationStock($productId, $locationId, -$qty);
    }

    public function getStockTransferHistory(int $limit = 100): array
    {
        $stmt = $this->pdo->prepare('
            SELECT st.*, p.name AS product_name, fl.name AS from_name, tl.name AS to_name
            FROM stock_transfers st
            JOIN products p ON p.id = st.product_id
            LEFT JOIN locations fl ON fl.id = st.from_location_id
            JOIN locations tl ON tl.id = st.to_location_id
            ORDER BY st.created_at DESC
            LIMIT ?
        ');
        $stmt->bindValue(1, $limit, PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /** Top-selling products by total quantity sold (last 90 days) — for the Kassza "gyakran vásárolt" quick-add row. */
    public function getTopSellingProducts(int $limit = 8): array
    {
        $since = date('Y-m-d H:i:s', strtotime('-90 days'));
        $stmt = $this->pdo->prepare('
            SELECT p.id, p.name, p.barcode, p.price, p.stock_qty, SUM(si.qty) AS total_qty
            FROM sale_items si
            JOIN products p ON p.id = si.product_id
            JOIN sales s ON s.id = si.sale_id
            WHERE p.is_deleted = 0 AND s.created_at >= ?
            GROUP BY p.id
            ORDER BY total_qty DESC
            LIMIT ?
        ');
        $stmt->bindValue(1, $since);
        $stmt->bindValue(2, $limit, PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    // ---------------------------------------------------------------
    // Ügyféllista — vásárlói statisztika és vásárlási előzmény
    // ---------------------------------------------------------------

    public function getCustomerStats(int $customerId): array
    {
        $stmt = $this->pdo->prepare('
            SELECT COUNT(*) AS purchase_count, AVG(total) AS avg_purchase,
                   MIN(created_at) AS first_purchase_at, MAX(created_at) AS last_purchase_at
            FROM sales
            WHERE customer_id = ?
        ');
        $stmt->execute([$customerId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];

        return [
            'purchase_count'    => (int) ($row['purchase_count'] ?? 0),
            'avg_purchase'      => (float) ($row['avg_purchase'] ?? 0),
            'first_purchase_at' => $row['first_purchase_at'] ?? null,
            'last_purchase_at'  => $row['last_purchase_at'] ?? null,
        ];
    }

    public function getCustomerPurchasedItems(int $customerId, int $limit = 100): array
    {
        $stmt = $this->pdo->prepare('
            SELECT si.name, si.qty, si.unit_price, si.vat_rate, s.id AS sale_id, s.created_at
            FROM sale_items si
            JOIN sales s ON s.id = si.sale_id
            WHERE s.customer_id = ?
            ORDER BY s.created_at DESC
            LIMIT ?
        ');
        $stmt->bindValue(1, $customerId, PDO::PARAM_INT);
        $stmt->bindValue(2, $limit, PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
}
