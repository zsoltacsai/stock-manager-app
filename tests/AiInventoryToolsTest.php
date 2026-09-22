<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Az InventoryTools eszközök determinisztikus, valódi adatbázis-alapú
 * tesztjei — lásd a kör 6./8./14. pontja: minden konkrét szám (készlet,
 * sebesség) a MEGLÉVŐ Database-rétegből kell jöjjön, nem a modellből.
 * Nincs LLM/provider ebben a fájlban — kizárólag a tool handler-eket
 * teszteljük közvetlenül.
 */
final class AiInventoryToolsTest extends TestCase
{
    private function seedProduct(Database $db, string $name, int $stockQty, ?int $threshold = null, ?string $barcode = null): int
    {
        $pdo = $db->pdo();
        $stmt = $pdo->prepare('
            INSERT INTO products (name, unit, price, net_price, stock_qty, low_stock_threshold, barcode, vat_rate)
            VALUES (?, "db", 1000, 787, ?, ?, ?, "27")
        ');
        $stmt->execute([$name, $stockQty, $threshold, $barcode]);
        return (int) $pdo->lastInsertId();
    }

    private function seedSale(Database $db, int $productId, int $qty, float $unitPrice, string $createdAt): void
    {
        $pdo = $db->pdo();
        $pdo->prepare("INSERT INTO sales (total, payment_method, created_at) VALUES (?, 'Készpénz', ?)")
            ->execute([$qty * $unitPrice, $createdAt]);
        $saleId = (int) $pdo->lastInsertId();
        $pdo->prepare('INSERT INTO sale_items (sale_id, product_id, name, qty, unit_price, vat_rate) VALUES (?, ?, "teszt", ?, ?, 27)')
            ->execute([$saleId, $productId, $qty, $unitPrice]);
    }

    private function tools(Database $db, array $settingsOverride = []): InventoryTools
    {
        $settings = array_merge(['low_stock_default_threshold' => 5], $settingsOverride);
        return new InventoryTools($db, $settings);
    }

    // ------------------------------------------------------------------
    // get_product
    // ------------------------------------------------------------------

    public function testGetProductById(): void
    {
        $db = tests_new_database();
        $id = $this->seedProduct($db, 'Coca Cola 0.5l', 50);

        $result = $this->tools($db)->getProduct(['id' => $id]);

        $this->assertTrue($result['found']);
        $this->assertSame('id', $result['match_type']);
        $this->assertSame('Coca Cola 0.5l', $result['product']['name']);
        $this->assertSame(50, $result['product']['stock_qty']);
    }

    public function testGetProductByIdNotFoundThrows(): void
    {
        $db = tests_new_database();
        $this->expectException(InvalidArgumentException::class);
        $this->tools($db)->getProduct(['id' => 999999]);
    }

    public function testGetProductByBarcode(): void
    {
        $db = tests_new_database();
        $this->seedProduct($db, 'Fanta 0.5l', 30, null, '1234567890123');

        $result = $this->tools($db)->getProduct(['barcode' => '1234567890123']);

        $this->assertTrue($result['found']);
        $this->assertSame('Fanta 0.5l', $result['product']['name']);
    }

    public function testGetProductByNamePartialMatchReturnsCandidates(): void
    {
        $db = tests_new_database();
        $this->seedProduct($db, 'Coca Cola 0.5l', 50);
        $this->seedProduct($db, 'Coca Cola 1.5l', 20);
        $this->seedProduct($db, 'Fanta 0.5l', 30);

        $result = $this->tools($db)->getProduct(['name' => 'Coca Cola']);

        $this->assertTrue($result['found']);
        $this->assertSame(2, $result['candidate_count']);
    }

    public function testGetProductByNameNoMatchReturnsEmptyNotFound(): void
    {
        $db = tests_new_database();
        $this->seedProduct($db, 'Fanta 0.5l', 30);

        $result = $this->tools($db)->getProduct(['name' => 'Teljesen mást keresek']);

        $this->assertFalse($result['found']);
        $this->assertSame([], $result['candidates']);
    }

    public function testGetProductWithNoIdentifierThrows(): void
    {
        $db = tests_new_database();
        $this->expectException(InvalidArgumentException::class);
        $this->tools($db)->getProduct([]);
    }

    // ------------------------------------------------------------------
    // get_stock_status
    // ------------------------------------------------------------------

    public function testStockStatusLowStock(): void
    {
        $db = tests_new_database();
        $id = $this->seedProduct($db, 'Alacsony készletű', 3, 5);

        $status = $this->tools($db)->getStockStatus(['product_id' => $id]);

        $this->assertSame(3, $status['stock_qty']);
        $this->assertTrue($status['is_low_stock']);
        $this->assertFalse($status['is_out_of_stock']);
    }

    public function testStockStatusOutOfStock(): void
    {
        $db = tests_new_database();
        $id = $this->seedProduct($db, 'Elfogyott', 0, 5);

        $status = $this->tools($db)->getStockStatus(['product_id' => $id]);

        $this->assertTrue($status['is_out_of_stock']);
        $this->assertFalse($status['is_low_stock']);
    }

    public function testStockStatusUsesDefaultThresholdWhenProductHasNone(): void
    {
        $db = tests_new_database();
        $id = $this->seedProduct($db, 'Nincs egyéni küszöb', 4, null);

        $status = $this->tools($db)->getStockStatus(['product_id' => $id]);

        $this->assertSame(5, $status['low_stock_threshold']); // a settings default
        $this->assertTrue($status['is_low_stock']);
    }

    // ------------------------------------------------------------------
    // get_low_stock_products
    // ------------------------------------------------------------------

    public function testLowStockProductsListsOnlyThoseBelowThreshold(): void
    {
        $db = tests_new_database();
        $this->seedProduct($db, 'Alacsony', 2, 5);
        $this->seedProduct($db, 'Rendben', 50, 5);

        $result = $this->tools($db)->getLowStockProducts([]);

        $this->assertSame('low', $result['filter']);
        $this->assertSame(1, $result['count']);
        $this->assertSame('Alacsony', $result['products'][0]['name']);
    }

    public function testLowStockProductsEmptyResultWhenNothingLow(): void
    {
        $db = tests_new_database();
        $this->seedProduct($db, 'Rendben', 50, 5);

        $result = $this->tools($db)->getLowStockProducts([]);

        $this->assertSame(0, $result['count']);
        $this->assertSame([], $result['products']);
    }

    public function testLowStockProductsOutFilterOnlyZeroStock(): void
    {
        $db = tests_new_database();
        $this->seedProduct($db, 'Alacsony de nem nulla', 2, 5);
        $this->seedProduct($db, 'Elfogyott', 0, 5);

        $result = $this->tools($db)->getLowStockProducts(['filter' => 'out']);

        $this->assertSame(1, $result['count']);
        $this->assertSame('Elfogyott', $result['products'][0]['name']);
    }

    public function testLowStockProductsLimitIsBounded(): void
    {
        $db = tests_new_database();
        for ($i = 0; $i < 5; $i++) {
            $this->seedProduct($db, "Alacsony $i", 1, 5);
        }

        $result = $this->tools($db)->getLowStockProducts(['limit' => 2]);

        $this->assertSame(2, $result['count']);
        $this->assertTrue($result['truncated']);
    }

    // ------------------------------------------------------------------
    // get_product_sales_velocity — determinisztikus számítás
    // ------------------------------------------------------------------

    public function testSalesVelocityComputesDeterministicAverage(): void
    {
        $db = tests_new_database();
        $id = $this->seedProduct($db, 'Gyorsan fogyó', 100, 5);
        // 3 különböző napon 5-5-5 db, az elmúlt 30 napos ablakban.
        $this->seedSale($db, $id, 5, 300.0, date('Y-m-d H:i:s', strtotime('-1 day')));
        $this->seedSale($db, $id, 5, 300.0, date('Y-m-d H:i:s', strtotime('-2 days')));
        $this->seedSale($db, $id, 5, 300.0, date('Y-m-d H:i:s', strtotime('-3 days')));

        $result = $this->tools($db)->getProductSalesVelocity(['product_id' => $id, 'window_days' => 30]);

        $this->assertSame('ok', $result['status']);
        $this->assertSame(round(15 / 30, 3), $result['avg_daily_consumption']);
        $this->assertIsInt($result['estimated_days_remaining']);
    }

    public function testSalesVelocityWithNoSalesIsZeroConsumptionNotFabricated(): void
    {
        $db = tests_new_database();
        $id = $this->seedProduct($db, 'Sose fogyott', 100, 5);

        $result = $this->tools($db)->getProductSalesVelocity(['product_id' => $id, 'window_days' => 30]);

        // Soha egyetlen eladás sem -> megbízhatóan "nulla fogyás", NEM
        // találgatott szám (lásd Database::getStockForecastBulk() docblokkja).
        $this->assertSame('zero_consumption', $result['status']);
        $this->assertSame(0.0, $result['avg_daily_consumption']);
        $this->assertNull($result['estimated_days_remaining']);
    }

    public function testSalesVelocityWithSingleIsolatedSaleDayIsInsufficientData(): void
    {
        $db = tests_new_database();
        $id = $this->seedProduct($db, 'Egyetlen elszigetelt nap', 100, 5);
        $this->seedSale($db, $id, 5, 300.0, date('Y-m-d H:i:s', strtotime('-1 day')));

        $result = $this->tools($db)->getProductSalesVelocity(['product_id' => $id, 'window_days' => 30]);

        // Csak EGY megfigyelt nap -> nem megbízható átlag, SOSE találgatott szám.
        $this->assertSame('insufficient_data', $result['status']);
        $this->assertNull($result['avg_daily_consumption']);
        $this->assertNull($result['estimated_days_remaining']);
    }

    public function testSalesVelocityOutOfStockStatus(): void
    {
        $db = tests_new_database();
        $id = $this->seedProduct($db, 'Elfogyott sebesség-teszt', 0, 5);

        $result = $this->tools($db)->getProductSalesVelocity(['product_id' => $id]);

        $this->assertSame('out_of_stock', $result['status']);
    }

    public function testSalesVelocityInvalidProductThrows(): void
    {
        $db = tests_new_database();
        $this->expectException(InvalidArgumentException::class);
        $this->tools($db)->getProductSalesVelocity(['product_id' => 999999]);
    }

    public function testSalesVelocityWindowDaysIsClamped(): void
    {
        $db = tests_new_database();
        $id = $this->seedProduct($db, 'Ablak-teszt', 100, 5);

        $result = $this->tools($db)->getProductSalesVelocity(['product_id' => $id, 'window_days' => 9999]);

        $this->assertSame(90, $result['window_days']);
    }

    // ------------------------------------------------------------------
    // get_inventory_movements
    // ------------------------------------------------------------------

    public function testInventoryMovementsReturnsSalesInRange(): void
    {
        $db = tests_new_database();
        $id = $this->seedProduct($db, 'Mozgás-teszt', 50, 5);
        $this->seedSale($db, $id, 3, 300.0, date('Y-m-d H:i:s', strtotime('-1 day')));

        $today = date('Y-m-d');
        $weekAgo = date('Y-m-d', strtotime('-7 days'));
        $result = $this->tools($db)->getInventoryMovements(['product_id' => $id, 'date_from' => $weekAgo, 'date_to' => $today]);

        $this->assertSame(1, $result['count']);
        $this->assertSame('sale', $result['movements'][0]['type']);
        $this->assertSame(-3, $result['movements'][0]['qty_change']);
    }

    public function testInventoryMovementsEmptyResultWhenNothingInRange(): void
    {
        $db = tests_new_database();
        $id = $this->seedProduct($db, 'Nincs mozgás', 50, 5);

        $result = $this->tools($db)->getInventoryMovements([
            'product_id' => $id,
            'date_from' => '2020-01-01',
            'date_to' => '2020-01-31',
        ]);

        $this->assertSame(0, $result['count']);
        $this->assertSame([], $result['movements']);
    }

    public function testInventoryMovementsInvalidDateFormatThrows(): void
    {
        $db = tests_new_database();
        $this->expectException(InvalidArgumentException::class);
        $this->tools($db)->getInventoryMovements(['date_from' => '2026/01/01', 'date_to' => '2026-01-31']);
    }

    public function testInventoryMovementsFromAfterToThrows(): void
    {
        $db = tests_new_database();
        $this->expectException(InvalidArgumentException::class);
        $this->tools($db)->getInventoryMovements(['date_from' => '2026-02-01', 'date_to' => '2026-01-01']);
    }

    public function testInventoryMovementsInvalidTypeThrows(): void
    {
        $db = tests_new_database();
        $this->expectException(InvalidArgumentException::class);
        $this->tools($db)->getInventoryMovements(['date_from' => '2026-01-01', 'date_to' => '2026-01-31', 'type' => 'not_a_real_type']);
    }

    public function testInventoryMovementsMissingRequiredDatesThrows(): void
    {
        $db = tests_new_database();
        $this->expectException(InvalidArgumentException::class);
        $this->tools($db)->getInventoryMovements([]);
    }

    // ------------------------------------------------------------------
    // registerAll — mind az 5 eszköz regisztrálva legyen
    // ------------------------------------------------------------------

    public function testRegisterAllRegistersAllFiveTools(): void
    {
        $db = tests_new_database();
        $registry = new ToolRegistry();
        InventoryTools::registerAll($registry, $db, ['low_stock_default_threshold' => 5]);

        foreach (['get_product', 'get_stock_status', 'get_low_stock_products', 'get_product_sales_velocity', 'get_inventory_movements'] as $name) {
            $this->assertTrue($registry->has($name), "Hiányzó eszköz: $name");
        }
        $this->assertCount(5, $registry->all());
        foreach ($registry->all() as $tool) {
            $this->assertTrue($tool->readOnly, "{$tool->name} nem olvasás-kizárólagosként van regisztrálva.");
        }
    }
}
