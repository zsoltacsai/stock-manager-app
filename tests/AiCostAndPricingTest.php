<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Fázis 9 — AiUsage/AiPricing/AiCostLimits determinisztikus tesztjei
 * (a kör 28. pontja). SOSE feltételez konkrét dollár-árat, amit az
 * AiPricing::TABLE (lásd ott a docblokkja) ténylegesen, hivatalos
 * forrással dokumentáltan NEM tartalmaz — a tesztek KIZÁRÓLAG a
 * KÖZPONTOSÍTOTT táblából ténylegesen olvasott eredményt ellenőrzik.
 *
 * Fázis 10 — a `claude-sonnet-5`/`gpt-6-sol` bejegyzések hivatalos
 * providerdokumentáció-kutatással ellenőrizve, valós árral bekerültek a
 * táblába (lásd AiPricing.php docblokkja a forrásokért) — az "ismeretlen
 * modell" teszteket ezért egy VALÓBAN nem-táblázott, kitalált jövőbeli
 * modellnévre kell futtatni, nem erre a kettőre.
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
        // Egy VALÓBAN nem-táblázott, kitalált jövőbeli modellnév — ez
        // KIZÁRÓLAG 'unavailable'-t (null) adhat, SOSE 0-t vagy kitalált
        // összeget.
        $result = AiPricing::estimate('anthropic', 'claude-sonnet-99-ismeretlen', $usage);
        $this->assertNull($result, 'Ellenőrizetlen árazású modellre a becslés MINDIG unavailable (null), SOSE kitalált szám.');
    }

    public function testKnownAnthropicModelReturnsOfficiallySourcedCost(): void
    {
        // Fázis 10 — claude.com/pricing hivatalos árlistája alapján
        // (lásd AiPricing.php docblokkja): $2/M bemenet, $10/M kimenet.
        $usage = new AiUsage(1_000_000, 1_000_000, 2_000_000);
        $result = AiPricing::estimate('anthropic', 'claude-sonnet-5', $usage);
        $this->assertNotNull($result);
        $this->assertSame(12.0, $result['cost'], '1M bemenet ($2) + 1M kimenet ($10) = $12.');
        $this->assertStringContainsString('claude.com/pricing', $result['source']);
    }

    public function testKnownAnthropicModelAppliesCachedInputDiscount(): void
    {
        $usage = new AiUsage(1_000_000, 0, 1_000_000, null, 400_000);
        $result = AiPricing::estimate('anthropic', 'claude-sonnet-5', $usage);
        $this->assertNotNull($result);
        // 600k normál bemenet ($2/M) + 400k cache-olvasás ($0.20/M).
        $this->assertEqualsWithDelta(1.28, $result['cost'], 0.000001);
    }

    public function testKnownOpenAiModelReturnsOfficiallySourcedCost(): void
    {
        // Fázis 10 — developers.openai.com/api/docs/pricing hivatalos
        // árlistája alapján: $2/M bemenet, $10/M kimenet.
        $usage = new AiUsage(1_000_000, 1_000_000, 2_000_000);
        $result = AiPricing::estimate('openai', 'gpt-6-sol', $usage);
        $this->assertNotNull($result);
        $this->assertSame(12.0, $result['cost']);
        $this->assertStringContainsString('developers.openai.com', $result['source']);
    }

    public function testDifferentConfiguredAnthropicModelStillUnavailable(): void
    {
        // Ha az admin egy MÁSIK (a táblában nem szereplő) Anthropic
        // modellre vált, a becslés ISMÉT unavailable — a Fázis 10
        // ellenőrzés KIZÁRÓLAG a pontosan konfigurált két modellnévre
        // szól, nem az egész Anthropic/OpenAI providerre.
        $usage = new AiUsage(1000, 500, 1500);
        $this->assertNull(AiPricing::estimate('anthropic', 'claude-opus-5-5', $usage));
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
        $this->assertTrue(AiPricing::isKnownModel('anthropic', 'claude-sonnet-5'));
        $this->assertTrue(AiPricing::isKnownModel('openai', 'gpt-6-sol'));
        $this->assertFalse(AiPricing::isKnownModel('anthropic', 'claude-opus-5-5'));
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
