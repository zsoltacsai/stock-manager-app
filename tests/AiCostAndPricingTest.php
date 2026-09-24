<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Fázis 9 — AiUsage/AiPricing/AiCostLimits determinisztikus tesztjei
 * (a kör 28. pontja). SOSE feltételez konkrét, ellenőrizetlen dollár-
 * árat Anthropic/OpenAI-ra (lásd AiPricing.php docblokkja, miért NINCS
 * ott ilyen bejegyzés jelenleg) — a tesztek KIZÁRÓLAG a KÖZPONTOSÍTOTT
 * AiPricing::TABLE-ből olvasott, tényleges eredményt ellenőrzik.
 */
final class AiCostAndPricingTest extends TestCase
{
    public function testUsageParsingFromKnownFields(): void
    {
        $usage = new AiUsage(100, 50, 150);
        $this->assertSame(100, $usage->inputTokens);
        $this->assertSame(50, $usage->outputTokens);
        $this->assertSame(150, $usage->totalTokens);
    }

    public function testMissingUsageStaysNullNeverInvented(): void
    {
        $usage = new AiUsage();
        $this->assertNull($usage->inputTokens);
        $this->assertNull($usage->outputTokens);
        $this->assertNull(AiUsage::merge(null, null));
    }

    public function testUsageMergeSumsAcrossMultipleCalls(): void
    {
        $a = new AiUsage(10, 20, 30);
        $b = new AiUsage(5, 15, 20);
        $merged = AiUsage::merge($a, $b);
        $this->assertSame(15, $merged->inputTokens);
        $this->assertSame(35, $merged->outputTokens);
        $this->assertSame(50, $merged->totalTokens);
    }

    public function testUsageMergeWithOneNullSideReturnsOther(): void
    {
        $a = new AiUsage(10, 20, 30);
        $this->assertSame($a, AiUsage::merge($a, null));
        $this->assertSame($a, AiUsage::merge(null, $a));
    }

    public function testLocalProviderCostIsDeterministicallyZero(): void
    {
        $usage = new AiUsage(1000, 500, 1500);
        $result = AiPricing::estimate('local', 'qwen3:8b', $usage);
        $this->assertNotNull($result, 'A helyi (Ollama) provider árazása MINDIG ismert — 0, alkalmazás-szabály alapján.');
        $this->assertSame(0.0, $result['cost']);
    }

    public function testUnknownProviderModelPairReturnsUnavailableNotZero(): void
    {
        $usage = new AiUsage(1000, 500, 1500);
        // A jelen implementáció idején ELLENŐRIZETLEN Anthropic/OpenAI
        // árazás miatt (lásd AiPricing.php docblokkja) ez KIZÁRÓLAG
        // 'unavailable'-t (null) adhat — SOSE 0-t vagy kitalált összeget.
        $result = AiPricing::estimate('anthropic', 'claude-sonnet-5', $usage);
        $this->assertNull($result, 'Ellenőrizetlen árazású modellre a becslés MINDIG unavailable (null), SOSE kitalált szám.');
    }

    public function testCompletelyUnknownProviderReturnsUnavailable(): void
    {
        $usage = new AiUsage(100, 100, 200);
        $this->assertNull(AiPricing::estimate('nem_letezo_provider', 'barmilyen-modell', $usage));
    }

    public function testMissingUsageFieldsMeansNoEstimate(): void
    {
        $usage = new AiUsage();
        $this->assertNull(AiPricing::estimate('local', 'qwen3:8b', $usage));
    }

    public function testIsKnownModelReflectsPricingTable(): void
    {
        $this->assertTrue(AiPricing::isKnownModel('local', 'qwen3:8b'));
        $this->assertFalse(AiPricing::isKnownModel('anthropic', 'claude-sonnet-5'));
    }

    public function testCostLimitsFromSettingsAppliesDefaults(): void
    {
        $limits = AiCostLimits::fromSettings([]);
        $this->assertSame(20, $limits->maxToolCalls);
        $this->assertNull($limits->maxEstimatedCostPerRequest);
    }

    public function testCostLimitsFromSettingsHonorsConfiguredValues(): void
    {
        $limits = AiCostLimits::fromSettings(['ai_max_tool_calls' => 5, 'ai_max_estimated_cost_per_request' => 0.50]);
        $this->assertSame(5, $limits->maxToolCalls);
        $this->assertSame(0.50, $limits->maxEstimatedCostPerRequest);
    }

    public function testCostLimitZeroOrEmptyMeansUnlimited(): void
    {
        $limits = AiCostLimits::fromSettings(['ai_max_estimated_cost_per_request' => 0]);
        $this->assertNull($limits->maxEstimatedCostPerRequest);
        $limits2 = AiCostLimits::fromSettings(['ai_max_estimated_cost_per_request' => '']);
        $this->assertNull($limits2->maxEstimatedCostPerRequest);
    }

    public function testToolCallLimitStopsAgentRunSafely(): void
    {
        $provider = new FakeAiProvider([
            new AiChatResponse(null, [new ToolCall('c1', 'get_product', ['id' => 1])]),
            new AiChatResponse(null, [new ToolCall('c2', 'get_product', ['id' => 2])]),
            new AiChatResponse(null, [new ToolCall('c3', 'get_product', ['id' => 3])]),
        ]);
        $registry = new ToolRegistry();
        $registry->register(new ToolDefinition('get_product', 'teszt', ['type' => 'object', 'properties' => []], function (array $args) {
            return ['id' => $args['id'] ?? 0];
        }));
        $runner = new AgentRunner($provider, $registry, 5, null, new AiCostLimits(maxToolCalls: 2));

        $result = $runner->run('system', 'kérdés');

        $this->assertFalse($result->success);
        $this->assertSame('tool_call_limit', $result->limitReached);
    }

    public function testDefaultToolCallLimitDoesNotInterfereWithNormalRuns(): void
    {
        $provider = new FakeAiProvider([
            new AiChatResponse(null, [new ToolCall('c1', 'get_product', ['id' => 1])]),
            new AiChatResponse('Végleges válasz.', []),
        ]);
        $registry = new ToolRegistry();
        $registry->register(new ToolDefinition('get_product', 'teszt', ['type' => 'object', 'properties' => []], function (array $args) {
            return ['id' => $args['id'] ?? 0];
        }));
        $runner = new AgentRunner($provider, $registry, 5);

        $result = $runner->run('system', 'kérdés');

        $this->assertTrue($result->success);
        $this->assertNull($result->limitReached);
    }
}
