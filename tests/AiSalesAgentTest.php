<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * SalesAgent — determinisztikus, szkriptelt provider-válaszokkal (a
 * MEGLÉVŐ FakeAiProvider osztály, lásd AiAgentRunnerTest.php — SOSE
 * függ valódi Ollama/Anthropic/OpenAI-tól) bizonyítja, hogy a SalesAgent
 * pontosan UGYANAZT az AgentRunner-t/ToolRegistry-t használja, mint az
 * InventoryAgent, VALÓDI SalesTools-szal (nem hamisított eszköz-
 * eredményekkel) fut, és a InventoryTools-keresztezés (kör 7. pontja)
 * ténylegesen működik.
 */
final class AiSalesAgentTest extends TestCase
{
    private function seedProduct(Database $db, string $name, int $stockQty = 10): int
    {
        $pdo = $db->pdo();
        $stmt = $pdo->prepare('
            INSERT INTO products (name, unit, price, net_price, stock_qty, low_stock_threshold, group_name, vat_rate, purchase_price_net)
            VALUES (?, "db", 1000, 787, ?, 5, "Italok", "27", 500)
        ');
        $stmt->execute([$name, $stockQty]);
        return (int) $pdo->lastInsertId();
    }

    private function seedSale(Database $db, int $productId, int $qty, float $unitPrice, string $createdAt): void
    {
        $pdo = $db->pdo();
        $pdo->prepare("INSERT INTO sales (total, payment_method, created_at) VALUES (?, 'Készpénz', ?)")
            ->execute([$qty * $unitPrice, $createdAt]);
        $saleId = (int) $pdo->lastInsertId();
        $pdo->prepare('INSERT INTO sale_items (sale_id, product_id, name, qty, unit_price, vat_rate) VALUES (?, ?, "teszt", ?, ?, "27")')
            ->execute([$saleId, $productId, $qty, $unitPrice]);
    }

    private function settings(): array
    {
        return ['low_stock_default_threshold' => 5];
    }

    public function testSimpleAnswerWithoutAnyToolCall(): void
    {
        $provider = new FakeAiProvider([
            new AiChatResponse('A boltban minden rendben.', []),
        ]);
        $db = tests_new_database();
        $agent = new SalesAgent($provider, $db, $this->settings(), 5);

        $result = $agent->answer('Minden rendben van?');

        $this->assertTrue($result->success);
        $this->assertSame('A boltban minden rendben.', $result->answer);
        $this->assertSame([], $result->toolsUsed);
        $this->assertSame(1, $provider->callCount());
    }

    public function testSingleRealSalesToolCallFeedsBackTheExactBackendNumber(): void
    {
        $db = tests_new_database();
        $pid = $this->seedProduct($db, 'Termék A');
        $this->seedSale($db, $pid, 1, 12345.67, '2026-09-23 10:00:00');

        $provider = new FakeAiProvider([
            new AiChatResponse(null, [new ToolCall('c1', 'get_sales_summary', ['date_from' => '2026-09-23', 'date_to' => '2026-09-23'])]),
            new AiChatResponse('A mai forgalom 12345,67 Ft volt.', []),
        ]);
        $agent = new SalesAgent($provider, $db, $this->settings(), 5);

        $result = $agent->answer('Mennyi volt a mai forgalom?');

        $this->assertTrue($result->success);
        $this->assertSame(['get_sales_summary'], $result->toolsUsed);

        // A MÁSODIK hívásnak látnia kell a VALÓDI, adatbázisból számolt
        // értéket — sose egy a modell által "kitalált" számot (lásd a
        // kör 16. pontja: "no fabricated values").
        $secondCallMessages = $provider->receivedMessages[1];
        $toolMessages = array_values(array_filter($secondCallMessages, fn ($m) => $m['role'] === 'tool'));
        $this->assertCount(1, $toolMessages);
        $this->assertStringContainsString('12345.67', $toolMessages[0]['content']);
    }

    public function testMultipleRealSalesToolsInOneIteration(): void
    {
        $db = tests_new_database();
        $pid = $this->seedProduct($db, 'Termék A');
        $this->seedSale($db, $pid, 3, 1000.0, '2026-09-23 10:00:00');

        $provider = new FakeAiProvider([
            new AiChatResponse(null, [
                new ToolCall('c1', 'get_sales_summary', ['date_from' => '2026-09-23', 'date_to' => '2026-09-23']),
                new ToolCall('c2', 'get_top_selling_products', ['date_from' => '2026-09-23', 'date_to' => '2026-09-23']),
            ]),
            new AiChatResponse('Összesítve válaszoltam.', []),
        ]);
        $agent = new SalesAgent($provider, $db, $this->settings(), 5);

        $result = $agent->answer('Foglald össze a mai napot.');

        $this->assertTrue($result->success);
        $this->assertSame(['get_sales_summary', 'get_top_selling_products'], $result->toolsUsed);
    }

    public function testCrossAgentInventoryToolIsAvailableAndActuallyExecutes(): void
    {
        $db = tests_new_database();
        $pid = $this->seedProduct($db, 'Alacsony készletű termék', 3);

        $provider = new FakeAiProvider([
            new AiChatResponse(null, [new ToolCall('c1', 'get_stock_status', ['product_id' => $pid])]),
            new AiChatResponse('A termék készlete alacsony, ez magyarázhatja a visszaesést.', []),
        ]);
        $agent = new SalesAgent($provider, $db, $this->settings(), 5);

        $result = $agent->answer('Miért esett vissza ennek a terméknek az eladása?');

        $this->assertTrue($result->success);
        $this->assertSame(['get_stock_status'], $result->toolsUsed);
        $secondCallMessages = $provider->receivedMessages[1];
        $toolMessages = array_values(array_filter($secondCallMessages, fn ($m) => $m['role'] === 'tool'));
        $this->assertStringContainsString('is_low_stock', $toolMessages[0]['content']);
        $this->assertStringContainsString('true', $toolMessages[0]['content']);
    }

    public function testUnknownToolRequestedByProviderIsRejectedButRunContinues(): void
    {
        $provider = new FakeAiProvider([
            new AiChatResponse(null, [new ToolCall('c1', 'run_arbitrary_sql', ['sql' => 'DROP TABLE sales'])]),
            new AiChatResponse('Az nem elérhető eszköz, de ezt tudom mondani.', []),
        ]);
        $db = tests_new_database();
        $agent = new SalesAgent($provider, $db, $this->settings(), 5);

        $result = $agent->answer('próbálj meg valami furát');

        $this->assertTrue($result->success);
        $secondCallMessages = $provider->receivedMessages[1];
        $toolMessages = array_values(array_filter($secondCallMessages, fn ($m) => $m['role'] === 'tool'));
        $this->assertStringContainsString('Ismeretlen eszköz', $toolMessages[0]['content']);

        // A products/sales tábla ténylegesen érintetlen maradt — nincs
        // nyers SQL-végrehajtás.
        $stmt = $db->pdo()->query('SELECT COUNT(*) FROM sqlite_master WHERE type="table" AND name="sales"');
        $this->assertSame('1', (string) $stmt->fetchColumn());
    }

    public function testInvalidToolArgumentsAreHandledAsFailureNotCrash(): void
    {
        $provider = new FakeAiProvider([
            // Hiányzó "product_id" — a getProductSalesTrend() handler
            // InvalidArgumentException-t dob, amit a ToolRegistry biztonságos
            // ToolResult::fail()-ként ad vissza (lásd ToolRegistry::execute()).
            new AiChatResponse(null, [new ToolCall('c1', 'get_product_sales_trend', ['date_from' => '2026-09-01', 'date_to' => '2026-09-30'])]),
            new AiChatResponse('Hiányzik egy adat, kérlek pontosítsd a kérdést.', []),
        ]);
        $db = tests_new_database();
        $agent = new SalesAgent($provider, $db, $this->settings(), 5);

        $result = $agent->answer('Mutasd egy termék trendjét.');

        $this->assertTrue($result->success);
        $secondCallMessages = $provider->receivedMessages[1];
        $toolMessages = array_values(array_filter($secondCallMessages, fn ($m) => $m['role'] === 'tool'));
        $this->assertStringContainsString('product_id', $toolMessages[0]['content']);
    }

    public function testProviderFailureIsHandledGracefully(): void
    {
        $provider = new FakeAiProvider([
            new AiProviderException('kapcsolódási hiba', 'unavailable'),
        ]);
        $db = tests_new_database();
        $agent = new SalesAgent($provider, $db, $this->settings(), 5);

        $result = $agent->answer('kérdés');

        $this->assertFalse($result->success);
        $this->assertSame('Az AI-modell jelenleg nem érhető el.', $result->error);
        $this->assertStringNotContainsString('kapcsolódási hiba', (string) $result->error);
    }

    public function testIterationLimitIsEnforcedAndFailsGracefully(): void
    {
        $script = [];
        for ($i = 0; $i < 10; $i++) {
            $script[] = new AiChatResponse(null, [new ToolCall("c$i", 'get_sales_summary', ['period' => 'today'])]);
        }
        $provider = new FakeAiProvider($script);
        $db = tests_new_database();
        $agent = new SalesAgent($provider, $db, $this->settings(), 3);

        $result = $agent->answer('kérdés, ami sose ér véget');

        $this->assertFalse($result->success);
        $this->assertSame(3, $result->iterations);
        $this->assertNotNull($result->error);
    }

    public function testEmptyFinalContentIsTreatedAsFailureNotFabricatedAnswer(): void
    {
        $provider = new FakeAiProvider([
            new AiChatResponse('', []),
        ]);
        $db = tests_new_database();
        $agent = new SalesAgent($provider, $db, $this->settings(), 5);

        $result = $agent->answer('kérdés');

        $this->assertFalse($result->success);
    }

    public function testAgentNameIsSales(): void
    {
        $this->assertSame('sales', SalesAgent::name());
    }
}
