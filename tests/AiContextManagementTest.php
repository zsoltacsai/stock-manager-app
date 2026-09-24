<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Fázis 9 — ConversationManager determinisztikus kontextus-korlátozás/
 * tömörítés tesztjei (a kör 27. pontja).
 */
final class AiContextManagementTest extends TestCase
{
    public function testNormalConversationIsNotCompacted(): void
    {
        $cm = new ConversationManager('system', 'user kérdés');
        $cm->addAssistantMessage('válasz', []);
        $this->assertFalse($cm->wasCompacted());
        $this->assertCount(3, $cm->toArray());
    }

    public function testMaxMessageCountTriggersCompaction(): void
    {
        $limits = new AiContextLimits(maxMessages: 6, maxUserInputChars: 4000, maxToolResultChars: 4000, maxTotalContextChars: 1_000_000);
        $cm = new ConversationManager('system', 'user kérdés', $limits);

        for ($i = 0; $i < 5; $i++) {
            $cm->addAssistantMessage("kör $i", [new ToolCall("c$i", 'get_product', ['id' => $i])]);
            $cm->addToolResult(ToolResult::ok("c$i", 'get_product', ['id' => $i, 'name' => "termék $i"]));
        }

        $this->assertTrue($cm->wasCompacted());
        $this->assertLessThanOrEqual(6, count($cm->toArray()));
    }

    public function testCurrentUserQuestionIsAlwaysPreserved(): void
    {
        $limits = new AiContextLimits(maxMessages: 6, maxUserInputChars: 4000, maxToolResultChars: 4000, maxTotalContextChars: 1_000_000);
        $cm = new ConversationManager('system', 'EREDETI KÉRDÉS', $limits);
        for ($i = 0; $i < 6; $i++) {
            $cm->addAssistantMessage("kör $i", [new ToolCall("c$i", 'get_product', ['id' => $i])]);
            $cm->addToolResult(ToolResult::ok("c$i", 'get_product', ['id' => $i]));
        }

        $messages = $cm->toArray();
        $this->assertSame('system', $messages[0]['role']);
        $this->assertSame('user', $messages[1]['role']);
        $this->assertSame('EREDETI KÉRDÉS', $messages[1]['content']);
    }

    public function testMostRecentTurnEvidenceIsNeverDiscarded(): void
    {
        $limits = new AiContextLimits(maxMessages: 4, maxUserInputChars: 4000, maxToolResultChars: 4000, maxTotalContextChars: 1_000_000);
        $cm = new ConversationManager('system', 'kérdés', $limits);
        for ($i = 0; $i < 5; $i++) {
            $cm->addAssistantMessage("kör $i", [new ToolCall("c$i", 'get_product', ['id' => $i])]);
            $cm->addToolResult(ToolResult::ok("c$i", 'get_product', ['id' => $i, 'marker' => "UTOLSO_$i"]));
        }

        $messages = $cm->toArray();
        $last = end($messages);
        $this->assertStringContainsString('UTOLSO_4', $last['content'], 'A LEGUTÓBBI kör eszköz-eredményének mindig meg kell maradnia.');
    }

    public function testMaxTotalContextCharsTriggersCompaction(): void
    {
        $limits = new AiContextLimits(maxMessages: 1000, maxUserInputChars: 4000, maxToolResultChars: 10000, maxTotalContextChars: 500);
        $cm = new ConversationManager('system', 'kérdés', $limits);
        for ($i = 0; $i < 5; $i++) {
            $cm->addAssistantMessage(str_repeat('x', 100), [new ToolCall("c$i", 'get_product', ['id' => $i])]);
            $cm->addToolResult(ToolResult::ok("c$i", 'get_product', ['payload' => str_repeat('y', 100)]));
        }
        $this->assertTrue($cm->wasCompacted());
    }

    public function testNoInfiniteCompactionLoopWithSingleOversizedTurn(): void
    {
        // Egyetlen, hatalmas (a teljes korlátot önmagában túllépő) kör —
        // a kör 27. pontja: "no infinite compaction loop" — a metódus
        // SOSE akadhat végtelen ciklusba, még akkor sem, ha a korlát
        // alá kerülés STRUKTURÁLISAN lehetetlen (nincs mit tovább dobni,
        // csak 1 kör van).
        $limits = new AiContextLimits(maxMessages: 100, maxUserInputChars: 4000, maxToolResultChars: 100000, maxTotalContextChars: 10);
        $cm = new ConversationManager('system', 'kérdés', $limits);

        $start = microtime(true);
        $cm->addAssistantMessage(str_repeat('x', 5000), [new ToolCall('c1', 'get_product', ['id' => 1])]);
        $cm->addToolResult(ToolResult::ok('c1', 'get_product', ['payload' => str_repeat('y', 5000)]));
        $elapsed = microtime(true) - $start;

        $this->assertLessThan(2.0, $elapsed, 'A tömörítésnek SOSE szabad végtelen ciklusba akadnia.');
        // Az utolsó (egyetlen) kör MEGMARAD, akkor is, ha ettől még a korlát felett marad.
        $this->assertGreaterThanOrEqual(3, count($cm->toArray()));
    }

    public function testOversizedToolResultIsBoundedButStaysValidJson(): void
    {
        $limits = new AiContextLimits(maxToolResultChars: 300);
        $cm = new ConversationManager('system', 'kérdés', $limits);

        $bigList = [];
        for ($i = 0; $i < 200; $i++) {
            $bigList[] = ['id' => $i, 'name' => 'Termék ' . $i, 'description' => str_repeat('szöveg ', 10)];
        }
        $cm->addToolResult(ToolResult::ok('c1', 'get_low_stock_products', ['products' => $bigList]));

        $messages = $cm->toArray();
        $toolMessage = end($messages);
        $decoded = json_decode($toolMessage['content'], true);
        $this->assertIsArray($decoded, 'A bounded tool-eredménynek MINDIG érvényes JSON-nak kell maradnia.');
        $this->assertTrue($decoded['success']);
        $this->assertLessThan(200, count($decoded['data']['products'] ?? $decoded['data'] ?? []));
    }

    public function testOversizedSingleFieldFallsBackToTruncatedEnvelope(): void
    {
        $limits = new AiContextLimits(maxToolResultChars: 200);
        $cm = new ConversationManager('system', 'kérdés', $limits);

        $cm->addToolResult(ToolResult::ok('c1', 'get_product', ['huge_field' => str_repeat('z', 5000)]));

        $messages = $cm->toArray();
        $toolMessage = end($messages);
        $decoded = json_decode($toolMessage['content'], true);
        $this->assertIsArray($decoded);
        $this->assertTrue($decoded['truncated'] ?? false);
        $this->assertArrayHasKey('original_size_chars', $decoded);
    }

    public function testFailedToolResultIsNeverTruncatedIntoInvalidJson(): void
    {
        $limits = new AiContextLimits(maxToolResultChars: 50);
        $cm = new ConversationManager('system', 'kérdés', $limits);
        $cm->addToolResult(ToolResult::fail('c1', 'get_product', str_repeat('nagyon hosszú hibaüzenet ', 20)));

        $messages = $cm->toArray();
        $toolMessage = end($messages);
        $decoded = json_decode($toolMessage['content'], true);
        $this->assertIsArray($decoded);
        $this->assertFalse($decoded['success']);
    }

    public function testUserInputIsBoundedDefensively(): void
    {
        $limits = new AiContextLimits(maxUserInputChars: 100);
        $cm = new ConversationManager('system', str_repeat('a', 500), $limits);
        $messages = $cm->toArray();
        $this->assertLessThanOrEqual(101, mb_strlen($messages[1]['content']));
    }
}
