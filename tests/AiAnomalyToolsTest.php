<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * AnomalyTools — determinisztikus, valódi adatbázis-alapú tesztek (Fázis
 * 5). Nincs LLM/provider ebben a fájlban. A kör 16. pontjának 16-19.
 * tesztelendő esetei (több anomália egyszerre, darabszám-limitálás,
 * determinisztikus sorrend, duplikátum-elnyomás) ITT, kandidátum-
 * kiválasztási szinten bizonyítottak — lásd tests/AnomalyDetectorTest.php
 * az 1-15./20. esetekért, tisztán a detektor-logikára.
 */
final class AiAnomalyToolsTest extends TestCase
{
    private function seedProduct(Database $db, string $name, int $stockQty, int $threshold = 5, string $groupName = 'Italok'): int
    {
        $pdo = $db->pdo();
        $stmt = $pdo->prepare('
            INSERT INTO products (name, unit, price, net_price, stock_qty, low_stock_threshold, group_name, vat_rate, purchase_price_net)
            VALUES (?, "db", 1000, 787, ?, ?, ?, "27", 500)
        ');
        $stmt->execute([$name, $stockQty, $threshold, $groupName]);
        return (int) $pdo->lastInsertId();
    }

    private function seedSale(Database $db, int $productId, int $qty, float $unitPrice, string $createdAt): int
    {
        $pdo = $db->pdo();
        $pdo->prepare("INSERT INTO sales (total, payment_method, created_at) VALUES (?, 'Készpénz', ?)")
            ->execute([$qty * $unitPrice, $createdAt]);
        $saleId = (int) $pdo->lastInsertId();
        $pdo->prepare('INSERT INTO sale_items (sale_id, product_id, name, qty, unit_price, vat_rate) VALUES (?, ?, "teszt", ?, ?, "27")')
            ->execute([$saleId, $productId, $qty, $unitPrice]);
        return $saleId;
    }

    private function seedReturn(Database $db, int $saleProductId, int $saleId, int $qty, float $unitPrice, string $createdAt): void
    {
        $pdo = $db->pdo();
        $pdo->prepare('INSERT INTO returns (sale_id, total_refund, created_at) VALUES (?, ?, ?)')
            ->execute([$saleId, $qty * $unitPrice, $createdAt]);
        $returnId = (int) $pdo->lastInsertId();
        $pdo->prepare('INSERT INTO return_items (return_id, product_id, name, qty, unit_price, created_at) VALUES (?, ?, "teszt", ?, ?, ?)')
            ->execute([$returnId, $saleProductId, $qty, $unitPrice, $createdAt]);
    }

    private function tools(Database $db): AnomalyTools
    {
        return new AnomalyTools($db, ['low_stock_default_threshold' => 5]);
    }

    private function ago(int $days): string
    {
        return date('Y-m-d H:i:s', strtotime("-$days days"));
    }

    // ------------------------------------------------------------------
    // get_sales_anomalies — érvényes bemenet, visszaesés/megugrás
    // ------------------------------------------------------------------

    public function testSalesAnomaliesDetectsDeclineAndSpikeWithAutoPreviousPeriod(): void
    {
        $db = tests_new_database();
        $decline = $this->seedProduct($db, 'Visszaeső', 500);
        $spike = $this->seedProduct($db, 'Megugró', 500);
        $this->seedSale($db, $decline, 20, 1000.0, $this->ago(40));
        $this->seedSale($db, $spike, 10, 1000.0, $this->ago(40));
        $this->seedSale($db, $decline, 2, 1000.0, $this->ago(5));
        $this->seedSale($db, $spike, 16, 1000.0, $this->ago(5));

        $result = $this->tools($db)->getSalesAnomalies(['period' => 'last_30_days']);

        $this->assertSame(2, $result['count']);
        $types = array_column($result['anomalies'], 'type');
        $this->assertContains('sales_decline', $types);
        $this->assertContains('sales_spike', $types);
    }

    public function testSalesAnomaliesEmptyShopHasNoFindingsNotError(): void
    {
        $db = tests_new_database();
        $result = $this->tools($db)->getSalesAnomalies(['period' => 'last_30_days']);
        $this->assertSame(0, $result['count']);
        $this->assertSame([], $result['anomalies']);
    }

    public function testEmptyShopSurfacesInsufficientDataNotSilentNormal(): void
    {
        // Lásd a kör 6. pontja: "Do not convert 'not enough data' into
        // 'no anomaly'" — egy vadonatúj, adat nélküli bolt esetén a
        // válasznak EXPLICIT jeleznie kell az adathiányt, nem csak egy
        // üres (és ezáltal félreérthető, "minden rendben"-nek tűnő)
        // anomalies listát adnia.
        $db = tests_new_database();
        $result = $this->tools($db)->getSalesAnomalies(['period' => 'last_30_days']);

        $this->assertNotEmpty($result['data_quality']);
        $reasons = array_column($result['data_quality'], 'reason');
        $this->assertContains('no_sales_data_in_either_period', $reasons);
    }

    public function testInventoryAnomaliesDataQualityEmptyWhenDataIsSufficient(): void
    {
        $db = tests_new_database();
        $pid = $this->seedProduct($db, 'Aktív', 50);
        $this->seedSale($db, $pid, 5, 1000.0, $this->ago(5));

        $result = $this->tools($db)->getInventoryAnomalies(['period' => 'last_30_days']);
        $this->assertSame([], $result['data_quality']);
    }

    public function testSalesAnomaliesRespectsExplicitCompareRange(): void
    {
        $db = tests_new_database();
        $pid = $this->seedProduct($db, 'Termék A', 500);
        $this->seedSale($db, $pid, 20, 1000.0, '2026-01-05 10:00:00');
        $this->seedSale($db, $pid, 2, 1000.0, '2026-02-05 10:00:00');

        $result = $this->tools($db)->getSalesAnomalies([
            'date_from' => '2026-02-01', 'date_to' => '2026-02-28',
            'compare_date_from' => '2026-01-01', 'compare_date_to' => '2026-01-31',
        ]);

        $this->assertSame(1, $result['count']);
        $this->assertSame('sales_decline', $result['anomalies'][0]['type']);
        $this->assertSame('2026-01-01', $result['compare_date_from']);
    }

    public function testSalesAnomaliesReturnRateAnomalyAtShopLevel(): void
    {
        $db = tests_new_database();
        $pid = $this->seedProduct($db, 'Termék A', 500);
        // Előző időszak: 10 tranzakció, nincs visszáru.
        for ($i = 0; $i < 10; $i++) {
            $this->seedSale($db, $pid, 1, 1000.0, $this->ago(45));
        }
        // Jelenlegi időszak: 10 tranzakció, 3 visszáruval (30% arány, jóval a küszöb felett).
        $saleIds = [];
        for ($i = 0; $i < 10; $i++) {
            $this->seedSale($db, $pid, 1, 1000.0, $this->ago(5));
        }
        $pdo = $db->pdo();
        $recentSaleIds = $pdo->query('SELECT id FROM sales ORDER BY id DESC LIMIT 3')->fetchAll(PDO::FETCH_COLUMN);
        foreach ($recentSaleIds as $sid) {
            $this->seedReturn($db, $pid, (int) $sid, 1, 1000.0, $this->ago(4));
        }

        $result = $this->tools($db)->getSalesAnomalies(['period' => 'last_30_days']);

        $returnAnomalies = array_values(array_filter($result['anomalies'], fn ($a) => $a['type'] === 'return_rate_anomaly'));
        $this->assertCount(1, $returnAnomalies);
        $this->assertSame('shop', $returnAnomalies[0]['entity_type']);
    }

    // ------------------------------------------------------------------
    // get_inventory_anomalies
    // ------------------------------------------------------------------

    public function testInventoryAnomaliesDetectsSlowMovingStock(): void
    {
        $db = tests_new_database();
        $this->seedProduct($db, 'Sosem fogyó', 80);
        // Van egy másik, aktívan fogyó termék is, hogy a lista ne legyen triviálisan üres.
        $active = $this->seedProduct($db, 'Aktív', 50);
        $this->seedSale($db, $active, 5, 1000.0, $this->ago(5));

        $result = $this->tools($db)->getInventoryAnomalies(['period' => 'last_30_days']);

        $slowMoving = array_values(array_filter($result['anomalies'], fn ($a) => $a['type'] === 'slow_moving_stock'));
        $this->assertCount(1, $slowMoving);
        $this->assertSame('Sosem fogyó', $slowMoving[0]['entity_name']);
    }

    public function testInventoryAnomaliesDetectsLowStockElevatedSales(): void
    {
        $db = tests_new_database();
        $pid = $this->seedProduct($db, 'Alacsony+megugrás', 3, 5); // alacsony küszöb alatt
        $this->seedSale($db, $pid, 10, 1000.0, $this->ago(40));
        $this->seedSale($db, $pid, 20, 1000.0, $this->ago(5));

        $result = $this->tools($db)->getInventoryAnomalies(['period' => 'last_30_days']);

        $found = array_values(array_filter($result['anomalies'], fn ($a) => $a['type'] === 'low_stock_elevated_sales'));
        $this->assertCount(1, $found);
        $this->assertSame($pid, $found[0]['entity_id']);
    }

    public function testInventoryAnomaliesDetectsStockSalesDivergenceWithReliableForecast(): void
    {
        $db = tests_new_database();
        $pid = $this->seedProduct($db, 'Túlkészletezett', 1000);
        // Jó megbízhatóságú (>= 2 elkülönülő nap) korábbi eladás.
        $this->seedSale($db, $pid, 15, 1000.0, $this->ago(50));
        $this->seedSale($db, $pid, 15, 1000.0, $this->ago(45));
        // Jelenlegi (utolsó 30 nap, getStockForecastBulk 30 napos ablakán belül IS), >= 2 nap, kis mennyiség.
        $this->seedSale($db, $pid, 1, 1000.0, $this->ago(20));
        $this->seedSale($db, $pid, 1, 1000.0, $this->ago(5));

        $result = $this->tools($db)->getInventoryAnomalies(['period' => 'last_30_days']);

        $divergence = array_values(array_filter($result['anomalies'], fn ($a) => $a['type'] === 'stock_sales_divergence'));
        $this->assertCount(1, $divergence);
        $this->assertSame($pid, $divergence[0]['entity_id']);
        $this->assertGreaterThanOrEqual(AnomalyDetector::DIVERGENCE_MIN_DAYS_REMAINING, $divergence[0]['current_value']);
    }

    // ------------------------------------------------------------------
    // 19. Duplikátum-elnyomás — egy divergenciaként MÁR flag-elt termék
    // NEM jelenik meg MÉG EGYSZER lassan-mozgó-készletként is.
    // ------------------------------------------------------------------

    public function testDivergingProductIsNotAlsoReportedAsSlowMovingStock(): void
    {
        $db = tests_new_database();
        $pid = $this->seedProduct($db, 'Túlkészletezett és alig fogyó', 1000);
        $this->seedSale($db, $pid, 15, 1000.0, $this->ago(50));
        $this->seedSale($db, $pid, 15, 1000.0, $this->ago(45));
        // A jelenlegi 30 napos ablakban KÉT elkülönülő napon kel el (a
        // getStockForecastBulk() megbízható becsléséhez, lásd Database
        // docblokkja — legalább 2 megfigyelt nap kell), de egy visszáru
        // NETTÓSÍTVA <= SLOW_MOVING_MAX_QTY(1)-re viszi a nettó
        // mennyiséget — e nélkül a termék EGYÜTT jogosult lenne
        // divergenciára ÉS lassan-mozgóra is (a nettósított
        // getTopProductsReport()-alapú "lassan mozgó" ellenőrzésen).
        $this->seedSale($db, $pid, 1, 1000.0, $this->ago(20));
        $saleId2 = $this->seedSale($db, $pid, 1, 1000.0, $this->ago(10));
        $this->seedReturn($db, $pid, $saleId2, 1, 1000.0, $this->ago(9));

        $result = $this->tools($db)->getInventoryAnomalies(['period' => 'last_30_days']);

        $types = array_column(array_filter($result['anomalies'], fn ($a) => $a['entity_id'] === $pid), 'type');
        $this->assertContains('stock_sales_divergence', $types);
        $this->assertNotContains('slow_moving_stock', $types, 'A divergencia már lefedi ugyanazt a jelenséget — nincs szükség duplikált bejegyzésre.');
        $this->assertCount(1, array_filter($result['anomalies'], fn ($a) => $a['entity_id'] === $pid), 'Pontosan EGY bejegyzés lehet ugyanarra a termékre ebben a forgatókönyvben.');
    }

    // ------------------------------------------------------------------
    // 17. Darabszám-limitálás
    // ------------------------------------------------------------------

    public function testResultLimitIsHonoredAndTruncatedFlagIsSet(): void
    {
        $db = tests_new_database();
        for ($i = 0; $i < 5; $i++) {
            $pid = $this->seedProduct($db, "Visszaeső $i", 500);
            $this->seedSale($db, $pid, 20, 1000.0, $this->ago(40));
            $this->seedSale($db, $pid, 2, 1000.0, $this->ago(5));
        }

        $result = $this->tools($db)->getSalesAnomalies(['period' => 'last_30_days', 'limit' => 2]);

        $this->assertCount(2, $result['anomalies']);
        $this->assertSame(2, $result['count']);
        $this->assertTrue($result['truncated']);
    }

    public function testLimitIsClampedToMaximum(): void
    {
        $db = tests_new_database();
        $pid = $this->seedProduct($db, 'Termék A', 500);
        $this->seedSale($db, $pid, 20, 1000.0, $this->ago(40));
        $this->seedSale($db, $pid, 2, 1000.0, $this->ago(5));

        $result = $this->tools($db)->getSalesAnomalies(['period' => 'last_30_days', 'limit' => 99999]);
        $this->assertLessThanOrEqual(30, count($result['anomalies']));
    }

    // ------------------------------------------------------------------
    // 18. Determinisztikus sorrend
    // ------------------------------------------------------------------

    public function testAnomaliesAreOrderedBySeverityThenMagnitudeDescending(): void
    {
        $db = tests_new_database();
        $critical = $this->seedProduct($db, 'Kritikus (-90%)', 500);
        $medium = $this->seedProduct($db, 'Közepes (-30%)', 500);
        $high = $this->seedProduct($db, 'Magas (-60%)', 500);
        $this->seedSale($db, $critical, 20, 1000.0, $this->ago(40));
        $this->seedSale($db, $critical, 2, 1000.0, $this->ago(5));
        $this->seedSale($db, $medium, 20, 1000.0, $this->ago(40));
        $this->seedSale($db, $medium, 14, 1000.0, $this->ago(5)); // (14-20)/20 = -30% pontosan
        $this->seedSale($db, $high, 20, 1000.0, $this->ago(40));
        $this->seedSale($db, $high, 8, 1000.0, $this->ago(5));

        $result = $this->tools($db)->getSalesAnomalies(['period' => 'last_30_days', 'limit' => 30]);

        $severities = array_column($result['anomalies'], 'severity');
        $rank = ['critical' => 4, 'high' => 3, 'medium' => 2, 'low' => 1];
        $ranks = array_map(fn ($s) => $rank[$s], $severities);
        $sortedRanks = $ranks;
        rsort($sortedRanks);
        $this->assertSame($sortedRanks, $ranks, 'A súlyosságnak csökkenő sorrendben kell szerepelnie.');

        // Determinizmus: ugyanaz a hívás, ugyanaz a sorrend.
        $result2 = $this->tools($db)->getSalesAnomalies(['period' => 'last_30_days', 'limit' => 30]);
        $this->assertSame(array_column($result['anomalies'], 'entity_id'), array_column($result2['anomalies'], 'entity_id'));
    }

    // ------------------------------------------------------------------
    // 20. Jövőbeli időszak elutasítva
    // ------------------------------------------------------------------

    public function testFullyFutureRangeIsRejected(): void
    {
        $db = tests_new_database();
        $farFuture = date('Y-m-d', strtotime('+30 days'));
        $farFuture2 = date('Y-m-d', strtotime('+40 days'));
        $this->expectException(InvalidArgumentException::class);
        $this->tools($db)->getSalesAnomalies(['date_from' => $farFuture, 'date_to' => $farFuture2]);
    }

    public function testInvalidDateRangeIsRejected(): void
    {
        $db = tests_new_database();
        $this->expectException(InvalidArgumentException::class);
        $this->tools($db)->getSalesAnomalies(['date_from' => 'nem-datum', 'date_to' => '2026-09-23']);
    }

    // ------------------------------------------------------------------
    // Biztonság: nincs write-jellegű mellékhatás, nincs nyers SQL kifelé
    // ------------------------------------------------------------------

    public function testToolsNeverModifyProductsOrSalesTables(): void
    {
        $db = tests_new_database();
        $pid = $this->seedProduct($db, 'Termék A', 500);
        $this->seedSale($db, $pid, 20, 1000.0, $this->ago(40));
        $this->seedSale($db, $pid, 2, 1000.0, $this->ago(5));

        $beforeProducts = $db->pdo()->query('SELECT COUNT(*) FROM products')->fetchColumn();
        $beforeSales = $db->pdo()->query('SELECT COUNT(*) FROM sales')->fetchColumn();

        $this->tools($db)->getSalesAnomalies(['period' => 'last_30_days']);
        $this->tools($db)->getInventoryAnomalies(['period' => 'last_30_days']);

        $this->assertSame($beforeProducts, $db->pdo()->query('SELECT COUNT(*) FROM products')->fetchColumn());
        $this->assertSame($beforeSales, $db->pdo()->query('SELECT COUNT(*) FROM sales')->fetchColumn());
    }
}
