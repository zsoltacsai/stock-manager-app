<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * PERF-02 — a riportok az SQLite 32 766-os (MySQL 65 535-ös) paraméterkorlátja
 * felett is működnek, és a PHP-memóriájuk nem a tartomány méretével nő.
 * Korábban egy 32 766-nál több eladást tartalmazó időszak riportja
 * („too many SQL variables”), és egy 32 766-nál több eladott/készleten lévő
 * terméket érintő top termék / árrés / kategória / készletérték riport HTTP
 * 500-zal bukott. A régi algoritmussal való bájtszintű egyezést a Phase 6
 * D1/D2/D3 adatkészleteken egy (csak az IN-listákat daraboló) régi-kód-oracle
 * igazolta; itt az invariánsokat egy független SQL-összesítéssel ellenőrizzük.
 */
final class ReportScalingTest extends TestCase
{
    private static string $dir;
    private static Database $db;

    public static function setUpBeforeClass(): void
    {
        self::$dir = sys_get_temp_dir() . '/sm_report_scaling_' . bin2hex(random_bytes(5));
        mkdir(self::$dir, 0775, true);
        self::$db = new Database(['driver' => 'sqlite', 'sqlite' => ['path' => self::$dir . '/db.sqlite']], dirname(__DIR__));
        $pdo = self::$db->pdo();
        $pdo->beginTransaction();
        // 40 000 termék (mind készleten), 40 000 eladás 40 napra elosztva, eladásonként 1–2 tétel.
        $pdo->exec("WITH RECURSIVE n(x) AS (SELECT 1 UNION ALL SELECT x + 1 FROM n WHERE x < 40000)
            INSERT INTO products (name, group_name, stock_qty, purchase_price_net, net_price, price, vat_rate)
            SELECT 'Skála termék ' || x, 'Csoport ' || (x % 7), 1 + x % 5, 50 + x % 100, 100 + x % 300, 127 + x % 381, '27' FROM n");
        $pdo->exec("WITH RECURSIVE n(x) AS (SELECT 1 UNION ALL SELECT x + 1 FROM n WHERE x < 40000)
            INSERT INTO sales (total, payment_method, gift_card_redeemed, created_at)
            SELECT 1000 + (x % 97) * 10, CASE x % 3 WHEN 0 THEN 'Készpénz' WHEN 1 THEN 'Bankkártya' ELSE 'Átutalás' END,
                   CASE WHEN x % 11 = 0 THEN 200 ELSE 0 END,
                   printf('2026-08-%02d %02d:%02d:00', 1 + (x % 31), x % 24, x % 60) FROM n");
        $pdo->exec("INSERT INTO sale_items (sale_id, product_id, name, qty, unit_price, vat_rate)
            SELECT id, id, 'Skála termék ' || id, 1, total + gift_card_redeemed, '27' FROM sales");
        $pdo->exec("INSERT INTO sale_items (sale_id, product_id, name, qty, unit_price, vat_rate)
            SELECT id, 1 + (id * 7) % 40000, 'Második tétel', 2, 150, '5' FROM sales WHERE id % 2 = 0");
        $pdo->exec("INSERT INTO purchases (supplier_name, total_net, total_gross, created_at) VALUES ('Skála', 0, 0, '2026-07-01 10:00:00')");
        $pdo->exec("INSERT INTO purchase_items (purchase_id, product_id, name, qty, vat_rate, unit_cost_net, unit_cost_gross, line_net, line_gross)
            SELECT 1, id, name, 1, '27', 50, 63.5, 50, 63.5 FROM products WHERE id % 3 = 0");
        $pdo->commit();
    }

    public static function tearDownAfterClass(): void
    {
        self::$db = new Database(['driver' => 'sqlite', 'sqlite' => ['path' => ':memory:']], dirname(__DIR__));
        foreach (glob(self::$dir . '/*') ?: [] as $f) {
            @unlink($f);
        }
        @rmdir(self::$dir);
    }

    public function testSalesReportOverMoreThanTheSqlVariableLimitWorksAndMatchesSqlTotals(): void
    {
        memory_reset_peak_usage();
        $base = memory_get_usage();
        $report = self::$db->getSalesReportSummary('2026-08-01', '2026-08-31');
        $peak = memory_get_peak_usage() - $base;

        $sql = self::$db->pdo()->query("SELECT COUNT(*) AS c, SUM(total + gift_card_redeemed) AS g FROM sales WHERE created_at >= '2026-08-01' AND created_at < '2026-09-01'")->fetch(PDO::FETCH_ASSOC);
        $this->assertGreaterThan(32766, (int) $sql['c'], 'A tartomány több eladást tartalmaz, mint az SQLite paraméterkorlátja.');
        $this->assertSame((int) $sql['c'], $report['sales_count']);
        $this->assertEqualsWithDelta((float) $sql['g'], $report['total_gross'], 0.005);
        $this->assertEqualsWithDelta($report['total_gross'], $report['total_net'] + $report['total_vat'], 0.05);
        $this->assertCount(31, $report['by_day']);
        $this->assertEqualsWithDelta($report['total_gross'], array_sum(array_column($report['by_day'], 'gross')), 0.05);
        $this->assertLessThan(16 * 1024 * 1024, $peak, 'A riport nem töltheti memóriába a teljes eladáslistát.');

        $cash = self::$db->getSalesReportSummary('2026-08-01', '2026-08-31', 'Készpénz');
        $cashSql = (int) self::$db->pdo()->query("SELECT COUNT(*) FROM sales WHERE payment_method = 'Készpénz'")->fetchColumn();
        $this->assertSame($cashSql, $cash['sales_count']);
    }

    public function testProductLevelReportsOverMoreThanTheSqlVariableLimitWork(): void
    {
        memory_reset_peak_usage();
        $base = memory_get_usage();
        $top = self::$db->getTopProductsReport('2026-08-01', '2026-08-31', null, 0, 50);
        $margin = self::$db->getSalesMarginSummary('2026-08-01', '2026-08-31');
        $categories = self::$db->getTopCategoriesReport('2026-08-01', '2026-08-31', 20);
        $valuation = self::$db->getInventoryValuationSummary();
        $peak = memory_get_peak_usage() - $base;

        $sold = self::$db->pdo()->query('SELECT COUNT(DISTINCT product_id) FROM sale_items')->fetchColumn();
        $this->assertGreaterThan(32766, (int) $sold);
        $this->assertCount(50, $top);
        $qty = array_column($top, 'qty');
        $sorted = $qty;
        rsort($sorted);
        $this->assertSame($sorted, $qty, 'Mennyiség szerint csökkenő sorrend.');
        $this->assertSame((int) $sold, $margin['products_with_margin'] + $margin['products_without_margin']);
        $withCost = (int) self::$db->pdo()->query('SELECT COUNT(DISTINCT si.product_id) FROM sale_items si WHERE si.product_id IN (SELECT product_id FROM purchase_items)')->fetchColumn();
        $this->assertSame($withCost, $margin['products_with_margin'], 'Árrés csak beszerzési előzménnyel rendelkező terméknél.');
        $this->assertSame((int) $sold, array_sum(array_column($categories, 'product_count')));
        $this->assertSame(40000, $valuation['products_total']);
        $this->assertSame((int) self::$db->pdo()->query('SELECT COUNT(DISTINCT product_id) FROM purchase_items')->fetchColumn(), $valuation['products_with_reliable_cost']);
        $retail = (float) self::$db->pdo()->query('SELECT SUM(stock_qty * net_price) FROM products WHERE is_deleted = 0 AND stock_qty > 0')->fetchColumn();
        $this->assertEqualsWithDelta($retail, $valuation['retail_value_net'], 0.005);
        $this->assertLessThan(48 * 1024 * 1024, $peak);
    }

    public function testBulkOperationsAndForecastAcceptMoreIdsThanTheSqlVariableLimit(): void
    {
        $ids = range(1, 40000);
        $this->assertSame(40000, count(self::$db->getStockForecastBulk($ids, 60)));
        // 33 000 azonosító egyetlen híváshoz (a régi, darabolatlan IN itt elbukott); ebből 1 000 létezik.
        $this->assertSame(1000, count(self::$db->findProductsByIds(range(39001, 72000))));
        $withHistory = self::$db->productsHavePurchaseHistory($ids);
        $this->assertSame((int) self::$db->pdo()->query('SELECT COUNT(DISTINCT product_id) FROM purchase_items')->fetchColumn(), count($withHistory));
        self::$db->bulkSetProductsGroup($ids, 'Közös csoport');
        $this->assertSame(40000, (int) self::$db->pdo()->query("SELECT COUNT(*) FROM products WHERE group_name = 'Közös csoport'")->fetchColumn());
    }
}
