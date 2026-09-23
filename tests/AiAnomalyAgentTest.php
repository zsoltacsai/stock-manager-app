<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * AnomalyAgent — determinisztikus, szkriptelt provider-válaszokkal (a
 * MEGLÉVŐ FakeAiProvider osztály, lásd AiAgentRunnerTest.php — SOSE
 * függ valódi Ollama/Anthropic/OpenAI-tól) bizonyítja, hogy az
 * AnomalyAgent pontosan UGYANAZT az AgentRunner-t/ToolRegistry-t
 * használja, mint az Inventory/SalesAgent, VALÓDI AnomalyTools/
 * SalesTools/InventoryTools-szal (nem hamisított eszköz-eredményekkel)
 * fut.
 */
final class AiAnomalyAgentTest extends TestCase
{
    private function seedProduct(Database $db, string $name, int $stockQty = 500, int $threshold = 5): int
    {
        $pdo = $db->pdo();
        $stmt = $pdo->prepare('
            INSERT INTO products (name, unit, price, net_price, stock_qty, low_stock_threshold, group_name, vat_rate, purchase_price_net)
            VALUES (?, "db", 1000, 787, ?, ?, "Italok", "27", 500)
        ');
        $stmt->execute([$name, $stockQty, $threshold]);
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

    private function ago(int $days): string
    {
        return date('Y-m-d H:i:s', strtotime("-$days days"));
    }

    private function settings(): array
    {
        return ['low_stock_default_threshold' => 5];
    }

    public function testSimpleAnswerWithoutAnyToolCall(): void
    {
        $provider = new FakeAiProvider([
            new AiChatResponse('Ma nincs semmi rendkívüli.', []),
        ]);
        $db = tests_new_database();
        $agent = new AnomalyAgent($provider, $db, $this->settings(), 5);

        $result = $agent->answer('Van valami szokatlan?');

        $this->assertTrue($result->success);
        $this->assertSame('Ma nincs semmi rendkívüli.', $result->answer);
        $this->assertSame([], $result->toolsUsed);
    }

    public function testSingleAnomalyToolCallFeedsBackRealBackendFindings(): void
    {
        $db = tests_new_database();
        $decline = $this->seedProduct($db, 'Visszaeső termék');
        $this->seedSale($db, $decline, 20, 1000.0, $this->ago(40));
        $this->seedSale($db, $decline, 2, 1000.0, $this->ago(5));

        $provider = new FakeAiProvider([
            new AiChatResponse(null, [new ToolCall('c1', 'get_sales_anomalies', ['period' => 'last_30_days'])]),
            new AiChatResponse('A "Visszaeső termék" eladása jelentősen visszaesett.', []),
        ]);
        $agent = new AnomalyAgent($provider, $db, $this->settings(), 5);

        $result = $agent->answer('Van valami szokatlan a forgalomban?');

        $this->assertTrue($result->success);
        $this->assertSame(['get_sales_anomalies'], $result->toolsUsed);

        $secondCallMessages = $provider->receivedMessages[1];
        $toolMessages = array_values(array_filter($secondCallMessages, fn ($m) => $m['role'] === 'tool'));
        $this->assertStringContainsString('sales_decline', $toolMessages[0]['content']);
        $this->assertStringContainsString('-90', $toolMessages[0]['content']);
    }

    public function testMultipleAnomalyToolCallsInOneIteration(): void
    {
        $db = tests_new_database();
        $decline = $this->seedProduct($db, 'Visszaeső');
        $this->seedSale($db, $decline, 20, 1000.0, $this->ago(40));
        $this->seedSale($db, $decline, 2, 1000.0, $this->ago(5));

        $provider = new FakeAiProvider([
            new AiChatResponse(null, [
                new ToolCall('c1', 'get_sales_anomalies', ['period' => 'last_30_days']),
                new ToolCall('c2', 'get_inventory_anomalies', ['period' => 'last_30_days']),
            ]),
            new AiChatResponse('Összesítve válaszoltam.', []),
        ]);
        $agent = new AnomalyAgent($provider, $db, $this->settings(), 5);

        $result = $agent->answer('Foglald össze a mai helyzetet.');

        $this->assertTrue($result->success);
        $this->assertSame(['get_sales_anomalies', 'get_inventory_anomalies'], $result->toolsUsed);
    }

    public function testAnomalyPlusInventoryCrossoverToolIsAvailableAndExecutes(): void
    {
        $db = tests_new_database();
        $pid = $this->seedProduct($db, 'Alacsony készletű', 3);

        $provider = new FakeAiProvider([
            new AiChatResponse(null, [new ToolCall('c1', 'get_stock_status', ['product_id' => $pid])]),
            new AiChatResponse('A készlet alacsony, ez magyarázhatja a jelenséget.', []),
        ]);
        $agent = new AnomalyAgent($provider, $db, $this->settings(), 5);

        $result = $agent->answer('Miért probléma ez a termék?');

        $this->assertTrue($result->success);
        $this->assertSame(['get_stock_status'], $result->toolsUsed);
        $toolMessages = array_values(array_filter($provider->receivedMessages[1], fn ($m) => $m['role'] === 'tool'));
        $this->assertStringContainsString('is_low_stock', $toolMessages[0]['content']);
    }

    public function testAnomalyPlusSalesCrossoverToolIsAvailableAndExecutes(): void
    {
        $db = tests_new_database();
        $pid = $this->seedProduct($db, 'Termék A');
        $this->seedSale($db, $pid, 5, 1000.0, $this->ago(3));

        $provider = new FakeAiProvider([
            new AiChatResponse(null, [new ToolCall('c1', 'get_product_sales_trend', ['product_id' => $pid, 'period' => 'last_30_days'])]),
            new AiChatResponse('Ez a termék eladási trendje.', []),
        ]);
        $agent = new AnomalyAgent($provider, $db, $this->settings(), 5);

        $result = $agent->answer('Mutasd ennek a terméknek a trendjét.');

        $this->assertTrue($result->success);
        $this->assertSame(['get_product_sales_trend'], $result->toolsUsed);
    }

    public function testNoAnomalyResponseWhenShopHasOnlyNormalActivity(): void
    {
        $db = tests_new_database();
        $pid = $this->seedProduct($db, 'Stabil termék');
        $this->seedSale($db, $pid, 10, 1000.0, $this->ago(40));
        $this->seedSale($db, $pid, 10, 1000.0, $this->ago(5));

        $provider = new FakeAiProvider([
            new AiChatResponse(null, [new ToolCall('c1', 'get_sales_anomalies', ['period' => 'last_30_days'])]),
            new AiChatResponse('Nem találtam anomáliát, a forgalom stabil.', []),
        ]);
        $agent = new AnomalyAgent($provider, $db, $this->settings(), 5);

        $result = $agent->answer('Van valami szokatlan?');

        $this->assertTrue($result->success);
        $toolMessages = array_values(array_filter($provider->receivedMessages[1], fn ($m) => $m['role'] === 'tool'));
        $decoded = json_decode($toolMessages[0]['content'], true);
        $this->assertSame(0, $decoded['data']['count']);
    }

    public function testInsufficientDataResponseIsDistinguishableFromNormal(): void
    {
        $db = tests_new_database(); // teljesen üres bolt

        $provider = new FakeAiProvider([
            new AiChatResponse(null, [new ToolCall('c1', 'get_sales_anomalies', ['period' => 'last_30_days'])]),
            new AiChatResponse('Nincs elég adat a megbízható elemzéshez ebben az időszakban.', []),
        ]);
        $agent = new AnomalyAgent($provider, $db, $this->settings(), 5);

        $result = $agent->answer('Van valami szokatlan?');

        $this->assertTrue($result->success);
        $toolMessages = array_values(array_filter($provider->receivedMessages[1], fn ($m) => $m['role'] === 'tool'));
        $decoded = json_decode($toolMessages[0]['content'], true);
        $this->assertNotEmpty($decoded['data']['data_quality'], 'A tool eredménynek jeleznie kellett az adathiányt.');
    }

    public function testUnknownToolRequestedByProviderIsRejectedButRunContinues(): void
    {
        $provider = new FakeAiProvider([
            new AiChatResponse(null, [new ToolCall('c1', 'delete_all_sales', [])]),
            new AiChatResponse('Az nem elérhető eszköz.', []),
        ]);
        $db = tests_new_database();
        $agent = new AnomalyAgent($provider, $db, $this->settings(), 5);

        $result = $agent->answer('próbálj meg valami furát');

        $this->assertTrue($result->success);
        $toolMessages = array_values(array_filter($provider->receivedMessages[1], fn ($m) => $m['role'] === 'tool'));
        $this->assertStringContainsString('Ismeretlen eszköz', $toolMessages[0]['content']);
    }

    public function testProviderFailureIsHandledGracefully(): void
    {
        $provider = new FakeAiProvider([
            new AiProviderException('kapcsolódási hiba', 'unavailable'),
        ]);
        $db = tests_new_database();
        $agent = new AnomalyAgent($provider, $db, $this->settings(), 5);

        $result = $agent->answer('kérdés');

        $this->assertFalse($result->success);
        $this->assertSame('Az AI-modell jelenleg nem érhető el.', $result->error);
    }

    public function testIterationLimitIsEnforcedAndFailsGracefully(): void
    {
        $script = [];
        for ($i = 0; $i < 10; $i++) {
            $script[] = new AiChatResponse(null, [new ToolCall("c$i", 'get_sales_anomalies', ['period' => 'today'])]);
        }
        $provider = new FakeAiProvider($script);
        $db = tests_new_database();
        $agent = new AnomalyAgent($provider, $db, $this->settings(), 3);

        $result = $agent->answer('kérdés, ami sose ér véget');

        $this->assertFalse($result->success);
        $this->assertSame(3, $result->iterations);
    }

    public function testNoFabricatedAnomalyWhenBackendFoundNone(): void
    {
        // A modell (itt: a szkript) NEM állíthat elő anomáliát a
        // válaszban, amit a backend nem jelzett — ezt architekturálisan
        // az garantálja, hogy a végleges választ a modell adja, DE a
        // ToolRegistry-n keresztül KAPOTT adat mindig a VALÓDI, backend
        // által számított eredmény (lásd a fenti "no anomaly" teszt) —
        // itt azt bizonyítjuk, hogy egy normál/nem-anomális adatra a tool
        // eredmény ténylegesen üres listát ad, amit a modell csak
        // MAGYARÁZHAT, nem alakíthat át anomáliává.
        $db = tests_new_database();
        $pid = $this->seedProduct($db, 'Stabil');
        $this->seedSale($db, $pid, 10, 1000.0, $this->ago(40));
        $this->seedSale($db, $pid, 10, 1000.0, $this->ago(5));

        $tools = new AnomalyTools($db, $this->settings());
        $result = $tools->getSalesAnomalies(['period' => 'last_30_days']);

        $this->assertSame(0, $result['count']);
        $this->assertSame([], $result['anomalies']);
    }

    public function testAgentNameIsAnomaly(): void
    {
        $this->assertSame('anomaly', AnomalyAgent::name());
    }
}
