<?php

require_once __DIR__ . '/AppVersion.php';
require_once __DIR__ . '/InvoiceNumbering.php';
require_once __DIR__ . '/PriceValidator.php';
require_once __DIR__ . '/PurchaseDecisionService.php';

class Database
{
    private const SCHEMA_VERSION = 26;

    private PDO $pdo;
    private string $driver;
    private array $dbConfig;

    public function __construct(array $dbConfig, string $schemaDir)
    {
        $this->driver = $dbConfig['driver'] ?? 'sqlite';
        $this->dbConfig = $dbConfig;
        $this->pdo = $this->connect();

        $schemaPath = rtrim($schemaDir, '/') . '/' . ($this->driver === 'mysql' ? 'schema.mysql.sql' : 'schema.sql');
        $this->ensureSchema($schemaPath);
    }

    private function connect(): PDO
    {
        if ($this->driver === 'mysql') {
            $m = $this->dbConfig['mysql'];
            $dsn = sprintf(
                'mysql:host=%s;port=%d;dbname=%s;charset=%s',
                $m['host'],
                $m['port'] ?? 3306,
                $m['database'],
                $m['charset'] ?? 'utf8mb4'
            );
            return new PDO($dsn, $m['username'], $m['password'], [
                PDO::ATTR_ERRMODE                  => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_EMULATE_PREPARES         => false,
                PDO::MYSQL_ATTR_INIT_COMMAND       => "SET NAMES {$m['charset']}",
            ]);
        }

        $sqlitePath = $this->dbConfig['sqlite']['path'];
        $pdo = new PDO('sqlite:' . $sqlitePath);
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->exec('PRAGMA foreign_keys = ON;');
        $pdo->exec('PRAGMA journal_mode = WAL;');
        $pdo->exec('PRAGMA busy_timeout = 5000;');
        $pdo->exec('PRAGMA synchronous = NORMAL;');
        return $pdo;
    }

    /**
     * Új PDO-kapcsolat nyitása UGYANARRA a fájlra/konfigurációra — a
     * meglévő kapcsolat eldobása, `ensureSchema()` ÚJRAFUTTATÁSA NÉLKÜL
     * (a sémát nem kell újra ellenőrizni, csak a kapcsolat-objektum
     * elavult állapotát). Hívandó `closeForExternalFileReplacement()`
     * UTÁN (lásd ott a teljes indoklást), a fájl-csere befejeztével.
     */
    public function reconnect(): void
    {
        $this->pdo = $this->connect();
    }

    /**
     * A JELENLEGI PDO-kapcsolat TÉNYLEGES lezárása (nem csak lecserélése)
     * — a hívónak `reconnect()`-tel kell ÚJRANYITNIA, mihelyt a mögöttes
     * fájl-csere befejeződött. Eközben MINDEN Database-hívás
     * (Error: "Typed property Database::$pdo must not be accessed before
     * initialization") hangosan elbukik — ez SZÁNDÉKOS: jelzi, ha valami
     * véletlenül DB-műveletet próbálna végezni a fájl-csere KÖZBEN.
     *
     * 1.1.1 — erre a hívónak (UpdateInstaller::install(), a MIGRÁCIÓ
     * ELŐTTI DB-mentés rollback-kori visszaállítása KÖRÜL, lásd ott)
     * KÖTELEZŐEN szüksége van: `BackupManager::restoreFromFile()` a
     * SQLite fájlt egy NYERS `copy()`-val írja felül — ha eközben EBBEN a
     * PHP-folyamatban egy MÁSIK, még nyitva lévő PDO/SQLite-kapcsolat is
     * memory-mappelve/megnyitva tartja UGYANAZT a fájlt (WAL-módban ez az
     * alapértelmezett), a nyers felülírás a MEGLÉVŐ kapcsolat oldal-
     * gyorsítótárával/leképezésével ütközve VALÓS fájlsérülést
     * okozhat, amit egy PUSZTA reconnect() a copy() UTÁN már nem tud
     * visszamenőleg orvosolni (a fájl MAGA sérült meg, nem csak a
     * kapcsolat-objektum elavult) — élesben reprodukálva:
     * "SQLSTATE[HY000]: General error: 11 database disk image is
     * malformed", determinisztikusan minden alkalommal (lásd
     * tests/UpdateInstallerTest.php::testHealthCheckFailureTriggersFullRollback).
     * A kapcsolat TÉNYLEGES, előzetes lezárása (nem csak lecserélése)
     * garantálja, hogy a raw `copy()` idején SEMMILYEN nyitott handle ne
     * ütközzön a fájlon ebben a folyamatban.
     */
    public function closeForExternalFileReplacement(): void
    {
        unset($this->pdo);
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
            if ($version < 16) {
                $this->migrateV16WebshopOrders();
            }
            if ($version < 17) {
                $this->migrateV17SaleIdempotency();
            }
            if ($version < 18) {
                $this->migrateV18SaleIdempotencyFingerprint();
            }
            if ($version < 19) {
                $this->migrateV19Invoices();
            }
            if ($version < 20) {
                $this->migrateV20IncomingInvoices();
            }
            if ($version < 21) {
                $this->migrateV21Updates();
            }
            if ($version < 22) {
                $this->migrateV22InvoiceOperations();
            }
            if ($version < 23) {
                $this->migrateV23PurchaseIdempotency();
            }
            if ($version < 24) {
                $this->migrateV24WcPushQueue();
            }
            if ($version < 25) {
                $this->migrateV25ReportingIndexes();
            }
            if ($version < 26) {
                $this->migrateV26SystemEvents();
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
        // Ez az UTOLSÓ lépés a migrációban — ha ez maga hibázik, a DB
        // ténylegesen migrálva lett, csak a verzió-jelző nem íródott ki
        // helyesen, ami minden további kérésnél újra megpróbálná a (már
        // idempotens) migrációt. Ezt szándékosan nem nyeljük el csendben.
        $this->pdo->exec('CREATE TABLE IF NOT EXISTS schema_version (version INTEGER NOT NULL)');
        $count = (int) $this->pdo->query('SELECT COUNT(*) FROM schema_version')->fetchColumn();
        if ($count === 0) {
            $this->pdo->prepare('INSERT INTO schema_version (version) VALUES (?)')->execute([$version]);
        } else {
            $this->pdo->prepare('UPDATE schema_version SET version = ?')->execute([$version]);
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
            'short_description'  => 'TEXT',
            'long_description'   => 'TEXT',
            'image_filename'     => 'VARCHAR(191)',
            'image_alt'          => 'VARCHAR(191)',
            'brand'              => 'VARCHAR(191)',
            'sync_to_woocommerce' => 'TINYINT(1) NOT NULL DEFAULT 1',
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
        $this->backfillMigratedProductCosts();
    }

    /**
     * Az imént (régi telepítés migrálásakor) létrehozott net_price/
     * purchase_price_net oszlopok DEFAULT 0-val jönnek létre minden már
     * meglévő terméknél — enélkül minden létező termék hamisan 0 Ft
     * nettó/beszerzési árat mutatna, amíg valaki kézzel újra el nem menti.
     * A nettó ár a meglévő bruttó árból/ÁFA-kulcsból pontosan
     * visszaszámolható; a beszerzési ár a legutóbbi purchase_items sorból.
     * Csak azokat a sorokat töltjük ki, ahol még ténylegesen 0 (egy már
     * helyesen kitöltött terméket nem írunk felül) — ez a metódus csak a
     * migráció alatt fut le (lásd ensureSchema()), utána sosem.
     */
    private function backfillMigratedProductCosts(): void
    {
        $rows = $this->pdo->query("SELECT id, price, vat_rate FROM products WHERE net_price = 0 AND price > 0")->fetchAll(PDO::FETCH_ASSOC);
        if ($rows) {
            $update = $this->pdo->prepare('UPDATE products SET net_price = ? WHERE id = ?');
            foreach ($rows as $row) {
                $vatPct = is_numeric($row['vat_rate']) ? ((float) $row['vat_rate']) / 100 : 0.0;
                $update->execute([round((float) $row['price'] / (1 + $vatPct), 2), $row['id']]);
            }
        }

        if ($this->hasTable('purchase_items')) {
            $this->pdo->exec('
                UPDATE products SET purchase_price_net = (
                    SELECT pi.unit_cost_net FROM purchase_items pi
                    WHERE pi.product_id = products.id ORDER BY pi.id DESC LIMIT 1
                )
                WHERE purchase_price_net = 0 AND EXISTS (
                    SELECT 1 FROM purchase_items pi2 WHERE pi2.product_id = products.id
                )
            ');
        }
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
                if (!$this->isBenignSchemaError($e)) {
                    throw $e;
                }
            }
        }
    }

    /**
     * A migrációs lépések nagy része "próbáld meg, és ha már létezik, nem
     * gond" mintát követ (mert sem a régi SQLite, sem a MySQL nem támogatja
     * mindenhol az IF NOT EXISTS-et — pl. ALTER TABLE ADD COLUMN esetén).
     * Ez a szűrő különbözteti meg ezt a JÓINDULATÚ, várt hibát egy VALÓDI
     * hibától (pl. lezárt fájl, lemez megtelt, hibás SQL) — enélkül minden
     * PDOException-t elnyeltünk, a séma-verziót pedig ennek ellenére
     * feljebb írtuk, ami egy valódi hibát csendben, láthatatlanul félig
     * migrált állapotban hagyott volna örökre "késznek" jelölve.
     */
    private function isBenignSchemaError(PDOException $e): bool
    {
        $message = strtolower($e->getMessage());
        return str_contains($message, 'duplicate column')
            || str_contains($message, 'duplicate key name')
            || str_contains($message, 'already exists')
            || str_contains($message, '42s21') // MySQL: duplicate column name
            || str_contains($message, '42s01'); // MySQL: table already exists
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
        } catch (PDOException $e) { if (!$this->isBenignSchemaError($e)) { throw $e; } }

        try {
            $this->pdo->exec("CREATE TABLE IF NOT EXISTS customers (
                id $pk, name VARCHAR(255) NOT NULL, phone VARCHAR(64), email VARCHAR(191),
                tax_number VARCHAR(64), notes TEXT, loyalty_points INTEGER NOT NULL DEFAULT 0,
                is_deleted INTEGER NOT NULL DEFAULT 0, created_at $ts, updated_at $tsNull
            )$engine");
        } catch (PDOException $e) { if (!$this->isBenignSchemaError($e)) { throw $e; } }

        try {
            $this->pdo->exec("CREATE TABLE IF NOT EXISTS loyalty_transactions (
                id $pk, customer_id $intCol, sale_id $intCol, points_delta INTEGER NOT NULL,
                note VARCHAR(255), created_at $ts
            )$engine");
        } catch (PDOException $e) { if (!$this->isBenignSchemaError($e)) { throw $e; } }

        $this->migrateColumns('purchases', ['supplier_id' => $intCol]);
        $this->migrateColumns('sales', [
            'customer_id'             => $intCol,
            'loyalty_points_earned'   => 'INTEGER NOT NULL DEFAULT 0',
            'loyalty_points_redeemed' => 'INTEGER NOT NULL DEFAULT 0',
        ]);

        try {
            $this->pdo->exec('CREATE INDEX idx_suppliers_name ON suppliers(name)');
        } catch (PDOException $e) { if (!$this->isBenignSchemaError($e)) { throw $e; } }
        try {
            $this->pdo->exec('CREATE INDEX idx_customers_name ON customers(name)');
        } catch (PDOException $e) { if (!$this->isBenignSchemaError($e)) { throw $e; } }
        try {
            $this->pdo->exec('CREATE INDEX idx_loyalty_customer_id ON loyalty_transactions(customer_id)');
        } catch (PDOException $e) { if (!$this->isBenignSchemaError($e)) { throw $e; } }
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
            // MODIFY COLUMN nem "már létezik"-jellegű, idempotens hiba —
            // ha másodszorra (már NULL-t engedő oszlopon) is lefut, a MySQL
            // simán újra végrehajtja, nem hibázik. Egy itt elkapott hiba
            // tehát mindig valódi probléma — nem nyeljük el.
            $this->pdo->exec('ALTER TABLE sale_items MODIFY COLUMN product_id INT UNSIGNED NULL');
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
            // Ez a legkockázatosabb migrációs lépés (teljes tábla-újraépítés)
            // — egy itt elkapott hiba biztosan nem "már létezik"-jellegű
            // jóindulatú eset, hanem valódi probléma (pl. a DROP/RENAME
            // valamiért nem sikerült). Rollback után továbbdobjuk, hogy ne
            // maradjon csendben, láthatatlanul félbehagyva.
            throw $e;
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
        } catch (PDOException $e) { if (!$this->isBenignSchemaError($e)) { throw $e; } }
        try {
            $this->pdo->exec($isMysql
                ? 'ALTER TABLE coupons ADD UNIQUE KEY uq_coupons_code (code)'
                : 'CREATE UNIQUE INDEX IF NOT EXISTS idx_coupons_code ON coupons(code)');
        } catch (PDOException $e) { if (!$this->isBenignSchemaError($e)) { throw $e; } }

        try {
            $this->pdo->exec("CREATE TABLE IF NOT EXISTS gift_cards (
                id $pk, code $codeCol, initial_balance $moneyCol NOT NULL, current_balance $moneyCol NOT NULL,
                is_active INTEGER NOT NULL DEFAULT 1, expiry_date $dateCol, notes TEXT, created_at $ts
            )$engine");
        } catch (PDOException $e) { if (!$this->isBenignSchemaError($e)) { throw $e; } }
        try {
            $this->pdo->exec($isMysql
                ? 'ALTER TABLE gift_cards ADD UNIQUE KEY uq_gift_cards_code (code)'
                : 'CREATE UNIQUE INDEX IF NOT EXISTS idx_gift_cards_code ON gift_cards(code)');
        } catch (PDOException $e) { if (!$this->isBenignSchemaError($e)) { throw $e; } }

        try {
            $this->pdo->exec("CREATE TABLE IF NOT EXISTS gift_card_transactions (
                id $pk, gift_card_id $intCol, sale_id $intCol, amount_delta $moneyCol NOT NULL,
                note VARCHAR(255), created_at $ts
            )$engine");
        } catch (PDOException $e) { if (!$this->isBenignSchemaError($e)) { throw $e; } }

        try {
            $this->pdo->exec("CREATE TABLE IF NOT EXISTS price_history (
                id $pk, product_id $intCol, old_net_price $moneyCol, old_price $moneyCol,
                new_net_price $moneyCol, new_price $moneyCol, changed_at $ts
            )$engine");
        } catch (PDOException $e) { if (!$this->isBenignSchemaError($e)) { throw $e; } }

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
            } catch (PDOException $e) { if (!$this->isBenignSchemaError($e)) { throw $e; } }
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
        } catch (PDOException $e) { if (!$this->isBenignSchemaError($e)) { throw $e; } }

        try {
            $this->pdo->exec("CREATE TABLE IF NOT EXISTS returns (
                id $pk, sale_id $intColRequired, staff_id $intCol, total_refund $moneyCol NOT NULL,
                reason VARCHAR(255), credit_invoice_number VARCHAR(64), created_at $ts
            )$engine");
        } catch (PDOException $e) { if (!$this->isBenignSchemaError($e)) { throw $e; } }

        try {
            $this->pdo->exec("CREATE TABLE IF NOT EXISTS return_items (
                id $pk, return_id $intColRequired, sale_item_id $intCol, product_id $intCol,
                name VARCHAR(255) NOT NULL, qty INTEGER NOT NULL, unit_price $moneyCol NOT NULL, created_at $ts
            )$engine");
        } catch (PDOException $e) { if (!$this->isBenignSchemaError($e)) { throw $e; } }

        try {
            $this->pdo->exec("CREATE TABLE IF NOT EXISTS stock_takes (
                id $pk, staff_id $intCol, notes VARCHAR(255), started_at $ts, completed_at $tsNull
            )$engine");
        } catch (PDOException $e) { if (!$this->isBenignSchemaError($e)) { throw $e; } }

        try {
            $this->pdo->exec("CREATE TABLE IF NOT EXISTS stock_take_items (
                id $pk, stock_take_id $intColRequired, product_id $intColRequired,
                expected_qty INTEGER NOT NULL, counted_qty INTEGER, created_at $ts
            )$engine");
        } catch (PDOException $e) { if (!$this->isBenignSchemaError($e)) { throw $e; } }

        $this->migrateColumns('sales', ['staff_id' => $intCol]);

        foreach ([
            'CREATE INDEX idx_returns_sale_id ON returns(sale_id)',
            'CREATE INDEX idx_return_items_return_id ON return_items(return_id)',
            'CREATE INDEX idx_stock_take_items_take_id ON stock_take_items(stock_take_id)',
        ] as $sql) {
            try {
                $this->pdo->exec($sql);
            } catch (PDOException $e) { if (!$this->isBenignSchemaError($e)) { throw $e; } }
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
        } catch (PDOException $e) { if (!$this->isBenignSchemaError($e)) { throw $e; } }

        try {
            $this->pdo->exec('CREATE INDEX idx_audit_log_created_at ON audit_log(created_at)');
        } catch (PDOException $e) { if (!$this->isBenignSchemaError($e)) { throw $e; } }

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
        } catch (PDOException $e) { if (!$this->isBenignSchemaError($e)) { throw $e; } }

        try {
            $this->pdo->exec("CREATE TABLE IF NOT EXISTS location_stock (
                id $pk, product_id $intColRequired, location_id $intColRequired, stock_qty INTEGER NOT NULL DEFAULT 0
            )$engine");
        } catch (PDOException $e) { if (!$this->isBenignSchemaError($e)) { throw $e; } }

        try {
            $this->pdo->exec("CREATE TABLE IF NOT EXISTS stock_transfers (
                id $pk, product_id $intColRequired, from_location_id $intCol, to_location_id $intColRequired,
                qty INTEGER NOT NULL, staff_id $intCol, created_at $ts
            )$engine");
        } catch (PDOException $e) { if (!$this->isBenignSchemaError($e)) { throw $e; } }

        foreach ([
            $isMysql
                ? 'ALTER TABLE location_stock ADD UNIQUE KEY uq_location_stock_product_location (product_id, location_id)'
                : 'CREATE UNIQUE INDEX IF NOT EXISTS idx_location_stock_product_location ON location_stock(product_id, location_id)',
            'CREATE INDEX idx_stock_transfers_product_id ON stock_transfers(product_id)',
        ] as $sql) {
            try {
                $this->pdo->exec($sql);
            } catch (PDOException $e) { if (!$this->isBenignSchemaError($e)) { throw $e; } }
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
            } catch (PDOException $e) { if (!$this->isBenignSchemaError($e)) { throw $e; } }
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

    // Beérkező webshop-rendelések: a webhook.php mostantól NEM csökkenti
    // azonnal a készletet, hanem ide ment egy piszkozatot — ezt egy ember
    // ellenőrzi (lásd api/webshop-order-*.php), majd "leadja" (ekkor lesz
    // belőle valódi eladás + készletcsökkenés), vagy elutasítja.
    private function migrateV16WebshopOrders(): void
    {
        $isMysql = $this->driver === 'mysql';
        $pk = $isMysql ? 'INT UNSIGNED AUTO_INCREMENT PRIMARY KEY' : 'INTEGER PRIMARY KEY AUTOINCREMENT';
        $intCol = $isMysql ? 'INT UNSIGNED NULL' : 'INTEGER';
        $moneyCol = $isMysql ? 'DECIMAL(12,2)' : 'REAL';
        $textCol = $isMysql ? 'TEXT' : 'TEXT';
        $ts = $isMysql ? 'DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP' : "TEXT NOT NULL DEFAULT (datetime('now'))";
        $tsNull = $isMysql ? 'DATETIME NULL' : 'TEXT';
        $engine = $isMysql ? ' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci' : '';

        try {
            $this->pdo->exec("CREATE TABLE IF NOT EXISTS webshop_orders (
                id $pk,
                wc_order_id INTEGER NOT NULL,
                order_number VARCHAR(64),
                status VARCHAR(16) NOT NULL DEFAULT 'draft',
                wc_status VARCHAR(32),
                customer_name VARCHAR(191),
                customer_email VARCHAR(191),
                billing_json $textCol,
                payment_method VARCHAR(64),
                currency VARCHAR(8),
                total $moneyCol NOT NULL DEFAULT 0,
                items_json $textCol,
                customer_note $textCol,
                sale_id $intCol,
                created_at $ts,
                confirmed_at $tsNull
            )$engine");
        } catch (PDOException $e) { if (!$this->isBenignSchemaError($e)) { throw $e; } }

        foreach ([
            $isMysql
                ? 'ALTER TABLE webshop_orders ADD UNIQUE KEY uq_webshop_orders_wc_order_id (wc_order_id)'
                : 'CREATE UNIQUE INDEX IF NOT EXISTS idx_webshop_orders_wc_order_id ON webshop_orders(wc_order_id)',
            'CREATE INDEX idx_webshop_orders_status ON webshop_orders(status)',
        ] as $sql) {
            try {
                $this->pdo->exec($sql);
            } catch (PDOException $e) { if (!$this->isBenignSchemaError($e)) { throw $e; } }
        }
    }

    /**
     * Idempotencia-kulcs (duplikált eladás elleni védelem — kettőzött
     * kattintás, hálózati újrapróbálkozás, elveszett válasz) és az
     * atomikus, versenyhelyzet-mentes számla-kiállítási "foglalás"
     * (invoice_claim_at) mezői a sales táblán. Lásd insertSale()/
     * findSaleByIdempotencyKey()/tryClaimInvoiceIssuance().
     */
    private function migrateV17SaleIdempotency(): void
    {
        $this->migrateColumns('sales', [
            'idempotency_key' => $this->driver === 'mysql' ? 'VARCHAR(64) NULL' : 'TEXT',
            'invoice_claim_at' => $this->driver === 'mysql' ? 'DATETIME NULL' : 'TEXT',
        ]);

        try {
            $this->pdo->exec($this->driver === 'mysql'
                ? 'ALTER TABLE sales ADD UNIQUE KEY uq_sales_idempotency_key (idempotency_key)'
                : 'CREATE UNIQUE INDEX IF NOT EXISTS idx_sales_idempotency_key ON sales(idempotency_key)');
        } catch (PDOException $e) {
            if (!$this->isBenignSchemaError($e)) {
                throw $e;
            }
        }
    }

    /**
     * Az idempotencia-kulcs önmagában csak azt garantálja, hogy UGYANAZ a
     * kulcs ne hozzon létre két sale-t. Nem védett viszont az az eset, ha
     * valaki (egy hibás kliens, vagy egy közvetlen API-hívást indító,
     * szkriptelő felhasználó) UGYANAZT a kulcsot egy MÁSIK, ténylegesen
     * eltérő kosárral/vevővel küldi be — enélkül a szerver csendben a
     * KORÁBBI eladás eredményét adná vissza, miközben az új kérés
     * tartalma sose kerülne ténylegesen feldolgozásra (se terheléshez,
     * se készletmozgáshoz). Az idempotency_fingerprint egy sha256-hash a
     * kérés üzletileg releváns mezőiről (tételek, vevő, fizetési mód,
     * kupon/pont/utalvány-felhasználás) — lásd sale.php
     * build_sale_fingerprint(). Ha egy MEGLÉVŐ kulcshoz tartozó ujjlenyomat
     * NEM egyezik az új kéréssel, a válasz 409 Conflict, a második kérés
     * fel sem dolgozódik.
     *
     * NULL marad minden, a bevezetés ELŐTT (V17 alatt) létrejött sale-nél
     * — ezeknél SZÁNDÉKOSAN nincs mit összehasonlítani, ezért a hívó
     * (sale.php) az ilyen régi rekordokat továbbra is visszajátszhatónak
     * kezeli, nem utasítja el őket utólag egy sose létezett ujjlenyomat
     * hiánya miatt.
     */
    private function migrateV18SaleIdempotencyFingerprint(): void
    {
        $this->migrateColumns('sales', [
            'idempotency_fingerprint' => $this->driver === 'mysql' ? 'VARCHAR(64) NULL' : 'TEXT',
        ]);
    }

    /**
     * Egységes, szolgáltató-független számla-tábla — a NAV Online Számla
     * (a Számlázz.hu mellett választható második számlázási szolgáltató,
     * lásd Settings::DEFAULTS 'invoice_provider') és a Számlázz.hu
     * kimenő számláinak KÖZÖS nyilvántartása. SZÁNDÉKOSAN nem külön
     * "queue" tábla + külön "invoices" tábla: egy NAV-számla és a hozzá
     * tartozó feldolgozási sor UGYANAZ a valós dolog minden állapotában
     * (queued → processing → submitted → done/failed/dead_letter), egy
     * külön tábla csak azt kockáztatná, hogy a kettő szétcsúszik. A
     * UNIQUE(sale_id, provider) egyben az idempotencia-védelem is: egy
     * eladáshoz szolgáltatónként legfeljebb egy sor tartozhat, az
     * ismételt beütemezés (pl. egy elveszett válasz utáni újrapróbálkozás
     * sale.php felől) biztonságosan no-op (INSERT OR IGNORE / INSERT
     * IGNORE).
     *
     * A meglévő sales.szamlazz_invoice_number / szamlazz_pdf_path /
     * status / invoice_claim_at oszlopok VÁLTOZATLANOK maradnak — a
     * Számlázz.hu-s út továbbra is a meglévő tryClaimInvoiceIssuance()/
     * attachInvoiceToSale() párost használja (lásd SzamlazzInvoiceProvider),
     * és ez a tábla csak egy TÜKÖR-bejegyzést kap utána, hogy az
     * egységesített "Kimenő számlák" nézetnek egyetlen, szolgáltató-
     * független helye legyen az olvasáshoz.
     *
     * FONTOS, DOKUMENTÁLT KORLÁT (ugyanaz, mint tryClaimInvoiceIssuance()-
     * nál): a `locked_at` csak azt garantálja, hogy egy adott pillanatban
     * csak egy HELYI worker kezdhet bele egy adott sor feldolgozásába — ha
     * a NAV-hívás ténylegesen célba ér, de a válasz elvész, mielőtt a
     * transactionId elmentődne, egy újrapróbálkozás emiatt elméletileg
     * másodszor is beküldhetné ugyanazt a számlát, mert a NAV API-nak
     * nincs erre valódi, a helyi rendszer által kihasználható dedup-kulcsa.
     * "Legalább egyszer" garantált helyileg, "pontosan egyszer" a NAV
     * felé nem — ugyanaz a korlát, mint amit a Számlázz.hu-integráció
     * docblockja is nyíltan vállal.
     */
    private function migrateV19Invoices(): void
    {
        $isMysql = $this->driver === 'mysql';
        $pk = $isMysql ? 'INT UNSIGNED AUTO_INCREMENT PRIMARY KEY' : 'INTEGER PRIMARY KEY AUTOINCREMENT';
        $intCol = $isMysql ? 'INT UNSIGNED NOT NULL' : 'INTEGER NOT NULL REFERENCES sales(id)';
        $moneyCol = $isMysql ? 'DECIMAL(12,2)' : 'REAL';
        $textCol = 'TEXT';
        $ts = $isMysql ? 'DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP' : "TEXT NOT NULL DEFAULT (datetime('now'))";
        $tsNull = $isMysql ? 'DATETIME NULL' : 'TEXT';
        // MySQL-en a sale_id -> sales(id) hivatkozás egy külön, névvel
        // ellátott CONSTRAINT-ként kerül be (ugyanaz a minta, mint
        // webshop_orders.fk_webshop_orders_sale-nél); SQLite-on ez már
        // magába az oszlop-definícióba (REFERENCES sales(id)) beépül —
        // lásd fent az $intCol értékét.
        $fkConstraint = $isMysql ? ', CONSTRAINT fk_invoices_sale FOREIGN KEY (sale_id) REFERENCES sales(id)' : '';
        $engine = $isMysql ? ' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci' : '';

        try {
            $this->pdo->exec("CREATE TABLE IF NOT EXISTS invoices (
                id $pk,
                sale_id $intCol,
                provider VARCHAR(16) NOT NULL,
                status VARCHAR(20) NOT NULL DEFAULT 'queued',
                provider_ref VARCHAR(64),
                invoice_number VARCHAR(64),
                net_total $moneyCol,
                vat_total $moneyCol,
                gross_total $moneyCol,
                currency VARCHAR(8) NOT NULL DEFAULT 'HUF',
                issued_at $tsNull,
                pdf_path $textCol,
                attempts INT UNSIGNED NOT NULL DEFAULT 0,
                next_attempt_at $tsNull,
                locked_at $tsNull,
                last_error $textCol,
                payload_json $textCol,
                created_at $ts,
                updated_at $ts
                $fkConstraint
            )$engine");
        } catch (PDOException $e) { if (!$this->isBenignSchemaError($e)) { throw $e; } }

        foreach ([
            $isMysql
                ? 'ALTER TABLE invoices ADD UNIQUE KEY uq_invoices_sale_provider (sale_id, provider)'
                : 'CREATE UNIQUE INDEX IF NOT EXISTS idx_invoices_sale_provider ON invoices(sale_id, provider)',
            'CREATE INDEX idx_invoices_status_next_attempt ON invoices(status, next_attempt_at)',
            'CREATE INDEX idx_invoices_provider ON invoices(provider)',
        ] as $sql) {
            try {
                $this->pdo->exec($sql);
            } catch (PDOException $e) { if (!$this->isBenignSchemaError($e)) { throw $e; } }
        }
    }

    /**
     * A NAV Online Számla BEÉRKEZŐ (más adózók által kiállított) számláinak
     * saját, az `invoices` (kimenő) tábláktól SZÁNDÉKOSAN KÜLÖN
     * adatmodellje — lásd a Phase 6 terv 2. pontját az indoklásért: az
     * `invoices` állapotgépe (queued/processing/submitted/...) a MI
     * beküldésünk életciklusát írja le, aminek egy bejövő számlánál nincs
     * értelme (egy bejövő számla nem "queued", egyszerűen VAN).
     *
     * `incoming_invoices` — egy sor a NAV `queryInvoiceDigest`
     * válaszának EGY digest-bejegyzése (egy eredeti számla VAGY egy
     * konkrét módosító/sztornó dokumentum) — UNIQUE(supplier_tax_number,
     * invoice_number, batch_index), mert a NAV-adatmodell szerint minden
     * dokumentum (eredeti ÉS minden módosítás) saját, egyedi sorszámot
     * kap az adott szállítónál (ÁFA tv. 169-170. §) — ez a természetes,
     * hamisítatlan dedup-kulcs, `INSERT OR IGNORE`/`INSERT IGNORE`-ra
     * építve (lásd Database::insertIncomingInvoiceDigestEntry()).
     *
     * `incoming_invoice_items` — a tételsorok, LAZY módon, csak akkor
     * töltve, amikor a felhasználó ténylegesen megnyitja egy számla
     * részletnézetét (`queryInvoiceData`), NEM minden sync-nél minden
     * számlához (az irreális NAV-terhelés elkerülése miatt).
     *
     * `incoming_invoice_sync` — a sync állapotgépe (idle/running/
     * success/retry/failed), KÜLÖN TÁBLA (nem Settings-kulcs, mint a
     * kimenő oldal `last_auto_sync_at`-ja), mert itt ATOMIKUS,
     * race-safe claim kell (lásd Database::claimIncomingInvoiceSync()) —
     * egy JSON-fájlírás (Settings::save()) erre nem alkalmas.
     */
    /**
     * ATOMICITÁS: ez a metódus a `migrateV6ManualSaleItems()`-ben már
     * bevált `$wasInTransaction`-mintát követi — SAJÁT tranzakcióba
     * csomagolja az összes DDL/DML lépését, DE csak akkor nyit ÚJAT, ha
     * még nincs aktív (kompozíció-barát: ha valaha egy külső hívó már
     * tranzakcióban van, nem nyit egymásba ágyazott tranzakciót).
     *
     * Miért kellett ez: élesben előfordult, hogy a `schema_version` 20-ra
     * ugrott, miközben ez a 3 tábla ténylegesen NEM jött létre (a régi,
     * tranzakció NÉLKÜLI verzióban minden `exec()` azonnal, önállóan
     * commit-olt — egy a metóduson KÍVÜLI okból megszakadt kérés emiatt
     * FÉLBEN hagyhatta a migrációt úgy, hogy a már lefutott lépések
     * hatása megmaradt, de az `ensureSchema()` sose jutott el a
     * `setSchemaVersion()`-ig). SQLite-on a `CREATE TABLE`/`CREATE INDEX`
     * is TELJES ÉRTÉKŰEN tranzakcionális — egy `rollBack()` ezeket is
     * visszavonja, tehát itt VALÓDI, teljes atomicitás érhető el: vagy
     * MINDHÁROM tábla + index + seed-sor létrejön, vagy semmi.
     *
     * MySQL-en ez a garancia GYENGÉBB egy alapvető motor-korlát miatt: a
     * MySQL/InnoDB DDL-utasításai (CREATE TABLE/INDEX, ALTER TABLE)
     * IMPLICIT COMMIT-ot végeznek, tehát tranzakción belül sem
     * visszavonhatók — ez NEM ennek a projektnek a hibája, hanem a MySQL
     * dokumentált, általános viselkedése (ugyanez igaz PL. a Rails/
     * Laravel/Doctrine migrációs rendszereire is). Emiatt a VALÓDI,
     * motor-független garancia itt NEM a tranzakciós rollback, hanem az,
     * hogy MINDEN egyes lépés (CREATE TABLE IF NOT EXISTS, CREATE INDEX
     * IF NOT EXISTS / benign-hiba-elnyelés, INSERT OR IGNORE/IGNORE a
     * seed-sornál) idempotens — egy megszakadt migráció a KÖVETKEZŐ
     * kérésnél, a hiányzó résztől folytatva, HIBA NÉLKÜL fejeződik be
     * (nincs "poison" állapot, ami minden további kérést elhasaltana).
     */
    private function migrateV20IncomingInvoices(): void
    {
        $isMysql = $this->driver === 'mysql';
        $pk = $isMysql ? 'INT UNSIGNED AUTO_INCREMENT PRIMARY KEY' : 'INTEGER PRIMARY KEY AUTOINCREMENT';
        $intCol = $isMysql ? 'INT UNSIGNED NOT NULL' : 'INTEGER NOT NULL';
        $moneyCol = $isMysql ? 'DECIMAL(14,4)' : 'REAL';
        $textCol = 'TEXT';
        $ts = $isMysql ? 'DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP' : "TEXT NOT NULL DEFAULT (datetime('now'))";
        $tsNull = $isMysql ? 'DATETIME NULL' : 'TEXT';
        $engine = $isMysql ? ' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci' : '';

        $wasInTransaction = $this->pdo->inTransaction();
        if (!$wasInTransaction) {
            $this->pdo->beginTransaction();
        }
        try {
            $this->migrateV20IncomingInvoicesBody($isMysql, $pk, $intCol, $moneyCol, $textCol, $ts, $tsNull, $engine);
            if (!$wasInTransaction) {
                $this->pdo->commit();
            }
        } catch (Throwable $e) {
            if (!$wasInTransaction && $this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }
    }

    private function migrateV20IncomingInvoicesBody(bool $isMysql, string $pk, string $intCol, string $moneyCol, string $textCol, string $ts, string $tsNull, string $engine): void
    {
        try {
            $this->pdo->exec("CREATE TABLE IF NOT EXISTS incoming_invoices (
                id $pk,
                nav_transaction_id VARCHAR(64),
                invoice_number VARCHAR(64) NOT NULL,
                batch_index INT UNSIGNED NOT NULL DEFAULT 0,
                supplier_tax_number VARCHAR(16) NOT NULL,
                supplier_group_member_tax_number VARCHAR(16),
                supplier_name VARCHAR(512) NOT NULL,
                supplier_country VARCHAR(8),
                customer_tax_number VARCHAR(16),
                customer_name VARCHAR(512),
                invoice_operation VARCHAR(16) NOT NULL,
                invoice_category VARCHAR(16),
                original_invoice_number VARCHAR(64),
                modification_index VARCHAR(32),
                invoice_issue_date VARCHAR(16),
                invoice_delivery_date VARCHAR(16),
                payment_date VARCHAR(16),
                payment_method VARCHAR(16),
                currency VARCHAR(8) NOT NULL DEFAULT 'HUF',
                net_total $moneyCol,
                vat_total $moneyCol,
                gross_total $moneyCol,
                nav_ins_date VARCHAR(32) NOT NULL,
                detail_fetched_at $tsNull,
                first_seen_at $ts,
                last_synced_at $ts,
                created_at $ts,
                updated_at $ts
            )$engine");
        } catch (PDOException $e) { if (!$this->isBenignSchemaError($e)) { throw $e; } }

        try {
            $this->pdo->exec("CREATE TABLE IF NOT EXISTS incoming_invoice_items (
                id $pk,
                incoming_invoice_id $intCol,
                line_number INT UNSIGNED NOT NULL,
                description $textCol,
                quantity $moneyCol,
                unit_of_measure VARCHAR(32),
                unit_net_price $moneyCol,
                vat_rate VARCHAR(8),
                net_amount $moneyCol,
                vat_amount $moneyCol,
                gross_amount $moneyCol,
                created_at $ts
            )$engine");
        } catch (PDOException $e) { if (!$this->isBenignSchemaError($e)) { throw $e; } }

        try {
            $this->pdo->exec("CREATE TABLE IF NOT EXISTS incoming_invoice_sync (
                id $pk,
                provider VARCHAR(16) NOT NULL DEFAULT 'nav',
                status VARCHAR(16) NOT NULL DEFAULT 'idle',
                sync_cursor_ins_date VARCHAR(32),
                last_requested_interval VARCHAR(64),
                last_success_at $tsNull,
                last_attempt_at $tsNull,
                attempts INT UNSIGNED NOT NULL DEFAULT 0,
                next_attempt_at $tsNull,
                locked_at $tsNull,
                last_error $textCol,
                created_at $ts,
                updated_at $ts
            )$engine");
        } catch (PDOException $e) { if (!$this->isBenignSchemaError($e)) { throw $e; } }

        foreach ([
            $isMysql
                ? 'ALTER TABLE incoming_invoices ADD UNIQUE KEY uq_incoming_invoices_identity (supplier_tax_number, invoice_number, batch_index)'
                : 'CREATE UNIQUE INDEX IF NOT EXISTS idx_incoming_invoices_identity ON incoming_invoices(supplier_tax_number, invoice_number, batch_index)',
            'CREATE INDEX idx_incoming_invoices_ins_date ON incoming_invoices(nav_ins_date)',
            'CREATE INDEX idx_incoming_invoices_issue_date ON incoming_invoices(invoice_issue_date)',
            'CREATE INDEX idx_incoming_invoices_supplier ON incoming_invoices(supplier_tax_number)',
            $isMysql
                ? 'ALTER TABLE incoming_invoice_items ADD UNIQUE KEY uq_incoming_invoice_items_line (incoming_invoice_id, line_number)'
                : 'CREATE UNIQUE INDEX IF NOT EXISTS idx_incoming_invoice_items_line ON incoming_invoice_items(incoming_invoice_id, line_number)',
            $isMysql
                ? 'ALTER TABLE incoming_invoice_sync ADD UNIQUE KEY uq_incoming_invoice_sync_provider (provider)'
                : 'CREATE UNIQUE INDEX IF NOT EXISTS idx_incoming_invoice_sync_provider ON incoming_invoice_sync(provider)',
        ] as $sql) {
            try {
                $this->pdo->exec($sql);
            } catch (PDOException $e) { if (!$this->isBenignSchemaError($e)) { throw $e; } }
        }

        // A sync-állapot sor MINDIG legyen jelen (idle-ként), hogy a
        // worker/manuális-trigger sose kelljen "hozz létre, ha nincs"
        // ágat futtatnia — egyetlen, előre garantált sor. INSERT OR
        // IGNORE/IGNORE (NEM sima INSERT): egy megszakadt-majd-újrafuttatott
        // migráció esetén ez a sor MÁR létezhet (lásd az osztály docblockja
        // az atomicitásról) — sima INSERT-tel ez egy el nem kapott UNIQUE-
        // ütközési hibát dobna (az isBenignSchemaError() csak "already
        // exists"-jellegű SÉMA-hibákat ismer fel, egy sor-szintű UNIQUE-
        // ütközést NEM), ami MINDEN további kérést véglegesen elhasalna —
        // élesben ténylegesen ez történt, éles adatbázison reprodukálva és
        // javítva.
        $sql = $isMysql
            ? "INSERT IGNORE INTO incoming_invoice_sync (provider, status, created_at, updated_at) VALUES ('nav', 'idle', NOW(), NOW())"
            : "INSERT OR IGNORE INTO incoming_invoice_sync (provider, status, created_at, updated_at) VALUES ('nav', 'idle', datetime('now'), datetime('now'))";
        try {
            $this->pdo->exec($sql);
        } catch (PDOException $e) { if (!$this->isBenignSchemaError($e)) { throw $e; } }
    }

    /**
     * A FountainTrade önfrissítő rendszerének (UpdateService/UpdateInstaller)
     * két tábláját hozza létre:
     *  - `update_state`: EGYETLEN sorral (id=1) leírja a jelenlegi
     *    állapotgép-státuszt (lásd UPDATE_STATES), a legutóbb ismert GitHub
     *    Release adatait, ÉS a konkurrencia-védő zárat (lock_token/
     *    lock_started_at/lock_hostname) — ugyanaz a "egyetlen garantált sor,
     *    zárolás UPDATE...WHERE-rel" minta, mint az incoming_invoice_sync
     *    táblánál (lásd claimIncomingInvoiceSync()), csak update-specifikus
     *    mezőkkel. NEM külön tábla a zárnak — egy singleton-sornál a zár és
     *    az állapot ugyanannak az "egyszerre csak egy fut" invariánsnak a
     *    két oldala, külön táblában tartva csak versenyhelyzetet
     *    kockáztatna a kettő szinkronban tartásával.
     *  - `update_history`: minden ténylegesen megkísérelt (admin- vagy
     *    cron-indított) frissítési folyamat tartós, utólag is vizsgálható
     *    naplója — SOSE törlődik automatikusan.
     */
    private function migrateV21Updates(): void
    {
        $isMysql = $this->driver === 'mysql';
        $pk = $isMysql ? 'INT UNSIGNED AUTO_INCREMENT PRIMARY KEY' : 'INTEGER PRIMARY KEY AUTOINCREMENT';
        $textCol = 'TEXT';
        $ts = $isMysql ? 'DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP' : "TEXT NOT NULL DEFAULT (datetime('now'))";
        $tsNull = $isMysql ? 'DATETIME NULL' : 'TEXT';
        $engine = $isMysql ? ' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci' : '';

        try {
            $this->pdo->exec("CREATE TABLE IF NOT EXISTS update_state (
                id INTEGER PRIMARY KEY,
                state VARCHAR(32) NOT NULL DEFAULT 'idle',
                current_version VARCHAR(32) NOT NULL,
                latest_version VARCHAR(32),
                latest_release_tag VARCHAR(64),
                latest_commit_sha VARCHAR(64),
                latest_release_notes $textCol,
                latest_published_at $tsNull,
                latest_checked_at $tsNull,
                last_check_error $textCol,
                last_successful_update_at $tsNull,
                last_successful_update_version VARCHAR(32),
                progress_message $textCol,
                install_requested_by VARCHAR(191),
                install_requested_at $tsNull,
                lock_token VARCHAR(64),
                lock_started_at $tsNull,
                lock_hostname VARCHAR(191),
                created_at $ts,
                updated_at $ts
            )$engine");
        } catch (PDOException $e) { if (!$this->isBenignSchemaError($e)) { throw $e; } }

        try {
            $this->pdo->exec("CREATE TABLE IF NOT EXISTS update_history (
                id $pk,
                from_version VARCHAR(32) NOT NULL,
                to_version VARCHAR(32) NOT NULL,
                release_tag VARCHAR(64),
                commit_sha VARCHAR(64),
                trigger_source VARCHAR(16) NOT NULL,
                actor VARCHAR(191),
                started_at $ts,
                finished_at $tsNull,
                state VARCHAR(32) NOT NULL,
                error $textCol,
                backup_reference VARCHAR(255),
                rollback_state VARCHAR(32),
                created_at $ts
            )$engine");
        } catch (PDOException $e) { if (!$this->isBenignSchemaError($e)) { throw $e; } }

        try {
            $this->pdo->exec('CREATE INDEX idx_update_history_started_at ON update_history(started_at)');
        } catch (PDOException $e) { if (!$this->isBenignSchemaError($e)) { throw $e; } }

        // A singleton állapot-sor MINDIG legyen jelen, ugyanazért, amiért az
        // incoming_invoice_sync sornál is (lásd ott a docblockot) — a hívónak
        // (UpdateService/UpdateInstaller) sose kelljen "hozz létre, ha nincs"
        // ágat futtatnia, ÉS egy megszakadt-majd-újrafuttatott migráció se
        // hasaljon el egy el nem kapott UNIQUE-ütközésen.
        $now = date('Y-m-d H:i:s');
        $sql = $isMysql
            ? "INSERT IGNORE INTO update_state (id, state, current_version, created_at, updated_at) VALUES (1, 'idle', ?, ?, ?)"
            : "INSERT OR IGNORE INTO update_state (id, state, current_version, created_at, updated_at) VALUES (1, 'idle', ?, ?, ?)";
        try {
            $this->pdo->prepare($sql)->execute([AppVersion::CURRENT, $now, $now]);
        } catch (PDOException $e) { if (!$this->isBenignSchemaError($e)) { throw $e; } }
    }

    /**
     * A FountainTrade 1.1.0 MODIFY/STORNO előkészítő rétege — KIZÁRÓLAG az
     * adatmodellt (invoice_type/original_invoice_id/operation_key/
     * modification_index + a két sequence-tábla) vezeti be. A tényleges
     * NAV/Számlázz.hu MODIFY/STORNO kérés-összeállítás EBBEN a körben
     * SZÁNDÉKOSAN nincs implementálva (lásd a kör explicit stop-feltétele).
     *
     * KRITIKUS DÖNTÉS — a `UNIQUE(sale_id, provider)` megszüntetése:
     * ez a régi constraint pontosan EGY invoice-sort engedett
     * sale_id+provider kombinációnként — ez a MODIFY/STORNO
     * bevezetésének strukturális akadálya volt (egy módosító/sztornó sor
     * UGYANAZT a sale_id+provider-t viszi, mint az eredeti). A pótlás NEM
     * egy `UNIQUE(sale_id, provider, invoice_type)` lett (ami blokkolná a
     * SZÁNDÉKOSAN engedélyezett normal→modification→modification láncot,
     * lásd migrateV22 docblockjának lentebbi része), hanem egyetlen,
     * egységes `operation_key` oszlop UNIQUE indexe, amit MINDHÁROM
     * típusú beszúrás (create/modify/storno) kitölt, csak MÁS-MÁS
     * KÉPZÉSI SZABÁLLYAL:
     *   - normal (a MEGLÉVŐ insertQueuedInvoice()/upsertInvoiceMirror()
     *     útvonalak): 'create:{sale_id}:{provider}' — DETERMINISZTIKUS,
     *     tehát PONTOSAN ugyanazt a "legfeljebb egy eredeti számla
     *     sale_id+provider-enként" garanciát adja, mint a régi
     *     UNIQUE(sale_id,provider) — a meglévő CREATE-folyamat emiatt
     *     VÁLTOZATLANUL, visszafelé kompatibilisen működik.
     *   - storno: 'storno:{original_invoice_id}' — SZINTÉN
     *     determinisztikus (a művelethez NINCS köze semmilyen kliens-
     *     oldali UUID-nek) — ez teszi a sztornót SZERKEZETILEG
     *     terminálissá: akárhány konkurrens sztornó-kísérlet érkezik
     *     ugyanarra az eredeti számlára, MINDEGYIK ugyanazt az
     *     operation_key-t próbálná beszúrni, a UNIQUE index emiatt
     *     PONTOSAN egyet enged át — ÖNMAGÁBAN, PHP-szintű "van-e már
     *     aktív sztornó" ellenőrzés NÉLKÜL is race-safe.
     *   - modification: 'modify:{original_invoice_id}:{kliens-generált
     *     UUID}' — a UUID-t a hívó (egy KÉSŐBBI körben megépítendő
     *     UI/endpoint) generálja EGYETLEN alkalommal egy adott módosítási
     *     SZÁNDÉK indításakor, és UGYANAZT küldi újra egy dupla
     *     kattintás/hálózati retry esetén — ez véd az EGY adott kísérlet
     *     duplikálása ellen, miközben KÉT, ténylegesen KÜLÖNBÖZŐ,
     *     egymást követő módosítás (más UUID) mindkettő sikeresen
     *     létrejöhet — pontosan a kérés 9. pontjának példája
     *     (MODIFY→1, MODIFY→2, STORNO→3).
     *
     * A régi 2-oszlopos UNIQUE index emiatt EGYSZERŰEN TÖRLÉSRE kerül (nem
     * lecserélve egy másik többoszloposra), és egy sima, NEM-unique
     * `(sale_id, provider)` index pótolja a lekérdezési sebességet (lásd
     * findInvoiceBySaleAndProvider()) — az uniqueness-t innentől KIZÁRÓLAG
     * az operation_key adja.
     *
     * MEGLÉVŐ SOROK: a migráció maga tölti fel az operation_key-t minden
     * MÁR LÉTEZŐ (értelemszerűen invoice_type='normal') sorra pontosan a
     * fenti 'create:{sale_id}:{provider}' képlettel — ez BIZTONSÁGOS,
     * mert ezek a sorok a RÉGI UNIQUE(sale_id,provider) alatt már eleve
     * egyediek voltak sale_id+provider szerint, tehát a visszamenőleges
     * kitöltés nem hozhat létre új ütközést.
     *
     * `original_invoice_id`: ÖNMAGÁRA és MODIFICATION/STORNO sorra
     * SOSE mutathat (lásd Database::createInvoiceOperation() explicit
     * ellenőrzése — ez egy CHECK constraint-tal portable módon, MySQL-en
     * is, nem fejezhető ki, ezért alkalmazás-szintű védelem, a
     * concurrency-safe operation_key-UNIQUE mellett második védelmi
     * rétegként).
     *
     * NAV számlaszám-szekvencia (`invoice_sequences`): egyetlen közös,
     * provider-kulcsolt, atomikusan növelt számláló — lásd
     * Database::allocateInvoiceNumber() docblockja a konkurrencia-
     * biztonságért. A Számlázz.hu-hoz tartozó sor is előre látra kerül
     * (kiterjeszthetőség), DE a Számlázz.hu tényleges számlaszámát
     * TOVÁBBRA IS a Számlázz.hu maga adja vissza (lásd
     * SzamlazzClient::handleResponse() szlahu_szamlaszam fejléce) — ezt a
     * sort a jelenlegi kód SOSE fogja ténylegesen inkrementálni, ez
     * SZÁNDÉKOS, dokumentált döntés, nem hiányosság.
     *
     * A NAV sorozat KEZDŐÉRTÉKE (migráció alatt, EGYSZERI művelet — nem
     * tévesztendő össze a tiltott "MAX()+1 minden allokáláskor" mintával)
     * a jelenlegi legmagasabb NAV invoices.id-ra van állítva, hogy az ÚJ
     * sorozat garantáltan a régi, id-alapú számok FÖLÖTT folytatódjon —
     * lásd a kör lezáró jelentésének "NAV numbering" szakaszát.
     *
     * `invoice_modification_sequences`: külön, `original_invoice_id`-
     * kulcsolt számláló a `modificationIndex`-hez — lásd
     * Database::allocateModificationIndex() docblockja.
     */
    private function migrateV22InvoiceOperations(): void
    {
        $isMysql = $this->driver === 'mysql';
        $pk = $isMysql ? 'INT UNSIGNED AUTO_INCREMENT PRIMARY KEY' : 'INTEGER PRIMARY KEY AUTOINCREMENT';
        $ts = $isMysql ? 'DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP' : "TEXT NOT NULL DEFAULT (datetime('now'))";
        $engine = $isMysql ? ' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci' : '';

        $wasInTransaction = $this->pdo->inTransaction();
        if (!$wasInTransaction) {
            $this->pdo->beginTransaction();
        }
        try {
            $this->migrateV22InvoiceOperationsBody($isMysql, $pk, $ts, $engine);
            if (!$wasInTransaction) {
                $this->pdo->commit();
            }
        } catch (Throwable $e) {
            if (!$wasInTransaction && $this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }
    }

    private function migrateV22InvoiceOperationsBody(bool $isMysql, string $pk, string $ts, string $engine): void
    {
        $this->migrateColumns('invoices', [
            'invoice_type'         => "VARCHAR(16) NOT NULL DEFAULT 'normal'",
            'original_invoice_id'  => $isMysql ? 'INT UNSIGNED NULL' : 'INTEGER NULL REFERENCES invoices(id)',
            'operation_key'        => 'VARCHAR(191) NULL',
            'modification_index'   => 'INT UNSIGNED NULL',
        ]);

        // A régi, KÉT oszlopos UNIQUE megszüntetése — lásd a metódus
        // előtti docblock. Motorfüggő szintaxis, "nincs ilyen index" hibát
        // is elnyelve (idempotens újrafuttatás — ha egy korábbi próbálkozás
        // már törölte, ez itt ártalmatlan no-op).
        try {
            $this->pdo->exec($isMysql
                ? 'ALTER TABLE invoices DROP INDEX uq_invoices_sale_provider'
                : 'DROP INDEX IF EXISTS idx_invoices_sale_provider');
        } catch (PDOException $e) { if (!$this->isBenignDropError($e)) { throw $e; } }

        foreach ([
            'CREATE INDEX idx_invoices_sale_provider ON invoices(sale_id, provider)',
            $isMysql
                ? 'ALTER TABLE invoices ADD UNIQUE KEY uq_invoices_operation_key (operation_key)'
                : 'CREATE UNIQUE INDEX IF NOT EXISTS idx_invoices_operation_key ON invoices(operation_key)',
            'CREATE INDEX idx_invoices_original_invoice_id ON invoices(original_invoice_id)',
            'CREATE INDEX idx_invoices_invoice_type ON invoices(invoice_type)',
        ] as $sql) {
            try {
                $this->pdo->exec($sql);
            } catch (PDOException $e) { if (!$this->isBenignSchemaError($e)) { throw $e; } }
        }

        // Visszamenőleges kitöltés — lásd a metódus előtti docblock
        // "MEGLÉVŐ SOROK" szakasza: BIZTONSÁGOS, mert ezek a sorok a régi
        // UNIQUE(sale_id,provider) alatt már eleve egyediek voltak.
        $concat = $isMysql ? "CONCAT('create:', sale_id, ':', provider)" : "'create:' || sale_id || ':' || provider";
        try {
            $this->pdo->exec("UPDATE invoices SET operation_key = $concat WHERE operation_key IS NULL AND invoice_type = 'normal'");
        } catch (PDOException $e) { if (!$this->isBenignSchemaError($e)) { throw $e; } }

        try {
            $this->pdo->exec("CREATE TABLE IF NOT EXISTS invoice_sequences (
                provider VARCHAR(16) NOT NULL PRIMARY KEY,
                last_allocated_number INT UNSIGNED NOT NULL DEFAULT 0,
                updated_at $ts
            )$engine");
        } catch (PDOException $e) { if (!$this->isBenignSchemaError($e)) { throw $e; } }

        try {
            $this->pdo->exec("CREATE TABLE IF NOT EXISTS invoice_modification_sequences (
                original_invoice_id INT UNSIGNED NOT NULL PRIMARY KEY,
                last_allocated_index INT UNSIGNED NOT NULL DEFAULT 0,
                updated_at $ts
                " . ($isMysql ? ', CONSTRAINT fk_invoice_mod_seq_original FOREIGN KEY (original_invoice_id) REFERENCES invoices(id)' : '') . "
            )$engine");
        } catch (PDOException $e) { if (!$this->isBenignSchemaError($e)) { throw $e; } }

        // Egyszeri (NEM ongoing-allokálási!) magszám: a NAV sorozat a
        // jelenlegi legmagasabb NAV invoices.id fölött folytatódik, hogy a
        // régi, id-alapú számokkal SOSE keveredhessen — lásd a metódus
        // előtti docblock. Ez a MAX() itt, EGYETLEN alkalommal, a
        // migráció alatt fut, nem az allocateInvoiceNumber() ongoing
        // logikájának része (ami TILOS lenne MAX()+1-et használni, lásd
        // ott).
        $maxNavId = (int) ($this->pdo->query("SELECT COALESCE(MAX(id), 0) FROM invoices WHERE provider = 'nav'")->fetchColumn() ?: 0);
        $sql = $isMysql
            ? 'INSERT IGNORE INTO invoice_sequences (provider, last_allocated_number, updated_at) VALUES (?, ?, ?)'
            : 'INSERT OR IGNORE INTO invoice_sequences (provider, last_allocated_number, updated_at) VALUES (?, ?, ?)';
        $now = date('Y-m-d H:i:s');
        try {
            $this->pdo->prepare($sql)->execute(['nav', $maxNavId, $now]);
            // A Számlázz.hu sor előre látra kerül (kiterjeszthetőség) — lásd
            // a metódus előtti docblock, miért NEM használja ezt a
            // jelenlegi kód ténylegesen.
            $this->pdo->prepare($sql)->execute(['szamlazz', 0, $now]);
        } catch (PDOException $e) { if (!$this->isBenignSchemaError($e)) { throw $e; } }
    }

    /** @see migrateV22InvoiceOperationsBody() — "nincs ilyen index/kulcs" hibák felismerése DROP-oknál (nem CREATE-eknél, lásd isBenignSchemaError()). */
    private function isBenignDropError(PDOException $e): bool
    {
        $message = strtolower($e->getMessage());
        return str_contains($message, 'no such index')
            || str_contains($message, "check that column/key exists")
            || str_contains($message, '1091'); // MySQL: can't DROP; check that column/key exists
    }

    /**
     * 1.1.1 — beszerzés-idempotencia, PONTOSAN a sales.idempotency_key
     * mintáját követve (lásd migrateV17SaleIdempotency() +
     * migrateV18SaleIdempotencyFingerprint() docblockja a teljes
     * indoklásért — kulcs+ujjlenyomat pár, dupla kattintás/hálózati
     * újrapróbálkozás/konkurrens kérés ellen). A sale-nél két külön
     * migrációs körben (V17/V18) került be — itt, mivel egyszerre,
     * frissen vezetjük be mindkettőt, egyetlen migrációban kerülnek fel,
     * felesleges történeti szétválasztás nélkül.
     */
    private function migrateV23PurchaseIdempotency(): void
    {
        $this->migrateColumns('purchases', [
            'idempotency_key'         => $this->driver === 'mysql' ? 'VARCHAR(64) NULL' : 'TEXT',
            'idempotency_fingerprint' => $this->driver === 'mysql' ? 'VARCHAR(64) NULL' : 'TEXT',
        ]);

        try {
            $this->pdo->exec($this->driver === 'mysql'
                ? 'ALTER TABLE purchases ADD UNIQUE KEY uq_purchases_idempotency_key (idempotency_key)'
                : 'CREATE UNIQUE INDEX IF NOT EXISTS idx_purchases_idempotency_key ON purchases(idempotency_key)');
        } catch (PDOException $e) {
            if (!$this->isBenignSchemaError($e)) {
                throw $e;
            }
        }
    }

    /**
     * 1.1.1 — aszinkron WooCommerce készlet-push sor, PONTOSAN az
     * `invoices` queue-állapotgépének mintáját követve (lásd
     * migrateV19Invoices() docblockja: queued/processing/status +
     * attempts/next_attempt_at/locked_at/last_error) — csak EGY fázisú
     * (nincs "beküldve, státuszra várunk" köztes állapot, mert a WC
     * updateStock() hívás önmagában szinkron/azonnal eldönti a sikert),
     * ezért nincs 'submitted' állapot, csak queued → processing →
     * done/failed/dead_letter (retry esetén vissza queued-ra, ugyanúgy,
     * mint scheduleInvoiceRetry()-nál).
     *
     * Az `operation_key` ('push:{trigger_type}:{trigger_id}:{product_id}')
     * a konkrét KIVÁLTÓ ESEMÉNYHEZ (egy adott eladás/beszerzés/leltár-
     * lezárás egy adott tételéhez) kötött, nem magához a termékhez — két
     * KÜLÖNBÖZŐ esemény (pl. egy eladás és egy utána következő beszerzés
     * ugyanarra a termékre) két külön sort kap, mindkettő a push
     * IDŐPONTJÁBAN érvényes, friss készletet olvassa ki (lásd
     * WcPushQueueWorker::processDuePushes()), nem egy a beütemezéskor
     * rögzített pillanatképet — enélkül egy gyorsan egymást követő két
     * esemény push-sorrendje felcserélődve egy ELAVULT abszolút értéket
     * írhatna felül a WooCommerce oldalán. A UNIQUE(operation_key) csak
     * azt zárja ki, hogy UGYANAZ a kiváltó esemény (dupla kattintás,
     * hálózati újrapróbálkozás) kétszer kerüljön beütemezésre — ez az
     * idempotens beütemezés; a TÉNYLEGES "ne fusson kétszer egyidejűleg"
     * védelmet a claimQueuedWcPush() feltételes UPDATE-je adja, ugyanaz a
     * minta, mint claimInvoiceRow()-nál.
     */
    private function migrateV24WcPushQueue(): void
    {
        $isMysql = $this->driver === 'mysql';
        $pk = $isMysql ? 'INT UNSIGNED AUTO_INCREMENT PRIMARY KEY' : 'INTEGER PRIMARY KEY AUTOINCREMENT';
        $intCol = $isMysql ? 'INT UNSIGNED NOT NULL' : 'INTEGER NOT NULL';
        $ts = $isMysql ? 'DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP' : "TEXT NOT NULL DEFAULT (datetime('now'))";
        $tsNull = $isMysql ? 'DATETIME NULL' : 'TEXT';
        $engine = $isMysql ? ' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci' : '';

        try {
            $this->pdo->exec("CREATE TABLE IF NOT EXISTS wc_push_queue (
                id              $pk,
                product_id      $intCol,
                wc_product_id   $intCol,
                trigger_type    VARCHAR(16) NOT NULL,
                trigger_id      $intCol,
                operation_key   VARCHAR(191) NOT NULL,
                status          VARCHAR(16) NOT NULL DEFAULT 'queued',
                attempts        INT UNSIGNED NOT NULL DEFAULT 0,
                next_attempt_at $tsNull,
                locked_at       $tsNull,
                last_error      TEXT,
                created_at      $ts,
                updated_at      $ts
                " . ($isMysql ? ', CONSTRAINT fk_wc_push_queue_product FOREIGN KEY (product_id) REFERENCES products(id)' : '') . "
            )$engine");
        } catch (PDOException $e) { if (!$this->isBenignSchemaError($e)) { throw $e; } }

        foreach ([
            $isMysql
                ? 'ALTER TABLE wc_push_queue ADD UNIQUE KEY uq_wc_push_queue_operation_key (operation_key)'
                : 'CREATE UNIQUE INDEX IF NOT EXISTS idx_wc_push_queue_operation_key ON wc_push_queue(operation_key)',
            'CREATE INDEX idx_wc_push_queue_status_next_attempt ON wc_push_queue(status, next_attempt_at)',
            'CREATE INDEX idx_wc_push_queue_product_id ON wc_push_queue(product_id)',
        ] as $sql) {
            try {
                $this->pdo->exec($sql);
            } catch (PDOException $e) { if (!$this->isBenignSchemaError($e)) { throw $e; } }
        }
    }

    /**
     * 1.2.0 — csak INDEX, séma-módosítás nélkül. A Dashboard/riportok
     * (lásd a fájl végén, "1.2.0 — Dashboard / Riportok" szakasz) új
     * lekérdezéseket vezetnek be `returns`/`return_items`/`stock_take_items`
     * táblákra dátum- ill. termék-szerinti szűréssel/csoportosítással —
     * ezeknek eddig NEM volt indexük (a returns/return_items eddig
     * kizárólag sale_id/return_id szerint volt lekérdezve, lásd
     * getDailySummary()), enélkül minden riport-lekérdezés teljes
     * tábla-bejárást igényelne, ami nagyobb adatmennyiségnél (lásd a kör
     * 18. pontja, "performance") már érezhető lassulást okozna.
     */
    private function migrateV25ReportingIndexes(): void
    {
        foreach ([
            'CREATE INDEX idx_returns_created_at ON returns(created_at)',
            'CREATE INDEX idx_return_items_product_id ON return_items(product_id)',
            'CREATE INDEX idx_stock_take_items_product_id ON stock_take_items(product_id)',
        ] as $sql) {
            if ($this->driver !== 'mysql') {
                $sql = str_replace('CREATE INDEX ', 'CREATE INDEX IF NOT EXISTS ', $sql);
            }
            try {
                $this->pdo->exec($sql);
            } catch (PDOException $e) {
                if (!$this->isBenignSchemaError($e)) {
                    throw $e;
                }
            }
        }
    }

    /**
     * 1.4.0 — "Operations & Reliability": egységes rendszeresemény-napló
     * (backup/WooCommerce/NAV/updater/nyomtató/SMTP/auth események egy
     * közös idővonalon). Szándékosan KÜLÖN tábla, NEM az audit_log
     * bővítése — az audit_log egy DOLGOZÓI cselekvés-naplót ír le ("ki
     * csinált mit"), aminek nincs `staff_id`-tól független "a háttérben,
     * cron-ból magától lefutott" fogalma, és nincs severity/status/
     * category mezője sem — ezeket ráhúzni az audit_log-ra összemosná a
     * két, tudatosan elkülönített koncepciót (emberi cselekvés-napló vs.
     * rendszeresemény-idővonal). A `sync_log` táblát sem bővítettük:
     * annak semmilyen retention/cleanup mechanizmusa nincs (korlátlanul
     * nő), és kizárólag WooCommerce termékszinkron-üzenetekre való —
     * nem terjed ki NAV/backup/updater/nyomtató/SMTP/auth eseményekre.
     */
    private function migrateV26SystemEvents(): void
    {
        $isMysql = $this->driver === 'mysql';
        $pk = $isMysql ? 'INT UNSIGNED AUTO_INCREMENT PRIMARY KEY' : 'INTEGER PRIMARY KEY AUTOINCREMENT';
        $ts = $isMysql ? 'DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP' : "TEXT NOT NULL DEFAULT (datetime('now'))";

        try {
            $this->pdo->exec("CREATE TABLE IF NOT EXISTS system_events (
                id               $pk,
                category         VARCHAR(32) NOT NULL,
                event_type       VARCHAR(64) NOT NULL,
                severity         VARCHAR(16) NOT NULL,
                status           VARCHAR(16) NOT NULL,
                user_message     TEXT NOT NULL,
                technical_detail TEXT,
                created_at       $ts
            )" . ($isMysql ? ' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci' : ''));
        } catch (PDOException $e) { if (!$this->isBenignSchemaError($e)) { throw $e; } }

        foreach ([
            $this->driver !== 'mysql'
                ? 'CREATE INDEX IF NOT EXISTS idx_system_events_created_at ON system_events(created_at)'
                : 'CREATE INDEX idx_system_events_created_at ON system_events(created_at)',
            $this->driver !== 'mysql'
                ? 'CREATE INDEX IF NOT EXISTS idx_system_events_category_severity ON system_events(category, severity)'
                : 'CREATE INDEX idx_system_events_category_severity ON system_events(category, severity)',
        ] as $sql) {
            try {
                $this->pdo->exec($sql);
            } catch (PDOException $e) { if (!$this->isBenignSchemaError($e)) { throw $e; } }
        }
    }

    // ---- Önfrissítés (GitHub Release-alapú) ----

    private const UPDATE_TERMINAL_STATES = ['idle', 'completed', 'failed', 'rolled_back', 'manual_recovery_required'];

    public function getUpdateState(): array
    {
        $row = $this->pdo->query('SELECT * FROM update_state WHERE id = 1')->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            // Csak akkor fordulhat elő, ha valaki kézzel törölte a sort — a
            // migráció mindig garantálja a meglétét. Helyben pótoljuk, hogy
            // a hívó sose kapjon váratlan null-t.
            $now = date('Y-m-d H:i:s');
            $this->pdo->prepare('INSERT INTO update_state (id, state, current_version, created_at, updated_at) VALUES (1, ?, ?, ?, ?)')
                ->execute(['idle', AppVersion::CURRENT, $now, $now]);
            $row = $this->pdo->query('SELECT * FROM update_state WHERE id = 1')->fetch(PDO::FETCH_ASSOC);
        }
        return $row;
    }

    /** Tetszőleges részhalmazát frissíti az update_state sornak — a hívó felelőssége csak érvényes oszlopneveket adni (lásd $allowedFields). */
    public function updateUpdateState(array $fields): void
    {
        $allowedFields = [
            'state', 'current_version', 'latest_version', 'latest_release_tag', 'latest_commit_sha',
            'latest_release_notes', 'latest_published_at', 'latest_checked_at', 'last_check_error',
            'last_successful_update_at', 'last_successful_update_version', 'progress_message',
            'install_requested_by', 'install_requested_at',
        ];
        $set = [];
        $params = [];
        foreach ($fields as $key => $value) {
            if (!in_array($key, $allowedFields, true)) {
                continue;
            }
            $set[] = "$key = ?";
            $params[] = $value;
        }
        if (!$set) {
            return;
        }
        $set[] = 'updated_at = ?';
        $params[] = date('Y-m-d H:i:s');

        $this->pdo->prepare('UPDATE update_state SET ' . implode(', ', $set) . ' WHERE id = 1')->execute($params);
    }

    /**
     * Durable, atomikus zár-igénylés a frissítési folyamatra — ugyanaz a
     * "UPDATE ... WHERE (nincs zár VAGY elavult a zár)" minta, mint
     * claimIncomingInvoiceSync()-nál, csak egy KÜLÖN (lock_token/
     * lock_started_at/lock_hostname), a state-től független mezőcsoporton.
     * SZÁNDÉKOSAN nem magára a `state`-re támaszkodik a zár (egy 'idle'
     * állapotú sor is lehetne zárolt, pl. checkNow() rövid ideig tartó
     * ellenőrzés közben) — a zár és az állapotgép két külön, bár
     * összefüggő invariáns.
     *
     * @return bool true, ha EZ a hívás szerezte meg a zárat.
     */
    public function claimUpdateLock(string $token, string $hostname, int $staleAfterSeconds = 3600): bool
    {
        $now = date('Y-m-d H:i:s');
        $staleBefore = date('Y-m-d H:i:s', time() - $staleAfterSeconds);

        $stmt = $this->pdo->prepare("
            UPDATE update_state
            SET lock_token = ?, lock_started_at = ?, lock_hostname = ?, updated_at = ?
            WHERE id = 1 AND (lock_token IS NULL OR lock_started_at IS NULL OR lock_started_at < ?)
        ");
        $stmt->execute([$token, $now, $hostname, $now, $staleBefore]);
        return $stmt->rowCount() > 0;
    }

    /** Csak a zár TÉNYLEGES birtokosa oldhatja fel — egy elavult, már mást ír le token nem szabadíthat fel egy azóta újra megszerzett zárat. */
    public function releaseUpdateLock(string $token): void
    {
        $this->pdo->prepare("
            UPDATE update_state
            SET lock_token = NULL, lock_started_at = NULL, lock_hostname = NULL, updated_at = ?
            WHERE id = 1 AND lock_token = ?
        ")->execute([date('Y-m-d H:i:s'), $token]);
    }

    public function isUpdateLockHeld(int $staleAfterSeconds = 3600): bool
    {
        $state = $this->getUpdateState();
        if (empty($state['lock_token']) || empty($state['lock_started_at'])) {
            return false;
        }
        return strtotime($state['lock_started_at']) >= time() - $staleAfterSeconds;
    }

    public function insertUpdateHistory(array $entry): int
    {
        $now = date('Y-m-d H:i:s');
        $this->pdo->prepare("
            INSERT INTO update_history
                (from_version, to_version, release_tag, commit_sha, trigger_source, actor, started_at, state, created_at)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
        ")->execute([
            $entry['from_version'],
            $entry['to_version'],
            $entry['release_tag'] ?? null,
            $entry['commit_sha'] ?? null,
            $entry['trigger_source'],
            $entry['actor'] ?? null,
            $entry['started_at'] ?? $now,
            $entry['state'] ?? 'checking',
            $now,
        ]);
        return (int) $this->pdo->lastInsertId();
    }

    public function updateUpdateHistory(int $id, array $fields): void
    {
        $allowedFields = ['state', 'finished_at', 'error', 'backup_reference', 'rollback_state', 'to_version', 'commit_sha', 'release_tag'];
        $set = [];
        $params = [];
        foreach ($fields as $key => $value) {
            if (!in_array($key, $allowedFields, true)) {
                continue;
            }
            $set[] = "$key = ?";
            $params[] = $value;
        }
        if (!$set) {
            return;
        }
        $params[] = $id;
        $this->pdo->prepare('UPDATE update_history SET ' . implode(', ', $set) . ' WHERE id = ?')->execute($params);
    }

    public function listUpdateHistory(int $limit = 50): array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM update_history ORDER BY started_at DESC, id DESC LIMIT ?');
        $stmt->bindValue(1, max(1, min(500, $limit)), PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    // ---- Beérkező (NAV) számlák ----

    public function getIncomingInvoiceById(int $id): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM incoming_invoices WHERE id = ?');
        $stmt->execute([$id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    /**
     * A módosító/sztornó dokumentumok `original_invoice_number` mezője
     * alapján megkeresi a helyi táblában az EREDETI számlát (ha az is
     * szinkronizálva már) — a UI ezzel ad kattintható hivatkozást a
     * módosítás/sztornó és az eredeti számla között, ha mindkettő a
     * lokálisan szinkronizált időablakba esik.
     */
    public function findIncomingInvoiceBySupplierAndNumber(string $supplierTaxNumber, string $invoiceNumber): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM incoming_invoices WHERE supplier_tax_number = ? AND invoice_number = ? ORDER BY batch_index ASC LIMIT 1');
        $stmt->execute([$supplierTaxNumber, $invoiceNumber]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    /**
     * Egy NAV queryInvoiceDigest válasz EGY digest-bejegyzését menti el,
     * race-safe módon — `INSERT OR IGNORE`/`INSERT IGNORE` a
     * `UNIQUE(supplier_tax_number, invoice_number, batch_index)`-re (lásd
     * migrateV20IncomingInvoices() docblockja). `null`-lal tér vissza, ha
     * a sor MÁR létezett (idempotens no-op — nem hiba, ugyanaz a mintázat,
     * mint insertQueuedInvoice()-nál).
     */
    public function insertIncomingInvoiceDigestEntry(array $entry): ?array
    {
        $now = date('Y-m-d H:i:s');
        $sql = $this->driver === 'mysql'
            ? "INSERT IGNORE INTO incoming_invoices
                (nav_transaction_id, invoice_number, batch_index, supplier_tax_number, supplier_group_member_tax_number,
                 supplier_name, customer_tax_number, customer_name, invoice_operation, invoice_category,
                 original_invoice_number, modification_index, invoice_issue_date, invoice_delivery_date, payment_date,
                 payment_method, currency, net_total, vat_total, gross_total, nav_ins_date,
                 first_seen_at, last_synced_at, created_at, updated_at)
               VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)"
            : "INSERT OR IGNORE INTO incoming_invoices
                (nav_transaction_id, invoice_number, batch_index, supplier_tax_number, supplier_group_member_tax_number,
                 supplier_name, customer_tax_number, customer_name, invoice_operation, invoice_category,
                 original_invoice_number, modification_index, invoice_issue_date, invoice_delivery_date, payment_date,
                 payment_method, currency, net_total, vat_total, gross_total, nav_ins_date,
                 first_seen_at, last_synced_at, created_at, updated_at)
               VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";

        $netTotal = $entry['net_total'] ?? 0.0;
        $vatTotal = $entry['vat_total'] ?? 0.0;

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([
            $entry['nav_transaction_id'] ?? null,
            $entry['invoice_number'],
            $entry['batch_index'] ?? 0,
            $entry['supplier_tax_number'],
            $entry['supplier_group_member_tax_number'] ?? null,
            $entry['supplier_name'],
            $entry['customer_tax_number'] ?? null,
            $entry['customer_name'] ?? null,
            $entry['invoice_operation'],
            $entry['invoice_category'] ?? null,
            $entry['original_invoice_number'] ?? null,
            $entry['modification_index'] ?? null,
            $entry['invoice_issue_date'] ?? null,
            $entry['invoice_delivery_date'] ?? null,
            $entry['payment_date'] ?? null,
            $entry['payment_method'] ?? null,
            $entry['currency'] ?? 'HUF',
            $netTotal,
            $vatTotal,
            round((float) $netTotal + (float) $vatTotal, 2),
            $entry['nav_ins_date'],
            $now,
            $now,
            $now,
            $now,
        ]);

        if ($stmt->rowCount() === 0) {
            return null;
        }
        return $this->getIncomingInvoiceById((int) $this->pdo->lastInsertId());
    }

    /**
     * A LAZY részletnézet-lekérdezés (queryInvoiceData) eredményét menti:
     * a szállító országát (amit a digest nem ad) és a tételsorokat, majd
     * beállítja `detail_fetched_at`-ot, hogy a KÖVETKEZŐ megnyitás már ne
     * hívja újra a NAV-ot ugyanerre a számlára.
     *
     * @param array $items lista ['line_number','description','quantity','unit_of_measure','unit_net_price','vat_rate','net_amount','vat_amount','gross_amount']
     */
    public function saveIncomingInvoiceDetail(int $incomingInvoiceId, ?string $supplierCountry, array $items): void
    {
        $now = date('Y-m-d H:i:s');

        $this->pdo->prepare('UPDATE incoming_invoices SET supplier_country = ?, detail_fetched_at = ?, updated_at = ? WHERE id = ?')
            ->execute([$supplierCountry, $now, $now, $incomingInvoiceId]);

        $itemSql = $this->driver === 'mysql'
            ? "INSERT IGNORE INTO incoming_invoice_items
                (incoming_invoice_id, line_number, description, quantity, unit_of_measure, unit_net_price, vat_rate, net_amount, vat_amount, gross_amount, created_at)
               VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)"
            : "INSERT OR IGNORE INTO incoming_invoice_items
                (incoming_invoice_id, line_number, description, quantity, unit_of_measure, unit_net_price, vat_rate, net_amount, vat_amount, gross_amount, created_at)
               VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";
        $itemStmt = $this->pdo->prepare($itemSql);
        foreach ($items as $item) {
            $itemStmt->execute([
                $incomingInvoiceId,
                $item['line_number'],
                $item['description'] ?? null,
                $item['quantity'] ?? null,
                $item['unit_of_measure'] ?? null,
                $item['unit_net_price'] ?? null,
                $item['vat_rate'] ?? null,
                $item['net_amount'] ?? null,
                $item['vat_amount'] ?? null,
                $item['gross_amount'] ?? null,
                $now,
            ]);
        }
    }

    public function getIncomingInvoiceItems(int $incomingInvoiceId): array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM incoming_invoice_items WHERE incoming_invoice_id = ? ORDER BY line_number ASC');
        $stmt->execute([$incomingInvoiceId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * A "Beérkezett számlák" nézet szűrt listája — `listInvoices()`
     * mintájára.
     *
     * @param array $filters opcionális: date_from/date_to (invoice_issue_date-re),
     *   supplier (szállító név VAGY adószám LIKE), invoice_number, tax_number
     *   (pontos egyezés supplier_tax_number-re), operation ('CREATE'|'MODIFY'|'STORNO'),
     *   currency
     */
    public function listIncomingInvoices(array $filters = [], int $limit = 300): array
    {
        $where = [];
        $params = [];

        if (!empty($filters['date_from'])) {
            $where[] = 'invoice_issue_date >= ?';
            $params[] = $filters['date_from'];
        }
        if (!empty($filters['date_to'])) {
            $where[] = 'invoice_issue_date <= ?';
            $params[] = $filters['date_to'];
        }
        if (!empty($filters['supplier'])) {
            $where[] = '(supplier_name LIKE ? OR supplier_tax_number LIKE ?)';
            $params[] = '%' . $filters['supplier'] . '%';
            $params[] = '%' . $filters['supplier'] . '%';
        }
        if (!empty($filters['invoice_number'])) {
            $where[] = 'invoice_number LIKE ?';
            $params[] = '%' . $filters['invoice_number'] . '%';
        }
        if (!empty($filters['tax_number'])) {
            $where[] = 'supplier_tax_number = ?';
            $params[] = $filters['tax_number'];
        }
        if (!empty($filters['operation']) && in_array($filters['operation'], ['CREATE', 'MODIFY', 'STORNO'], true)) {
            $where[] = 'invoice_operation = ?';
            $params[] = $filters['operation'];
        }
        if (!empty($filters['currency'])) {
            $where[] = 'currency = ?';
            $params[] = $filters['currency'];
        }

        $sql = 'SELECT * FROM incoming_invoices';
        if ($where) {
            $sql .= ' WHERE ' . implode(' AND ', $where);
        }
        $sql .= ' ORDER BY invoice_issue_date DESC, id DESC LIMIT ' . (int) $limit;

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    // ---- Beérkező-számla sync állapotgép (race-safe) ----

    public function getIncomingInvoiceSyncState(string $provider = 'nav'): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM incoming_invoice_sync WHERE provider = ?');
        $stmt->execute([$provider]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    /**
     * Atomikusan lefoglalja a sync-sort — csak akkor sikeres, ha NEM
     * `running` állapotú, VAGY `running`, de a zárja elavult. Pontosan a
     * `claimQueuedInvoiceForSubmission()` már bevált elve (a `rowCount()`
     * dönt, nem egy korábbi SELECT), egyetlen sorra alkalmazva — ez a
     * PRIMER védelem az ellen, hogy a manuális és az automatikus sync
     * egyszerre fusson (lásd Phase 6 terv 6. pontja).
     */
    public function claimIncomingInvoiceSync(string $provider = 'nav', int $staleAfterSeconds = 1800): ?array
    {
        $now = date('Y-m-d H:i:s');
        $staleBefore = date('Y-m-d H:i:s', time() - $staleAfterSeconds);

        $stmt = $this->pdo->prepare("
            UPDATE incoming_invoice_sync
            SET status = 'running', locked_at = ?, last_attempt_at = ?, updated_at = ?
            WHERE provider = ?
              AND (status != 'running' OR locked_at IS NULL OR locked_at < ?)
        ");
        $stmt->execute([$now, $now, $now, $provider, $staleBefore]);

        if ($stmt->rowCount() === 0) {
            return null;
        }
        return $this->getIncomingInvoiceSyncState($provider);
    }

    /**
     * Ablakonkénti cursor-előrehaladás — a `running` állapotot és a
     * zárat NEM érinti (a worker még dolgozhat további ablakokon), csak
     * a magas-vízjelet tolja előre, hogy egy megszakadt/korlátozott
     * futás ne dolgozza fel feleslegesen újra a már kész ablakokat.
     */
    public function advanceIncomingInvoiceSyncCursor(string $provider, string $cursorInsDate): void
    {
        $this->pdo->prepare('UPDATE incoming_invoice_sync SET sync_cursor_ins_date = ?, updated_at = ? WHERE provider = ?')
            ->execute([$cursorInsDate, date('Y-m-d H:i:s'), $provider]);
    }

    public function markIncomingInvoiceSyncSuccess(string $provider, string $cursorInsDate, string $requestedInterval): void
    {
        $now = date('Y-m-d H:i:s');
        $this->pdo->prepare("
            UPDATE incoming_invoice_sync
            SET status = 'success', sync_cursor_ins_date = ?, last_requested_interval = ?, last_success_at = ?,
                attempts = 0, next_attempt_at = NULL, locked_at = NULL, last_error = NULL, updated_at = ?
            WHERE provider = ?
        ")->execute([$cursorInsDate, $requestedInterval, $now, $now, $provider]);
    }

    /**
     * Átmeneti (hálózati/timeout/NAV 5xx) hiba után `retry`-ra állítja,
     * a megadott $nextAttemptAt időpontig várakozásra — a locked_at
     * felszabadul, hogy a claimIncomingInvoiceSync() a következő
     * cron-tick-nél (ha a next_attempt_at már elmúlt) újra próbálkozhasson.
     */
    public function markIncomingInvoiceSyncRetry(string $provider, string $error, string $nextAttemptAt, int $attempts): void
    {
        $this->pdo->prepare("
            UPDATE incoming_invoice_sync
            SET status = 'retry', last_error = ?, attempts = ?, next_attempt_at = ?, locked_at = NULL, updated_at = ?
            WHERE provider = ?
        ")->execute([$error, $attempts, $nextAttemptAt, date('Y-m-d H:i:s'), $provider]);
    }

    /**
     * Végleges, NEM újrapróbálandó hiba (üzleti validációs hiba,
     * hitelesítési hiba, kimerült backoff) — terminális, admin
     * figyelmét igényli, de a legközelebbi manuális/automatikus
     * próbálkozás (a claim WHERE-je `status != 'running'`-t is enged)
     * TOVÁBBRA IS újra megpróbálhatja, csak nincs automatikus retry-ütemezés rá.
     */
    public function markIncomingInvoiceSyncFailed(string $provider, string $error): void
    {
        $this->pdo->prepare("
            UPDATE incoming_invoice_sync
            SET status = 'failed', last_error = ?, next_attempt_at = NULL, locked_at = NULL, updated_at = ?
            WHERE provider = ?
        ")->execute([$error, date('Y-m-d H:i:s'), $provider]);
    }

    /**
     * Az ELSŐ sync kezdő időpontját állítja be (admin által választott 7
     * nap / 30 nap / egyedi tartomány, lásd nav-incoming-sync-trigger.php)
     * — de KIZÁRÓLAG akkor hat, ha `sync_cursor_ins_date` MÉG NULL (azaz
     * még sosem futott sikeres/részleges sync). Race-safe, feltételes
     * UPDATE: ha két admin-kérés (vagy egy admin-kérés és a cron) épp
     * egyszerre próbálná beállítani az első sync kezdőpontját, csak az
     * NYER, amelyiket a DB ténylegesen elsőként hajtja végre — a
     * másodiknak a WHERE feltétele már nem teljesül, no-op marad. Egy már
     * folyamatban lévő/befejezett synchez ez a metódus SOSE nyúl hozzá
     * (nem írja felül a cursor-t utólag), azt kizárólag a
     * advanceIncomingInvoiceSyncCursor()/markIncomingInvoiceSyncSuccess()
     * teheti.
     */
    public function setIncomingInvoiceSyncCursorIfUnset(string $provider, string $cursorInsDate): void
    {
        $this->pdo->prepare('UPDATE incoming_invoice_sync SET sync_cursor_ins_date = ?, updated_at = ? WHERE provider = ? AND sync_cursor_ins_date IS NULL')
            ->execute([$cursorInsDate, date('Y-m-d H:i:s'), $provider]);
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

    public function listDistinctBrands(): array
    {
        $stmt = $this->pdo->query("SELECT DISTINCT brand FROM products WHERE brand IS NOT NULL AND brand != '' ORDER BY brand");
        return array_column($stmt->fetchAll(PDO::FETCH_ASSOC), 'brand');
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
                    preferred_supplier_id = :preferred_supplier_id,
                    short_description = :short_description, long_description = :long_description,
                    image_filename = :image_filename, image_alt = :image_alt, brand = :brand,
                    sync_to_woocommerce = :sync_to_woocommerce, updated_at = :now
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

        // P1-3 javítás: egy dupla kattintás (vagy egy elveszett válasz utáni
        // automatikus kliens-oldali újrapróbálkozás) két, TARTALMILAG AZONOS
        // "új termék" kérést küldhetett be — a barcode-dal rendelkező esetet
        // a hívó (webroot/api/product-save.php) MÁR véd a meglévő
        // findProductByBarcode()-ellenőrzéssel, DE barcode NÉLKÜL (a
        // leggyakoribb eset egy gyors termékfelvitelnél) semmi nem
        // akadályozta meg, hogy két azonos INSERT lefusson. Ez EGY, kis,
        // gyakorlati védelmi vonal (NEM egy teljes idempotencia-kulcs-
        // architektúra, mint sale.php-nál) — ŐSZINTE KORLÁT: SELECT-majd-
        // INSERT, tehát NEM atomikus két, ténylegesen egyidejű (néhány
        // ezredmásodpercen belüli) kérésre; AZT a réteget a kliens-oldali
        // gombletiltás adja (lásd product-modal.js). Ez a réteg a
        // GYAKORLATBAN releváns, néhány másodperces időablakon belüli,
        // tartalmilag azonos ismételt beküldést fedi le.
        $recentDuplicateId = $this->findRecentlyCreatedIdenticalProduct($p);
        if ($recentDuplicateId !== null) {
            return $recentDuplicateId;
        }

        $stmt = $this->pdo->prepare('
            INSERT INTO products (
                name, unit, group_name, cikkszam, vtsz, barcode, currency,
                vat_rate, net_price, price, purchase_price_net, stock_qty,
                weight, volume, notes, show_pricelist, show_webshop, is_deleted, low_stock_threshold, preferred_supplier_id,
                short_description, long_description, image_filename, image_alt, brand, sync_to_woocommerce, updated_at
            ) VALUES (
                :name, :unit, :group_name, :cikkszam, :vtsz, :barcode, :currency,
                :vat_rate, :net_price, :price, 0, 0,
                :weight, :volume, :notes, :show_pricelist, :show_webshop, :is_deleted, :low_stock_threshold, :preferred_supplier_id,
                :short_description, :long_description, :image_filename, :image_alt, :brand, :sync_to_woocommerce, :now
            )
        ');
        $stmt->execute($this->productParams($p, $now));
        return (int) $this->pdo->lastInsertId();
    }

    /** @see saveProduct() a hívási hely docblokkjáért (P1-3, pontos indoklás/korlát). */
    private function findRecentlyCreatedIdenticalProduct(array $p): ?int
    {
        $stmt = $this->pdo->prepare('
            SELECT id, updated_at FROM products
            WHERE name = :name AND unit = :unit AND currency = :currency
              AND ROUND(net_price, 2) = ROUND(:net_price, 2)
              AND ROUND(price, 2) = ROUND(:price, 2)
            ORDER BY id DESC
            LIMIT 1
        ');
        $stmt->execute([
            ':name'      => $p['name'],
            ':unit'      => ($p['unit'] ?? '') ?: 'db',
            ':currency'  => ($p['currency'] ?? '') ?: 'HUF',
            ':net_price' => (float) $p['net_price'],
            ':price'     => (float) $p['price'],
        ]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row === false || empty($row['updated_at'])) {
            return null;
        }

        $updatedAtTs = strtotime((string) $row['updated_at']);
        if ($updatedAtTs === false || (time() - $updatedAtTs) > 5) {
            return null;
        }

        return (int) $row['id'];
    }

    private function productParams(array $p, string $now): array
    {
        return [
            ':name'           => $p['name'],
            ':unit'           => ($p['unit'] ?? '') ?: 'db',
            ':group_name'     => $p['group_name'] ?? null,
            ':cikkszam'       => $p['cikkszam'] ?? null,
            ':vtsz'           => $p['vtsz'] ?? null,
            ':barcode'        => ($p['barcode'] ?? '') ?: null,
            ':currency'       => ($p['currency'] ?? '') ?: 'HUF',
            ':vat_rate'       => (string) $p['vat_rate'],
            ':net_price'      => (float) $p['net_price'],
            ':price'          => (float) $p['price'],
            ':weight'         => ($p['weight'] ?? '') !== '' ? (float) $p['weight'] : null,
            ':volume'         => ($p['volume'] ?? '') !== '' ? (float) $p['volume'] : null,
            ':notes'          => $p['notes'] ?? null,
            ':show_pricelist' => !empty($p['show_pricelist']) ? 1 : 0,
            ':show_webshop'   => !empty($p['show_webshop']) ? 1 : 0,
            ':is_deleted'     => !empty($p['is_deleted']) ? 1 : 0,
            ':low_stock_threshold' => ($p['low_stock_threshold'] ?? '') !== '' ? (int) $p['low_stock_threshold'] : null,
            ':preferred_supplier_id' => !empty($p['preferred_supplier_id']) ? (int) $p['preferred_supplier_id'] : null,
            ':short_description' => $p['short_description'] ?? null,
            ':long_description' => $p['long_description'] ?? null,
            ':image_filename'   => $p['image_filename'] ?? null,
            ':image_alt'        => $p['image_alt'] ?? null,
            ':brand'            => $p['brand'] ?? null,
            ':sync_to_woocommerce' => array_key_exists('sync_to_woocommerce', $p) ? (!empty($p['sync_to_woocommerce']) ? 1 : 0) : 1,
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
            'preferred_supplier_id' => $existing['preferred_supplier_id'] ?? null,
            'short_description' => $existing['short_description'] ?? null,
            'long_description' => $existing['long_description'] ?? null,
            'image_filename' => $existing['image_filename'] ?? null,
            'image_alt'      => $existing['image_alt'] ?? null,
            'brand'          => $existing['brand'] ?? null,
            'sync_to_woocommerce' => $existing['sync_to_woocommerce'] ?? true,
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

        // Védelmi mélység — lásd PriceValidator docblockja: a WooCommerce
        // REST API-nak elméletileg SOSE kellene negatív árat visszaadnia
        // (a WC saját admin felülete is tiltja), de ha mégis (hibás
        // harmadik fél plugin, kézi API-hívás a WC oldalán stb.), egy
        // meglévő helyi terméknél inkább MEGTARTJUK a régi, érvényes árat,
        // mint hogy egy nyilvánvalóan hibás értékkel felülírjuk — egy ÚJ
        // (helyben még nem létező) terméknél pedig 0-ra esik vissza,
        // ugyanúgy, mint WooCommerceClient::normaliseProduct() hiányzó ár
        // esetén (lásd ott).
        if (!PriceValidator::isValid($p['price'] ?? null)) {
            $this->logSync('pull', $existing['id'] ?? null, "Érvénytelen ár érkezett WooCommerce-ről '{$p['name']}'-hez (WC #{$p['wc_product_id']}) — az ár mező kihagyva ennél a szinkronnál.");
            $p['price'] = $existing['price'] ?? 0.0;
        }
        if (!$existing && !empty($p['barcode'])) {
            $byBarcode = $this->findProductByBarcode($p['barcode']);
            // A vonalkód-egyezés csak akkor számít biztonságos párosításnak,
            // ha a helyi termék MÉG NINCS másik WooCommerce termékhez kötve —
            // ha már van (pl. két különböző WC termék véletlenül azonos
            // vonalkóddal), a szinkron ne írja át csendben a meglévő
            // kapcsolatot egy másikra, mert az mindkét oldalon összekutyulhatja
            // az adatokat. Ilyenkor inkább kihagyjuk, és naplózzuk a ütközést.
            if ($byBarcode && !empty($byBarcode['wc_product_id']) && (int) $byBarcode['wc_product_id'] !== (int) $p['wc_product_id']) {
                $this->logSync('pull', (int) $byBarcode['id'], "Vonalkód-ütközés: '{$p['name']}' (WC #{$p['wc_product_id']}) ugyanazt a vonalkódot használja, mint a már WC #{$byBarcode['wc_product_id']}-hoz kötött '{$byBarcode['name']}' — kihagyva.");
                return;
            }
            $existing = $byBarcode;
        }

        // Ha egy meglévő terméknél ki van kapcsolva a WooCommerce-szinkron
        // ("csak üzletben"), a behúzás ne írja felül — se most, se a jövőben,
        // amíg vissza nem kapcsolják.
        if ($existing && empty($existing['sync_to_woocommerce'])) {
            return;
        }

        $now = date('c');

        if ($existing) {
            // SZÁNDÉKOSAN nem írjuk felül a stock_qty-t egy már ismert,
            // helyi termék pull-szinkronjakor. Ez az app mindenhol máshol
            // (eladás, beszerzés, leltár) a HELYI adatbázist kezeli a
            // készlet egyetlen hiteles forrásának, és minden változást
            // onnan PUSH-ol ki a WooCommerce felé (lásd sale.php,
            // purchase-save.php, stock-take-complete.php) — soha nem
            // fordítva. Egy behúzott, a lekérdezés pillanatában már
            // elavulttá válható WC-érték csendben felülírhatná/eltüntetné
            // egy közben (a WC-lekérdezés és e tranzakció írása közötti
            // időben) lezajlott helyi eladás/beszerzés készlethatását.
            $stmt = $this->pdo->prepare('
                UPDATE products
                SET wc_product_id = :wc_product_id,
                    sku = :sku,
                    barcode = :barcode,
                    name = :name,
                    price = :price,
                    short_description = :short_description,
                    long_description = :long_description,
                    brand = :brand,
                    wc_synced_at = :now
                WHERE id = :id
            ');
            $stmt->execute([
                ':wc_product_id' => $p['wc_product_id'],
                ':sku'           => $p['sku'],
                // WC-oldali üres/hiányzó érték (pl. nincs _barcode meta)
                // NEM törölheti a helyi vonalkódot — az a POS-os
                // vonalkód-olvasás alapja, elvesztése csendben törné a
                // kasszai keresést. Csak akkor írjuk felül, ha WC tényleg
                // ad egy nem üres értéket — ugyanaz a "ne csendben nullázd
                // ki a helyi mezőt" minta, mint importUpsertProduct()-nál
                // a preferred_supplier_id-nál.
                ':barcode'       => (($p['barcode'] ?? '') !== '') ? $p['barcode'] : $existing['barcode'],
                ':name'          => $p['name'],
                ':price'         => $p['price'],
                ':short_description' => (($p['short_description'] ?? '') !== '') ? $p['short_description'] : ($existing['short_description'] ?? null),
                ':long_description'  => (($p['long_description'] ?? '') !== '') ? $p['long_description'] : ($existing['long_description'] ?? null),
                ':brand'         => (($p['brand'] ?? '') !== '') ? $p['brand'] : ($existing['brand'] ?? null),
                ':now'           => $now,
                ':id'            => $existing['id'],
            ]);
        } else {
            $stmt = $this->pdo->prepare('
                INSERT INTO products (wc_product_id, sku, barcode, name, price, stock_qty, short_description, long_description, brand, updated_at, wc_synced_at)
                VALUES (:wc_product_id, :sku, :barcode, :name, :price, :stock_qty, :short_description, :long_description, :brand, :now, :now)
            ');
            $stmt->execute([
                ':wc_product_id' => $p['wc_product_id'],
                ':sku'           => $p['sku'],
                ':barcode'       => $p['barcode'],
                ':name'          => $p['name'],
                ':price'         => $p['price'],
                ':stock_qty'     => $p['stock_qty'],
                ':short_description' => $p['short_description'] ?? null,
                ':long_description'  => $p['long_description'] ?? null,
                ':brand'         => $p['brand'] ?? null,
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

    /**
     * Csak a "mikor szinkronizáltunk utoljára a WooCommerce felé" jelzőt
     * frissíti, a stock_qty-t nem — arra az esetre, amikor a helyi
     * készlet már helyesen áll (relatív decrementStock/incrementStock
     * történt), és csak a WC-push megtörténtét kell jelezni, anélkül
     * hogy egy elavult, abszolút értékkel felülírnánk a közben
     * megváltozott készletet.
     */
    public function touchWcSyncedAt(int $productId): void
    {
        $stmt = $this->pdo->prepare('UPDATE products SET wc_synced_at = :now WHERE id = :id');
        $stmt->execute([':now' => date('c'), ':id' => $productId]);
    }

    /**
     * @throws PDOException UNIQUE constraint hibával, ha $idempotencyKey
     *         nem üres és már létezik egy sale ugyanezzel a kulccsal — ez a
     *         tényleges atomikus védelem két majdnem egyidejű, ugyanazt az
     *         idempotencia-kulcsot használó kérés ellen: a UNIQUE INDEX
     *         miatt az adatbázis maga garantálja, hogy csak EGYIKÜK
     *         sikerülhet, még ha mindkettő ugyanabban a pillanatban is
     *         próbálkozik (nem "ellenőrizd, majd írd be" versenyhelyzet).
     *         A hívónak (api/sale.php) ezt a konkrét hibát el kell
     *         kapnia, és findSaleByIdempotencyKey()-jel visszaadnia az
     *         (időközben a MÁSIK kérés által létrehozott) eredeti eladást
     *         újrafuttatás helyett.
     */
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
        ?int $staffId = null,
        ?string $idempotencyKey = null,
        ?string $idempotencyFingerprint = null
    ): int {
        // A token a nyugta bejelentkezés nélküli (QR-kódos) megtekintéséhez
        // kell — kitalálhatatlan, ellentétben magával a sorszámozott
        // eladás-azonosítóval.
        $receiptToken = bin2hex(random_bytes(24));

        $stmt = $this->pdo->prepare('
            INSERT INTO sales (total, payment_method, buyer_name, customer_id, loyalty_points_earned, loyalty_points_redeemed, coupon_id, coupon_discount, gift_card_redeemed, staff_id, status, receipt_token, idempotency_key, idempotency_fingerprint, created_at)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ');
        $stmt->execute([
            $total, $paymentMethod, $buyerName, $customerId, $loyaltyPointsEarned, $loyaltyPointsRedeemed,
            $couponId, $couponDiscount, $giftCardRedeemed, $staffId, 'completed', $receiptToken,
            ($idempotencyKey !== null && $idempotencyKey !== '') ? $idempotencyKey : null,
            ($idempotencyFingerprint !== null && $idempotencyFingerprint !== '') ? $idempotencyFingerprint : null,
            date('Y-m-d H:i:s'),
        ]);
        return (int) $this->pdo->lastInsertId();
    }

    public function findSaleByIdempotencyKey(string $key): ?array
    {
        if ($key === '') {
            return null;
        }
        $stmt = $this->pdo->prepare('SELECT * FROM sales WHERE idempotency_key = ?');
        $stmt->execute([$key]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
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

    /**
     * Atomikusan "lefoglalja" a számla-kiállítás jogát egy eladáshoz —
     * csak akkor sikeres, ha a sale-nek MÉG NINCS számlaszáma, ÉS nincs
     * (vagy elévült) egy folyamatban lévő korábbi foglalása. Az UPDATE
     * WHERE-je ugyanazt a mintát követi, mint redeemGiftCard()/
     * incrementCouponUsage(): a feltétel-ellenőrzés és a foglalás egyetlen
     * atomikus lépés, hogy két majdnem egyidejű kérés (pl. a webshop-
     * rendelés számlázása duplán elküldve) közül csak EGYIK hívhassa
     * ténylegesen a Számlázz.hu-t.
     *
     * Az "elévült" ág (invoice_claim_at régebbi, mint $staleAfterSeconds)
     * önjavító helyreállítás: ha egy korábbi kérés a Számlázz.hu-hívás
     * KÖZBEN megszakadt (PHP-folyamat leállt, időtúllépés a válaszban),
     * a foglalás enélkül örökre "beragadva" maradna, és a számlázás
     * SOSE lenne újra próbálható erre az eladásra. $staleAfterSeconds a
     * SzamlazzClient HTTP időkorlátjánál (30s) bőven nagyobb legyen, hogy
     * egy ténylegesen még folyamatban lévő, csak lassú hívást ne
     * előzhessen meg egy türelmetlen újrapróbálkozás.
     *
     * FONTOS, DOKUMENTÁLT KORLÁT (P1-5 ÓTA RÉSZLEGESEN KEZELVE): ez a
     * foglalás csak azt garantálja, hogy a HELYI adatbázisban csak egy
     * kérés kezdhet bele a Számlázz.hu hívásba. Ha a hívás ténylegesen
     * elindul, de a VÁLASZ vész el (hálózati hiba a kérés UTÁN), a
     * Számlázz.hu oldalán a számla elkészülhetett, miközben a helyi
     * állapot bizonytalan — a Számlázz.hu Számla Agent API nem kínál
     * idegazolt, dokumentált idempotencia-kulcsot a kiküszöbölésére
     * (csak a szamlaKulsoAzon mezőt, ami NEM garantált egyedi-kiállítási
     * védelem a dokumentáció szerint). EMIATT egy ilyen VALÓDI, transport-
     * szintű hiba (a kérésre EGYÁLTALÁN nem érkezett válasz) NEM vezet
     * automatikus retry-hoz — a hívó (SzamlazzInvoiceProvider::issueSync())
     * a sale-t 'invoice_uncertain' állapotba teszi
     * (markSaleInvoiceUncertain()), amit az alábbi WHERE-feltétel
     * STRUKTURÁLISAN kizár az újra-claim-elésből, amíg admin kézzel fel
     * nem oldja (resolveUncertainSzamlazzInvoice()) — "vakon SOSE küld
     * második számlát" immár garantált, a korábbi "elévült foglalás utáni
     * automatikus újrapróbálkozás" kockázat helyett.
     */
    public function tryClaimInvoiceIssuance(int $saleId, int $staleAfterSeconds = 90): bool
    {
        $staleBefore = date('Y-m-d H:i:s', time() - $staleAfterSeconds);
        $stmt = $this->pdo->prepare("
            UPDATE sales
            SET invoice_claim_at = :now
            WHERE id = :id
              AND szamlazz_invoice_number IS NULL
              AND status != 'invoice_uncertain'
              AND (invoice_claim_at IS NULL OR invoice_claim_at < :staleBefore)
        ");
        $stmt->execute([':now' => date('Y-m-d H:i:s'), ':id' => $saleId, ':staleBefore' => $staleBefore]);
        return $stmt->rowCount() > 0;
    }

    /**
     * P1-5: a Számlázz.hu-hívás VÁLASZA elveszett (transport-szintű hiba
     * — pl. hálózati timeout, a kérés talán megérkezett, talán nem), tehát
     * NEM TUDHATÓ, hogy a számla ténylegesen kiállításra került-e. A sor
     * 'invoice_uncertain' állapotba kerül — lásd tryClaimInvoiceIssuance()
     * docblockja a strukturális garanciáért (nincs automatikus retry).
     */
    public function markSaleInvoiceUncertain(int $saleId, string $error): void
    {
        $this->pdo->prepare("UPDATE sales SET status = 'invoice_uncertain', invoice_claim_at = NULL WHERE id = ?")
            ->execute([$saleId]);
    }

    /**
     * Admin-kezdeményezett kézi feloldás egy 'invoice_uncertain' sorra —
     * csak ilyen állapotból engedélyezett (atomikus, feltételes UPDATE).
     * KÉT lehetséges kimenet:
     *   - $foundInvoiceNumber KITÖLTVE: az admin a Számlázz.hu felületén
     *     ellenőrizve MEGTALÁLTA a ténylegesen kiállított számlát — a
     *     sale ettől rögzül számlázottnak, ÚJ kísérlet NÉLKÜL (a
     *     duplikátum-kockázat itt sose merül fel, mert nem hívjuk újra
     *     a Számlázz.hu-t).
     *   - $foundInvoiceNumber NULL: az admin megerősítette, hogy NEM
     *     készült számla — a sor visszaáll 'completed'-re, a KÖVETKEZŐ
     *     normál számlázási kísérlet (pl. a webshop-order-invoice.php
     *     újbóli meghívása) ismét megpróbálhatja.
     *
     * A `sales` mellett a szolgáltató-független `invoices` TÜKÖR-sort is
     * rendezi (lásd upsertInvoiceMirror() docblokkja) — enélkül a "Kimenő
     * számlák" nézet a feloldás UTÁN is a régi "uncertain_manual" badge-et
     * mutatná, holott a helyi állapot már rendezve van.
     */
    public function resolveUncertainSzamlazzInvoice(int $saleId, ?string $foundInvoiceNumber): bool
    {
        if ($foundInvoiceNumber !== null && $foundInvoiceNumber !== '') {
            $stmt = $this->pdo->prepare("
                UPDATE sales SET szamlazz_invoice_number = ?, status = 'completed', invoice_claim_at = NULL
                WHERE id = ? AND status = 'invoice_uncertain'
            ");
            $stmt->execute([$foundInvoiceNumber, $saleId]);
            if ($stmt->rowCount() > 0) {
                $this->pdo->prepare("
                    UPDATE invoices SET status = 'done', invoice_number = ?, issued_at = ?, last_error = NULL, updated_at = ?
                    WHERE sale_id = ? AND provider = 'szamlazz'
                ")->execute([$foundInvoiceNumber, date('Y-m-d H:i:s'), date('Y-m-d H:i:s'), $saleId]);
            }
        } else {
            $stmt = $this->pdo->prepare("
                UPDATE sales SET status = 'completed', invoice_claim_at = NULL
                WHERE id = ? AND status = 'invoice_uncertain'
            ");
            $stmt->execute([$saleId]);
            if ($stmt->rowCount() > 0) {
                // Nem tudjuk, hogy KÉSZÜLT-e számla, admin megerősítette,
                // hogy nem — a tükör-sor ettől kezdve semmilyen aktív
                // problémát nem ír le (a sale ismét "friss, még nem
                // számlázott" állapotú), ezért törlődik, NEM egy hamis
                // "failed"/"done" státusszal marad a UI-ban.
                $this->pdo->prepare("DELETE FROM invoices WHERE sale_id = ? AND provider = 'szamlazz' AND status = 'uncertain_manual'")
                    ->execute([$saleId]);
            }
        }
        return $stmt->rowCount() > 0;
    }

    public function attachInvoiceToSale(int $saleId, ?string $invoiceNumber, ?string $pdfPath, string $status): void
    {
        // invoice_claim_at nullázása mindig együtt jár — akár sikerült a
        // számla (a szamlazz_invoice_number már úgyis örökre letiltja az
        // újra-foglalást), akár nem (invoice_failed esetén ez engedi meg,
        // hogy egy retry ÚJRA lefoglalhassa, ne kelljen kivárni a
        // staleAfterSeconds ablakot egy már ismerten véglegesen lezárult
        // kísérlet után).
        $stmt = $this->pdo->prepare('
            UPDATE sales SET szamlazz_invoice_number = ?, szamlazz_pdf_path = ?, status = ?, invoice_claim_at = NULL WHERE id = ?
        ');
        $stmt->execute([$invoiceNumber, $pdfPath, $status, $saleId]);
    }

    /**
     * Egy (szinkron, azonnal lezáruló — jelenleg csak Számlázz.hu-s)
     * számlázási kísérlet eredményének tükrözése az egységes `invoices`
     * táblába, hogy egy jövőbeli, szolgáltató-független "Kimenő számlák"
     * nézet egyetlen helyről olvashasson. A tényleges Számlázz.hu-
     * specifikus állapotot (sales.szamlazz_invoice_number/szamlazz_pdf_path/
     * status/invoice_claim_at) továbbra is KIZÁRÓLAG attachInvoiceToSale()
     * kezeli, változatlanul — ez a metódus csak egy MÁSODLAGOS,
     * upsert-elt tükör-bejegyzést ír, sose helyettesíti azt, és a
     * meglévő számlázási folyamat viselkedését nem befolyásolja (ha ez a
     * hívás bármiért elhasalna, azt a hívó — SzamlazzInvoiceProvider —
     * szándékosan elnyeli, nem engedi meghiúsítani a már ténylegesen
     * megtörtént számlázást).
     *
     * UPSERT, mert egy korábban sikertelen (invoice_failed) kísérlet
     * utáni manuális újrapróbálkozás ugyanarra a sale_id+provider
     * kombinációra fut újra — a UNIQUE(sale_id, provider) index miatt ez
     * módosítja, nem duplikálja a korábbi tükör-sort.
     */
    public function upsertInvoiceMirror(
        int $saleId,
        string $provider,
        bool $success,
        ?string $invoiceNumber,
        ?string $pdfPath,
        ?string $error,
        float $netTotal,
        float $vatTotal,
        float $grossTotal,
        string $currency,
        ?string $statusOverride = null,
        ?array $payload = null
    ): void {
        // $statusOverride (P1-5): bizonytalan kimenetelű (transport-hiba
        // utáni) kísérletnél sem 'done', sem sima 'failed' nem pontos — az
        // 'uncertain_manual' érték (ugyanaz a szóhasználat, mint a NAV
        // kimerült-egyeztetés terminális állapotánál, lásd
        // markInvoiceUncertainManual()) jelzi a "Kimenő számlák" UI-nak,
        // hogy admin kézi feloldása szükséges, NEM egy sima, egyszerűen
        // újrapróbálható hiba.
        $status = $statusOverride ?? ($success ? 'done' : 'failed');
        $now = date('Y-m-d H:i:s');
        $issuedAt = $success ? $now : null;
        // Lásd migrateV22InvoiceOperationsBody() docblockja: ez a
        // DETERMINISZTIKUS operation_key adja ma is (mint korábban a
        // törölt UNIQUE(sale_id,provider)) az "legfeljebb egy EREDETI
        // számla ehhez a sale_id+provider-hez" garanciát — ez a metódus
        // KIZÁRÓLAG invoice_type='normal' (az oszlop DEFAULT-ja) sorokhoz
        // való, változatlanul.
        $operationKey = 'create:' . $saleId . ':' . $provider;

        $params = [
            ':sale_id' => $saleId,
            ':provider' => $provider,
            ':operation_key' => $operationKey,
            ':status' => $status,
            ':invoice_number' => $invoiceNumber,
            ':net_total' => $netTotal,
            ':vat_total' => $vatTotal,
            ':gross_total' => $grossTotal,
            ':currency' => $currency,
            ':issued_at' => $issuedAt,
            ':pdf_path' => $pdfPath,
            ':last_error' => $error,
            // 1.1.0: buyer/items (ugyanaz az alak, mint NAV insertQueuedInvoice()-nél)
            // — enélkül egy Számlázz.hu-s eredeti számla MODIFY/STORNO
            // kontextusa (lásd InvoiceService::buildStornoContext()) nem
            // lenne rekonstruálható, mert a `sales` tábla csak buyer_name-et
            // tárol, strukturált nev/irsz/telepules/cim/adoszam-ot nem. $payload
            // NULL esetén (a hívó nem adott meg) a MEGLÉVŐ értéket megőrizzük
            // (lásd COALESCE lent) — egy retry sose törölhet ki egy korábban
            // már eltárolt payloadot.
            ':payload_json' => $payload !== null ? json_encode($payload, JSON_UNESCAPED_UNICODE) : null,
            ':now' => $now,
        ];

        if ($this->driver === 'mysql') {
            $sql = "INSERT INTO invoices
                    (sale_id, provider, operation_key, status, invoice_number, net_total, vat_total, gross_total, currency, issued_at, pdf_path, last_error, payload_json, created_at, updated_at)
                VALUES
                    (:sale_id, :provider, :operation_key, :status, :invoice_number, :net_total, :vat_total, :gross_total, :currency, :issued_at, :pdf_path, :last_error, :payload_json, :now, :now)
                ON DUPLICATE KEY UPDATE
                    status = VALUES(status), invoice_number = VALUES(invoice_number),
                    net_total = VALUES(net_total), vat_total = VALUES(vat_total), gross_total = VALUES(gross_total),
                    currency = VALUES(currency), issued_at = VALUES(issued_at), pdf_path = VALUES(pdf_path),
                    last_error = VALUES(last_error), payload_json = COALESCE(VALUES(payload_json), payload_json), updated_at = VALUES(updated_at)";
        } else {
            $sql = "INSERT INTO invoices
                    (sale_id, provider, operation_key, status, invoice_number, net_total, vat_total, gross_total, currency, issued_at, pdf_path, last_error, payload_json, created_at, updated_at)
                VALUES
                    (:sale_id, :provider, :operation_key, :status, :invoice_number, :net_total, :vat_total, :gross_total, :currency, :issued_at, :pdf_path, :last_error, :payload_json, :now, :now)
                ON CONFLICT(operation_key) DO UPDATE SET
                    status = excluded.status, invoice_number = excluded.invoice_number,
                    net_total = excluded.net_total, vat_total = excluded.vat_total, gross_total = excluded.gross_total,
                    currency = excluded.currency, issued_at = excluded.issued_at, pdf_path = excluded.pdf_path,
                    last_error = excluded.last_error, payload_json = COALESCE(excluded.payload_json, invoices.payload_json), updated_at = excluded.updated_at";
        }

        $this->pdo->prepare($sql)->execute($params);
    }

    public function findInvoiceBySaleAndProvider(int $saleId, string $provider): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM invoices WHERE sale_id = ? AND provider = ?');
        $stmt->execute([$saleId, $provider]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    public function getInvoiceById(int $id): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM invoices WHERE id = ?');
        $stmt->execute([$id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    // ---- Számla-műveletek (MODIFY/STORNO) — 1.1.0 numbering/schema réteg ----
    //
    // FONTOS: ez a szakasz KIZÁRÓLAG az adatmodellt (sorszám-allokáció,
    // modificationIndex-allokáció, a módosító/sztornó invoices-sor
    // létrehozása) szolgáltatja — a TÉNYLEGES NAV/Számlázz.hu kérés
    // összeállítása/beküldése egy KÉSŐBBI kör feladata (lásd
    // migrateV22InvoiceOperationsBody() docblockja a pontos hatókörért).

    /**
     * Atomikusan lefoglal egy ÚJ, egyedi, monoton növekvő sorszámot egy
     * adott providerhez — lásd migrateV22InvoiceOperationsBody() docblockja
     * a teljes indoklásért (miért NEM invoices.id/sales.id/MAX()+1 alapú).
     *
     * KONKURRENCIA-BIZTONSÁG (mindkét motoron AZONOS mintával): egy
     * tranzakción BELÜL az UPDATE (ami sor-zárat szerez — SQLite-on a
     * teljes írás-zárat, MySQL/InnoDB-n a konkrét sor zárát) UTÁN a SELECT
     * MINDIG a SAJÁT, MÉG COMMIT ELŐTTI írását olvassa vissza — egy másik,
     * egyidejű hívás UGYANARRA a providerre a commit-ig BLOKKOLÓDIK, tehát
     * két hívás SOSE kaphatja ugyanazt az értéket. Ugyanaz az elv, mint a
     * projekt már bevált "UPDATE ... WHERE" claim-mintáinál (pl.
     * claimUpdateLock()) — csak itt a cél nem zár megszerzése, hanem
     * garantáltan egyedi sorszám kiosztása.
     *
     * @return int a frissen lefoglalt, NYERS (formázatlan) sorszám — lásd
     *   InvoiceNumbering::format() a végleges string-alakért; ez a metódus
     *   SOSE ad vissza kész számlaszámot, hogy a formázási logika
     *   egyetlen helyen éljen.
     */
    public function allocateInvoiceNumber(string $provider): int
    {
        $wasInTransaction = $this->pdo->inTransaction();
        if (!$wasInTransaction) {
            $this->pdo->beginTransaction();
        }
        try {
            $now = date('Y-m-d H:i:s');
            $insertSql = $this->driver === 'mysql'
                ? 'INSERT IGNORE INTO invoice_sequences (provider, last_allocated_number, updated_at) VALUES (?, 0, ?)'
                : 'INSERT OR IGNORE INTO invoice_sequences (provider, last_allocated_number, updated_at) VALUES (?, 0, ?)';
            $this->pdo->prepare($insertSql)->execute([$provider, $now]);

            $this->pdo->prepare('UPDATE invoice_sequences SET last_allocated_number = last_allocated_number + 1, updated_at = ? WHERE provider = ?')
                ->execute([$now, $provider]);

            $stmt = $this->pdo->prepare('SELECT last_allocated_number FROM invoice_sequences WHERE provider = ?');
            $stmt->execute([$provider]);
            $number = (int) $stmt->fetchColumn();

            if (!$wasInTransaction) {
                $this->pdo->commit();
            }
            return $number;
        } catch (Throwable $e) {
            if (!$wasInTransaction && $this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }
    }

    /**
     * Atomikusan lefoglal egy ÚJ, az adott EREDETI számlára vonatkozó,
     * 1-től induló, monoton növekvő `modificationIndex`-et — lásd a NAV
     * `InvoiceReferenceType.modificationIndex` mezőjének hivatalos
     * dokumentációja (github.com/nav-gov-hu/Online-Invoice): "1-től induló,
     * az EREDETI számlára vonatkozó módosítások/sztornók KÖZÖS,
     * folyamatos sorszáma" — tehát MODIFY és STORNO UGYANABBÓL a
     * számlálóból merít (lásd a kör 9. pontjának példája: MODIFY→1,
     * MODIFY→2, STORNO→3), NEM külön-külön műveletenkénti számlálóból.
     *
     * Ugyanaz az atomicitási minta, mint allocateInvoiceNumber()-nél —
     * lásd ott a docblockot a konkurrencia-bizonyítékért.
     *
     * @throws RuntimeException ha $originalInvoiceId nem létező invoices-
     *   sorra mutat, vagy nem invoice_type='normal' sorra (lásd
     *   createInvoiceOperation() — a modificationIndex-lánc MINDIG a
     *   VALÓDI eredeti számlához kötődik, sose egy közbenső
     *   módosításhoz/sztornóhoz, lásd a kör 9. pontjának ábrája).
     */
    public function allocateModificationIndex(int $originalInvoiceId): int
    {
        $original = $this->getInvoiceById($originalInvoiceId);
        if ($original === null) {
            throw new RuntimeException("A hivatkozott eredeti számla (id=$originalInvoiceId) nem létezik — modificationIndex nem allokálható.");
        }
        if ((string) $original['invoice_type'] !== 'normal') {
            throw new RuntimeException("A modificationIndex kizárólag EREDETI (invoice_type='normal') számlához allokálható, nem egy másik módosításhoz/sztornóhoz (id=$originalInvoiceId, típus: {$original['invoice_type']}).");
        }

        $wasInTransaction = $this->pdo->inTransaction();
        if (!$wasInTransaction) {
            $this->pdo->beginTransaction();
        }
        try {
            $now = date('Y-m-d H:i:s');
            $insertSql = $this->driver === 'mysql'
                ? 'INSERT IGNORE INTO invoice_modification_sequences (original_invoice_id, last_allocated_index, updated_at) VALUES (?, 0, ?)'
                : 'INSERT OR IGNORE INTO invoice_modification_sequences (original_invoice_id, last_allocated_index, updated_at) VALUES (?, 0, ?)';
            $this->pdo->prepare($insertSql)->execute([$originalInvoiceId, $now]);

            $this->pdo->prepare('UPDATE invoice_modification_sequences SET last_allocated_index = last_allocated_index + 1, updated_at = ? WHERE original_invoice_id = ?')
                ->execute([$now, $originalInvoiceId]);

            $stmt = $this->pdo->prepare('SELECT last_allocated_index FROM invoice_modification_sequences WHERE original_invoice_id = ?');
            $stmt->execute([$originalInvoiceId]);
            $index = (int) $stmt->fetchColumn();

            if (!$wasInTransaction) {
                $this->pdo->commit();
            }
            return $index;
        } catch (Throwable $e) {
            if (!$wasInTransaction && $this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }
    }

    /**
     * Létrehoz egy ÚJ, `modification` VAGY `storno` típusú invoices-sort,
     * a hozzá tartozó modificationIndex ÉS (NAV esetén) számlaszám
     * allokálásával együtt. A TÉNYLEGES NAV/Számlázz.hu kérés-
     * összeállítás/beküldés NEM ennek a metódusnak a feladata (lásd a
     * szakasz eleji docblock) — ez a sor `status='queued'`-ként jön létre,
     * pontosan úgy, mint egy normál NAV CREATE queue-bejegyzés
     * (insertQueuedInvoice()), hogy a MEGLÉVŐ NavInvoiceQueueWorker/claim-
     * mechanika egy KÉSŐBBI körben változtatás nélkül fel tudja dolgozni.
     *
     * VALIDÁCIÓ (lásd a kör 15. pontja):
     *   - $invoiceType KIZÁRÓLAG 'modification'/'storno' lehet (a 'normal'
     *     típusú sorokat továbbra is insertQueuedInvoice()/
     *     upsertInvoiceMirror() hozza létre, változatlanul);
     *   - $originalInvoiceId KÖTELEZŐEN egy LÉTEZŐ, invoice_type='normal'
     *     sorra mutat — SOSE egy másik módosításra/sztornóra (lásd a kör
     *     9. pontjának ábrája: a lánc MINDIG a gyökér eredetihez kötődik);
     *   - önhivatkozás elleni védelem (a beszúrás UTÁN, lásd lent) —
     *     ELMÉLETILEG nem fordulhat elő ezen a hívási úton (mindig egy
     *     MÁR LÉTEZŐ eredeti sorra hivatkozunk egy ÚJ sor beszúrásakor),
     *     de védelmi mélységként explicit ellenőrizve marad.
     *
     * IDEMPOTENCIA/EGYEDISÉG: az `operation_key` UNIQUE indexe (lásd
     * migrateV22InvoiceOperationsBody() docblockja) a VALÓDI, konkurrencia-
     * biztos védelem — ez a metódus ELŐSZÖR egy olvasható hibaüzenettel
     * jelzi, ha a beszúrás emiatt ütközik (nem hagyja a hívót egy nyers
     * PDO-kivétellel/SQL-hibaüzenettel szembesülni).
     *
     * @param array $params {
     *   sale_id: int, provider: string ('nav'|'szamlazz'),
     *   invoice_type: string ('modification'|'storno'),
     *   original_invoice_id: int, operation_key: string,
     *   net_total?: float, vat_total?: float, gross_total?: float, currency?: string,
     *   payload?: array,
     * }
     * @return array a frissen létrehozott invoices-sor.
     */
    public function createInvoiceOperation(array $params): array
    {
        $invoiceType = (string) ($params['invoice_type'] ?? '');
        if (!in_array($invoiceType, ['modification', 'storno'], true)) {
            throw new InvalidArgumentException("createInvoiceOperation() kizárólag 'modification'/'storno' típusra való, kapott: '$invoiceType'.");
        }

        $originalInvoiceId = (int) ($params['original_invoice_id'] ?? 0);
        $operationKey = (string) ($params['operation_key'] ?? '');
        if ($operationKey === '') {
            throw new InvalidArgumentException('createInvoiceOperation() operation_key nélkül nem hívható.');
        }

        // allocateModificationIndex() maga is elvégzi az eredeti számla
        // létezés-/típus-ellenőrzését — itt NEM ismételjük meg, hogy a
        // hibaüzenet egyetlen, konzisztens helyről származzon.
        $modificationIndex = $this->allocateModificationIndex($originalInvoiceId);

        $now = date('Y-m-d H:i:s');
        $sql = 'INSERT INTO invoices
                (sale_id, provider, status, invoice_type, original_invoice_id, operation_key, modification_index,
                 net_total, vat_total, gross_total, currency, payload_json, attempts, created_at, updated_at)
            VALUES (?, ?, \'queued\', ?, ?, ?, ?, ?, ?, ?, ?, ?, 0, ?, ?)';
        try {
            $this->pdo->prepare($sql)->execute([
                (int) $params['sale_id'],
                (string) $params['provider'],
                $invoiceType,
                $originalInvoiceId,
                $operationKey,
                $modificationIndex,
                (float) ($params['net_total'] ?? 0.0),
                (float) ($params['vat_total'] ?? 0.0),
                (float) ($params['gross_total'] ?? 0.0),
                (string) ($params['currency'] ?? 'HUF'),
                json_encode($params['payload'] ?? [], JSON_UNESCAPED_UNICODE),
                $now,
                $now,
            ]);
        } catch (PDOException $e) {
            if ($this->isUniqueConstraintViolation($e)) {
                throw new RuntimeException("Ez a művelet (operation_key: $operationKey) már létrehozott egy invoice-rekordot — a duplikálás megakadályozva.", 0, $e);
            }
            throw $e;
        }

        $id = (int) $this->pdo->lastInsertId();

        if ($id === $originalInvoiceId) {
            // Lásd a metódus docblockja — elméletileg elérhetetlen ág,
            // védelmi mélységként mégis explicit visszavonva.
            $this->pdo->prepare('DELETE FROM invoices WHERE id = ?')->execute([$id]);
            throw new RuntimeException('Egy számla nem hivatkozhat önmagára mint eredeti számla.');
        }

        if ((string) $params['provider'] === 'nav') {
            $number = $this->allocateInvoiceNumber('nav');
            $invoiceNumber = InvoiceNumbering::format('nav', $number);
            $this->pdo->prepare('UPDATE invoices SET invoice_number = ? WHERE id = ?')->execute([$invoiceNumber, $id]);
        }
        // Számlázz.hu esetén az invoice_number NULL marad — a Számlázz.hu
        // adja vissza a TÉNYLEGES, saját maga generálta helyesbítő/
        // sztornó számlaszámot (lásd a kör auditjának "Számlázz.hu MODIFY/
        // STORNO protokoll" szakasza), ugyanúgy, mint a normál CREATE-nél.

        return $this->getInvoiceById($id);
    }

    /** SQLSTATE 23000 — mindkét motoron ("integrity constraint violation") ez jelez UNIQUE/PRIMARY KEY-ütközést. */
    private function isUniqueConstraintViolation(PDOException $e): bool
    {
        return ($e->getCode() === '23000') || str_starts_with((string) $e->getCode(), '23');
    }

    /**
     * SZINKRON szolgáltató (Számlázz.hu) MODIFY/STORNO eredményének
     * beírása egy MÁR LÉTEZŐ (createInvoiceOperation() által létrehozott)
     * sorba — SZÁNDÉKOSAN egyszerű, id-alapú UPDATE, NEM upsertInvoiceMirror()
     * (ami operation_key='create:...'-re épülő UPSERT, KIZÁRÓLAG a normál
     * CREATE-flow-hoz — egy modify/storno sorra hívva összekeverné/felül-
     * írná az EREDETI számla tükör-bejegyzését, mert mindkettő ugyanahhoz
     * a sale_id+providerhez tartozna operation_key szinten, ha véletlenül
     * a 'create:' kulcsot használnánk). Aszinkron szolgáltatónál (NAV) NEM
     * használt — ott a meglévő markInvoiceSubmitted()/markInvoiceDone()/
     * markInvoiceFailed() sor a queue-worker-en keresztül, változatlanul.
     */
    public function updateInvoiceOperationResult(
        int $id,
        bool $success,
        ?string $invoiceNumber,
        ?string $pdfPath,
        ?string $error,
        ?string $statusOverride = null
    ): void {
        $status = $statusOverride ?? ($success ? 'done' : 'failed');
        $now = date('Y-m-d H:i:s');
        $this->pdo->prepare('
            UPDATE invoices
            SET status = ?, invoice_number = COALESCE(?, invoice_number), pdf_path = ?, last_error = ?,
                issued_at = ?, next_attempt_at = NULL, locked_at = NULL, updated_at = ?
            WHERE id = ?
        ')->execute([$status, $invoiceNumber, $pdfPath, $error, $success ? $now : null, $now, $id]);
    }

    /**
     * A "Kimenő számlák" számla-kapcsolat navigációhoz (lásd a kör 20.
     * pontja) — az EREDETI (normal) számlához tartozó ÖSSZES módosító/
     * sztornó műveletet adja vissza, modification_index szerint rendezve
     * (a NAV-nál is ez a kanonikus, MODIFY-STORNO közös sorrend, lásd
     * allocateModificationIndex() docblockja).
     */
    public function getInvoiceOperationsForOriginal(int $originalInvoiceId): array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM invoices WHERE original_invoice_id = ? ORDER BY modification_index ASC, id ASC');
        $stmt->execute([$originalInvoiceId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Üzleti szabály (lásd a kör 16. pontja, "conflicting active operation"):
     * ha egy EREDETI számlához MÁR létezik olyan STORNO-művelet, ami NEM
     * véglegesen (failed/dead_letter) hiúsult meg — tehát vagy már sikeres
     * (done), vagy még aktívan folyamatban van (queued/processing/submitted/
     * uncertain/uncertain_manual, azaz elvben MÉG sikeres lehet) — akkor
     * SEM újabb MODIFY, SEM újabb STORNO nem indítható ugyanarra az
     * eredetire: a sztornó STRUKTURÁLISAN lezárja a számla életciklusát.
     * Csak egy VÉGLEGESEN meghiúsult (failed/dead_letter) sztornó-kísérlet
     * UTÁN engedélyezett új próbálkozás.
     */
    public function invoiceHasBlockingStorno(int $originalInvoiceId): bool
    {
        $stmt = $this->pdo->prepare("
            SELECT 1 FROM invoices
            WHERE original_invoice_id = ? AND invoice_type = 'storno' AND status NOT IN ('failed', 'dead_letter')
            LIMIT 1
        ");
        $stmt->execute([$originalInvoiceId]);
        return $stmt->fetchColumn() !== false;
    }

    /**
     * A "Kimenő számlák" nézet felhasználóbarát állapot-csoportosítása —
     * a NAV teljes állapotgépe (queued/processing/.../uncertain/
     * dead_letter) technikai részlet, amit a kasszásnak nem kell
     * ismernie. Számlázz.hu sorok gyakorlatban csak 'done'/'failed'-et
     * érnek el (szinkron, azonnali), így ott csak ez a két bucket
     * releváns. Ugyanezt a leképezést használja listInvoices() (szűrés)
     * ÉS a frontend (megjelenítés) is — itt, egy helyen.
     */
    public const INVOICE_STATUS_BUCKETS = [
        'done' => ['done'],
        'pending' => ['queued', 'processing', 'processing_status_check', 'processing_uncertain_recovery', 'submitted'],
        'failed' => ['failed', 'dead_letter', 'uncertain', 'uncertain_manual'],
    ];

    /**
     * A "Kimenő számlák" nézet szűrt listája — `listSales()` mintájára
     * (lásd ott), de `sales` JOIN-nal a vevőnévhez, mert az `invoices`
     * sor önmagában nem tartalmaz vevő-adatot.
     *
     * @param array $filters opcionális: date (ÉÉÉÉ-HH-NN, invoices.created_at-ra),
     *   id (int — invoices.id VAGY a kapcsolódó sale_id egyezik),
     *   provider ('szamlazz'|'nav'), status (bucket-név: 'done'|'pending'|'failed',
     *   lásd INVOICE_STATUS_BUCKETS), query (sales.buyer_name VAGY invoices.invoice_number-re illeszkedik)
     */
    public function listInvoices(array $filters = [], int $limit = 300): array
    {
        $where = [];
        $params = [];

        if (!empty($filters['date'])) {
            $where[] = ($this->driver === 'mysql' ? 'DATE(invoices.created_at)' : "substr(invoices.created_at, 1, 10)") . ' = ?';
            $params[] = $filters['date'];
        }
        if (!empty($filters['id'])) {
            $where[] = '(invoices.id = ? OR invoices.sale_id = ?)';
            $params[] = (int) $filters['id'];
            $params[] = (int) $filters['id'];
        }
        if (!empty($filters['provider']) && in_array($filters['provider'], ['szamlazz', 'nav'], true)) {
            $where[] = 'invoices.provider = ?';
            $params[] = $filters['provider'];
        }
        if (!empty($filters['status']) && isset(self::INVOICE_STATUS_BUCKETS[$filters['status']])) {
            $statuses = self::INVOICE_STATUS_BUCKETS[$filters['status']];
            $where[] = 'invoices.status IN (' . implode(',', array_fill(0, count($statuses), '?')) . ')';
            array_push($params, ...$statuses);
        }
        if (!empty($filters['query'])) {
            $where[] = '(sales.buyer_name LIKE ? OR invoices.invoice_number LIKE ?)';
            $params[] = '%' . $filters['query'] . '%';
            $params[] = '%' . $filters['query'] . '%';
        }

        $sql = 'SELECT invoices.*, sales.buyer_name AS sale_buyer_name FROM invoices JOIN sales ON sales.id = invoices.sale_id';
        if ($where) {
            $sql .= ' WHERE ' . implode(' AND ', $where);
        }
        $sql .= ' ORDER BY invoices.created_at DESC LIMIT ' . (int) $limit;

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Aszinkron szolgáltató (jelenleg: NAV) számára hozza létre a tartós
     * queue-bejegyzést — magát az `invoices` sort, `status='queued'`-del,
     * még a tényleges NAV-hívás ELŐTT. Race-safe: az `INSERT OR IGNORE` /
     * `INSERT IGNORE` a (migrateV22InvoiceOperationsBody() óta)
     * `UNIQUE(operation_key)` indexre támaszkodik — az itt képzett
     * `operation_key` ('create:{sale_id}:{provider}') DETERMINISZTIKUS,
     * tehát pontosan ugyanazt a garanciát adja, mint korábban a törölt
     * `UNIQUE(sale_id, provider)`: két egyidejű kérés közül csak az egyik
     * ténylegesen szúr be új sort, a másik `rowCount()===0`-t lát és
     * `null`-lal tér vissza, ahelyett hogy egy második, duplikált
     * számlázási feladatot hozna létre ugyanahhoz az eladáshoz.
     *
     * A `invoice_number`-t SZÁNDÉKOSAN két lépésben állítja elő (előbb
     * beszúrás invoice_number NÉLKÜL, utána egy UPDATE) — a NAV-nak
     * stabil, a beküldés előtt ismert sorszám kell, de a tényleges
     * allokálás (lásd Database::allocateInvoiceNumber()) csak a sikeres
     * INSERT UTÁN, a duplikálás-ellenőrzés lezárultával történik, hogy egy
     * idempotens no-op (fenti `rowCount()===0` ág) SOSE égessen el
     * feleslegesen egy sorszámot.
     *
     * KORÁBBI KORLÁT MEGSZŰNT (1.1.0): a számlaszám 1.0.x-ben az
     * `invoices` tábla saját, providerek között OSZTOTT auto-increment
     * id-jára épült ("SM-NAV-{év}-{id}") — ez a metódus 1.1.0 óta a
     * KÜLÖN, provider-kulcsolt `invoice_sequences` táblából (lásd
     * allocateInvoiceNumber()) allokál, ami a Számlázz.hu-s sorok
     * beszúrásaitól TELJESEN FÜGGETLEN, folyamatos NAV-only sorozat — lásd
     * migrateV22InvoiceOperationsBody() docblockja a teljes indoklásért és
     * a meglévő (1.0.x-ben allokált) számok kezeléséért.
     */
    public function insertQueuedInvoice(
        int $saleId,
        string $provider,
        float $netTotal,
        float $vatTotal,
        float $grossTotal,
        string $currency,
        array $payload
    ): ?array {
        $now = date('Y-m-d H:i:s');
        $operationKey = 'create:' . $saleId . ':' . $provider;
        $sql = $this->driver === 'mysql'
            ? "INSERT IGNORE INTO invoices (sale_id, provider, operation_key, status, net_total, vat_total, gross_total, currency, payload_json, attempts, created_at, updated_at)
               VALUES (?, ?, ?, 'queued', ?, ?, ?, ?, ?, 0, ?, ?)"
            : "INSERT OR IGNORE INTO invoices (sale_id, provider, operation_key, status, net_total, vat_total, gross_total, currency, payload_json, attempts, created_at, updated_at)
               VALUES (?, ?, ?, 'queued', ?, ?, ?, ?, ?, 0, ?, ?)";
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([$saleId, $provider, $operationKey, $netTotal, $vatTotal, $grossTotal, $currency, json_encode($payload, JSON_UNESCAPED_UNICODE), $now, $now]);

        if ($stmt->rowCount() === 0) {
            // A UNIQUE(operation_key) ütközés miatt nem szúrt be új sort —
            // vagy egy konkurens hívás nyerte a versenyt, vagy már
            // korábban létrejött ehhez a sale_id+provider-hez tartozó
            // queue-bejegyzés. Mindkét esetben idempotens no-op.
            return null;
        }

        $id = (int) $this->pdo->lastInsertId();
        $number = $this->allocateInvoiceNumber($provider);
        $invoiceNumber = InvoiceNumbering::format($provider, $number);
        $this->pdo->prepare('UPDATE invoices SET invoice_number = ? WHERE id = ?')->execute([$invoiceNumber, $id]);

        return $this->getInvoiceById($id);
    }

    /**
     * Atomikusan lefoglal (claim-el) egy, a megadott állapotok
     * valamelyikében lévő, esedékes ($next_attempt_at elmúlt vagy NULL)
     * sort a megadott providerhez, `status='processing'`-re állítva.
     *
     * A race-safety NEM a fenti SELECT-ből ered (az csupán jelölteket
     * gyűjt), hanem abból, hogy minden jelöltre egy KÜLÖN, feltételes
     * UPDATE-et próbál (WHERE status even IS still eligible ÉS a zár
     * elavult-e) — két egyidejű worker közül csak az egyik UPDATE-je érint
     * ténylegesen sort (rowCount()>0), a másik a listában a KÖVETKEZŐ
     * jelöltre lép. Ugyanaz az "olvasás nem garancia, csak a feltételes
     * UPDATE rowCount()-ja számít" minta, mint amit
     * tryClaimInvoiceIssuance() már bevezetett — csak itt a jelöltet is
     * meg kell először KERESNI, mert a worker előre nem ismeri a sor id-ját.
     */
    /**
     * $lockingStatus a claim SIKERE esetén beállított, ÁTMENETI állapot —
     * SZÁNDÉKOSAN KÜLÖN érték minden claim-típushoz (submission:
     * 'processing', státusz-ellenőrzés: 'processing_status_check',
     * bizonytalan-egyeztetés: 'processing_uncertain_recovery'), NEM egy
     * közös "processing" — enélkül egy elavult zár helyreállításakor NEM
     * lehetne megkülönböztetni, hogy az összeomlott worker éppen egy
     * SUBMISSION-t vagy egy STÁTUSZ-ELLENŐRZÉST végzett-e, és egy
     * 'submitted' sor (aminek MÁR VAN valódi NAV transactionId-je) téves
     * újra-claim-elése a submission-körben egy MÁSODIK, duplikált
     * manageInvoice CREATE-et eredményezne ugyanarra a számlára.
     */
    private function claimInvoiceRow(array $eligibleStatuses, string $lockingStatus, string $provider, int $staleAfterSeconds): ?array
    {
        $now = date('Y-m-d H:i:s');
        $staleBefore = date('Y-m-d H:i:s', time() - $staleAfterSeconds);
        $allMatchStatuses = array_merge($eligibleStatuses, [$lockingStatus]);
        $placeholders = implode(',', array_fill(0, count($allMatchStatuses), '?'));

        $candidateStmt = $this->pdo->prepare("
            SELECT id FROM invoices
            WHERE provider = ?
              AND status IN ($placeholders)
              AND (next_attempt_at IS NULL OR next_attempt_at <= ?)
              AND (locked_at IS NULL OR locked_at < ?)
            ORDER BY next_attempt_at IS NULL DESC, next_attempt_at ASC, id ASC
            LIMIT 20
        ");
        $candidateStmt->execute(array_merge([$provider], $allMatchStatuses, [$now, $staleBefore]));
        $candidateIds = $candidateStmt->fetchAll(PDO::FETCH_COLUMN);

        foreach ($candidateIds as $id) {
            $claimStmt = $this->pdo->prepare("
                UPDATE invoices SET status = ?, locked_at = ?, updated_at = ?
                WHERE id = ? AND status IN ($placeholders) AND (locked_at IS NULL OR locked_at < ?)
            ");
            $claimStmt->execute(array_merge([$lockingStatus, $now, $now, $id], $allMatchStatuses, [$staleBefore]));
            if ($claimStmt->rowCount() > 0) {
                return $this->getInvoiceById((int) $id);
            }
        }

        return null;
    }

    /**
     * Egy még be nem küldött (vagy backoff után újra esedékes) 'queued'
     * sort claim-el, ténylegesen a NAV manageInvoice-hoz küldésre.
     */
    public function claimQueuedInvoiceForSubmission(string $provider, int $staleAfterSeconds = 600): ?array
    {
        return $this->claimInvoiceRow(['queued'], 'processing', $provider, $staleAfterSeconds);
    }

    /**
     * Egy már beküldött, transactionId-val rendelkező 'submitted' sort
     * claim-el, queryTransactionStatus-szal való státusz-ellenőrzésre.
     */
    public function claimSubmittedInvoiceForStatusCheck(string $provider, int $staleAfterSeconds = 600): ?array
    {
        return $this->claimInvoiceRow(['submitted'], 'processing_status_check', $provider, $staleAfterSeconds);
    }

    /**
     * 'uncertain' állapotú (bizonytalan kimenetelű manageInvoice-timeout
     * utáni) sort claim-el, a queryTransactionList-alapú egyeztetési
     * kísérletre (lásd NavInvoiceProvider::recoverUncertainInvoice()).
     */
    public function claimUncertainInvoiceForRecovery(string $provider, int $staleAfterSeconds = 600): ?array
    {
        return $this->claimInvoiceRow(['uncertain'], 'processing_uncertain_recovery', $provider, $staleAfterSeconds);
    }

    /**
     * Sikeres manageInvoice CREATE után: a sor 'submitted'-re vált, a
     * kapott transactionId eltárolódik, és a $nextCheckAt időpontban esedékes
     * lesz egy queryTransactionStatus-ellenőrzésre. Az attempts SZÁNDÉKOSAN
     * VÁLTOZATLAN marad — ez nem egy sikertelen próbálkozás utáni retry,
     * hanem egy sikeres beküldés, aminek csak az eredménye még nem ismert.
     */
    public function markInvoiceSubmitted(int $id, string $transactionId, string $nextCheckAt): void
    {
        $this->pdo->prepare("
            UPDATE invoices
            SET status = 'submitted', provider_ref = ?, next_attempt_at = ?, locked_at = NULL, last_error = NULL, updated_at = ?
            WHERE id = ?
        ")->execute([$transactionId, $nextCheckAt, date('Y-m-d H:i:s'), $id]);
    }

    /**
     * A NAV végleg elfogadta a számlát (queryTransactionStatus → DONE) —
     * terminális, sikeres állapot.
     */
    public function markInvoiceDone(int $id, string $issuedAt): void
    {
        $this->pdo->prepare("
            UPDATE invoices
            SET status = 'done', issued_at = ?, next_attempt_at = NULL, locked_at = NULL, last_error = NULL, updated_at = ?
            WHERE id = ?
        ")->execute([$issuedAt, date('Y-m-d H:i:s'), $id]);
    }

    /**
     * Végleges, NEM újrapróbálandó hiba (üzleti validációs hiba, hibás
     * hitelesítő adat, NAV ABORTED-eredmény, stb.) — terminális állapot,
     * csak admin-kezdeményezett kézi újrapróbálkozással indítható újra
     * (lásd resetInvoiceForManualRetry()).
     */
    public function markInvoiceFailed(int $id, string $error): void
    {
        $this->pdo->prepare("
            UPDATE invoices
            SET status = 'failed', last_error = ?, next_attempt_at = NULL, locked_at = NULL, updated_at = ?
            WHERE id = ?
        ")->execute([$error, date('Y-m-d H:i:s'), $id]);
    }

    /**
     * Átmeneti (hálózati/timeout/NAV 5xx) hiba után visszaállítja a sort
     * 'queued'-ra, a megadott $nextAttemptAt időpontig várakozásra —
     * ugyanaz a sor kerül újra claim-elésre, amint esedékessé válik.
     */
    public function scheduleInvoiceRetry(int $id, string $error, string $nextAttemptAt, int $attempts): void
    {
        $this->pdo->prepare("
            UPDATE invoices
            SET status = 'queued', last_error = ?, next_attempt_at = ?, locked_at = NULL, attempts = ?, updated_at = ?
            WHERE id = ?
        ")->execute([$error, $nextAttemptAt, $attempts, date('Y-m-d H:i:s'), $id]);
    }

    /**
     * A backoff-ütemezés kimerült (lásd NavInvoiceQueueWorker::BACKOFF_SECONDS)
     * — terminális, de admin-kezdeményezett kézi újrapróbálkozással
     * indítható állapot, megkülönböztetve markInvoiceFailed()-től (ami
     * VÉGLEGES, nem-újrapróbálandó hiba miatt áll be).
     */
    public function markInvoiceDeadLetter(int $id, string $error): void
    {
        $this->pdo->prepare("
            UPDATE invoices
            SET status = 'dead_letter', last_error = ?, next_attempt_at = NULL, locked_at = NULL, updated_at = ?
            WHERE id = ?
        ")->execute([$error, date('Y-m-d H:i:s'), $id]);
    }

    /**
     * A manageInvoice hívás közben timeout/hálózati hiba történt, ÉS nincs
     * ismert transactionId — tehát nem tudható, hogy a NAV ténylegesen
     * megkapta-e a kérést. Ez SOHA nem eredményez automatikus vak
     * újraküldést (lásd NavInvoiceProvider::submit() docblockja) — helyette
     * egy bizonytalan állapotba kerül, amit a queryTransactionList-alapú
     * egyeztetés próbál (bounded számú alkalommal) feloldani.
     */
    public function markInvoiceUncertain(int $id, string $error, ?string $nextRecoveryAttemptAt, int $attempts): void
    {
        $this->pdo->prepare("
            UPDATE invoices
            SET status = 'uncertain', last_error = ?, next_attempt_at = ?, locked_at = NULL, attempts = ?, updated_at = ?
            WHERE id = ?
        ")->execute([$error, $nextRecoveryAttemptAt, $attempts, date('Y-m-d H:i:s'), $id]);
    }

    /**
     * P0-3 javítás: a bounded queryTransactionList-alapú egyeztetési
     * kísérletek (lásd NavInvoiceQueueWorker::MAX_UNCERTAIN_RECOVERY_ATTEMPTS)
     * KIMERÜLTEK — VALÓDI terminális állapot, NEM 'uncertain' NULL
     * next_attempt_at-tal. Az utóbbi látszólag ártalmatlan volt, de a
     * claimUncertainInvoiceForRecovery() (lásd claimInvoiceRow()) a NULL
     * next_attempt_at-ot "azonnal esedékes"-ként értelmezte, tehát a sor
     * MINDEN további workerfutás alatt újra claim-elődött és újra
     * feldolgozódott — végtelen, öngerjesztő NAV API-forgalmat okozva
     * (empirikusan reprodukálva). A 'uncertain_manual' állapot NINCS benne
     * egyetlen claim-hívás jogosult-állapot listájában sem (lásd
     * claimUncertainInvoiceForRecovery()) — tehát STRUKTURÁLISAN
     * kizárt az automatikus újra-feldolgozás, nem csak egy konkrét
     * időbélyeg-értéken múlik. A last_error és attempts MEGMARAD
     * (diagnosztikai kontextus admin számára), NEM törlődik.
     */
    public function markInvoiceUncertainManual(int $id, string $error, int $attempts): void
    {
        $this->pdo->prepare("
            UPDATE invoices
            SET status = 'uncertain_manual', last_error = ?, next_attempt_at = NULL, locked_at = NULL, attempts = ?, updated_at = ?
            WHERE id = ?
        ")->execute([$error, $attempts, date('Y-m-d H:i:s'), $id]);
    }

    /**
     * Admin-kezdeményezett kézi újrapróbálkozás — csak terminális
     * (failed/dead_letter/uncertain/uncertain_manual) állapotból
     * engedélyezett, atomikusan (a feltételes UPDATE WHERE-je zárja ki,
     * hogy egy épp folyamatban lévő — 'processing'/'submitted'/'queued'/
     * 'done' — sort megzavarjon). Nullázza az attempts-et — az operátor
     * szándéka egy TELJESEN friss próbálkozás, nem a kimerült backoff
     * folytatása.
     */
    public function resetInvoiceForManualRetry(int $id): bool
    {
        $stmt = $this->pdo->prepare("
            UPDATE invoices
            SET status = 'queued', attempts = 0, next_attempt_at = NULL, locked_at = NULL, last_error = NULL, updated_at = ?
            WHERE id = ? AND status IN ('failed', 'dead_letter', 'uncertain', 'uncertain_manual')
        ");
        $stmt->execute([date('Y-m-d H:i:s'), $id]);
        return $stmt->rowCount() > 0;
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

            // A sale_items.unit_price a kedvezmény ELŐTTI (tétel-szintű) árat
            // tartalmazza — ha az eladáson bármilyen rendelés-szintű
            // kedvezmény érvényesült (kupon, hűségpont-beváltás, hűségszint),
            // a sales.total ennél alacsonyabb. E nélkül az arányosítás nélkül
            // a Nettó+ÁFA sor összege meghaladná a tényleges Bruttó forgalmat
            // minden olyan napon, amikor bármelyik eladásnál kedvezmény volt —
            // pontosan ugyanaz a probléma és ugyanaz a megoldás, mint a
            // részleges visszáru arányosításánál (lásd api/return-create.php).
            $saleSubtotal = 0.0;
            foreach ($sale['items'] as $item) {
                $saleSubtotal += (float) $item['unit_price'] * (int) $item['qty'];
            }
            $discountRatio = $saleSubtotal > 0 ? min(1, (float) $sale['total'] / $saleSubtotal) : 1.0;

            foreach ($sale['items'] as $item) {
                $vatRate = (string) $item['vat_rate'];
                $vatPct = is_numeric($vatRate) ? ((float) $vatRate) / 100 : 0.0;
                $lineGross = round((float) $item['unit_price'] * (int) $item['qty'] * $discountRatio, 2);
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

        // A visszáruk (returns) a NAP FOLYAMÁN keletkezett jóváírások — az
        // eredeti eladás-rekord (fent) szándékosan változatlanul megmarad
        // (lásd api/return-create.php), így enélkül a napi zárás minden
        // olyan napon túlbecsülné a valós forgalmat, amikor visszáru történt.
        // A visszáru SAJÁT napja számít (nem az eredeti eladásé), mert a
        // kasszazárás mindig az adott napi tényleges pénzmozgást összegzi.
        $totalReturnsGross = 0.0;
        // Saját, EXPLICIT módon a returns táblára ("r.") minősített
        // dátum-kifejezés kell — a fenti $dateExpr szándékosan minősítetlen
        // (csak "sales" ellen futott eddig), és mivel a returns ÉS a sales
        // tábla is rendelkezik created_at oszloppal, a JOIN-elt lekérdezésben
        // a minősítetlen változat kétértelmű oszlopnév-hibát adna.
        $returnDateExpr = $this->driver === 'mysql' ? 'DATE(r.created_at)' : "substr(r.created_at, 1, 10)";
        $returnsStmt = $this->pdo->prepare("
            SELECT r.*, s.payment_method
            FROM returns r
            JOIN sales s ON s.id = r.sale_id
            WHERE $returnDateExpr = ?
            ORDER BY r.created_at
        ");
        $returnsStmt->bindValue(1, $date);
        $returnsStmt->execute();
        $returns = $returnsStmt->fetchAll(PDO::FETCH_ASSOC);

        if ($returns) {
            $returnIds = array_column($returns, 'id');
            $riPlaceholders = implode(',', array_fill(0, count($returnIds), '?'));
            $riStmt = $this->pdo->prepare("
                SELECT ri.*, si.vat_rate AS vat_rate
                FROM return_items ri
                LEFT JOIN sale_items si ON si.id = ri.sale_item_id
                WHERE ri.return_id IN ($riPlaceholders)
            ");
            $riStmt->execute($returnIds);
            $returnItemsByReturn = [];
            foreach ($riStmt->fetchAll(PDO::FETCH_ASSOC) as $ri) {
                $returnItemsByReturn[$ri['return_id']][] = $ri;
            }

            foreach ($returns as $ret) {
                $refund = (float) $ret['total_refund'];
                $totalGross -= $refund;
                $totalReturnsGross += $refund;

                $method = $ret['payment_method'] ?: 'Készpénz';
                $byPayment[$method]['count'] = $byPayment[$method]['count'] ?? 0;
                $byPayment[$method]['total'] = ($byPayment[$method]['total'] ?? 0) - $refund;

                $retItems = $returnItemsByReturn[$ret['id']] ?? [];
                $rawRefund = 0.0;
                foreach ($retItems as $ri) {
                    $rawRefund += (float) $ri['unit_price'] * (int) $ri['qty'];
                }
                // Ugyanaz az arányosítás, amit a visszáru LÉTREHOZÁSAKOR is
                // alkalmaztunk (lásd api/return-create.php) — itt visszafejtjük
                // a már eltárolt total_refund-ból, mert magát az arányt nem
                // tároljuk el külön a returns táblán.
                $retRatio = $rawRefund > 0 ? ($refund / $rawRefund) : 1.0;

                foreach ($retItems as $ri) {
                    $vatRate = (string) ($ri['vat_rate'] ?? '');
                    $vatPct = is_numeric($vatRate) ? ((float) $vatRate) / 100 : 0.0;
                    $lineGross = round((float) $ri['unit_price'] * (int) $ri['qty'] * $retRatio, 2);
                    $lineNet = is_numeric($vatRate) ? round($lineGross / (1 + $vatPct), 2) : $lineGross;
                    $lineVat = round($lineGross - $lineNet, 2);

                    $totalNet -= $lineNet;
                    $totalVat -= $lineVat;

                    $byVatRate[$vatRate]['net'] = ($byVatRate[$vatRate]['net'] ?? 0) - $lineNet;
                    $byVatRate[$vatRate]['vat'] = ($byVatRate[$vatRate]['vat'] ?? 0) - $lineVat;
                    $byVatRate[$vatRate]['gross'] = ($byVatRate[$vatRate]['gross'] ?? 0) - $lineGross;
                }
            }
        }

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
            'total_returns'     => round($totalReturnsGross, 2),
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

    /**
     * @throws PDOException UNIQUE constraint hibával, ha $idempotencyKey nem
     *         üres és már létezik egy purchase ugyanezzel a kulccsal —
     *         PONTOSAN ugyanaz a garancia, mint insertSale()-nél (lásd ott a
     *         docblockot): az adatbázis UNIQUE INDEXe zárja ki atomikusan,
     *         hogy két, majdnem egyidejű, ugyanazt a kulcsot használó kérés
     *         mindkettő sikerüljön. A hívónak (api/purchase-save.php) ezt a
     *         konkrét hibát el kell kapnia, és findPurchaseByIdempotencyKey()-
     *         jel visszaadnia az (időközben a MÁSIK kérés által létrehozott)
     *         eredeti beszerzést, újrafuttatás helyett.
     */
    public function recordPurchase(array $purchase, array $items, ?string $idempotencyKey = null, ?string $idempotencyFingerprint = null): array
    {
        // A kedvezmény (discount_percent) a beszerzés végösszegére vonatkozik
        // — tétel-szinten, kerekítés ELŐTT alkalmazzuk, hogy a fejléc-összeg
        // (purchases.total_net/total_gross) garantáltan megegyezzen a mentett
        // tételek (purchase_items.line_net/line_gross) összegével, ne
        // csúszhasson szét egy csak az egyik oldalon alkalmazott kerekítés
        // miatt. A készletre/beszerzési árra (applyPurchaseLine) ez nem hat
        // ki — az a ténylegesen beírt, kedvezmény előtti egységárat tükrözi.
        $discountPercent = min(100, max(0, (float) ($purchase['discount_percent'] ?? 0)));
        $discountRatio = 1 - $discountPercent / 100;

        $totalNet = 0.0;
        $totalGross = 0.0;
        $lineTotals = [];
        foreach ($items as $i => $item) {
            $lineNet = round($item['unit_cost_net'] * $item['qty'] * $discountRatio, 2);
            $lineGross = round($item['unit_cost_gross'] * $item['qty'] * $discountRatio, 2);
            $lineTotals[$i] = ['net' => $lineNet, 'gross' => $lineGross];
            $totalNet += $lineNet;
            $totalGross += $lineGross;
        }

        $now = date('Y-m-d H:i:s');

        $this->beginTransaction();
        try {
            $stmt = $this->pdo->prepare('
                INSERT INTO purchases (
                    supplier_id, supplier_name, supplier_tax_number, supplier_country, supplier_zip,
                    supplier_city, supplier_address, payment_method, currency,
                    discount_percent, paid, note, total_net, total_gross,
                    idempotency_key, idempotency_fingerprint, created_at
                ) VALUES (
                    :supplier_id, :supplier_name, :supplier_tax_number, :supplier_country, :supplier_zip,
                    :supplier_city, :supplier_address, :payment_method, :currency,
                    :discount_percent, :paid, :note, :total_net, :total_gross,
                    :idempotency_key, :idempotency_fingerprint, :now
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
                ':idempotency_key'         => ($idempotencyKey !== null && $idempotencyKey !== '') ? $idempotencyKey : null,
                ':idempotency_fingerprint' => ($idempotencyFingerprint !== null && $idempotencyFingerprint !== '') ? $idempotencyFingerprint : null,
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

            foreach ($items as $i => $item) {
                $lineNet = $lineTotals[$i]['net'];
                $lineGross = $lineTotals[$i]['gross'];

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

                // A WooCommerce-push beütemezése UGYANEBBEN a tranzakcióban —
                // lásd migrateV24WcPushQueue() docblockja: vagy a beszerzés ÉS
                // a hozzá tartozó push-sor MINDKETTŐ rögzül, vagy egyik sem
                // (nem maradhat dangling push egy visszagörgetett beszerzéshez,
                // és nem veszhet el egy push egy sikeresen commitolt
                // beszerzéshez, ha a folyamat pont a commit és egy külön,
                // tranzakción kívüli beütemezés között omlana össze).
                if (!empty($item['wc_product_id'])) {
                    $this->enqueueWcPush((int) $item['product_id'], (int) $item['wc_product_id'], 'purchase', $purchaseId);
                }
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

    public function findPurchaseByIdempotencyKey(string $key): ?array
    {
        if ($key === '') {
            return null;
        }
        $stmt = $this->pdo->prepare('SELECT * FROM purchases WHERE idempotency_key = ?');
        $stmt->execute([$key]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    // ---- WooCommerce push queue (1.1.1) — lásd migrateV24WcPushQueue() docblockja ----

    /**
     * Egy adott kiváltó esemény (eladás/beszerzés/leltár-lezárás egy adott
     * tételének) készlet-push-át ütemezi be — idempotens, lásd
     * migrateV24WcPushQueue() docblockja. A hívónak (recordPurchase(),
     * sale.php, stock-take-complete.php) ezt UGYANABBAN a tranzakcióban kell
     * hívnia, mint ami a készletváltozást maga okozza — tartóssági
     * (durability) garancia, nem versenyhelyzet-védelem.
     */
    public function enqueueWcPush(int $productId, int $wcProductId, string $triggerType, int $triggerId): ?array
    {
        $now = date('Y-m-d H:i:s');
        $operationKey = 'push:' . $triggerType . ':' . $triggerId . ':' . $productId;
        $sql = $this->driver === 'mysql'
            ? "INSERT IGNORE INTO wc_push_queue (product_id, wc_product_id, trigger_type, trigger_id, operation_key, status, attempts, created_at, updated_at)
               VALUES (?, ?, ?, ?, ?, 'queued', 0, ?, ?)"
            : "INSERT OR IGNORE INTO wc_push_queue (product_id, wc_product_id, trigger_type, trigger_id, operation_key, status, attempts, created_at, updated_at)
               VALUES (?, ?, ?, ?, ?, 'queued', 0, ?, ?)";
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([$productId, $wcProductId, $triggerType, $triggerId, $operationKey, $now, $now]);

        if ($stmt->rowCount() === 0) {
            // UNIQUE(operation_key) ütközés — ugyanaz a kiváltó esemény már
            // beütemezte ezt a push-t (dupla kattintás/hálózati
            // újrapróbálkozás); idempotens no-op, lásd insertQueuedInvoice().
            return null;
        }

        return $this->getWcPushQueueRowById((int) $this->pdo->lastInsertId());
    }

    public function getWcPushQueueRowById(int $id): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM wc_push_queue WHERE id = ?');
        $stmt->execute([$id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    /**
     * Atomikusan lefoglal egy esedékes 'queued' sort feldolgozásra —
     * PONTOSAN ugyanaz a claim-with-lock minta, mint claimInvoiceRow()-nál
     * (lásd ott a race-safety indoklást): a SELECT csak jelölteket gyűjt, a
     * tényleges kizárólagosságot minden jelöltre egy feltételes UPDATE adja.
     */
    public function claimQueuedWcPush(int $staleAfterSeconds = 600): ?array
    {
        $now = date('Y-m-d H:i:s');
        $staleBefore = date('Y-m-d H:i:s', time() - $staleAfterSeconds);

        $candidateStmt = $this->pdo->prepare("
            SELECT id FROM wc_push_queue
            WHERE status IN ('queued', 'processing')
              AND (next_attempt_at IS NULL OR next_attempt_at <= ?)
              AND (locked_at IS NULL OR locked_at < ?)
            ORDER BY next_attempt_at IS NULL DESC, next_attempt_at ASC, id ASC
            LIMIT 20
        ");
        $candidateStmt->execute([$now, $staleBefore]);
        $candidateIds = $candidateStmt->fetchAll(PDO::FETCH_COLUMN);

        foreach ($candidateIds as $id) {
            $claimStmt = $this->pdo->prepare("
                UPDATE wc_push_queue SET status = 'processing', locked_at = ?, updated_at = ?
                WHERE id = ? AND status IN ('queued', 'processing') AND (locked_at IS NULL OR locked_at < ?)
            ");
            $claimStmt->execute([$now, $now, $id, $staleBefore]);
            if ($claimStmt->rowCount() > 0) {
                return $this->getWcPushQueueRowById((int) $id);
            }
        }

        return null;
    }

    public function markWcPushDone(int $id): void
    {
        $this->pdo->prepare("
            UPDATE wc_push_queue
            SET status = 'done', last_error = NULL, next_attempt_at = NULL, locked_at = NULL, updated_at = ?
            WHERE id = ?
        ")->execute([date('Y-m-d H:i:s'), $id]);
    }

    /** Végleges, NEM újrapróbálandó hiba (pl. HTTP 4xx — üzleti elutasítás) — lásd markInvoiceFailed(). */
    public function markWcPushFailed(int $id, string $error): void
    {
        $this->pdo->prepare("
            UPDATE wc_push_queue
            SET status = 'failed', last_error = ?, next_attempt_at = NULL, locked_at = NULL, updated_at = ?
            WHERE id = ?
        ")->execute([$error, date('Y-m-d H:i:s'), $id]);
    }

    public function scheduleWcPushRetry(int $id, string $error, string $nextAttemptAt, int $attempts): void
    {
        $this->pdo->prepare("
            UPDATE wc_push_queue
            SET status = 'queued', last_error = ?, next_attempt_at = ?, locked_at = NULL, attempts = ?, updated_at = ?
            WHERE id = ?
        ")->execute([$error, $nextAttemptAt, $attempts, date('Y-m-d H:i:s'), $id]);
    }

    /** @see markInvoiceDeadLetter() — a backoff-ütemezés kimerült. */
    public function markWcPushDeadLetter(int $id, string $error): void
    {
        $this->pdo->prepare("
            UPDATE wc_push_queue
            SET status = 'dead_letter', last_error = ?, next_attempt_at = NULL, locked_at = NULL, updated_at = ?
            WHERE id = ?
        ")->execute([$error, date('Y-m-d H:i:s'), $id]);
    }

    /** @see resetInvoiceForManualRetry() — admin-kezdeményezett kézi újrapróbálkozás terminális állapotból. */
    public function resetWcPushForManualRetry(int $id): bool
    {
        $stmt = $this->pdo->prepare("
            UPDATE wc_push_queue
            SET status = 'queued', attempts = 0, next_attempt_at = NULL, locked_at = NULL, last_error = NULL, updated_at = ?
            WHERE id = ? AND status IN ('failed', 'dead_letter')
        ");
        $stmt->execute([date('Y-m-d H:i:s'), $id]);
        return $stmt->rowCount() > 0;
    }

    public function bulkSetProductsDeleted(array $ids, bool $deleted): void
    {
        if (!$ids) {
            return;
        }
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $stmt = $this->pdo->prepare("UPDATE products SET is_deleted = ?, updated_at = ? WHERE id IN ($placeholders)");
        $stmt->execute(array_merge([$deleted ? 1 : 0, date('c')], array_values($ids)));
    }

    public function bulkSetProductsGroup(array $ids, ?string $groupName): void
    {
        if (!$ids) {
            return;
        }
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $stmt = $this->pdo->prepare("UPDATE products SET group_name = ?, updated_at = ? WHERE id IN ($placeholders)");
        $stmt->execute(array_merge([$groupName !== '' ? $groupName : null, date('c')], array_values($ids)));
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

    // ---------------------------------------------------------------
    // Beérkező webshop-rendelések (WooCommerce webhook → piszkozat → leadás)
    // ---------------------------------------------------------------

    /**
     * Új piszkozat-rendelés mentése. Ha ez a wc_order_id már ismert (a
     * webhook újraküldi ugyanazt a rendelést minden mentésnél), nem hoz
     * létre duplikátumot — null-t ad vissza.
     */
    public function insertWebshopOrderDraft(array $o): ?int
    {
        try {
            $stmt = $this->pdo->prepare('
                INSERT INTO webshop_orders
                    (wc_order_id, order_number, status, wc_status, customer_name, customer_email,
                     billing_json, payment_method, currency, total, items_json, customer_note, created_at)
                VALUES (:wc_order_id, :order_number, :status, :wc_status, :customer_name, :customer_email,
                        :billing_json, :payment_method, :currency, :total, :items_json, :customer_note, :created_at)
            ');
            $stmt->execute([
                ':wc_order_id'    => $o['wc_order_id'],
                ':order_number'   => $o['order_number'] ?? (string) $o['wc_order_id'],
                ':status'         => 'draft',
                ':wc_status'      => $o['wc_status'] ?? '',
                ':customer_name'  => $o['customer_name'] ?? '',
                ':customer_email' => $o['customer_email'] ?? '',
                ':billing_json'   => json_encode($o['billing'] ?? [], JSON_UNESCAPED_UNICODE),
                ':payment_method' => $o['payment_method'] ?? '',
                ':currency'       => $o['currency'] ?? 'HUF',
                ':total'          => $o['total'] ?? 0,
                ':items_json'     => json_encode($o['items'] ?? [], JSON_UNESCAPED_UNICODE),
                ':customer_note'  => $o['customer_note'] ?? '',
                ':created_at'     => date('Y-m-d H:i:s'),
            ]);
            return (int) $this->pdo->lastInsertId();
        } catch (PDOException $e) {
            // UNIQUE constraint ütközés = már ismert rendelés — ez a webhook
            // idempotenciájának alapja, nem hibaállapot. Regresszió (1.3.1):
            // korábban EZ a catch MINDEN PDOException-t (pl. SQLITE_BUSY egy
            // egyidejű eladással/NAV-workerrel, vagy egy lemez-megtelt hiba)
            // is csendben "már ismert rendelésként" kezelt — a hívó
            // (webhook.php) erre 200 OK-t adott a WooCommerce-nek, ami így
            // sose próbálta újraküldeni, a rendelés pedig véglegesen
            // elveszett. Most csak a TÉNYLEGES UNIQUE-ütközést nyeljük el,
            // minden más hiba továbbterjed, hogy webhook.php 5xx-et adjon és
            // a WooCommerce újraküldje.
            if ($this->isUniqueConstraintViolation($e)) {
                return null;
            }
            throw $e;
        }
    }

    public function listWebshopOrders(string $status = ''): array
    {
        if ($status !== '') {
            $stmt = $this->pdo->prepare('SELECT * FROM webshop_orders WHERE status = ? ORDER BY created_at DESC LIMIT 300');
            $stmt->execute([$status]);
        } else {
            $stmt = $this->pdo->query('SELECT * FROM webshop_orders ORDER BY created_at DESC LIMIT 300');
        }
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        foreach ($rows as &$row) {
            $row['billing'] = json_decode((string) $row['billing_json'], true) ?: [];
            $row['items'] = json_decode((string) $row['items_json'], true) ?: [];
            unset($row['billing_json'], $row['items_json']);
        }
        return $rows;
    }

    public function getWebshopOrder(int $id): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM webshop_orders WHERE id = ?');
        $stmt->execute([$id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            return null;
        }
        $row['billing'] = json_decode((string) $row['billing_json'], true) ?: [];
        $row['items'] = json_decode((string) $row['items_json'], true) ?: [];
        unset($row['billing_json'], $row['items_json']);
        return $row;
    }

    public function countDraftWebshopOrders(): int
    {
        return (int) $this->pdo->query("SELECT COUNT(*) FROM webshop_orders WHERE status = 'draft'")->fetchColumn();
    }

    /**
     * Atomikusan lefoglalja a piszkozatot feldolgozásra (draft -> confirmed),
     * mielőtt az eladás-rekord létrejönne — ez zárja ki, hogy két majdnem
     * egyidejű "Rendelés leadása" kattintás (pl. két dolgozó ugyanazt a
     * rendelést nyitja meg) kétszer hozzon létre eladást/készletcsökkenést.
     * Igaz-t ad vissza, ha ez a hívás nyerte a versenyt (a sor még draft
     * volt), hamisat, ha valaki más már időközben feldolgozta.
     */
    public function claimDraftWebshopOrder(int $id): bool
    {
        $stmt = $this->pdo->prepare("UPDATE webshop_orders SET status = 'confirmed' WHERE id = :id AND status = 'draft'");
        $stmt->execute([':id' => $id]);
        return $stmt->rowCount() > 0;
    }

    public function setWebshopOrderSale(int $id, int $saleId, string $paymentMethod): void
    {
        $stmt = $this->pdo->prepare("
            UPDATE webshop_orders
            SET sale_id = :sale_id, payment_method = :payment_method, confirmed_at = :now
            WHERE id = :id
        ");
        $stmt->execute([':sale_id' => $saleId, ':payment_method' => $paymentMethod, ':now' => date('Y-m-d H:i:s'), ':id' => $id]);
    }

    /** Lásd claimDraftWebshopOrder — ugyanaz a versenyhelyzet-védelem elutasításra. */
    public function claimAndRejectDraftWebshopOrder(int $id): bool
    {
        $stmt = $this->pdo->prepare("UPDATE webshop_orders SET status = 'rejected', confirmed_at = :now WHERE id = :id AND status = 'draft'");
        $stmt->execute([':now' => date('Y-m-d H:i:s'), ':id' => $id]);
        return $stmt->rowCount() > 0;
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

    /**
     * GDPR "elfeledtetéshez való jog" — a személyazonosításra alkalmas
     * mezőket törli/felülírja, de magát a sort (és a hozzá tartozó
     * eladás-/hűségpont-történetet) megtartja, mert azok a számviteli
     * és hűségpont-egyenleg konzisztenciájához szükségesek. Ez nem
     * ugyanaz, mint a sima "Törlés" (is_deleted) — az visszaállítható,
     * ez nem: a személyes adat véglegesen elvész.
     */
    public function anonymizeCustomer(int $id): void
    {
        $anonName = 'Törölt vásárló (GDPR) #' . $id;
        $stmt = $this->pdo->prepare("
            UPDATE customers
            SET name = :name, phone = NULL, email = NULL, tax_number = NULL,
                zip = NULL, city = NULL, address = NULL, country = NULL, notes = NULL,
                is_deleted = 1, updated_at = :now
            WHERE id = :id
        ");
        $stmt->execute([':name' => $anonName, ':now' => date('c'), ':id' => $id]);

        // A customers.name törlése önmagában nem elég: minden korábbi
        // eladás sale_items.buyer_name mezője a vásárló nevét a
        // VÁSÁRLÁS pillanatában másolta be (lásd insertSale()), és azóta
        // sosem frissül — enélkül a "végleges" GDPR-törlés után a név
        // változatlanul olvasható maradna minden nyugtán, eladás-listán
        // és exportban.
        $this->pdo->prepare('UPDATE sales SET buyer_name = :name WHERE customer_id = :id')
            ->execute([':name' => $anonName, ':id' => $id]);
    }

    public function bulkSetCustomersDeleted(array $ids, bool $deleted): void
    {
        if (!$ids) {
            return;
        }
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $stmt = $this->pdo->prepare("UPDATE customers SET is_deleted = ?, updated_at = ? WHERE id IN ($placeholders)");
        $stmt->execute(array_merge([$deleted ? 1 : 0, date('c')], array_values($ids)));
    }

    public function findCustomersByIds(array $ids): array
    {
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $stmt = $this->pdo->prepare("SELECT * FROM customers WHERE id IN ($placeholders)");
        $stmt->execute(array_values($ids));

        $byId = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $byId[(int) $row['id']] = $row;
        }
        return $byId;
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

    /**
     * Pontok jóváírása/visszavonása — ATOMIKUS, relatív UPDATE, nem
     * "olvasd ki, majd írd vissza az abszolút új értéket" minta. A korábbi
     * (olvasás → PHP-oldali számítás → abszolút érték visszaírása) minta
     * versenyhelyzetben ELVESZTETT jóváírásokhoz vezetett: ha két majdnem
     * egyidejű eladás ugyanannak a vásárlónak írt jóvá pontot, mindkettő
     * ugyanazt a kiinduló egyenleget olvashatta ki, és amelyik később írt,
     * egyszerűen felülírta (nem összeadta) a másikét. A SET loyalty_points
     * = MAX(0, loyalty_points + :delta) forma — ugyanaz a minta, mint
     * decrementStock()/addCustomerSpend()-nél — kizárja ezt: az adatbázis
     * maga, egyetlen lépésben végzi el az összeadást és a 0-ra
     * korlátozást, konkurrens hívások alatt is helyesen.
     *
     * MEGJEGYZÉS a könyvelési sorról (loyalty_transactions): a rögzített
     * points_delta a KÉRT $delta, nem egy utólag (egy második, elméletileg
     * versenyhelyzetben elavulható olvasással) visszaszámolt "ténylegesen
     * alkalmazott, 0-ra vágott" érték — egy ilyen visszaszámolás maga is
     * új versenyhelyzetet vezetne be a KÖNYVELÉSI SORBA (bár a tényleges,
     * tárolt egyenlegbe sosem, mert az UPDATE fentebb már önmagában
     * atomikus). A gyakorlatban ez csak abban a ritka esetben térhet el a
     * ténylegesen alkalmazott változástól, ha a 0-s alsó korlát ténylegesen
     * közbelép (pl. egy teljes visszáru pontvisszavonása egy már részben
     * elköltött egyenlegnél) — a vásárló egyenlege ilyenkor is mindig
     * helyesen, sosem negatívba záródik, csak a könyvelési sor leíró
     * értéke közelítő ebben a szélsőséges esetben.
     */
    public function applyLoyaltyPoints(int $customerId, int $delta, ?int $saleId, string $note): int
    {
        // A kétparaméteres MAX(a, b) SQLite-ban skalár "nagyobbik érték"
        // függvényként működik, de MySQL-ben a MAX() KIZÁRÓLAG aggregált
        // (egyparaméteres) függvény — ott a GREATEST() az azonos jelentésű
        // skalár megfelelő. Driver-függő SQL-t igényel, mint a séma-
        // migrációk többi driver-specifikus ága ebben az osztályban.
        $clampFn = $this->driver === 'mysql' ? 'GREATEST' : 'MAX';
        $stmt = $this->pdo->prepare("
            UPDATE customers SET loyalty_points = $clampFn(0, loyalty_points + :delta), updated_at = :now WHERE id = :id
        ");
        $stmt->execute([':delta' => $delta, ':now' => date('Y-m-d H:i:s'), ':id' => $customerId]);
        if ($stmt->rowCount() === 0) {
            return 0;
        }

        $balanceStmt = $this->pdo->prepare('SELECT loyalty_points FROM customers WHERE id = ?');
        $balanceStmt->execute([$customerId]);
        $newBalance = (int) $balanceStmt->fetchColumn();

        if ($delta !== 0) {
            $this->pdo->prepare('
                INSERT INTO loyalty_transactions (customer_id, sale_id, points_delta, note, created_at)
                VALUES (?, ?, ?, ?, ?)
            ')->execute([$customerId, $saleId, $delta, $note, date('Y-m-d H:i:s')]);
        }

        return $newBalance;
    }

    /**
     * Atomikus, feltételes pontlevonás a kasszai beváltáshoz — ugyanaz a
     * race-védelem, mint redeemGiftCard()/incrementCouponUsage()-nál: a
     * WHERE a jelenlegi egyenleget ellenőrzi UGYANABBAN a lépésben, amiben
     * le is vonja, hogy két majdnem egyidejű eladás ne tudja mindkettő
     * sikeresen beváltani ugyanazt a (már csak egyszer meglévő)
     * pontmennyiséget. A hívó (sale.php) ezt MÉG az eladás rögzítése ELŐTT,
     * ugyanabban a tranzakcióban hívja — ha ez itt sikertelen, az egész
     * eladás visszagördül, ahelyett hogy a kedvezmény csendben "ingyen"
     * érvényesülne. A könyvelési sor (loyalty_transactions) beírása
     * szándékosan külön lépés — lásd recordLoyaltyPointsRedemption() —,
     * mert az eladás-azonosító csak az insertSale() UTÁN ismert.
     */
    public function tryClaimLoyaltyPoints(int $customerId, int $points): bool
    {
        $stmt = $this->pdo->prepare('
            UPDATE customers SET loyalty_points = loyalty_points - :points, updated_at = :now
            WHERE id = :id AND loyalty_points >= :points2
        ');
        $stmt->execute([
            ':points' => $points, ':now' => date('Y-m-d H:i:s'), ':id' => $customerId, ':points2' => $points,
        ]);
        return $stmt->rowCount() > 0;
    }

    /**
     * A tryClaimLoyaltyPoints()-szal már lefoglalt pontbeváltáshoz tartozó
     * könyvelési sor rögzítése, miután az eladás-azonosító ismertté vált.
     */
    public function recordLoyaltyPointsRedemption(int $customerId, int $points, int $saleId): void
    {
        $this->pdo->prepare('
            INSERT INTO loyalty_transactions (customer_id, sale_id, points_delta, note, created_at)
            VALUES (?, ?, ?, ?, ?)
        ')->execute([$customerId, $saleId, -$points, "Beváltva eladás #$saleId-nél", date('Y-m-d H:i:s')]);
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
            ':expiry_date'  => ($c['expiry_date'] ?? '') ?: null,
            ':usage_limit'  => ($c['usage_limit'] ?? '') !== '' ? (int) $c['usage_limit'] : null,
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

    /**
     * Atomikus, feltételes növelés — ugyanaz a race-védelem, mint
     * redeemGiftCard()-nál: a WHERE a jelenlegi times_used-ot a
     * usage_limit-hez képest ellenőrzi UGYANABBAN a lépésben, amiben
     * növeli is, hogy két majdnem egyidejű eladás ne tudja mindkettő
     * sikeresen felhasználni egy már az utolsó alkalomnál tartó kupont.
     * Igaz-t ad vissza, ha a növelés megtörtént.
     */
    public function incrementCouponUsage(int $couponId): bool
    {
        $stmt = $this->pdo->prepare('
            UPDATE coupons
            SET times_used = times_used + 1
            WHERE id = :id AND (usage_limit IS NULL OR times_used < usage_limit)
        ');
        $stmt->execute([':id' => $couponId]);
        return $stmt->rowCount() > 0;
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

    public function findGiftCardById(int $id): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM gift_cards WHERE id = ?');
        $stmt->execute([$id]);
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

    /**
     * Atomikus, feltételes egyenleg-levonás — az UPDATE WHERE-je a
     * jelenlegi (nem egy korábban lekérdezett, esetleg elavult) egyenleget
     * ellenőrzi ugyanabban a lépésben, amiben le is vonja. Enélkül két
     * majdnem egyidejű eladás ugyanazon utalvánnyal mindkettő ugyanazt a
     * (még csökkentetlen) egyenleget olvashatná ki érvényesítéskor, és az
     * egyik levonás felülírná/eltüntetné a másikét (lost update) — pontosan
     * ugyanaz a hibaosztály, mint amit a WooCommerce-készletszinkronnál már
     * javítottunk. Ha időközben más már elköltötte a szükséges összeget,
     * ez itt 0 érintett sorral tér vissza, és NEM von le semmit.
     */
    public function redeemGiftCard(int $giftCardId, float $amount, ?int $saleId): float
    {
        $amount = round($amount, 2);
        $stmt = $this->pdo->prepare('
            UPDATE gift_cards
            SET current_balance = ROUND(current_balance - :amount, 2)
            WHERE id = :id AND current_balance >= :amount2
        ');
        $stmt->execute([':amount' => $amount, ':id' => $giftCardId, ':amount2' => $amount]);
        $applied = $stmt->rowCount() > 0;

        $balanceStmt = $this->pdo->prepare('SELECT current_balance FROM gift_cards WHERE id = ?');
        $balanceStmt->execute([$giftCardId]);
        $newBalance = (float) $balanceStmt->fetchColumn();

        if ($applied) {
            $this->pdo->prepare('INSERT INTO gift_card_transactions (gift_card_id, sale_id, amount_delta, note, created_at) VALUES (?, ?, ?, ?, ?)')
                ->execute([$giftCardId, $saleId, -$amount, 'Beváltva' . ($saleId ? " eladás #$saleId-nél" : ''), date('Y-m-d H:i:s')]);
        }

        return $newBalance;
    }

    /**
     * Atomikus, feltételes egyenleg-levonás a kasszai beváltás ELSŐ (foglalás)
     * fázisához — ugyanaz a race-védelem, mint magában redeemGiftCard()-ban,
     * de itt szándékosan NEM ír könyvelési sort, mert a hívó (sale.php) ezt
     * MÉG az eladás rögzítése ELŐTT, ugyanabban a tranzakcióban hívja, amikor
     * az eladás-azonosító még nem ismert — lásd recordGiftCardRedemption().
     */
    public function tryClaimGiftCardBalance(int $giftCardId, float $amount): bool
    {
        $amount = round($amount, 2);
        $stmt = $this->pdo->prepare('
            UPDATE gift_cards SET current_balance = ROUND(current_balance - :amount, 2)
            WHERE id = :id AND current_balance >= :amount2
        ');
        $stmt->execute([':amount' => $amount, ':id' => $giftCardId, ':amount2' => $amount]);
        return $stmt->rowCount() > 0;
    }

    /**
     * A tryClaimGiftCardBalance()-szal már lefoglalt egyenleghez tartozó
     * könyvelési sor rögzítése, miután az eladás-azonosító ismertté vált.
     * Az új egyenleget adja vissza (a válaszban való megjelenítéshez).
     */
    public function recordGiftCardRedemption(int $giftCardId, float $amount, int $saleId): float
    {
        $amount = round($amount, 2);
        $this->pdo->prepare('
            INSERT INTO gift_card_transactions (gift_card_id, sale_id, amount_delta, note, created_at)
            VALUES (?, ?, ?, ?, ?)
        ')->execute([$giftCardId, $saleId, -$amount, "Beváltva eladás #$saleId-nél", date('Y-m-d H:i:s')]);

        $balanceStmt = $this->pdo->prepare('SELECT current_balance FROM gift_cards WHERE id = ?');
        $balanceStmt->execute([$giftCardId]);
        return (float) $balanceStmt->fetchColumn();
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

    /**
     * @param array $sale a teljes, eredeti eladás-rekord (getSaleWithItems),
     *        hogy a kupon/hűségpont/ajándékutalvány visszapörgetéséhez ne
     *        kelljen újra lekérdezni
     *
     * FONTOS, SZÁNDÉKOS KORLÁT: a kupon-felhasználás, a hűségpontok és az
     * ajándékutalvány-egyenleg VISSZAPÖRGETÉSE csak akkor történik meg, ha
     * ez a visszáru az eladás ÖSSZES tételét lefedi (azaz a teljes rendelés
     * visszavételre kerül, ezzel is számolva). Részleges (csak néhány
     * tételre kiterjedő) visszárunál ez szándékosan kimarad — egy kupon
     * vagy hűségpont-kedvezmény arányos szétosztása tételek között
     * félrevezető lenne, és könnyen saját maga hibaforrásává válna. Ez azt
     * jelenti, hogy egy részleges visszáru után a kupon "elhasználtnak"
     * marad, és a hűségpontok nem módosulnak — ez dokumentált,
     * megfontolt korlát, nem hiba.
     */
    public function processReturn(int $saleId, array $items, string $reason, ?int $staffId, float $totalRefund, array $sale = []): int
    {
        $this->beginTransaction();
        try {
            // Frissen, a TRANZAKCIÓN BELÜL ellenőrizzük, mennyi lett eddig
            // ténylegesen visszavéve — a hívó (api/return-create.php) saját,
            // tranzakción KÍVÜLI olvasása elavulttá válhat két majdnem
            // egyidejű visszáru-kérés között (mindkettő ugyanazt a "még nem
            // lett visszavéve" állapotot látná, és mindkettő átmenne az
            // ellenőrzésen). Az SQLite soros végrehajtása miatt ez az
            // olvasás itt már biztosan látja egy közben lefutott és
            // commit-olt párhuzamos visszáru tételeit is, így a második
            // kérés itt helyesen elutasítható — enélkül ugyanaz a mennyiség
            // kétszer is visszavehető lenne, és emiatt (ha ez a visszáru a
            // "teljesen visszavéve" küszöböt átlépi) a hűségpontok/kupon/
            // ajándékutalvány is kétszer pörögne vissza lentebb.
            $alreadyReturned = $this->getReturnedQuantitiesForSale($saleId);
            $originalItemsById = [];
            foreach ($sale['items'] ?? [] as $si) {
                $originalItemsById[(int) $si['id']] = $si;
            }
            foreach ($items as $item) {
                $saleItemId = (int) ($item['sale_item_id'] ?? 0);
                $original = $originalItemsById[$saleItemId] ?? null;
                if ($original === null) {
                    continue; // nincs eredeti tétel-adat átadva (pl. régi hívó) — nem tudjuk itt ellenőrizni
                }
                $maxReturnable = (int) $original['qty'] - ($alreadyReturned[$saleItemId] ?? 0);
                if ((int) $item['qty'] > $maxReturnable) {
                    throw new RuntimeException("\"{$item['name']}\" tételből időközben már csak $maxReturnable db vihető vissza.");
                }
            }

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

            if ($sale && $this->isSaleNowFullyReturned($saleId, $sale)) {
                $this->reverseSaleBenefits($saleId, $sale);
            }

            $this->commit();
            return $returnId;
        } catch (Throwable $e) {
            $this->rollBack();
            throw $e;
        }
    }

    private function isSaleNowFullyReturned(int $saleId, array $sale): bool
    {
        $returned = $this->getReturnedQuantitiesForSale($saleId);
        foreach ($sale['items'] as $si) {
            if (($returned[(int) $si['id']] ?? 0) < (int) $si['qty']) {
                return false;
            }
        }
        return true;
    }

    /**
     * A teljesen visszavett eladáshoz tartozó kedvezmények/jóváírások
     * visszapörgetése — hívja: processReturn(), csak teljes visszárunál.
     */
    private function reverseSaleBenefits(int $saleId, array $sale): void
    {
        if (!empty($sale['customer_id'])) {
            $customerId = (int) $sale['customer_id'];
            if ((int) $sale['loyalty_points_earned'] > 0) {
                $this->applyLoyaltyPoints($customerId, -(int) $sale['loyalty_points_earned'], $saleId, "Visszavonva teljes visszáru miatt (eladás #$saleId)");
            }
            if ((int) $sale['loyalty_points_redeemed'] > 0) {
                $this->applyLoyaltyPoints($customerId, (int) $sale['loyalty_points_redeemed'], $saleId, "Visszaadva teljes visszáru miatt (eladás #$saleId)");
            }
            // A total_spent alapja az eladáskor a kedvezmények UTÁN, de az
            // ajándékutalvány-beváltás ELŐTTI összeg volt (lásd sale.php
            // loyaltyBasisTotal) — ezt rekonstruáljuk vissza a tárolt
            // total + gift_card_redeemed összegéből.
            $loyaltyBasisTotal = (float) $sale['total'] + (float) $sale['gift_card_redeemed'];
            $this->addCustomerSpend($customerId, -round($loyaltyBasisTotal, 2));
        }

        if (!empty($sale['coupon_id'])) {
            $this->pdo->prepare('UPDATE coupons SET times_used = MAX(0, times_used - 1) WHERE id = ?')
                ->execute([(int) $sale['coupon_id']]);
        }

        if ((float) ($sale['gift_card_redeemed'] ?? 0) > 0) {
            $cardStmt = $this->pdo->prepare('
                SELECT gift_card_id FROM gift_card_transactions
                WHERE sale_id = ? AND amount_delta < 0
                ORDER BY id DESC LIMIT 1
            ');
            $cardStmt->execute([$saleId]);
            $giftCardId = $cardStmt->fetchColumn();
            if ($giftCardId) {
                $amount = round((float) $sale['gift_card_redeemed'], 2);
                $this->pdo->prepare('UPDATE gift_cards SET current_balance = ROUND(current_balance + ?, 2) WHERE id = ?')
                    ->execute([$amount, (int) $giftCardId]);
                $this->pdo->prepare('INSERT INTO gift_card_transactions (gift_card_id, sale_id, amount_delta, note, created_at) VALUES (?, ?, ?, ?, ?)')
                    ->execute([(int) $giftCardId, $saleId, $amount, "Visszatérítve teljes visszáru miatt (eladás #$saleId)", date('Y-m-d H:i:s')]);
            }
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
        // Két egyidejűleg nyitva lévő leltár ugyanarra a készletre
        // egymástól függetlenül, ugyanahhoz a kiindulási pillanatfelvételhez
        // (expected_qty) képest számítana eltérést lezáráskor — ami könnyen
        // duplán könyvelné el ugyanazt a hiányt/többletet. Amíg van nyitott
        // (le nem zárt) leltár, nem indítható újabb — a "Folytatás" gombbal
        // a meglévőt kell folytatni.
        $openId = $this->pdo->query('SELECT id FROM stock_takes WHERE completed_at IS NULL LIMIT 1')->fetchColumn();
        if ($openId !== false) {
            throw new RuntimeException('Már van nyitott (le nem zárt) leltár — előbb azt zárd le, vagy folytasd.');
        }

        $stmt = $this->pdo->prepare('INSERT INTO stock_takes (staff_id, notes, started_at) VALUES (?, ?, ?)');
        $stmt->execute([$staffId, $notes, date('Y-m-d H:i:s')]);
        $takeId = (int) $this->pdo->lastInsertId();

        // Regresszió (1.3.1): korábban ez egy PHP-hurokban, termékenként
        // KÜLÖN INSERT-tel töltötte fel a stock_take_items-et (egy 2000
        // cikkes katalógusnál 2000 külön kör-utazás) — egyetlen "INSERT ...
        // SELECT" ugyanezt egy DB-hívásban végzi el.
        $now = date('Y-m-d H:i:s');
        $this->pdo->prepare('
            INSERT INTO stock_take_items (stock_take_id, product_id, expected_qty, created_at)
            SELECT ?, id, stock_qty, ? FROM products WHERE is_deleted = 0
        ')->execute([$takeId, $now]);

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

    /**
     * @return array<int, array{id:int, stock_qty:int, wc_product_id:?int, sync_to_woocommerce:int, name:string}>
     *         a leltár által ténylegesen módosított (WC-szinkronra jogosult) termékek —
     *         a hívó (api/stock-take-complete.php) ez alapján küldi ki a WooCommerce-push-t,
     *         mert a leltári korrekció önmagában NEM szinkronizál automatikusan.
     */
    public function completeStockTake(int $id, bool $applyCorrections): array
    {
        $updated = [];
        $this->beginTransaction();
        try {
            $takeStmt = $this->pdo->prepare('SELECT completed_at FROM stock_takes WHERE id = ?');
            $takeStmt->execute([$id]);
            $take = $takeStmt->fetch(PDO::FETCH_ASSOC);
            if (!$take) {
                throw new RuntimeException('A leltár nem található.');
            }
            if ($take['completed_at'] !== null) {
                // Idempotencia-védelem: egy már lezárt leltár ismételt
                // lezárása (dupla kattintás, hálózati újrapróbálkozás, két
                // nyitva hagyott böngészőfül) enélkül még egyszer
                // alkalmazná a korrekciót — ugyanaz a hibaosztály, mint amit
                // a visszáru-versenyhelyzetnél is javítottunk ebben a körben.
                throw new RuntimeException('Ez a leltár már le van zárva.');
            }

            if ($applyCorrections) {
                // A leltár indításakor rögzített expected_qty a KIINDULÓ
                // állapot pillanatfelvétele — a lezárásig (ami akár órákig
                // vagy napokig tarthat, hiszen a leltár "Folytatás"-sal
                // bármikor újranyitható) közben valódi eladások/beszerzések
                // tovább módosíthatták a stock_qty-t. Ezért NEM a megszámolt
                // értéket írjuk rá abszolút értékként — az elveszítené a
                // közbeni valódi mozgásokat (pl. egy leltár közben lezajlott
                // eladás készlet-csökkentését csendben visszaírná) —, hanem
                // a leltár által felfedezett ELTÉRÉST (counted - expected)
                // alkalmazzuk a JELENLEGI stock_qty-re relatív korrekcióként,
                // pontosan úgy, ahogy egy hagyományos leltári
                // eltérés-könyvelés is működik.
                $stmt = $this->pdo->prepare('SELECT product_id, expected_qty, counted_qty FROM stock_take_items WHERE stock_take_id = ? AND counted_qty IS NOT NULL');
                $stmt->execute([$id]);
                $deltasByProductId = [];
                foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
                    $delta = (int) $row['counted_qty'] - (int) $row['expected_qty'];
                    if ($delta !== 0) {
                        $deltasByProductId[(int) $row['product_id']] = $delta;
                    }
                }

                // Regresszió (1.3.1): korábban minden eltérő terméknél egy
                // UPDATE UTÁN külön SELECT-tel olvastuk vissza a frissített
                // sort (2 kör-utazás/termék) — az UPDATE ELŐTTI állapotot
                // (stock_qty/wc_product_id/sync_to_woocommerce/name) EGY
                // bulk lekérdezéssel is megkaphatjuk, a frissített
                // stock_qty pedig egyszerűen oldStock+delta, PHP-ban
                // számolva — nincs szükség az újra-SELECT-re.
                $now = date('c');
                if ($deltasByProductId) {
                    $placeholders = implode(',', array_fill(0, count($deltasByProductId), '?'));
                    $productsStmt = $this->pdo->prepare("SELECT id, stock_qty, wc_product_id, sync_to_woocommerce, name FROM products WHERE id IN ($placeholders)");
                    $productsStmt->execute(array_keys($deltasByProductId));
                    $productsById = [];
                    foreach ($productsStmt->fetchAll(PDO::FETCH_ASSOC) as $p) {
                        $productsById[(int) $p['id']] = $p;
                    }

                    $updateStmt = $this->pdo->prepare('UPDATE products SET stock_qty = stock_qty + :delta, updated_at = :now, wc_synced_at = :now WHERE id = :id');
                    foreach ($deltasByProductId as $productId => $delta) {
                        $updateStmt->execute([':delta' => $delta, ':now' => $now, ':id' => $productId]);
                        $product = $productsById[$productId] ?? null;
                        if ($product && $product['wc_product_id'] && !empty($product['sync_to_woocommerce'])) {
                            $updated[] = [
                                'id' => $productId,
                                'stock_qty' => (int) $product['stock_qty'] + $delta,
                                'wc_product_id' => (int) $product['wc_product_id'],
                                'sync_to_woocommerce' => (int) $product['sync_to_woocommerce'],
                                'name' => $product['name'],
                            ];
                            // A WooCommerce-push beütemezése UGYANEBBEN a
                            // tranzakcióban — lásd migrateV24WcPushQueue()
                            // docblockja / recordPurchase() ugyanezen mintája.
                            $this->enqueueWcPush($productId, (int) $product['wc_product_id'], 'stock_take', $id);
                        }
                    }
                }
            }
            // Regresszió (1.3.1): a fenti PHP-szintű completed_at-ellenőrzés
            // önmagában NEM elég a versenyhelyzet ellen — a beginTransaction()
            // itt (a kódbázis más claim-mintáival, pl.
            // claimQueuedInvoiceForSubmission()/tryDecrementLocationStock()-kal
            // ellentétben) egy sima, deferred SQLite tranzakció, aminek az
            // olvasása NEM garantáltan látja egy másik, KÖZBEN commit-olt
            // lezárás hatását az itt lentebbi ÍRÁS pillanatában. Enélkül a
            // WHERE-feltétel nélkül két, majdnem egyidejű lezárási kérés
            // (dupla kattintás, hálózati újrapróbálkozás) mindkettő
            // átmehetett a fenti ellenőrzésen, és MINDKETTŐ alkalmazhatta
            // volna a leltári korrekciót — csendben duplázva a
            // stock_qty-eltérést. Az atomikus "UPDATE ... WHERE
            // completed_at IS NULL" + rowCount()-ellenőrzés ugyanaz a minta,
            // mint a kódbázis többi claim-jénél.
            $closeStmt = $this->pdo->prepare('UPDATE stock_takes SET completed_at = ? WHERE id = ? AND completed_at IS NULL');
            $closeStmt->execute([date('Y-m-d H:i:s'), $id]);
            if ($closeStmt->rowCount() === 0) {
                throw new RuntimeException('Ez a leltár már le van zárva.');
            }
            $this->commit();
        } catch (Throwable $e) {
            $this->rollBack();
            throw $e;
        }
        return $updated;
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

        // A visszárukat is le kell vonni napi bontásban, ugyanazért, amiért
        // getDailySummary() is teszi — enélkül a trend minden olyan napon
        // túlbecsülné a forgalmat, amikor visszáru történt.
        $returnsStmt = $this->pdo->prepare("
            SELECT $dateExpr AS day, SUM(total_refund) AS total
            FROM returns
            WHERE $dateExpr >= ?
            GROUP BY $dateExpr
        ");
        $returnsStmt->execute([$since]);
        foreach ($returnsStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $byDate[$row['day']]['total'] = ($byDate[$row['day']]['total'] ?? 0.0) - (float) $row['total'];
            $byDate[$row['day']]['count'] = $byDate[$row['day']]['count'] ?? 0;
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
    // Rendszeresemény-napló (1.4.0, "Operations & Reliability") — lásd
    // migrateV26SystemEvents() docblokkja az audit_log-tól való
    // szándékos elkülönítés indoklásáért.
    // ---------------------------------------------------------------

    private const SYSTEM_EVENT_CATEGORIES = ['backup', 'woocommerce', 'nav', 'updater', 'printer', 'smtp', 'auth', 'database'];
    private const SYSTEM_EVENT_SEVERITIES = ['info', 'warning', 'error'];
    private const SYSTEM_EVENT_STATUSES = ['started', 'success', 'failure'];

    /**
     * @param string $userMessage Rövid, felhasználó számára érthető üzenet
     *   — SOSE tartalmazhat titkot, hitelesítő adatot, fájlrendszer-
     *   elérési utat vagy nyers kivétel-szöveget (lásd 1.3.1 release-gate
     *   audit "error message leak" javításainak ugyanezen elve).
     * @param string|null $technicalDetail Admin-only diagnosztikai
     *   kiegészítés — EZ IS a hívó felelőssége szűrten tartani (nem egy
     *   nyers $e->getMessage() dump helye).
     */
    public function logSystemEvent(
        string $category,
        string $eventType,
        string $severity,
        string $status,
        string $userMessage,
        ?string $technicalDetail = null,
        int $retentionDays = 14
    ): void {
        if (!in_array($category, self::SYSTEM_EVENT_CATEGORIES, true)) {
            throw new InvalidArgumentException("Érvénytelen system_events kategória: $category");
        }
        if (!in_array($severity, self::SYSTEM_EVENT_SEVERITIES, true)) {
            throw new InvalidArgumentException("Érvénytelen system_events severity: $severity");
        }
        if (!in_array($status, self::SYSTEM_EVENT_STATUSES, true)) {
            throw new InvalidArgumentException("Érvénytelen system_events status: $status");
        }

        $this->pdo->prepare('
            INSERT INTO system_events (category, event_type, severity, status, user_message, technical_detail, created_at)
            VALUES (?, ?, ?, ?, ?, ?, ?)
        ')->execute([$category, $eventType, $severity, $status, $userMessage, $technicalDetail, date('Y-m-d H:i:s')]);

        // Ugyanaz a "beírás-kor takarít" minta, mint logAudit()-nál — a
        // rendszeresemény-napló ELVÁRHATÓAN sokkal magasabb írási
        // gyakoriságú (percenkénti cron-eseményekig), ezért itt SZÜKSÉGES
        // a retention (a sync_log-gal ellentétben, aminek korábban semmi
        // nem volt, és emiatt korlátlanul nőtt — lásd migrateV26 docblokkja).
        $cutoff = date('Y-m-d H:i:s', strtotime("-$retentionDays days"));
        $this->pdo->prepare('DELETE FROM system_events WHERE created_at < ?')->execute([$cutoff]);
    }

    /**
     * @param array $filters Opcionális: 'category' (string), 'severity' (string)
     */
    public function getSystemEvents(array $filters = [], int $limit = 100): array
    {
        $where = [];
        $params = [];
        if (!empty($filters['category'])) {
            $where[] = 'category = ?';
            $params[] = $filters['category'];
        }
        if (!empty($filters['severity'])) {
            $where[] = 'severity = ?';
            $params[] = $filters['severity'];
        }
        $whereSql = $where ? ('WHERE ' . implode(' AND ', $where)) : '';

        $stmt = $this->pdo->prepare("SELECT * FROM system_events $whereSql ORDER BY created_at DESC LIMIT ?");
        foreach ($params as $i => $p) {
            $stmt->bindValue($i + 1, $p);
        }
        $stmt->bindValue(count($params) + 1, $limit, PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Kompakt összesítő a legutóbbi N óra eseményeiről kategóriánként —
     * a health-aggregátor (HealthMonitor) ezt használja, NEM a teljes
     * lista betöltésével (lásd a kör 16. pontja, "ne legyen teljes
     * event-log betöltés csak a Dashboard miatt").
     */
    public function countRecentSystemEventsBySeverity(int $hours = 24): array
    {
        $since = date('Y-m-d H:i:s', strtotime("-$hours hours"));
        $stmt = $this->pdo->prepare('SELECT severity, COUNT(*) AS cnt FROM system_events WHERE created_at >= ? GROUP BY severity');
        $stmt->execute([$since]);
        $counts = ['info' => 0, 'warning' => 0, 'error' => 0];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $counts[$row['severity']] = (int) $row['cnt'];
        }
        return $counts;
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
                // Szigorú, elutasítható csökkentés a forrás oldalon — enélkül
                // két majdnem egyidejű mozgatás ugyanazt a (már csak egyszer
                // meglévő) telephelyi készletet mindkettő sikeresen elvihetné,
                // negatívba döntve a valós készletet (a hívó oldali, tranzakción
                // KÍVÜLI előzetes ellenőrzés — api/stock-transfer.php — erre
                // önmagában nem elég, mert két kérés között elavulttá válhat).
                if (!$this->tryDecrementLocationStock($productId, $fromLocationId, $qty)) {
                    throw new RuntimeException('A forrás telephelyen időközben már nincs elég készlet.');
                }
            } else {
                // "Új készlet" (nincs forrás telephely) — ez egy TÉNYLEGES
                // készletnövekedés (pl. utólag rögzített beszerzés), nem egy
                // meglévő tétel áthelyezése egyik telephelyről a másikra.
                // Enélkül a globális stock_qty (amit a WooCommerce-szinkron,
                // az alacsony-készlet figyelmeztetés és a kassza eladáskori
                // ellenőrzése is olvas) sose látná ezt a mennyiséget, csendben
                // szétcsúszva a telephelyi bontástól.
                $this->pdo->prepare('UPDATE products SET stock_qty = stock_qty + ? WHERE id = ?')
                    ->execute([$qty, $productId]);
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

    /**
     * Csak növelésre, illetve "korlátlan" (nullánál sose eshet lejjebb)
     * csökkentésre szolgál — a kasszai eladás telephelyi könyvelése ezt
     * használja: egy eladás sose bukjon el csak azért, mert a telephelyi
     * bontás (ami csak könyvelési célú, a tényleges készlet-korlátozást
     * szándékosan az összesített stock_qty adja — lásd api/sale.php) éppen
     * nem elég pontos. Szigorú, elutasítható csökkentéshez lásd
     * tryDecrementLocationStock().
     */
    private function adjustLocationStock(int $productId, int $locationId, int $delta): void
    {
        $stmt = $this->pdo->prepare('
            INSERT INTO location_stock (product_id, location_id, stock_qty)
            VALUES (:pid, :lid, MAX(0, :delta1))
            ON CONFLICT(product_id, location_id) DO UPDATE SET stock_qty = MAX(0, stock_qty + :delta2)
        ');
        $stmt->execute([':pid' => $productId, ':lid' => $locationId, ':delta1' => $delta, ':delta2' => $delta]);
    }

    /**
     * Atomikus, feltételes csökkentés — ugyanaz a race-védelmi minta, mint
     * redeemGiftCard()/incrementCouponUsage()-nál: a WHERE a jelenlegi
     * telephelyi készletet ellenőrzi UGYANABBAN a lépésben, amiben csökkenti
     * is. Hamis-at ad vissza (és nem nyúl semmihez), ha nincs elég készlet —
     * ezt a hívó dönti el, hogy elutasítja-e emiatt a műveletet.
     */
    public function tryDecrementLocationStock(int $productId, int $locationId, int $qty): bool
    {
        $stmt = $this->pdo->prepare('
            UPDATE location_stock SET stock_qty = stock_qty - :qty
            WHERE product_id = :pid AND location_id = :lid AND stock_qty >= :qty2
        ');
        $stmt->execute([':qty' => $qty, ':pid' => $productId, ':lid' => $locationId, ':qty2' => $qty]);
        return $stmt->rowCount() > 0;
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

    // =================================================================
    // 1.2.0 — Dashboard / Riportok
    //
    // Design-elvek (lásd a fejlesztési kör 1-21. pontjait):
    //  - Kizárólag a MEGLÉVŐ sales/sale_items/purchases/purchase_items/
    //    returns/return_items/stock_take_items/stock_transfers/products/
    //    wc_push_queue/invoices adatokból dolgoznak — nincs új, párhuzamos
    //    "stock ledger" vagy riport-tábla.
    //  - A visszárukat MINDENHOL a saját (a visszáru rögzítésének) napja
    //    szerint vonjuk le, UGYANÚGY, mint a már meglévő getDailySummary()/
    //    getDailyRevenueTrend() — ez a kasszazárással konzisztens,
    //    "mi történt ténylegesen ezen a napon" nézőpont.
    //  - A `sales`/`returns` a forgalom forrása, az `invoices` tábla
    //    (NAV modification/storno lánc) SOSE kerül bele a forgalom-
    //    számításba — ez zárja ki a kör 3. pontjának tiltott dupla
    //    számolását ("sales és invoice fogalmat külön kezeld").
    // =================================================================

    /**
     * Forgalmi összesítő egy TETSZŐLEGES dátumtartományra — a már bevált
     * getDailySummary() PONTOS tétel-szintű, kedvezmény-arányosításos
     * logikáját alkalmazza (lásd ott a docblockot), csak tartományra és
     * opcionális fizetésimód-szűrésre általánosítva, plusz napi bontással.
     * Két batch-lekérdezés (sales + sale_items IN (...)) — nincs N+1.
     */
    public function getSalesReportSummary(string $dateFrom, string $dateTo, ?string $paymentMethod = null): array
    {
        $dateExpr = $this->driver === 'mysql' ? 'DATE(created_at)' : "substr(created_at, 1, 10)";
        $sql = "SELECT * FROM sales WHERE $dateExpr BETWEEN ? AND ?";
        $params = [$dateFrom, $dateTo];
        if ($paymentMethod !== null && $paymentMethod !== '') {
            $sql .= ' AND payment_method = ?';
            $params[] = $paymentMethod;
        }
        $stmt = $this->pdo->prepare($sql . ' ORDER BY created_at');
        $stmt->execute($params);
        $sales = $stmt->fetchAll(PDO::FETCH_ASSOC);

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

        $byDay = [];
        $byPayment = [];
        $totalGross = 0.0;
        $totalNet = 0.0;
        $totalVat = 0.0;

        foreach ($sales as $sale) {
            $day = substr($sale['created_at'], 0, 10);
            $byDay[$day] ??= ['date' => $day, 'gross' => 0.0, 'net' => 0.0, 'count' => 0];
            $byDay[$day]['gross'] += (float) $sale['total'];
            $byDay[$day]['count']++;

            $method = $sale['payment_method'] ?: 'Készpénz';
            $byPayment[$method]['count'] = ($byPayment[$method]['count'] ?? 0) + 1;
            $byPayment[$method]['total'] = ($byPayment[$method]['total'] ?? 0) + (float) $sale['total'];

            $items = $itemsBySale[$sale['id']] ?? [];
            $saleSubtotal = 0.0;
            foreach ($items as $item) {
                $saleSubtotal += (float) $item['unit_price'] * (int) $item['qty'];
            }
            $discountRatio = $saleSubtotal > 0 ? min(1, (float) $sale['total'] / $saleSubtotal) : 1.0;

            $saleNet = 0.0;
            foreach ($items as $item) {
                $vatRate = (string) $item['vat_rate'];
                $vatPct = is_numeric($vatRate) ? ((float) $vatRate) / 100 : 0.0;
                $lineGross = round((float) $item['unit_price'] * (int) $item['qty'] * $discountRatio, 2);
                $lineNet = is_numeric($vatRate) ? round($lineGross / (1 + $vatPct), 2) : $lineGross;
                $saleNet += $lineNet;
            }
            $totalNet += $saleNet;
            $totalVat += ((float) $sale['total'] - $saleNet);
            $byDay[$day]['net'] += $saleNet;
            $totalGross += (float) $sale['total'];
        }

        // Visszáruk — saját napjuk szerint, ugyanaz a levonás-elv, mint
        // getDailySummary()-nél (lásd ott a docblockot a részletes indoklásért).
        $returnDateExpr = $this->driver === 'mysql' ? 'DATE(r.created_at)' : "substr(r.created_at, 1, 10)";
        $returnsSql = "
            SELECT r.*, s.payment_method
            FROM returns r
            JOIN sales s ON s.id = r.sale_id
            WHERE $returnDateExpr BETWEEN ? AND ?
        ";
        $returnsParams = [$dateFrom, $dateTo];
        if ($paymentMethod !== null && $paymentMethod !== '') {
            $returnsSql .= ' AND s.payment_method = ?';
            $returnsParams[] = $paymentMethod;
        }
        $returnsStmt = $this->pdo->prepare($returnsSql);
        $returnsStmt->execute($returnsParams);
        $returns = $returnsStmt->fetchAll(PDO::FETCH_ASSOC);

        $totalReturnsGross = 0.0;
        if ($returns) {
            $returnIds = array_column($returns, 'id');
            $riPlaceholders = implode(',', array_fill(0, count($returnIds), '?'));
            $riStmt = $this->pdo->prepare("
                SELECT ri.*, si.vat_rate AS vat_rate
                FROM return_items ri
                LEFT JOIN sale_items si ON si.id = ri.sale_item_id
                WHERE ri.return_id IN ($riPlaceholders)
            ");
            $riStmt->execute($returnIds);
            $returnItemsByReturn = [];
            foreach ($riStmt->fetchAll(PDO::FETCH_ASSOC) as $ri) {
                $returnItemsByReturn[$ri['return_id']][] = $ri;
            }

            foreach ($returns as $ret) {
                $day = substr($ret['created_at'], 0, 10);
                $refund = (float) $ret['total_refund'];
                $totalGross -= $refund;
                $totalReturnsGross += $refund;
                $byDay[$day] ??= ['date' => $day, 'gross' => 0.0, 'net' => 0.0, 'count' => 0];
                $byDay[$day]['gross'] -= $refund;

                $method = $ret['payment_method'] ?: 'Készpénz';
                $byPayment[$method]['count'] = $byPayment[$method]['count'] ?? 0;
                $byPayment[$method]['total'] = ($byPayment[$method]['total'] ?? 0) - $refund;

                $retItems = $returnItemsByReturn[$ret['id']] ?? [];
                $rawRefund = 0.0;
                foreach ($retItems as $ri) {
                    $rawRefund += (float) $ri['unit_price'] * (int) $ri['qty'];
                }
                $retRatio = $rawRefund > 0 ? ($refund / $rawRefund) : 1.0;

                $retNet = 0.0;
                foreach ($retItems as $ri) {
                    $vatRate = (string) ($ri['vat_rate'] ?? '');
                    $vatPct = is_numeric($vatRate) ? ((float) $vatRate) / 100 : 0.0;
                    $lineGross = round((float) $ri['unit_price'] * (int) $ri['qty'] * $retRatio, 2);
                    $lineNet = is_numeric($vatRate) ? round($lineGross / (1 + $vatPct), 2) : $lineGross;
                    $retNet += $lineNet;
                }
                $totalNet -= $retNet;
                $totalVat -= ($refund - $retNet);
                $byDay[$day]['net'] -= $retNet;
            }
        }

        ksort($byDay);
        foreach ($byDay as &$row) {
            $row['gross'] = round($row['gross'], 2);
            $row['net'] = round($row['net'], 2);
        }
        unset($row);
        foreach ($byPayment as $method => &$row) {
            $row['total'] = round($row['total'], 2);
        }
        unset($row);
        $salesCount = count($sales);
        $paymentTotalAbs = array_sum(array_map('abs', array_column($byPayment, 'total'))) ?: 0.0;
        foreach ($byPayment as $method => &$row) {
            $row['percent'] = $paymentTotalAbs > 0 ? round(abs($row['total']) / $paymentTotalAbs * 100, 1) : 0.0;
        }
        unset($row);

        return [
            'date_from'         => $dateFrom,
            'date_to'           => $dateTo,
            'sales_count'       => $salesCount,
            'total_gross'       => round($totalGross, 2),
            'total_net'         => round($totalNet, 2),
            'total_vat'         => round($totalVat, 2),
            'total_returns'     => round($totalReturnsGross, 2),
            'avg_sale_gross'    => $salesCount > 0 ? round($totalGross / $salesCount, 2) : 0.0,
            'by_payment_method' => $byPayment,
            'by_day'            => array_values($byDay),
        ];
    }

    /**
     * Top termékek egy dátumtartományra, opcionális csoport-szűréssel és
     * minimum darabszámmal — a visszáruval NETTÓSÍTVA (eladott - visszáru,
     * ugyanabban a tartományban, a visszáru SAJÁT dátuma szerint), lásd a
     * kör 5. pontja ("Ne egyszerűen SUM(quantity)-t használj"). Két
     * aggregált SQL lekérdezés (eladás + visszáru), PHP-ban összefésülve —
     * nincs N+1, nincs termékenkénti külön lekérdezés.
     */
    public function getTopProductsReport(string $dateFrom, string $dateTo, ?string $groupName = null, int $minQty = 0, int $limit = 50): array
    {
        $dateExpr = $this->driver === 'mysql' ? 'DATE(s.created_at)' : "substr(s.created_at, 1, 10)";
        $sql = "
            SELECT si.product_id, p.name, p.barcode, p.group_name, p.vat_rate, p.purchase_price_net,
                   SUM(si.qty) AS qty, SUM(si.unit_price * si.qty) AS revenue
            FROM sale_items si
            JOIN sales s ON s.id = si.sale_id
            JOIN products p ON p.id = si.product_id
            WHERE $dateExpr BETWEEN ? AND ? AND si.product_id IS NOT NULL
        ";
        $params = [$dateFrom, $dateTo];
        if ($groupName !== null && $groupName !== '') {
            $sql .= ' AND p.group_name = ?';
            $params[] = $groupName;
        }
        $sql .= ' GROUP BY si.product_id';
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);

        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        // Egy bulk lekérdezéssel eldöntjük, mely termékekhez van egyáltalán
        // valaha rögzített beszerzés — enélkül egy sose beszerzett termék
        // alapértelmezett 0 purchase_price_net-je hamis (100%-os) árrést
        // mutatna, lásd PurchaseDecisionService::computeMargin() docblockja.
        $hasCostHistory = $rows ? $this->productsHavePurchaseHistory(array_column($rows, 'product_id')) : [];

        $byProduct = [];
        foreach ($rows as $row) {
            $pid = (int) $row['product_id'];
            $vatPct = is_numeric($row['vat_rate']) ? ((float) $row['vat_rate']) / 100 : 0.0;
            $revenueGross = round((float) $row['revenue'], 2);
            // Az árrés-számításhoz nettósítunk — a TERMÉK JELENLEGI áfakulcsával
            // (nem az eladáskori sale_items.vat_rate-tel): dokumentált
            // egyszerűsítés, konzisztensen a jelenlegi purchase_price_net
            // ("utolsó ismert" költség) használatával — lásd README.
            $revenueNet = is_numeric($row['vat_rate']) ? round($revenueGross / (1 + $vatPct), 2) : $revenueGross;
            $margin = PurchaseDecisionService::computeMargin(
                (float) $row['qty'] > 0 ? round($revenueNet / max(1, (int) $row['qty']), 4) : null,
                (float) $row['purchase_price_net'],
                !empty($hasCostHistory[$pid])
            );
            $unitNet = (int) $row['qty'] > 0 ? $revenueNet / (int) $row['qty'] : 0.0;
            $byProduct[$pid] = [
                'product_id'     => $pid,
                'name'           => $row['name'],
                'barcode'        => $row['barcode'],
                'group_name'     => $row['group_name'],
                'qty'            => (int) $row['qty'],
                'revenue'        => $revenueGross,
                'revenue_net'    => $revenueNet,
                'cost_net_total' => $margin !== null ? round(((float) $row['purchase_price_net']) * (int) $row['qty'], 2) : null,
                'margin_net'     => $margin !== null ? round($margin['margin_ft'] * (int) $row['qty'], 2) : null,
                'margin_pct'     => $margin['margin_pct'] ?? null,
                // Csak belső újraszámoláshoz (visszáru-nettósítás) — a
                // hívó felé sose adjuk vissza, lásd a metódus végét.
                '_unit_net'       => $unitNet,
                '_unit_cost_net'  => $margin !== null ? (float) $row['purchase_price_net'] : null,
                '_unit_margin'    => $margin['margin_ft'] ?? null,
            ];
        }

        $returnDateExpr = $this->driver === 'mysql' ? 'DATE(r.created_at)' : "substr(r.created_at, 1, 10)";
        $returnsStmt = $this->pdo->prepare("
            SELECT ri.product_id, SUM(ri.qty) AS qty, SUM(ri.unit_price * ri.qty) AS revenue
            FROM return_items ri
            JOIN returns r ON r.id = ri.return_id
            WHERE $returnDateExpr BETWEEN ? AND ? AND ri.product_id IS NOT NULL
            GROUP BY ri.product_id
        ");
        $returnsStmt->execute([$dateFrom, $dateTo]);
        foreach ($returnsStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $pid = (int) $row['product_id'];
            if (!isset($byProduct[$pid])) {
                continue; // visszáru olyan termékre, ami ebben a tartományban nem is szerepelt eladásként — nincs mit nettósítani
            }
            $byProduct[$pid]['qty'] -= (int) $row['qty'];
            $byProduct[$pid]['revenue'] = round($byProduct[$pid]['revenue'] - (float) $row['revenue'], 2);
            // A nettó forgalmat/költséget/árrést az ÚJ (visszáruval csökkentett)
            // darabszámból, az egységértékekből számoljuk újra — pontosan
            // ugyanaz az elv, mint a bruttó forgalomnál, csak levezetve.
            $adjQty = $byProduct[$pid]['qty'];
            $byProduct[$pid]['revenue_net'] = round($byProduct[$pid]['_unit_net'] * $adjQty, 2);
            if ($byProduct[$pid]['_unit_cost_net'] !== null) {
                $byProduct[$pid]['cost_net_total'] = round($byProduct[$pid]['_unit_cost_net'] * $adjQty, 2);
                $byProduct[$pid]['margin_net'] = round($byProduct[$pid]['_unit_margin'] * $adjQty, 2);
            }
        }

        foreach ($byProduct as $pid => &$row) {
            unset($row['_unit_net'], $row['_unit_cost_net'], $row['_unit_margin']);
        }
        unset($row);

        $result = array_values(array_filter($byProduct, static fn ($r) => $r['qty'] >= $minQty));
        usort($result, static fn ($a, $b) => $b['qty'] <=> $a['qty']);
        return array_slice($result, 0, $limit);
    }

    /**
     * Készletáttekintés — összesített SQL aggregáció, NEM termékenkénti
     * lekérdezés (lásd a kör 18. pontja, "ne indítson több száz
     * product-level queryt"). A készletérték a MEGLÉVŐ purchase_price_net
     * mezőt használja (utolsó ismert nettó beszerzési ár) — nincs új cost
     * accounting bevezetve (lásd a kör 6. pontja).
     */
    public function getInventoryOverview(int $defaultLowStockThreshold, int $topByValueLimit = 10): array
    {
        $stmt = $this->pdo->prepare('
            SELECT
                COUNT(*) AS total_products,
                SUM(CASE WHEN stock_qty > 0 THEN 1 ELSE 0 END) AS in_stock,
                SUM(CASE WHEN stock_qty = 0 THEN 1 ELSE 0 END) AS zero_stock,
                SUM(CASE WHEN stock_qty < 0 THEN 1 ELSE 0 END) AS negative_stock,
                SUM(CASE WHEN stock_qty > 0 AND stock_qty <= COALESCE(low_stock_threshold, ?) THEN 1 ELSE 0 END) AS low_stock,
                SUM(stock_qty * purchase_price_net) AS stock_value_net
            FROM products
            WHERE is_deleted = 0
        ');
        $stmt->execute([$defaultLowStockThreshold]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];

        $topStmt = $this->pdo->prepare('
            SELECT id, name, barcode, stock_qty, purchase_price_net, (stock_qty * purchase_price_net) AS value
            FROM products
            WHERE is_deleted = 0 AND stock_qty > 0
            ORDER BY value DESC
            LIMIT ?
        ');
        $topStmt->bindValue(1, $topByValueLimit, PDO::PARAM_INT);
        $topStmt->execute();

        return [
            'total_products'   => (int) ($row['total_products'] ?? 0),
            'in_stock'         => (int) ($row['in_stock'] ?? 0),
            'zero_stock'       => (int) ($row['zero_stock'] ?? 0),
            'negative_stock'   => (int) ($row['negative_stock'] ?? 0),
            'low_stock'        => (int) ($row['low_stock'] ?? 0),
            'stock_value_net'  => round((float) ($row['stock_value_net'] ?? 0), 2),
            'top_by_value'     => array_map(static function ($r) {
                $r['stock_qty'] = (int) $r['stock_qty'];
                $r['purchase_price_net'] = (float) $r['purchase_price_net'];
                $r['value'] = round((float) $r['value'], 2);
                return $r;
            }, $topStmt->fetchAll(PDO::FETCH_ASSOC)),
        ];
    }

    /**
     * Alacsony készletű / kifogyott termékek LAPOS listája (a meglévő
     * getPurchaseSuggestions() beszállító szerint CSOPORTOSÍT — ez a
     * riport-nézethez, szűréshez/exporthoz lapos lista kell, lásd a kör
     * 7. pontja). Ugyanazt a javasolt-mennyiség ökölszabályt használja,
     * mint getPurchaseSuggestions() ("a küszöb duplájára tölt fel").
     *
     * @param string $filter 'low' (készlet <= küszöb, a kifogyottat/negatívat is beleértve) | 'out' (készlet <= 0)
     */
    public function getLowStockReport(int $defaultThreshold, string $filter = 'low'): array
    {
        $sql = '
            SELECT p.id, p.name, p.barcode, p.group_name, p.stock_qty, p.low_stock_threshold,
                   p.preferred_supplier_id, s.name AS supplier_name
            FROM products p
            LEFT JOIN suppliers s ON s.id = p.preferred_supplier_id
            WHERE p.is_deleted = 0
        ';
        if ($filter === 'out') {
            $sql .= ' AND p.stock_qty <= 0';
        } else {
            $sql .= ' AND p.stock_qty <= COALESCE(p.low_stock_threshold, ?)';
        }
        $sql .= ' ORDER BY p.stock_qty ASC, p.name ASC';

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($filter === 'out' ? [] : [$defaultThreshold]);

        $result = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $threshold = $row['low_stock_threshold'] !== null ? (int) $row['low_stock_threshold'] : $defaultThreshold;
            $result[] = [
                'id'             => (int) $row['id'],
                'name'           => $row['name'],
                'barcode'        => $row['barcode'],
                'group_name'     => $row['group_name'],
                'stock_qty'      => (int) $row['stock_qty'],
                'threshold'      => $threshold,
                'supplier_id'    => $row['preferred_supplier_id'] !== null ? (int) $row['preferred_supplier_id'] : null,
                'supplier_name'  => $row['supplier_name'],
                'suggested_qty'  => max(1, ($threshold * 2) - (int) $row['stock_qty']),
            ];
        }
        return $result;
    }

    /**
     * Készletmozgás-riport — a MEGLÉVŐ, egymástól független forrás-táblák
     * (sale_items/purchase_items/return_items/stock_take_items/
     * stock_transfers) UNIÓJA, PHP-ban összefésülve és dátum szerint
     * rendezve. Szándékosan NEM egy párhuzamos "stock ledger" tábla (lásd
     * a kör 8. pontja) — nincs "before/after" mező, mert ezt a meglévő
     * adatmodell történeti mozgásokra nem biztosítja (csak a termék JELENLEGI
     * stock_qty-ja ismert) — ezt a hívó (API-végpont) NULL-ként adja tovább,
     * nem hamis pontossággal.
     *
     * A stock_transfers közül csak a from_location_id IS NULL sorok
     * kerülnek bele: ezek TÉNYLEGESEN növelik az összesített products.stock_qty-t
     * ("új készlet felvétele" egy telephelyre) — a telephelyek KÖZÖTTI
     * mozgatás nettó 0 hatással van az összesített készletre, azt a
     * Telephelyek oldal saját előzmény-nézete (getStockTransferHistory())
     * már lefedi, ide belevenni csak zajt jelentene.
     *
     * @param array $filters date_from, date_to (kötelező, YYYY-MM-DD), product_id (opcionális int),
     *   type (opcionális: 'sale'|'purchase'|'return'|'stock_take'|'transfer')
     */
    /**
     * Biztonsági korlát forrás-táblánként (lásd a kör 28. pontja,
     * "oversized requests, expensive report queries") — egy nagyon széles
     * (akár az 5 éves maximumot kihasználó, lásd ReportPeriod) egyedi
     * dátumtartomány se tölthessen be korlátlan sormennyiséget a PHP
     * memóriájába egyetlen forrás-táblából. A legfrissebb (DESC rendezett)
     * sorok maradnak meg csonkoláskor — ha ez a korlát ténylegesen
     * érvényesül, a `total`/`has_more` a CSONKOLT halmazra vonatkozik,
     * nem a valódi teljes találatszámra; egy ilyen tartomány gyakorlati
     * használatra amúgy is túl széles lenne egyetlen riport-nézethez.
     */
    private const MAX_MOVEMENT_ROWS_PER_SOURCE = 5000;

    public function getStockMovements(array $filters, int $limit = 200, int $offset = 0): array
    {
        $dateFrom = $filters['date_from'];
        $dateTo = $filters['date_to'];
        $productId = isset($filters['product_id']) ? (int) $filters['product_id'] : null;
        $type = $filters['type'] ?? null;

        $movements = [];

        if ($type === null || $type === 'sale') {
            $productFilter = $productId ? ' AND si.product_id = ?' : '';
            $sql = "
                SELECT s.created_at AS date, si.product_id, p.name AS product_name,
                       -si.qty AS qty_change, s.id AS ref_id
                FROM sale_items si
                JOIN sales s ON s.id = si.sale_id
                LEFT JOIN products p ON p.id = si.product_id
                WHERE si.product_id IS NOT NULL AND s.created_at >= ? AND s.created_at < ?" . $productFilter . "
                ORDER BY s.created_at DESC LIMIT " . self::MAX_MOVEMENT_ROWS_PER_SOURCE . "
            ";
            $params = [$dateFrom . ' 00:00:00', $this->nextDay($dateTo)];
            if ($productId) { $params[] = $productId; }
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute($params);
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
                $movements[] = $this->movementRow($r['date'], (int) $r['product_id'], $r['product_name'], 'sale', (int) $r['qty_change'], 'sale', (int) $r['ref_id']);
            }
        }

        if ($type === null || $type === 'purchase') {
            $productFilter = $productId ? ' AND pi.product_id = ?' : '';
            $sql = "
                SELECT pu.created_at AS date, pi.product_id, p.name AS product_name,
                       pi.qty AS qty_change, pu.id AS ref_id
                FROM purchase_items pi
                JOIN purchases pu ON pu.id = pi.purchase_id
                LEFT JOIN products p ON p.id = pi.product_id
                WHERE pu.created_at >= ? AND pu.created_at < ?" . $productFilter . "
                ORDER BY pu.created_at DESC LIMIT " . self::MAX_MOVEMENT_ROWS_PER_SOURCE . "
            ";
            $params = [$dateFrom . ' 00:00:00', $this->nextDay($dateTo)];
            if ($productId) { $params[] = $productId; }
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute($params);
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
                $movements[] = $this->movementRow($r['date'], (int) $r['product_id'], $r['product_name'], 'purchase', (int) $r['qty_change'], 'purchase', (int) $r['ref_id']);
            }
        }

        if ($type === null || $type === 'return') {
            $productFilter = $productId ? ' AND ri.product_id = ?' : '';
            $sql = "
                SELECT r.created_at AS date, ri.product_id, p.name AS product_name,
                       ri.qty AS qty_change, r.id AS ref_id
                FROM return_items ri
                JOIN returns r ON r.id = ri.return_id
                LEFT JOIN products p ON p.id = ri.product_id
                WHERE ri.product_id IS NOT NULL AND r.created_at >= ? AND r.created_at < ?" . $productFilter . "
                ORDER BY r.created_at DESC LIMIT " . self::MAX_MOVEMENT_ROWS_PER_SOURCE . "
            ";
            $params = [$dateFrom . ' 00:00:00', $this->nextDay($dateTo)];
            if ($productId) { $params[] = $productId; }
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute($params);
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
                $movements[] = $this->movementRow($r['date'], (int) $r['product_id'], $r['product_name'], 'return', (int) $r['qty_change'], 'return', (int) $r['ref_id']);
            }
        }

        if ($type === null || $type === 'stock_take') {
            $productFilter = $productId ? ' AND sti.product_id = ?' : '';
            $sql = "
                SELECT st.completed_at AS date, sti.product_id, p.name AS product_name,
                       (sti.counted_qty - sti.expected_qty) AS qty_change, sti.stock_take_id AS ref_id
                FROM stock_take_items sti
                JOIN stock_takes st ON st.id = sti.stock_take_id
                LEFT JOIN products p ON p.id = sti.product_id
                WHERE st.completed_at IS NOT NULL AND sti.counted_qty IS NOT NULL
                  AND sti.counted_qty != sti.expected_qty
                  AND st.completed_at >= ? AND st.completed_at < ?" . $productFilter . "
                ORDER BY st.completed_at DESC LIMIT " . self::MAX_MOVEMENT_ROWS_PER_SOURCE . "
            ";
            $params = [$dateFrom . ' 00:00:00', $this->nextDay($dateTo)];
            if ($productId) { $params[] = $productId; }
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute($params);
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
                $movements[] = $this->movementRow($r['date'], (int) $r['product_id'], $r['product_name'], 'stock_take', (int) $r['qty_change'], 'stock_take', (int) $r['ref_id']);
            }
        }

        if ($type === null || $type === 'transfer') {
            $productFilter = $productId ? ' AND st.product_id = ?' : '';
            $sql = "
                SELECT st.created_at AS date, st.product_id, p.name AS product_name, st.qty AS qty_change, st.id AS ref_id
                FROM stock_transfers st
                LEFT JOIN products p ON p.id = st.product_id
                WHERE st.from_location_id IS NULL AND st.created_at >= ? AND st.created_at < ?" . $productFilter . "
                ORDER BY st.created_at DESC LIMIT " . self::MAX_MOVEMENT_ROWS_PER_SOURCE . "
            ";
            $params = [$dateFrom . ' 00:00:00', $this->nextDay($dateTo)];
            if ($productId) { $params[] = $productId; }
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute($params);
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
                $movements[] = $this->movementRow($r['date'], (int) $r['product_id'], $r['product_name'], 'transfer', (int) $r['qty_change'], 'transfer', (int) $r['ref_id']);
            }
        }

        usort($movements, static fn ($a, $b) => $b['date'] <=> $a['date']);
        $total = count($movements);
        return [
            'movements' => array_slice($movements, $offset, $limit),
            'total'     => $total,
            'has_more'  => ($offset + $limit) < $total,
        ];
    }

    private function movementRow(string $date, int $productId, ?string $productName, string $type, int $qtyChange, string $sourceType, int $refId): array
    {
        return [
            'date'         => $date,
            'product_id'   => $productId,
            'product_name' => $productName,
            'type'         => $type,
            'qty_change'   => $qtyChange,
            'before_qty'   => null, // a meglévő adatmodell nem tárol historikus készlet-pillanatképet — lásd a metódus docblockja
            'after_qty'    => null,
            'source_type'  => $sourceType,
            'source_ref'   => $refId,
        ];
    }

    private function nextDay(string $date): string
    {
        return (new DateTimeImmutable($date))->modify('+1 day')->format('Y-m-d') . ' 00:00:00';
    }

    /**
     * Egyszerű, átlátható készlet-előrejelzés (lásd a kör 10. pontja) —
     * NEM ML/bonyolult forecasting, csak "átlagos napi fogyás + aktuális
     * készlet = becsült hátralévő napok". A visszárukkal NETTÓSÍTOTT
     * fogyást használja (ugyanaz az elv, mint getTopProductsReport()-nál).
     *
     * Bulk-metódus (nem termékenkénti lekérdezés, lásd a kör 18. pontja) —
     * egyetlen batch lekérdezés adja az eladás-oldalt, egy másik a
     * visszáru-oldalt, PHP-ban termékenként összegezve.
     *
     * Állapotok:
     *  - 'insufficient_data': a termékre ebben az ablakban 0 vagy PONTOSAN 1
     *    elszigetelt eladási NAP volt — egyetlen adatpont nem megbízható
     *    "ráta", hamis pontosságot adna (lásd a kör 10. pontja: "ne pedig
     *    hamis pontosságú 23 nap"). Kivéve, ha a fogyás ténylegesen nulla
     *    (lásd lent) — az EGY konkrét, megbízható eredmény, nem bizonytalan.
     *  - 'zero_consumption': legalább 2 különböző napon volt adat a
     *    termékhez (tehát van elég megfigyelés), de az ablakban a nettó
     *    (visszáruval csökkentett) fogyás összesen <= 0 — ez egy MEGBÍZHATÓ,
     *    magabiztos megállapítás ("jelenleg nem fogy"), nem bizonytalanság.
     *  - 'out_of_stock': a jelenlegi készlet <= 0 — nincs értelme "X nap
     *    múlva fogy el"-t mondani, ha már most sincs készleten.
     *  - 'ok': normál előrejelzés, estimated_days_remaining kitöltve.
     */
    public function getStockForecastBulk(array $productIds, int $windowDays = 30): array
    {
        $productIds = array_values(array_unique(array_map('intval', $productIds)));
        if (!$productIds) {
            return [];
        }
        $since = date('Y-m-d H:i:s', strtotime("-$windowDays days"));
        $placeholders = implode(',', array_fill(0, count($productIds), '?'));

        $soldStmt = $this->pdo->prepare("
            SELECT si.product_id, SUM(si.qty) AS qty, COUNT(DISTINCT substr(s.created_at, 1, 10)) AS sale_days
            FROM sale_items si
            JOIN sales s ON s.id = si.sale_id
            WHERE si.product_id IN ($placeholders) AND s.created_at >= ?
            GROUP BY si.product_id
        ");
        $soldStmt->execute(array_merge($productIds, [$since]));
        $sold = [];
        foreach ($soldStmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $sold[(int) $r['product_id']] = ['qty' => (int) $r['qty'], 'days' => (int) $r['sale_days']];
        }

        $returnedStmt = $this->pdo->prepare("
            SELECT ri.product_id, SUM(ri.qty) AS qty, COUNT(DISTINCT substr(r.created_at, 1, 10)) AS return_days
            FROM return_items ri
            JOIN returns r ON r.id = ri.return_id
            WHERE ri.product_id IN ($placeholders) AND r.created_at >= ?
            GROUP BY ri.product_id
        ");
        $returnedStmt->execute(array_merge($productIds, [$since]));
        $returned = [];
        foreach ($returnedStmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $returned[(int) $r['product_id']] = ['qty' => (int) $r['qty'], 'days' => (int) $r['return_days']];
        }

        $stockStmt = $this->pdo->prepare("SELECT id, stock_qty FROM products WHERE id IN ($placeholders)");
        $stockStmt->execute($productIds);
        $stockByProduct = [];
        foreach ($stockStmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $stockByProduct[(int) $r['id']] = (int) $r['stock_qty'];
        }

        $result = [];
        foreach ($productIds as $pid) {
            $currentStock = $stockByProduct[$pid] ?? 0;
            $soldQty = $sold[$pid]['qty'] ?? 0;
            $returnedQty = $returned[$pid]['qty'] ?? 0;
            $netQty = max(0, $soldQty - $returnedQty);
            // Egyedi napok száma, amikor EGYÁLTALÁN történt valami (eladás
            // VAGY visszáru) ezzel a termékkel — ez adja a "hány
            // MEGFIGYELÉST láttunk" jelet, nem maga a nettó mennyiség.
            $observedDays = max($sold[$pid]['days'] ?? 0, $returned[$pid]['days'] ?? 0);

            if ($currentStock <= 0) {
                $result[$pid] = ['product_id' => $pid, 'status' => 'out_of_stock', 'window_days' => $windowDays, 'avg_daily_consumption' => null, 'estimated_days_remaining' => null];
                continue;
            }
            if ($observedDays < 2 && $netQty > 0) {
                // Volt fogyás, de csak egyetlen elszigetelt napon — túl kevés
                // megfigyelés egy megbízható átlaghoz.
                $result[$pid] = ['product_id' => $pid, 'status' => 'insufficient_data', 'window_days' => $windowDays, 'avg_daily_consumption' => null, 'estimated_days_remaining' => null];
                continue;
            }
            if ($netQty <= 0) {
                $result[$pid] = ['product_id' => $pid, 'status' => 'zero_consumption', 'window_days' => $windowDays, 'avg_daily_consumption' => 0.0, 'estimated_days_remaining' => null];
                continue;
            }

            $avgDaily = $netQty / $windowDays;
            $result[$pid] = [
                'product_id'               => $pid,
                'status'                   => 'ok',
                'window_days'              => $windowDays,
                'avg_daily_consumption'    => round($avgDaily, 3),
                'estimated_days_remaining' => (int) floor($currentStock / $avgDaily),
            ];
        }
        return $result;
    }

    /**
     * WooCommerce push-sor állapot-összesítő (lásd a kör 12-13. pontja) —
     * a meglévő wc_push_queue táblát csoportosítja állapot szerint, plusz
     * a legutóbbi/legközelebbi esedékes sorok referenciái. NEM új
     * állapotgép — a queue meglévő queued/processing/done/failed/dead_letter
     * állapotait adja vissza, olvasva.
     */
    public function getWcQueueStatusSummary(int $recentLimit = 20): array
    {
        $counts = [];
        $stmt = $this->pdo->query('SELECT status, COUNT(*) AS cnt FROM wc_push_queue GROUP BY status');
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $counts[$r['status']] = (int) $r['cnt'];
        }

        $recentStmt = $this->pdo->prepare("
            SELECT wq.*, p.name AS product_name
            FROM wc_push_queue wq
            LEFT JOIN products p ON p.id = wq.product_id
            WHERE wq.status IN ('failed', 'dead_letter')
            ORDER BY wq.updated_at DESC
            LIMIT ?
        ");
        $recentStmt->bindValue(1, $recentLimit, PDO::PARAM_INT);
        $recentStmt->execute();

        return [
            'counts'        => [
                'queued'      => $counts['queued'] ?? 0,
                'processing'  => $counts['processing'] ?? 0,
                'done'        => $counts['done'] ?? 0,
                'failed'      => $counts['failed'] ?? 0,
                'dead_letter' => $counts['dead_letter'] ?? 0,
            ],
            'recent_failed' => $recentStmt->fetchAll(PDO::FETCH_ASSOC),
        ];
    }

    /**
     * Kimenő számla-queue (`invoices`) állapot-összesítő, a MEGLÉVŐ
     * INVOICE_STATUS_BUCKETS leképezéssel (lásd ott a docblockot) — nincs
     * új state machine (lásd a kör 14. pontja).
     */
    public function getInvoiceQueueStatusSummary(string $provider = 'nav'): array
    {
        $stmt = $this->pdo->prepare('SELECT status, COUNT(*) AS cnt FROM invoices WHERE provider = ? GROUP BY status');
        $stmt->execute([$provider]);
        $byStatus = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $byStatus[$r['status']] = (int) $r['cnt'];
        }

        $buckets = ['done' => 0, 'pending' => 0, 'failed' => 0];
        foreach (self::INVOICE_STATUS_BUCKETS as $bucket => $statuses) {
            foreach ($statuses as $status) {
                $buckets[$bucket] += $byStatus[$status] ?? 0;
            }
        }

        return ['provider' => $provider, 'buckets' => $buckets, 'by_status' => $byStatus];
    }

    /** Beszerzések összege egy dátumtartományra (bruttó) — Dashboard "mai/időszaki beszerzés" KPI-hoz. */
    public function getPeriodPurchaseTotal(string $dateFrom, string $dateTo): array
    {
        $dateExpr = $this->driver === 'mysql' ? 'DATE(created_at)' : "substr(created_at, 1, 10)";
        $stmt = $this->pdo->prepare("
            SELECT COUNT(*) AS cnt, COALESCE(SUM(total_gross), 0) AS total_gross, COALESCE(SUM(total_net), 0) AS total_net
            FROM purchases
            WHERE $dateExpr BETWEEN ? AND ?
        ");
        $stmt->execute([$dateFrom, $dateTo]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
        return [
            'count'       => (int) ($row['cnt'] ?? 0),
            'total_gross' => round((float) ($row['total_gross'] ?? 0), 2),
            'total_net'   => round((float) ($row['total_net'] ?? 0), 2),
        ];
    }

    // =================================================================
    // 1.3.0 — Beszerzési döntéstámogatás / árrés / készletérték
    //
    // A TÉNYLEGES döntési képletek (biztonsági készlet, rendelési pont,
    // javasolt mennyiség, árrés) a src/PurchaseDecisionService.php-ban
    // élnek (lásd ott a docblockot) — ez a szakasz KIZÁRÓLAG a MEGLÉVŐ
    // (1.1.1/1.2.0-ban bevált) bulk-lekérdezési mintákat (getLowStockReport,
    // getStockForecastBulk) komponálja össze, majd adja át a szolgáltatásnak.
    // Nincs itt önálló üzleti szabály.
    // =================================================================

    /**
     * Bulk ellenőrzés: mely termékekhez van EGYÁLTALÁN valaha rögzített
     * beszerzés (purchase_items sor) — ez dönti el, hogy egy
     * purchase_price_net = 0 "sose lett beszerezve" (megbízhatatlan
     * költség) vagy "ténylegesen 0-ért lett beszerezve" (megbízható).
     * Egyetlen lekérdezés, NEM termékenkénti (lásd a kör 11. pontja).
     *
     * @return array<int,bool> product_id => true (csak azok szerepelnek, amikhez van előzmény)
     */
    public function productsHavePurchaseHistory(array $productIds): array
    {
        $productIds = array_values(array_unique(array_map('intval', $productIds)));
        if (!$productIds) {
            return [];
        }
        $placeholders = implode(',', array_fill(0, count($productIds), '?'));
        $stmt = $this->pdo->prepare("SELECT DISTINCT product_id FROM purchase_items WHERE product_id IN ($placeholders)");
        $stmt->execute($productIds);
        $result = [];
        foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $pid) {
            $result[(int) $pid] = true;
        }
        return $result;
    }

    /**
     * Beszerzési javaslat lista, TELJES döntéstámogató mezőkkel (lásd a
     * kör 1-2. pontja) — a MEGLÉVŐ getLowStockReport()/getStockForecastBulk()
     * bulk-eredményét adja át a PurchaseDecisionService-nek soronként.
     * NINCS termékenkénti lekérdezés (lásd a kör 11. pontja): a teljes
     * lista 3 bulk lekérdezésből épül fel (alacsony készlet, forecast,
     * "nemrég volt-e rá beszerzés" — utóbbi a beszerzési workflow
     * KIZÁRÓLAG a meglévő adatokból levezetett jelzéséhez, lásd a kör 3.
     * pontja: nincs új "workflow status" oszlop/tábla).
     */
    public function getPurchaseRecommendations(int $defaultThreshold, int $windowDays = 30): array
    {
        $lowStock = $this->getLowStockReport($defaultThreshold, 'low');
        if (!$lowStock) {
            return [];
        }
        $ids = array_column($lowStock, 'id');
        $forecast = $this->getStockForecastBulk($ids, $windowDays);

        // "Folyamatban" jelzés — volt-e MÁR beszerzés erre a termékre az
        // elmúlt RECENT_PURCHASE_WINDOW_DAYS napban. Ez KIZÁRÓLAG a
        // meglévő purchase_items/purchases táblákból levezetett, olvasott
        // jel — nem perzisztált "workflow állapot" (lásd a kör 10. pontja:
        // "ne hozz létre új táblát csak kényelmi okból"). Ha a készlet
        // ennek ellenére még mindig alacsony, az azt jelzi, hogy a
        // korábbi beszerzés még nem (vagy nem eléggé) oldotta meg a
        // hiányt — ettől még jogos, hogy a lista mutassa, csak más
        // címkével ("Folyamatban", nem "Javasolt").
        $recentPurchaseWindowDays = 3;
        $recentSince = date('Y-m-d H:i:s', strtotime("-$recentPurchaseWindowDays days"));
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $recentStmt = $this->pdo->prepare("
            SELECT DISTINCT pi.product_id
            FROM purchase_items pi
            JOIN purchases pu ON pu.id = pi.purchase_id
            WHERE pi.product_id IN ($placeholders) AND pu.created_at >= ?
        ");
        $recentStmt->execute(array_merge($ids, [$recentSince]));
        $recentlyOrdered = array_fill_keys(array_map('intval', $recentStmt->fetchAll(PDO::FETCH_COLUMN)), true);

        $result = [];
        foreach ($lowStock as $row) {
            $pid = (int) $row['id'];
            $f = $forecast[$pid] ?? null;
            $threshold = (int) $row['threshold'];
            $stock = (int) $row['stock_qty'];
            $avgDaily = ($f !== null && $f['status'] === 'ok') ? (float) $f['avg_daily_consumption'] : null;

            $safetyStock = PurchaseDecisionService::safetyStock($threshold);
            $reorderPoint = PurchaseDecisionService::reorderPoint($safetyStock, $avgDaily);
            $recommendedQty = PurchaseDecisionService::recommendedQuantity($safetyStock, $avgDaily, $stock);

            $result[] = [
                'id'                => $pid,
                'name'              => $row['name'],
                'barcode'           => $row['barcode'],
                'group_name'        => $row['group_name'],
                'stock_qty'         => $stock,
                'avg_daily_consumption' => $avgDaily !== null ? round($avgDaily, 3) : null,
                'forecast_status'   => $f['status'] ?? null,
                'estimated_days_remaining' => $f['estimated_days_remaining'] ?? null,
                'safety_stock'      => $safetyStock,
                'reorder_point'     => $reorderPoint,
                'recommended_qty'   => $recommendedQty,
                'urgency'           => PurchaseDecisionService::classifyUrgency($f, $stock, $threshold),
                'reason'            => PurchaseDecisionService::buildReason($f, $stock, $threshold),
                'workflow_status'   => isset($recentlyOrdered[$pid]) ? 'in_progress' : 'suggested',
                'supplier_id'       => $row['supplier_id'] ?? null,
                'supplier_name'     => $row['supplier_name'],
            ];
        }

        // Sürgősség szerint csökkenő (urgent -> soon -> low), azon belül
        // a leghamarabb kifogyó elöl.
        $urgencyOrder = ['urgent' => 0, 'soon' => 1, 'low' => 2];
        usort($result, static function ($a, $b) use ($urgencyOrder) {
            $cmp = $urgencyOrder[$a['urgency']] <=> $urgencyOrder[$b['urgency']];
            if ($cmp !== 0) {
                return $cmp;
            }
            return ($a['estimated_days_remaining'] ?? PHP_INT_MAX) <=> ($b['estimated_days_remaining'] ?? PHP_INT_MAX);
        });
        return $result;
    }

    /**
     * Egy termék beszerzési előzménye — a MEGLÉVŐ purchase_items/purchases
     * táblákból, dátum szerint csökkenő sorrendben (lásd a kör 4. pontja).
     */
    public function getProductPurchaseHistory(int $productId, int $limit = 50): array
    {
        $stmt = $this->pdo->prepare('
            SELECT pi.qty, pi.unit_cost_net, pi.unit_cost_gross, pi.line_net, pi.line_gross,
                   pu.id AS purchase_id, pu.created_at, pu.supplier_name, pu.supplier_id
            FROM purchase_items pi
            JOIN purchases pu ON pu.id = pi.purchase_id
            WHERE pi.product_id = ?
            ORDER BY pu.created_at DESC
            LIMIT ?
        ');
        $stmt->bindValue(1, $productId, PDO::PARAM_INT);
        $stmt->bindValue(2, $limit, PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Beszerzési ártrend — dátum + nettó egységár pontok (lásd a kör 4.
     * pontja: "egyszerű beszerzési ártrend"). Ugyanabból a táblából, mint
     * getProductPurchaseHistory(), csak a trendhez szükséges 2 mezőre
     * szűkítve, időrendben NÖVEKVŐ sorrendben (grafikonhoz).
     */
    public function getProductPurchasePriceTrend(int $productId, int $limit = 24): array
    {
        $stmt = $this->pdo->prepare('
            SELECT pu.created_at AS date, pi.unit_cost_net
            FROM purchase_items pi
            JOIN purchases pu ON pu.id = pi.purchase_id
            WHERE pi.product_id = ?
            ORDER BY pu.created_at DESC
            LIMIT ?
        ');
        $stmt->bindValue(1, $productId, PDO::PARAM_INT);
        $stmt->bindValue(2, $limit, PDO::PARAM_INT);
        $stmt->execute();
        $rows = array_reverse($stmt->fetchAll(PDO::FETCH_ASSOC));
        return array_map(static fn ($r) => ['date' => $r['date'], 'unit_cost_net' => round((float) $r['unit_cost_net'], 2)], $rows);
    }

    /**
     * Egy termék eladási forgalma az utolsó N napban, visszáruval
     * nettósítva — UGYANAZ az elv, mint getTopProductsReport()/
     * getStockForecastBulk() (lásd ott a docblockot), csak egyetlen
     * termékre szűkítve, a termék mini-dashboardhoz (lásd a kör 8. pontja).
     */
    public function getProductSalesSummary(int $productId, int $days): array
    {
        $since = date('Y-m-d H:i:s', strtotime("-$days days"));
        $soldStmt = $this->pdo->prepare('
            SELECT COALESCE(SUM(si.qty), 0) AS qty
            FROM sale_items si JOIN sales s ON s.id = si.sale_id
            WHERE si.product_id = ? AND s.created_at >= ?
        ');
        $soldStmt->execute([$productId, $since]);
        $sold = (int) $soldStmt->fetchColumn();

        $returnedStmt = $this->pdo->prepare('
            SELECT COALESCE(SUM(ri.qty), 0) AS qty
            FROM return_items ri JOIN returns r ON r.id = ri.return_id
            WHERE ri.product_id = ? AND r.created_at >= ?
        ');
        $returnedStmt->execute([$productId, $since]);
        $returned = (int) $returnedStmt->fetchColumn();

        return ['days' => $days, 'qty' => max(0, $sold - $returned)];
    }

    /**
     * Termék mini-dashboard — EGYETLEN hívásban minden adat, amire a
     * termék-részletező "Áttekintés" fülének szüksége van (lásd a kör 8.
     * pontja), hogy a modal megnyitása NE indítson 6-8 külön kérést
     * (lásd a kör 11. pontja). Kizárólag MÁR MEGLÉVŐ metódusokat hív.
     */
    public function getProductInsights(int $productId, int $defaultThreshold): array
    {
        $product = $this->findProductById($productId);
        if (!$product) {
            return [];
        }

        $forecast = $this->getStockForecastBulk([$productId]);
        $f = $forecast[$productId] ?? null;
        $hasCost = $this->productsHavePurchaseHistory([$productId]);
        $margin = PurchaseDecisionService::computeMargin(
            (float) $product['net_price'],
            (float) $product['purchase_price_net'],
            !empty($hasCost[$productId])
        );

        $threshold = $product['low_stock_threshold'] !== null ? (int) $product['low_stock_threshold'] : $defaultThreshold;
        $stock = (int) $product['stock_qty'];

        return [
            'product_id' => $productId,
            'status' => [
                'stock_qty'          => $stock,
                'stock_value_net'    => round($stock * (float) $product['purchase_price_net'], 2),
                'price'              => (float) $product['price'],
                'net_price'          => (float) $product['net_price'],
                'purchase_price_net' => (float) $product['purchase_price_net'],
                'has_cost_history'   => !empty($hasCost[$productId]),
                'margin_ft'          => $margin['margin_ft'] ?? null,
                'margin_pct'         => $margin['margin_pct'] ?? null,
            ],
            'sales' => [
                'last_30_days' => $this->getProductSalesSummary($productId, 30),
                'last_90_days' => $this->getProductSalesSummary($productId, 90),
            ],
            'forecast' => $f,
            'urgency' => $stock <= $threshold ? PurchaseDecisionService::classifyUrgency($f, $stock, $threshold) : null,
            'recent_purchases' => $this->getProductPurchaseHistory($productId, 10),
            'price_trend' => $this->getProductPurchasePriceTrend($productId, 12),
        ];
    }

    /**
     * Készletérték-mutatók (lásd a kör 7. pontja) — bulk SQL aggregáció,
     * nem termékenkénti. Nettó beszerzési ÉS nettó eladási áron is
     * megadja az értéket, plusz a potenciális árrés-értéket (csak azokra
     * a termékekre, amikhez van megbízható beszerzési előzmény — a
     * "sose beszerzett" termékek 0 purchase_price_net-je itt sem
     * torzíthatja hamisan az árrés-értéket).
     */
    public function getInventoryValuationSummary(): array
    {
        $stmt = $this->pdo->query('
            SELECT id, stock_qty, purchase_price_net, net_price
            FROM products
            WHERE is_deleted = 0 AND stock_qty > 0
        ');
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        if (!$rows) {
            return ['cost_value_net' => 0.0, 'retail_value_net' => 0.0, 'potential_margin_value_net' => 0.0, 'products_with_reliable_cost' => 0, 'products_total' => 0];
        }

        $hasCost = $this->productsHavePurchaseHistory(array_column($rows, 'id'));

        $costValue = 0.0;
        $retailValue = 0.0;
        $potentialMargin = 0.0;
        $reliableCount = 0;
        foreach ($rows as $row) {
            $qty = (int) $row['stock_qty'];
            $retailValue += $qty * (float) $row['net_price'];
            if (!empty($hasCost[(int) $row['id']])) {
                $costValue += $qty * (float) $row['purchase_price_net'];
                $potentialMargin += $qty * ((float) $row['net_price'] - (float) $row['purchase_price_net']);
                $reliableCount++;
            }
        }

        return [
            'cost_value_net'              => round($costValue, 2),
            'retail_value_net'            => round($retailValue, 2),
            'potential_margin_value_net'  => round($potentialMargin, 2),
            'products_with_reliable_cost' => $reliableCount,
            'products_total'              => count($rows),
        ];
    }

    /**
     * Időszaki árrés-összesítő (lásd a kör 6. pontja) — a MEGLÉVŐ,
     * termékenkénti getTopProductsReport() eredményét összegzi (nincs
     * párhuzamos, harmadik implementáció ugyanarra a bruttó/nettó
     * levezetésre). $limit nélkül (gyakorlatilag "az összes terméket")
     * kéri le, hogy az összesítő ténylegesen teljes legyen, ne csak a
     * top N termékre vonatkozzon.
     */
    public function getSalesMarginSummary(string $dateFrom, string $dateTo, ?string $paymentMethod = null): array
    {
        $products = $this->getTopProductsReport($dateFrom, $dateTo, null, 0, 100000);

        $revenueNet = 0.0;
        $costNet = 0.0;
        $marginNet = 0.0;
        $productsWithMargin = 0;
        $productsWithoutMargin = 0;
        foreach ($products as $p) {
            $revenueNet += $p['revenue_net'];
            if ($p['margin_net'] !== null) {
                $costNet += $p['cost_net_total'];
                $marginNet += $p['margin_net'];
                $productsWithMargin++;
            } else {
                $productsWithoutMargin++;
            }
        }

        return [
            'revenue_net'             => round($revenueNet, 2),
            'estimated_cost_net'      => round($costNet, 2),
            'margin_net'              => round($marginNet, 2),
            'margin_pct'              => $revenueNet > 0 ? round($marginNet / $revenueNet * 100, 1) : null,
            'products_with_margin'    => $productsWithMargin,
            'products_without_margin' => $productsWithoutMargin,
        ];
    }
}
