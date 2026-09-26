<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/Support/AiFakeProviders.php';

/**
 * AI-01 — az `ai_max_tool_calls` a TELJES AI-futás tényleges
 * tool-végrehajtási kerete: egy modellválaszon belül, több fordulón át,
 * streamelve, provider-újrapróbálással és a Copilot al-ügynökein át is.
 */
final class AiToolCallBudgetTest extends TestCase
{
    /** @return array{0:ToolRegistry,1:object} a registry és egy {count} számláló */
    private function countingRegistry(): array
    {
        $counter = new class { public int $count = 0; };
        $registry = new ToolRegistry();
        $registry->register(new ToolDefinition('counted_tool', 'teszt', ['type' => 'object', 'properties' => []], function (array $a) use ($counter) {
            $counter->count++;
            return ['ok' => true];
        }));
        return [$registry, $counter];
    }

    /** @return ToolCall[] */
    private function calls(int $n, string $name = 'counted_tool', string $prefix = 'c'): array
    {
        $out = [];
        for ($i = 0; $i < $n; $i++) {
            $out[] = new ToolCall($prefix . $i, $name, []);
        }
        return $out;
    }

    private function runner(AiProviderInterface $provider, ToolRegistry $registry, int $maxToolCalls, int $maxIterations = 10, ?AiRunContext $context = null): AgentRunner
    {
        return new AgentRunner($provider, $registry, $maxIterations, null, AiCostLimits::fromSettings(['ai_max_tool_calls' => $maxToolCalls]), true, $context);
    }

    public function testExactlyTwentyToolCallsInOneResponseSucceed(): void
    {
        [$registry, $counter] = $this->countingRegistry();
        $provider = new FakeAiProvider([
            new AiChatResponse(null, $this->calls(20)),
            new AiChatResponse('Kész.', []),
        ]);

        $result = $this->runner($provider, $registry, 20)->run('sys', 'kérdés');

        $this->assertTrue($result->success);
        $this->assertSame('Kész.', $result->answer);
        $this->assertSame(20, $counter->count);
    }

    public function testTwentyFirstToolCallInOneResponseIsRejectedAndNeverExecuted(): void
    {
        [$registry, $counter] = $this->countingRegistry();
        $provider = new FakeAiProvider([
            new AiChatResponse(null, $this->calls(21)),
            new AiChatResponse('ezt a választ már nem kérjük le', []),
        ]);

        $result = $this->runner($provider, $registry, 20)->run('sys', 'kérdés');

        $this->assertFalse($result->success);
        $this->assertSame('tool_call_limit', $result->limitReached);
        $this->assertSame(20, $counter->count);
        $this->assertSame(1, $provider->callCount(), 'a keret túllépése után nincs további provider-hívás');
        $this->assertStringContainsString('eszköz-hívási korlát', (string) $result->error);
    }

    /**
     * A Phase 4 audit AI-01 reprodukciója: MOCK provider + valódi
     * AgentRunner/ToolRegistry + valódi SQLite + valódi InventoryTools,
     * egy válaszban 300 tool-hívás, `ai_max_tool_calls` = 20. A javítás
     * előtt mind a 300 lefutott.
     */
    public function testPhase4ReproductionThreeHundredCallsInOneResponseExecuteAtMostTwenty(): void
    {
        $db = tests_new_database();
        $db->pdo()->exec('INSERT INTO products (name, unit, price, net_price, stock_qty, group_name, vat_rate) VALUES ("AI-01 termék", "db", 100, 79, 1, "Italok", "27")');
        $source = new ToolRegistry();
        InventoryTools::registerAll($source, $db, []);
        $executed = 0;
        $registry = new ToolRegistry();
        foreach ($source->all() as $tool) {
            $handler = $tool->handler;
            $registry->register(new ToolDefinition($tool->name, $tool->description, $tool->inputSchema, function (array $a) use ($handler, &$executed) {
                $executed++;
                return $handler($a);
            }, $tool->readOnly));
        }
        $provider = new FakeAiProvider([new AiChatResponse(null, $this->calls(300, 'get_low_stock_products'))]);

        $result = $this->runner($provider, $registry, 20)->run('sys', 'kérdés');

        $this->assertFalse($result->success);
        $this->assertSame('tool_call_limit', $result->limitReached);
        $this->assertSame(20, $executed);
        $this->assertSame(1, $provider->callCount());
        $this->assertStringNotContainsString('FakeAiProvider', (string) $result->error, 'nincs nyers provider-hiba a válaszban');
    }

    public function testThreeHundredCallsViaRealInventoryAgentConsumeAtMostTheConfiguredBudget(): void
    {
        $db = tests_new_database();
        $settings = ['ai_max_tool_calls' => 20];
        $context = AiRunContext::fromSettings($settings);
        $provider = new FakeAiProvider([new AiChatResponse(null, $this->calls(300, 'get_low_stock_products'))]);

        $result = (new InventoryAgent($provider, $db, $settings, 5, $context))->answer('Mi fogy ki?');

        $this->assertFalse($result->success);
        $this->assertSame('tool_call_limit', $result->limitReached);
        $this->assertSame(20, $context->toolCallsUsed());
    }

    public function testBudgetIsAggregatedAcrossMultipleTurns(): void
    {
        [$registry, $counter] = $this->countingRegistry();
        $provider = new FakeAiProvider([
            new AiChatResponse(null, $this->calls(8, 'counted_tool', 'a')),
            new AiChatResponse(null, $this->calls(8, 'counted_tool', 'b')),
            new AiChatResponse(null, $this->calls(8, 'counted_tool', 'c')),
            new AiChatResponse('nem érünk ide', []),
        ]);

        $result = $this->runner($provider, $registry, 20)->run('sys', 'kérdés');

        $this->assertFalse($result->success);
        $this->assertSame('tool_call_limit', $result->limitReached);
        $this->assertSame(20, $counter->count, '8 + 8 + 4 — a harmadik forduló 5. hívása már nem fut');
        $this->assertSame(3, $provider->callCount());
    }

    public function testFinalAnswerIsStillPossibleWhenBudgetIsExactlySpent(): void
    {
        [$registry, $counter] = $this->countingRegistry();
        $provider = new FakeAiProvider([
            new AiChatResponse(null, $this->calls(10, 'counted_tool', 'a')),
            new AiChatResponse(null, $this->calls(10, 'counted_tool', 'b')),
            new AiChatResponse('Összefoglaló.', []),
        ]);

        $result = $this->runner($provider, $registry, 20)->run('sys', 'kérdés');

        $this->assertTrue($result->success);
        $this->assertSame(20, $counter->count);
    }

    public function testStreamingEnforcesTheSameBudgetWithinOneResponse(): void
    {
        [$registry, $counter] = $this->countingRegistry();
        $provider = new FakeAiProvider([new AiChatResponse(null, $this->calls(300))]);
        $events = [];

        $result = $this->runner($provider, $registry, 20)->runStreaming('sys', 'kérdés', function (AiStreamEvent $e) use (&$events) {
            $events[] = $e;
        }, 'inventory');

        $this->assertFalse($result->success);
        $this->assertSame('tool_call_limit', $result->limitReached);
        $this->assertSame(20, $counter->count);
        $started = array_filter($events, fn (AiStreamEvent $e) => $e->type === 'tool_call_started');
        $this->assertCount(20, $started, 'a 21. tool-hívás el sem indul');
        $this->assertSame('error', end($events)->type);
    }

    public function testStreamingBudgetIsAggregatedAcrossTurns(): void
    {
        [$registry, $counter] = $this->countingRegistry();
        $provider = new FakeAiProvider([
            new AiChatResponse(null, $this->calls(15, 'counted_tool', 'a')),
            new AiChatResponse(null, $this->calls(15, 'counted_tool', 'b')),
        ]);

        $result = $this->runner($provider, $registry, 20)->runStreaming('sys', 'kérdés', fn (AiStreamEvent $e) => null, 'sales');

        $this->assertSame('tool_call_limit', $result->limitReached);
        $this->assertSame(20, $counter->count);
    }

    /**
     * A provider-szintű újrapróbálás (AiRetryPolicy — a valódi providerek
     * a HTTP-hívást csomagolják vele) a futás keretét nem nullázza: a
     * keret a futáshoz tartozik, nem a provider-híváshoz.
     */
    public function testProviderRetryDoesNotResetTheBudget(): void
    {
        [$registry, $counter] = $this->countingRegistry();
        $provider = new class ($this->calls(15, 'counted_tool', 'a'), $this->calls(15, 'counted_tool', 'b')) implements AiProviderInterface {
            public int $attempts = 0;
            private int $turn = 0;
            public function __construct(private array $first, private array $second) {}
            public function name(): string { return 'fake'; }
            public function checkAvailability(): AiAvailability { return AiAvailability::available(); }
            public function chat(array $messages, array $tools): AiChatResponse
            {
                $turn = $this->turn++;
                $failedOnce = false;
                return AiRetryPolicy::run(function () use ($turn, &$failedOnce) {
                    $this->attempts++;
                    if ($turn === 1 && !$failedOnce) {
                        $failedOnce = true;
                        throw new AiProviderException('átmeneti hiba', 'unavailable');
                    }
                    return new AiChatResponse(null, $turn === 0 ? $this->first : $this->second);
                });
            }
        };

        $result = $this->runner($provider, $registry, 20)->run('sys', 'kérdés');

        $this->assertSame(3, $provider->attempts, 'a második forduló egyszer újrapróbált');
        $this->assertSame('tool_call_limit', $result->limitReached);
        $this->assertSame(20, $counter->count, '15 + 5: az újrapróbálás után sem indul új keret');
    }

    public function testNewRunGetsAFreshBudget(): void
    {
        [$registry, $counter] = $this->countingRegistry();
        $provider = new FakeAiProvider([
            new AiChatResponse(null, $this->calls(20, 'counted_tool', 'a')),
            new AiChatResponse('első', []),
            new AiChatResponse(null, $this->calls(20, 'counted_tool', 'b')),
            new AiChatResponse('második', []),
        ]);
        $runner = $this->runner($provider, $registry, 20);

        $first = $runner->run('sys', 'kérdés 1');
        $second = $runner->run('sys', 'kérdés 2');

        $this->assertTrue($first->success);
        $this->assertTrue($second->success);
        $this->assertSame(40, $counter->count);
    }

    public function testExplicitRunContextIsSharedAcrossRunnersOfTheSameRun(): void
    {
        [$registry, $counter] = $this->countingRegistry();
        $context = new AiRunContext(20);
        $provider = new FakeAiProvider([
            new AiChatResponse(null, $this->calls(12, 'counted_tool', 'a')),
            new AiChatResponse('egy', []),
            new AiChatResponse(null, $this->calls(12, 'counted_tool', 'b')),
        ]);

        $this->assertTrue($this->runner($provider, $registry, 20, 10, $context)->run('sys', 'q')->success);
        $second = $this->runner($provider, $registry, 20, 10, $context)->run('sys', 'q');

        $this->assertSame('tool_call_limit', $second->limitReached);
        $this->assertSame(20, $counter->count);
        $this->assertSame(20, $context->toolCallsUsed());
    }

    /**
     * A Copilot delegáló toolja és az al-ügynökök tool-hívásai UGYANABBÓL
     * a keretből fogynak — egy delegálás nem nyit új, 20-as keretet.
     */
    public function testCopilotSubAgentsShareTheRunBudget(): void
    {
        $db = tests_new_database();
        $settings = ['ai_max_tool_calls' => 5];
        $context = AiRunContext::fromSettings($settings);
        $provider = new FakeAiProvider([
            // Copilot: 1 delegálás (1. egység)
            new AiChatResponse(null, [new ToolCall('d1', 'ask_inventory_agent', ['question' => 'Mi fogy ki?'])]),
            // Inventory al-ügynök: 50 hívás egy válaszban → csak 4 fér a keretbe
            new AiChatResponse(null, $this->calls(50, 'get_low_stock_products')),
            // Copilot: az al-ügynök hibája után újabb delegálás — már nincs keret
            new AiChatResponse(null, [new ToolCall('d2', 'ask_sales_agent', ['question' => 'Forgalom?'])]),
        ]);

        $result = (new AiCopilot($provider, $db, $settings, 5, $context))->answer('Mi fogy ki és mennyi a forgalom?');

        $this->assertFalse($result->success);
        $this->assertSame('tool_call_limit', $result->limitReached);
        $this->assertSame(5, $context->toolCallsUsed());
        $this->assertSame(['inventory'], $result->agentsUsed, 'a sales ügynök el sem indult');
        $this->assertFalse($result->agentResults['inventory']['success']);
        $this->assertSame(3, $provider->callCount());
    }

    public function testCopilotWithoutExplicitContextStillSharesOneBudgetFromSettings(): void
    {
        $db = tests_new_database();
        $provider = new FakeAiProvider([
            new AiChatResponse(null, [new ToolCall('d1', 'ask_inventory_agent', ['question' => 'Mi fogy ki?'])]),
            new AiChatResponse(null, $this->calls(300, 'get_low_stock_products')),
            new AiChatResponse('A kért adatok egy része nem áll rendelkezésre.', []),
        ]);

        $result = (new AiCopilot($provider, $db, ['ai_max_tool_calls' => 3], 5))->answer('Mi fogy ki?');

        // 1 delegálás + 2 al-ügynök-hívás; a Copilot a végén még válaszolhat (nincs újabb tool).
        $this->assertTrue($result->success);
        $this->assertFalse($result->agentResults['inventory']['success']);
        $this->assertStringContainsString('eszköz-hívási korlát', (string) $result->agentResults['inventory']['error']);
    }

    public function testCopilotStreamingSharesTheBudgetWithSubAgents(): void
    {
        $db = tests_new_database();
        $settings = ['ai_max_tool_calls' => 4];
        $context = AiRunContext::fromSettings($settings);
        $provider = new FakeAiProvider([
            new AiChatResponse(null, [new ToolCall('d1', 'ask_inventory_agent', ['question' => 'Mi fogy ki?'])]),
            new AiChatResponse(null, $this->calls(100, 'get_low_stock_products')),
            new AiChatResponse(null, [new ToolCall('d2', 'ask_anomaly_agent', ['question' => 'Anomália?'])]),
        ]);

        $result = (new AiCopilot($provider, $db, $settings, 5, $context))->answerStreaming('Kérdés', fn (AiStreamEvent $e) => null);

        $this->assertFalse($result->success);
        $this->assertSame('tool_call_limit', $result->limitReached);
        $this->assertSame(4, $context->toolCallsUsed());
    }
}
