<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Fázis 10 — a kör 16/17/23. pontja: az `AiAuditLogger` első dedikált,
 * közvetlen tesztje (korábban csak közvetve, a HTTP-végpont-teszteken
 * keresztül volt lefedve). Konkrét, ebben a fázisban felfedezett rés
 * indokolja: az `extendedFields()` (streamed/tokenek/becsült
 * költség/limit_reached/context_compacted/failure_category) helyes
 * levezetését eddig SEMMI nem ellenőrizte közvetlenül.
 */
final class AiAuditLoggerTest extends TestCase
{
    private Database $db;

    protected function setUp(): void
    {
        $this->db = tests_new_database();
    }

    public function testLogRunPersistsExtendedFieldsForSuccessfulKnownPricedRun(): void
    {
        $usage = new AiUsage(1_000_000, 1_000_000, 2_000_000);
        $result = AgentRunResult::ok('Válasz.', ['get_low_stock_products'], 2, $usage, true, false);

        AiAuditLogger::logRun($this->db, [], null, 'inventory', 'anthropic', 'claude-sonnet-5', 'kérdés', $result, 1234.5);

        $entries = $this->db->getAiRunHistory(['agent' => 'inventory']);
        $this->assertCount(1, $entries);
        $entry = $entries[0];

        $this->assertTrue($entry['streamed']);
        $this->assertSame(1_000_000, $entry['input_tokens']);
        $this->assertSame(1_000_000, $entry['output_tokens']);
        $this->assertSame(2_000_000, $entry['total_tokens']);
        // claude-sonnet-5: $2/M be + $10/M ki = $12 (lásd AiPricing.php).
        // assertEquals (nem assertSame): a technical_detail JSON-kerülőúton
        // egy egész-értékű float (pl. 12.0) int-té válhat dekódoláskor —
        // ez a JSON kódolás/dekódolás ismert, a projekt egész AI-
        // naplózásában jelen lévő sajátossága, nem ennek a tesztnek/
        // mezőnek a hibája.
        $this->assertEquals(12.0, $entry['estimated_cost']);
        $this->assertNull($entry['limit_reached']);
        $this->assertFalse($entry['context_compacted']);
        $this->assertNull($entry['failure_category']);
        $this->assertSame('success', $entry['status']);
    }

    public function testLogRunPersistsNullCostForUnpricedModel(): void
    {
        $usage = new AiUsage(1000, 500, 1500);
        $result = AgentRunResult::ok('Válasz.', [], 1, $usage, false, false);

        AiAuditLogger::logRun($this->db, [], null, 'sales', 'anthropic', 'claude-opus-5-5-ismeretlen', 'kérdés', $result, 500.0);

        $entries = $this->db->getAiRunHistory(['agent' => 'sales']);
        $this->assertNull($entries[0]['estimated_cost'], 'Ismeretlen árazású modellnél a becslés SOSE 0 vagy kitalált szám, mindig null.');
    }

    public function testLogRunPersistsFailureCategoryFromProviderException(): void
    {
        $result = AgentRunResult::fail('Az AI-modell jelenleg nem érhető el.', [], 1, null, false, null, false, 'rate_limit');

        AiAuditLogger::logRun($this->db, [], null, 'anomaly', 'openai', 'gpt-6-sol', 'kérdés', $result, 100.0);

        $entries = $this->db->getAiRunHistory(['agent' => 'anomaly']);
        $this->assertSame('rate_limit', $entries[0]['failure_category']);
        $this->assertSame('failure', $entries[0]['status']);
    }

    public function testLogRunLimitReachedHasNoFailureCategory(): void
    {
        // Egy elért eszköz-hívási/lépésszám-korlát NEM egy provider-
        // kivétel — a `limit_reached` mező már önmagában azonosítja,
        // a `failure_category`-nak itt SZÁNDÉKOSAN null-nak kell lennie.
        $result = AgentRunResult::fail('Elérted a korlátot.', [], 5, null, false, 'tool_call_limit', false);

        AiAuditLogger::logRun($this->db, [], null, 'inventory', 'local', 'qwen3:8b', 'kérdés', $result, 100.0);

        $entries = $this->db->getAiRunHistory(['agent' => 'inventory']);
        $this->assertSame('tool_call_limit', $entries[0]['limit_reached']);
        $this->assertNull($entries[0]['failure_category']);
    }

    public function testLogCopilotRunPersistsExtendedFields(): void
    {
        $usage = new AiUsage(2000, 1000, 3000);
        $runResult = AgentRunResult::ok('Szintézis-válasz.', ['ask_inventory_agent'], 2, $usage, true, false);
        $copilotResult = CopilotRunResult::fromRun($runResult, ['inventory'], [
            'inventory' => ['success' => true, 'tools_used' => ['get_low_stock_products'], 'error' => null],
        ]);

        AiAuditLogger::logCopilotRun($this->db, [], null, 'local', 'qwen3:8b', 'kérdés', $copilotResult, 2000.0);

        $entries = $this->db->getAiRunHistory(['agent' => 'copilot']);
        $this->assertCount(1, $entries);
        $this->assertTrue($entries[0]['streamed']);
        $this->assertSame(3000, $entries[0]['total_tokens']);
        // local (Ollama) — mindig ismert, determinisztikusan $0.
        $this->assertEquals(0.0, $entries[0]['estimated_cost']);
        $this->assertSame(['inventory'], $entries[0]['agents_used']);
    }
}
