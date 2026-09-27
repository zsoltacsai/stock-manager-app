<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/Support/SqlCapturePdo.php';

/**
 * PERF-06 — V38: index a products.name oszlopon. Az új termék mentésénél (így
 * az importnál is) futó dupla-beküldés elleni keresés
 * (findRecentlyCreatedIdenticalProduct()) korábban minden beszúrásnál a teljes
 * terméktáblát bejárta (50 000 soros import ~30 perc). A friss telepítés és a
 * v37-ről frissített adatbázis is megkapja az indexet, a keresés azt használja.
 */
final class ProductNameIndexMigrationTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/sm_v38_' . bin2hex(random_bytes(5));
        mkdir($this->dir, 0775, true);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->dir . '/*') ?: [] as $f) {
            @unlink($f);
        }
        @rmdir($this->dir);
    }

    private static function indexNames(PDO $pdo): array
    {
        return array_column($pdo->query("PRAGMA index_list(products)")->fetchAll(PDO::FETCH_ASSOC), 'name');
    }

    public function testFreshInstallAndUpgradeFromV37BothHaveTheNameIndexAndTheLookupUsesIt(): void
    {
        $path = $this->dir . '/db.sqlite';
        $db = new Database(['driver' => 'sqlite', 'sqlite' => ['path' => $path]], dirname(__DIR__));
        $this->assertContains('idx_products_unit_name', self::indexNames($db->pdo()), 'Friss telepítés (schema.sql).');
        unset($db);

        $pdo = new PDO('sqlite:' . $path);
        $pdo->exec('DROP INDEX idx_products_unit_name');
        $pdo->exec('UPDATE schema_version SET version = 37');
        $pdo->exec("INSERT INTO products (name, unit, currency, net_price, price) VALUES ('Megmaradó termék', 'db', 'HUF', 100, 127)");
        $pdo = null;

        $db = new Database(['driver' => 'sqlite', 'sqlite' => ['path' => $path]], dirname(__DIR__));
        $this->assertContains('idx_products_unit_name', self::indexNames($db->pdo()), 'v37 → v38 frissítés.');
        $this->assertSame(38, (int) $db->pdo()->query('SELECT version FROM schema_version')->fetchColumn());
        $this->assertSame('Megmaradó termék', $db->pdo()->query('SELECT name FROM products')->fetchColumn());

        $plan = implode(' | ', array_column($db->pdo()->query("EXPLAIN QUERY PLAN SELECT id, updated_at FROM products
            WHERE name = 'x' AND unit = 'db' AND currency = 'HUF' AND ROUND(net_price, 2) = ROUND(1, 2) AND ROUND(price, 2) = ROUND(1, 2)
            ORDER BY id DESC LIMIT 1")->fetchAll(PDO::FETCH_ASSOC), 'detail'));
        $this->assertStringContainsString('idx_products_unit_name', $plan, 'A dupla-beküldés elleni keresés az indexet használja, nem a teljes táblát.');

        // Terv-regresszió őr: az index NEM veheti át a név szerint rendezett,
        // LIKE-os keresések tervét (globalSearch, searchProductsByName) — egy
        // csak name-re épülő index ezt tette (D3: 54 ms → 395 ms).
        $searchPlan = implode(' | ', array_column($db->pdo()->query("EXPLAIN QUERY PLAN SELECT id, name FROM products
            WHERE is_deleted = 0 AND (name LIKE '%x%' OR barcode LIKE '%x%' OR cikkszam LIKE '%x%') ORDER BY name LIMIT 20")->fetchAll(PDO::FETCH_ASSOC), 'detail'));
        $this->assertStringNotContainsString('idx_products_unit_name', $searchPlan);
    }

    public function testDuplicateSubmitGuardBehaviourIsUnchanged(): void
    {
        $db = new Database(['driver' => 'sqlite', 'sqlite' => ['path' => $this->dir . '/db2.sqlite']], dirname(__DIR__));
        $p = ['name' => 'Gyors termék', 'unit' => 'db', 'currency' => 'HUF', 'vat_rate' => '27', 'net_price' => 100, 'price' => 127];
        $first = $db->saveProduct($p);
        $this->assertSame($first, $db->saveProduct($p), 'Tartalmilag azonos, 5 s-on belüli ismételt beküldés ugyanazt a terméket adja (P1-3).');
        $this->assertNotSame($first, $db->saveProduct(['price' => 128] + $p), 'Eltérő ár: új termék.');
        $db->pdo()->exec("UPDATE products SET updated_at = '2000-01-01 00:00:00'");
        $this->assertNotSame($first, $db->saveProduct($p), 'Az 5 s-os ablakon túl új termék jön létre, mint korábban.');
    }

    public function testMysqlMigrationCreatesTheSameIndex(): void
    {
        $ref = new ReflectionClass(Database::class);
        $db = $ref->newInstanceWithoutConstructor();
        $capture = new SqlCapturePdo();
        $ref->getProperty('pdo')->setValue($db, $capture);
        $ref->getProperty('driver')->setValue($db, 'mysql');
        $ref->getMethod('migrateV38ProductNameIndex')->invoke($db);
        $this->assertSame(['CREATE INDEX idx_products_unit_name ON products(unit, name)'], array_map(static fn ($e) => SqlCapturePdo::normalize($e['sql']), $capture->log));
        $this->assertMatchesRegularExpression('/KEY idx_products_unit_name \(unit, name\)/', (string) file_get_contents(dirname(__DIR__) . '/schema.mysql.sql'));
    }
}
