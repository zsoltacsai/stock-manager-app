<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * B-02/B-03/B-04 (correctness audit) — MySQL/MariaDB-kompatibilitás.
 *
 * A tesztkörnyezetben nincs MySQL-szerver (és pdo_mysql sincs), ezért a
 * MySQL-ágakat itt NEM élő adatbázison futtatjuk, hanem egy SQL-felvevő
 * PDO-n: a Database 'mysql' driverrel, de egy olyan PDO-val fut, ami a
 * kiadott SQL-t csak rögzíti (nem hajtja végre). Így ellenőrizhető, hogy a
 * MySQL-ág pontosan milyen SQL-t küldene — pl. hogy nem maradt benne
 * SQLite-only szintaxis, és a migráció típusai egyeznek a kanonikus
 * schema.mysql.sql-lel. Ez NEM helyettesíti az élő MySQL-futtatást.
 */
final class MysqlDialectRegressionTest extends TestCase
{
    private function mysqlDatabase(?SqlRecordingPdo &$pdo): Database
    {
        $pdo = new SqlRecordingPdo();
        $db = (new ReflectionClass(Database::class))->newInstanceWithoutConstructor();
        foreach (['driver' => 'mysql', 'pdo' => $pdo, 'dbConfig' => ['driver' => 'mysql']] as $name => $value) {
            $prop = new ReflectionProperty(Database::class, $name);
            $prop->setAccessible(true);
            $prop->setValue($db, $value);
        }
        return $db;
    }

    private function invokePrivate(Database $db, string $method, array $args = []): mixed
    {
        $ref = new ReflectionMethod(Database::class, $method);
        $ref->setAccessible(true);
        return $ref->invokeArgs($db, $args);
    }

    private static function normalize(string $sql): string
    {
        return trim((string) preg_replace('/\s+/', ' ', $sql));
    }

    /** @param list<string> $log */
    private function assertNoSqliteOnlySyntax(array $log): void
    {
        foreach ($log as $sql) {
            $this->assertDoesNotMatchRegularExpression('/ON CONFLICT|INSERT OR (IGNORE|REPLACE)|\bMAX\(\s*[^(),]+,|datetime\(\'now\'\)|strftime\(|julianday\(/', $sql, "SQLite-only szintaxis a MySQL-ágban: $sql");
        }
    }

    // ------------------------------------------------------------------
    // B-02 — V31/V32 migráció MySQL-en
    // ------------------------------------------------------------------

    /**
     * Egy CREATE TABLE utasítás oszlopdefinícióit adja vissza (név => definíció).
     *
     * @return array<string, string>
     */
    private static function parseColumns(string $createTableSql): array
    {
        $body = substr($createTableSql, strpos($createTableSql, '(') + 1);
        $body = substr($body, 0, strrpos($body, ')'));
        $columns = [];
        foreach (preg_split('/,\s*\n/', $body) as $line) {
            $line = trim(preg_replace('/--.*$/m', '', $line));
            if ($line === '' || preg_match('/^(INDEX|UNIQUE|KEY|PRIMARY KEY|CONSTRAINT)\b/i', $line)) {
                continue;
            }
            if (preg_match('/^(\w+)\s+(.+)$/s', $line, $m)) {
                $columns[$m[1]] = self::normalize($m[2]);
            }
        }
        return $columns;
    }

    /** Alaptípus + NOT NULL jelző (az INTEGER a MySQL-ben az INT szinonimája). */
    private static function typeSignature(string $definition): string
    {
        preg_match('/^([A-Z]+(?:\([^)]*\))?(?:\s+UNSIGNED)?)/i', $definition, $m);
        $base = strtoupper($m[1] ?? $definition);
        $base = preg_replace('/^INTEGER\b/', 'INT', $base);
        return $base . (stripos($definition, 'NOT NULL') !== false || stripos($definition, 'PRIMARY KEY') !== false ? ' NOT NULL' : '');
    }

    /** @return array<string, string> */
    private static function canonicalMysqlColumns(string $table): array
    {
        $schema = file_get_contents(dirname(__DIR__) . '/schema.mysql.sql');
        if (!preg_match('/CREATE TABLE IF NOT EXISTS ' . $table . ' \((.*?)\n\) ENGINE/s', $schema, $m)) {
            throw new RuntimeException("$table nem található a schema.mysql.sql-ben");
        }
        return self::parseColumns("CREATE TABLE $table (" . $m[1] . "\n)");
    }

    /** @return array{0: list<string>, 1: array<string, string>, 2: array<string, string>} */
    private function runV31AndV32InMysqlMode(): array
    {
        $db = $this->mysqlDatabase($pdo);
        $this->invokePrivate($db, 'migrateV31ActionProposals');
        $this->invokePrivate($db, 'migrateV32ActionExecution');

        $proposals = [];
        $drafts = [];
        foreach ($pdo->log as $sql) {
            if (str_contains($sql, 'CREATE TABLE IF NOT EXISTS ai_action_proposals')) {
                $proposals = self::parseColumns($sql);
            } elseif (preg_match('/^ALTER TABLE ai_action_proposals ADD COLUMN (\w+) (.+)$/s', self::normalize($sql), $m)) {
                $proposals[$m[1]] = $m[2];
            } elseif (str_contains($sql, 'CREATE TABLE IF NOT EXISTS purchase_order_drafts')) {
                $drafts = self::parseColumns($sql);
            }
        }
        return [$pdo->log, $proposals, $drafts];
    }

    public function testV31MysqlMigrationNeverIndexesATextColumn(): void
    {
        [$log, $proposals] = $this->runV31AndV32InMysqlMode();
        $this->assertNotEmpty($proposals);

        $indexed = 0;
        foreach ($log as $sql) {
            if (preg_match('/^CREATE (?:UNIQUE )?INDEX \w+ ON ai_action_proposals\((\w+)\)$/', self::normalize($sql), $m)) {
                $indexed++;
                $this->assertArrayHasKey($m[1], $proposals);
                // MySQL 1170: BLOB/TEXT column used in key specification without a key length.
                $this->assertStringStartsNotWith('TEXT', strtoupper($proposals[$m[1]]), "Indexelt oszlop nem lehet TEXT: {$m[1]}");
            }
        }
        $this->assertSame(6, $indexed);
        $this->assertSame('DATETIME NOT NULL', $proposals['expires_at']);
        $this->assertSame('DATETIME NULL', $proposals['reviewed_at']);
    }

    public function testV31MysqlRepairAlterRunsBeforeTheExpiresAtIndex(): void
    {
        [$log] = $this->runV31AndV32InMysqlMode();
        $normalized = array_map([self::class, 'normalize'], $log);

        $alter = array_search('ALTER TABLE ai_action_proposals MODIFY COLUMN expires_at DATETIME NOT NULL, MODIFY COLUMN reviewed_at DATETIME NULL', $normalized, true);
        $index = array_search('CREATE INDEX idx_ai_action_proposals_expires_at ON ai_action_proposals(expires_at)', $normalized, true);
        $this->assertIsInt($alter, 'Egy félbemaradt (TEXT oszlopos) korábbi V31-futást javítani kell.');
        $this->assertIsInt($index);
        $this->assertLessThan($index, $alter);
    }

    public function testV31AndV32MysqlColumnTypesMatchCanonicalSchema(): void
    {
        [, $proposals, $drafts] = $this->runV31AndV32InMysqlMode();

        foreach (['ai_action_proposals' => $proposals, 'purchase_order_drafts' => $drafts] as $table => $migrated) {
            $canonical = self::canonicalMysqlColumns($table);
            $this->assertSame(array_keys($canonical), array_keys($migrated), "$table: az oszlopkészlet eltér a friss telepítéstől");
            foreach ($canonical as $column => $definition) {
                $this->assertSame(
                    self::typeSignature($definition),
                    self::typeSignature($migrated[$column]),
                    "$table.$column: a frissített és a friss MySQL-telepítés típusa eltér"
                );
            }
        }
    }

    public function testV31AndV32MysqlMigrationEmitsNoSqliteOnlySyntax(): void
    {
        [$log] = $this->runV31AndV32InMysqlMode();
        $this->assertNoSqliteOnlySyntax($log);
    }

    public function testV33MysqlMigrationAddsNullableIntColumn(): void
    {
        $db = $this->mysqlDatabase($pdo);
        $this->invokePrivate($db, 'migrateV33StockTakeCountBaseline');

        $this->assertSame(['ALTER TABLE stock_take_items ADD COLUMN system_qty_at_count INT NULL'], array_map([self::class, 'normalize'], $pdo->log));
        $this->assertSame('INT NULL', self::canonicalMysqlColumns('stock_take_items')['system_qty_at_count']);
    }

    // ------------------------------------------------------------------
    // B-02 — SQLite: friss telepítés, frissítés, újrafuttatás
    // ------------------------------------------------------------------

    public function testSqliteUpgradeFrom30CreatesTablesIndexesAndKeepsTextTypes(): void
    {
        $db = tests_new_database();
        $pathProp = new ReflectionProperty(Database::class, 'dbConfig');
        $pathProp->setAccessible(true);
        $path = $pathProp->getValue($db)['sqlite']['path'];
        unset($db);

        $pdo = new PDO('sqlite:' . $path);
        $pdo->exec('DROP TABLE ai_action_proposals');
        $pdo->exec('DROP TABLE purchase_order_drafts');
        $pdo->exec('UPDATE schema_version SET version = 30');
        $pdo = null;

        $db = new Database(['driver' => 'sqlite', 'sqlite' => ['path' => $path]], dirname(__DIR__));
        $pdo = $db->pdo();
        $this->assertSame(33, (int) $pdo->query('SELECT version FROM schema_version')->fetchColumn());

        $types = array_column($pdo->query('PRAGMA table_info(ai_action_proposals)')->fetchAll(PDO::FETCH_ASSOC), 'type', 'name');
        $this->assertSame('TEXT', $types['expires_at'], 'SQLite-on a típus változatlan.');
        $this->assertSame('TEXT', $types['executed_at']);

        $indexes = array_column($pdo->query("SELECT name FROM sqlite_master WHERE type = 'index' AND tbl_name IN ('ai_action_proposals', 'purchase_order_drafts')")->fetchAll(PDO::FETCH_ASSOC), 'name');
        foreach ([
            'idx_ai_action_proposals_status', 'idx_ai_action_proposals_created_at', 'idx_ai_action_proposals_expires_at',
            'idx_ai_action_proposals_agent', 'idx_ai_action_proposals_type', 'idx_ai_action_proposals_fingerprint',
            'idx_purchase_order_drafts_proposal_id', 'idx_purchase_order_drafts_product_id',
        ] as $expected) {
            $this->assertContains($expected, $indexes);
        }

        $this->assertExpiresAtRoundTrips($db);
    }

    public function testSqliteFreshInstallWritesAndReadsExpiresAt(): void
    {
        $this->assertExpiresAtRoundTrips(tests_new_database());
    }

    public function testSqliteRerunningV31ToV33MigrationsIsANoOp(): void
    {
        $db = tests_new_database();
        $this->assertExpiresAtRoundTrips($db);
        foreach (['migrateV31ActionProposals', 'migrateV32ActionExecution', 'migrateV33StockTakeCountBaseline'] as $method) {
            $this->invokePrivate($db, $method);
            $this->invokePrivate($db, $method);
        }
        $this->assertSame(1, (int) $db->pdo()->query('SELECT COUNT(*) FROM ai_action_proposals')->fetchColumn(), 'Az újrafuttatás nem érinti a meglévő adatot.');
    }

    private function assertExpiresAtRoundTrips(Database $db): void
    {
        $expiresAt = '2026-10-02 08:30:00';
        $id = $db->createActionProposal([
            'proposal_type' => 'reorder', 'agent' => 'inventory', 'entity_type' => 'product', 'entity_id' => 1,
            'fingerprint' => 'b02-' . bin2hex(random_bytes(4)),
            'created_at' => '2026-09-25 08:30:00', 'updated_at' => '2026-09-25 08:30:00', 'expires_at' => $expiresAt,
        ]);
        $this->assertIsInt($id);
        $this->assertSame($expiresAt, $db->getActionProposal($id)['expires_at']);

        $stmt = $db->pdo()->prepare('SELECT COUNT(*) FROM ai_action_proposals WHERE expires_at < ?');
        $stmt->execute(['2026-10-03 00:00:00']);
        $this->assertSame(1, (int) $stmt->fetchColumn());
    }

    // ------------------------------------------------------------------
    // B-03 — adjustLocationStock() MySQL-ága
    // ------------------------------------------------------------------

    public function testLocationStockUpsertUsesNativeMysqlUpsert(): void
    {
        $db = $this->mysqlDatabase($pdo);
        $db->decrementLocationStock(7, 3, 2);

        $this->assertCount(1, $pdo->log);
        $this->assertSame(
            'INSERT INTO location_stock (product_id, location_id, stock_qty) VALUES (:pid, :lid, GREATEST(0, :delta1)) ON DUPLICATE KEY UPDATE stock_qty = GREATEST(0, stock_qty + :delta2)',
            self::normalize($pdo->log[0])
        );
        // Natív MySQL prepare-nél egy névvel ellátott paraméter nem ismételhető,
        // és a delta egész típusként megy át (numerikus GREATEST-összehasonlítás).
        $this->assertSame([
            ':pid' => [7, PDO::PARAM_INT], ':lid' => [3, PDO::PARAM_INT],
            ':delta1' => [-2, PDO::PARAM_INT], ':delta2' => [-2, PDO::PARAM_INT],
        ], SqlRecordingStatement::$lastBindings);
    }

    public function testStockTransferMysqlPathEmitsNoSqliteOnlySyntax(): void
    {
        $db = $this->mysqlDatabase($pdo);
        $db->transferStock(7, 1, 2, 4, null);
        $db->transferStock(7, null, 2, 4, null);

        $this->assertNoSqliteOnlySyntax($pdo->log);
        $upserts = array_filter($pdo->log, fn($sql) => str_contains($sql, 'INTO location_stock'));
        $this->assertCount(2, $upserts);
        foreach ($upserts as $sql) {
            $this->assertStringContainsString('ON DUPLICATE KEY UPDATE', $sql);
        }
    }

    // ------------------------------------------------------------------
    // B-04 — reverseSaleBenefits() MySQL-ága (teljes kódút)
    // ------------------------------------------------------------------

    public function testReverseSaleBenefitsMysqlPathUsesGreatestAndNoSqliteOnlySyntax(): void
    {
        $db = $this->mysqlDatabase($pdo);
        SqlRecordingStatement::$fetchColumnValue = 5; // pl. a visszakeresett ajándékkártya-id
        try {
            $this->invokePrivate($db, 'reverseSaleBenefits', [42, [
                'customer_id' => 9, 'loyalty_points_earned' => 12, 'loyalty_points_redeemed' => 3,
                'total' => 900.0, 'gift_card_redeemed' => 100.0, 'coupon_id' => 11,
            ]]);
        } finally {
            SqlRecordingStatement::$fetchColumnValue = false;
        }

        $normalized = array_map([self::class, 'normalize'], $pdo->log);
        $this->assertContains('UPDATE coupons SET times_used = GREATEST(0, times_used - 1) WHERE id = ?', $normalized);
        $this->assertNotEmpty(array_filter($normalized, fn($sql) => str_contains($sql, 'loyalty_points = GREATEST(0, loyalty_points + :delta)')));
        $this->assertNotEmpty(array_filter($normalized, fn($sql) => str_starts_with($sql, 'INSERT INTO gift_card_transactions')), 'A teljes kódút (ajándékkártya-ág is) lefutott.');
        $this->assertNoSqliteOnlySyntax($pdo->log);
    }

    // ------------------------------------------------------------------
    // Statikus őr: SQLite-only SQL csak driver-függő ágban állhat
    // ------------------------------------------------------------------

    /**
     * Azokat a sorokat adja vissza, ahol SQLite-only SQL áll anélkül, hogy
     * az előző 25 sorban driver-elágazás ('driver'/'isMysql') lenne.
     *
     * @param list<string> $lines
     * @return list<string>
     */
    private static function unguardedSqliteOnlySql(array $lines): array
    {
        $offenders = [];
        foreach ($lines as $i => $line) {
            $trimmed = ltrim($line);
            if (str_starts_with($trimmed, '*') || str_starts_with($trimmed, '//') || str_starts_with($trimmed, '/*')) {
                continue;
            }
            if (!preg_match('/ON CONFLICT|INSERT OR (IGNORE|REPLACE)|\bMAX\(\s*[^(),]+,/', $line)) {
                continue;
            }
            $context = implode("\n", array_slice($lines, max(0, $i - 25), 26));
            if (!preg_match('/\bdriver\b|\$isMysql\b/', $context)) {
                $offenders[] = ($i + 1) . ': ' . trim($line);
            }
        }
        return $offenders;
    }

    public function testDatabaseHasNoUnguardedSqliteOnlySql(): void
    {
        $lines = file(dirname(__DIR__) . '/src/Database.php', FILE_IGNORE_NEW_LINES);
        $this->assertSame([], self::unguardedSqliteOnlySql($lines));
    }

    public function testGuardDetectsTheOriginalB03AndB04Code(): void
    {
        $b04 = [
            '    private function reverseSaleBenefits(int $saleId, array $sale): void',
            '    {',
            "            \$this->pdo->prepare('UPDATE coupons SET times_used = MAX(0, times_used - 1) WHERE id = ?')",
        ];
        $b03 = [
            '    private function adjustLocationStock(int $productId, int $locationId, int $delta): void',
            '    {',
            '        $stmt = $this->pdo->prepare(\'',
            '            INSERT INTO location_stock (product_id, location_id, stock_qty)',
            '            VALUES (:pid, :lid, MAX(0, :delta))',
            '            ON CONFLICT(product_id, location_id) DO UPDATE SET stock_qty = MAX(0, stock_qty + :delta)',
        ];
        $this->assertCount(1, self::unguardedSqliteOnlySql($b04));
        $this->assertCount(2, self::unguardedSqliteOnlySql($b03));
    }
}

/**
 * SQL-felvevő PDO: a kiadott SQL-t rögzíti, de nem hajtja végre (a MySQL-
 * dialektus egy SQLite-kapcsolaton úgysem futna). A tranzakciókezelés a
 * valódi (memóriabeli SQLite) kapcsolaté.
 */
final class SqlRecordingPdo extends PDO
{
    /** @var list<string> */
    public array $log = [];

    public function __construct()
    {
        parent::__construct('sqlite::memory:');
        $this->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->setAttribute(PDO::ATTR_STATEMENT_CLASS, [SqlRecordingStatement::class, []]);
    }

    public function exec(string $statement): int|false
    {
        $this->log[] = $statement;
        return 0;
    }

    public function prepare(string $query, array $options = []): PDOStatement|false
    {
        $this->log[] = $query;
        SqlRecordingStatement::$lastBindings = [];
        return parent::prepare('SELECT 1');
    }

    public function query(string $query, ?int $fetchMode = null, mixed ...$fetchModeArgs): PDOStatement|false
    {
        return $this->prepare($query);
    }

    public function lastInsertId(?string $name = null): string|false
    {
        return '1';
    }
}

final class SqlRecordingStatement extends PDOStatement
{
    public static mixed $fetchColumnValue = false;
    /** @var array<int|string, array{0: mixed, 1: int}> */
    public static array $lastBindings = [];

    protected function __construct()
    {
    }

    public function execute(?array $params = null): bool
    {
        return true;
    }

    public function bindValue(int|string $param, mixed $value, int $type = PDO::PARAM_STR): bool
    {
        self::$lastBindings[$param] = [$value, $type];
        return true;
    }

    public function rowCount(): int
    {
        return 1;
    }

    public function fetchColumn(int $column = 0): mixed
    {
        return self::$fetchColumnValue;
    }

    public function fetch(int $mode = PDO::FETCH_DEFAULT, int $cursorOrientation = PDO::FETCH_ORI_NEXT, int $cursorOffset = 0): mixed
    {
        return false;
    }

    public function fetchAll(int $mode = PDO::FETCH_DEFAULT, mixed ...$args): array
    {
        return [];
    }
}
