<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * P0-1 regresszió: schema.mysql.sql valaha lemaradt a schema.sql (SQLite)
 * mögött — a sales.idempotency_key/idempotency_fingerprint/invoice_claim_at
 * oszlopok bekerültek a SQLite sémába (migrateV17SaleIdempotency()/
 * migrateV18SaleIdempotencyFingerprint() útján), de a MySQL friss-telepítési
 * fájlba nem, ami minden eladást elhasított egy friss MySQL-telepítésen.
 *
 * Ez a teszt nem igényel élő MySQL szervert: egy valódi, friss SQLite
 * adatbázist épít fel a teljes ensureSchema()/schema.sql úton (ez a
 * "jelenlegi migrációs eredmény" — élőben, nem csak szövegből olvasva), majd
 * statikusan feldolgozza a schema.mysql.sql fájlt, és minden táblára
 * ellenőrzi, hogy a SQLite oldalon létező oszlopok mindegyike szerepel-e a
 * MySQL oldalon is. Ha valaha újra lemarad egy oszlop, ez a teszt elbukik.
 */
final class SchemaDriftTest extends TestCase
{
    public function testMysqlFreshInstallSchemaContainsEveryTablePresentInSqliteFreshInstallSchema(): void
    {
        [, $mysqlColumnsByTable] = $this->loadSchemas();
        $sqliteTables = $this->loadSqliteTables();

        $missingTables = [];
        foreach (array_keys($sqliteTables) as $table) {
            if (!array_key_exists($table, $mysqlColumnsByTable)) {
                $missingTables[] = $table;
            }
        }

        $this->assertSame(
            [],
            $missingTables,
            'Tables present in the SQLite fresh-install schema (schema.sql) but entirely ' .
            'missing from the MySQL fresh-install schema (schema.mysql.sql): ' . implode(', ', $missingTables)
        );
    }

    public function testMysqlFreshInstallSchemaHasEveryColumnPresentInSqliteFreshInstallSchema(): void
    {
        [, $mysqlColumnsByTable] = $this->loadSchemas();
        $sqliteTables = $this->loadSqliteTables();

        $missingColumns = [];
        foreach ($sqliteTables as $table => $sqliteColumns) {
            $mysqlColumns = $mysqlColumnsByTable[$table] ?? null;
            if ($mysqlColumns === null) {
                // Az egész tábla hiányát a másik teszt jelzi külön.
                continue;
            }
            foreach ($sqliteColumns as $column) {
                if (!in_array($column, $mysqlColumns, true)) {
                    $missingColumns[] = "{$table}.{$column}";
                }
            }
        }

        $this->assertSame(
            [],
            $missingColumns,
            "Columns present in the SQLite fresh-install schema (schema.sql) but missing from " .
            "the MySQL fresh-install schema (schema.mysql.sql) — this is exactly the class of bug " .
            "that broke every sale on a fresh MySQL install (P0-1): " . implode(', ', $missingColumns)
        );
    }

    /**
     * @return array{0: array<string, array<int, string>>, 1: array<string, array<int, string>>}
     */
    private function loadSchemas(): array
    {
        $mysqlSchemaSql = file_get_contents(dirname(__DIR__) . '/schema.mysql.sql');
        $this->assertIsString($mysqlSchemaSql, 'schema.mysql.sql must be readable');

        $mysqlColumnsByTable = [];
        foreach ($this->loadSqliteTables() as $table => $ignored) {
            $columns = $this->extractMysqlTableColumns($mysqlSchemaSql, $table);
            if ($columns !== null) {
                $mysqlColumnsByTable[$table] = $columns;
            }
        }

        return [[], $mysqlColumnsByTable];
    }

    /**
     * @return array<string, array<int, string>> table name => column names,
     *         built from a REAL freshly-installed SQLite database (i.e. the
     *         actual, executed result of schema.sql — not a text guess).
     */
    private function loadSqliteTables(): array
    {
        static $cache = null;
        if ($cache !== null) {
            return $cache;
        }

        $db = tests_new_database();
        $pdo = $db->pdo();

        $tableNames = $pdo->query(
            "SELECT name FROM sqlite_master WHERE type = 'table' AND name NOT LIKE 'sqlite_%'"
        )->fetchAll(PDO::FETCH_COLUMN);

        $tables = [];
        foreach ($tableNames as $table) {
            $columns = [];
            foreach ($pdo->query("PRAGMA table_info({$table})")->fetchAll(PDO::FETCH_ASSOC) as $col) {
                $columns[] = $col['name'];
            }
            $tables[$table] = $columns;
        }

        $cache = $tables;
        return $tables;
    }

    /**
     * Statikusan kinyeri egy MySQL "CREATE TABLE IF NOT EXISTS <table> ( ... )"
     * blokk oszlopneveit a schema.mysql.sql szövegéből, a KEY/UNIQUE
     * KEY/PRIMARY KEY/CONSTRAINT/FOREIGN KEY/INDEX sorokat kihagyva.
     *
     * @return array<int, string>|null null, ha a tábla egyáltalán nem
     *         található a MySQL sémában.
     */
    private function extractMysqlTableColumns(string $mysqlSchemaSql, string $table): ?array
    {
        $needle = "CREATE TABLE IF NOT EXISTS {$table} (";
        $pos = strpos($mysqlSchemaSql, $needle);
        if ($pos === false) {
            return null;
        }

        $start = $pos + strlen($needle);
        $len = strlen($mysqlSchemaSql);
        $depth = 1;
        $i = $start;
        while ($i < $len && $depth > 0) {
            $ch = $mysqlSchemaSql[$i];
            if ($ch === '(') {
                $depth++;
            } elseif ($ch === ')') {
                $depth--;
            }
            $i++;
        }
        $body = substr($mysqlSchemaSql, $start, max(0, $i - $start - 1));

        $skipPrefixes = ['KEY ', 'UNIQUE KEY', 'PRIMARY KEY', 'CONSTRAINT', 'FOREIGN KEY', 'INDEX '];

        $columns = [];
        foreach (explode("\n", $body) as $line) {
            $line = trim(rtrim(trim($line), ','));
            if ($line === '' || str_starts_with($line, '--')) {
                continue;
            }
            $upper = strtoupper($line);
            $skip = false;
            foreach ($skipPrefixes as $prefix) {
                if (str_starts_with($upper, $prefix)) {
                    $skip = true;
                    break;
                }
            }
            if ($skip) {
                continue;
            }
            if (preg_match('/^([A-Za-z_][A-Za-z0-9_]*)/', $line, $m)) {
                $columns[] = $m[1];
            }
        }

        return $columns;
    }
}
