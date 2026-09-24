<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Fázis 9 — AiCopilot::answerStreaming() tesztjei. A FakeAiProvider
 * (lásd tests/AiAgentRunnerTest.php) NEM vállalja az
 * AiStreamingProviderInterface-t, tehát ezek a tesztek EGYBEN a kör 9.
 * pontja szerinti automatikus visszaesést (fallback a szinkron chat()-re)
 * is bizonyítják — pontosan úgy, ahogy egy VALÓS, streamelést nem
 * támogató jövőbeli provider is viselkedne.
 */
final class AiCopilotStreamingTest extends TestCase
{
    public function testStreamingEmitsAgentAndToolLifecycleEventsAndFinalAnswer(): void
    {
        $db = tests_new_database();
        $provider = new FakeAiProvider([
            new AiChatResponse(null, [new ToolCall('c1', 'ask_inventory_agent', ['question' => 'Mi fogyott ki?'])]),
            new AiChatResponse('Semmi nem fogyott ki jelenleg.', []),
            new AiChatResponse('A készlet alapján jelenleg semmi nem fogyott ki.', []),
        ]);
        $copilot = new AiCopilot($provider, $db, [], 5);

        $events = [];
        $result = $copilot->answerStreaming('Mi fogyott ki?', function (AiStreamEvent $e) use (&$events) {
            $events[] = $e;
        });

        $this->assertTrue($result->success);
        $this->assertSame('A készlet alapján jelenleg semmi nem fogyott ki.', $result->answer);

        $types = array_map(static fn ($e) => $e->type, $events);
        $this->assertContains(AiStreamEvent::TYPE_AGENT_STARTED, $types);
        $this->assertContains(AiStreamEvent::TYPE_TOOL_CALL_STARTED, $types);
        $this->assertContains(AiStreamEvent::TYPE_TOOL_CALL_COMPLETED, $types);
        $this->assertContains(AiStreamEvent::TYPE_FINAL, $types);
        $this->assertContains(AiStreamEvent::TYPE_AGENT_COMPLETED, $types);

        $toolStarted = array_values(array_filter($events, static fn ($e) => $e->type === AiStreamEvent::TYPE_TOOL_CALL_STARTED))[0];
        $this->assertSame('ask_inventory_agent', $toolStarted->payload['name']);
        $this->assertNotEmpty($toolStarted->payload['label'], 'A tool_call_started eseménynek EMBER-olvasható feliratot kell hordoznia.');

        $final = array_values(array_filter($events, static fn ($e) => $e->type === AiStreamEvent::TYPE_FINAL))[0];
        $this->assertSame('A készlet alapján jelenleg semmi nem fogyott ki.', $final->payload['answer']);
    }

    public function testStreamingNeverExposesRawProviderPayloadOrToolArguments(): void
    {
        $db = tests_new_database();
        $provider = new FakeAiProvider([
            new AiChatResponse(null, [new ToolCall('c1', 'ask_inventory_agent', ['question' => 'titkos_argumentum_string_12345'])]),
            new AiChatResponse('Válasz.', []),
            new AiChatResponse('Végleges válasz.', []),
        ]);
        $copilot = new AiCopilot($provider, $db, [], 5);

        $events = [];
        $copilot->answerStreaming('Kérdés', function (AiStreamEvent $e) use (&$events) {
            $events[] = $e;
        });

        foreach ($events as $e) {
            $encoded = json_encode($e->toArray(), JSON_UNESCAPED_UNICODE);
            $this->assertStringNotContainsString('titkos_argumentum_string_12345', (string) $encoded, 'A tool_call_started esemény SOSE tartalmazhatja a nyers argumentumokat.');
        }
    }

    public function testErrorEventEmittedWhenProviderFails(): void
    {
        $db = tests_new_database();
        $provider = new FakeAiProvider([
            new AiProviderException('kapcsolódási hiba', 'unavailable'),
        ]);
        $copilot = new AiCopilot($provider, $db, [], 5);

        $events = [];
        $result = $copilot->answerStreaming('Kérdés', function (AiStreamEvent $e) use (&$events) {
            $events[] = $e;
        });

        $this->assertFalse($result->success);
        $types = array_map(static fn ($e) => $e->type, $events);
        $this->assertContains(AiStreamEvent::TYPE_ERROR, $types);
    }

    public function testStreamingRespectsMaxAgentCallLimit(): void
    {
        // UGYANAZ a MAX_AGENT_CALLS=3 védelem, mint answer()-nél — a
        // streamelt út SOSE lazítja a MEGLÉVŐ korlátot.
        $db = tests_new_database();
        $provider = new FakeAiProvider([
            new AiChatResponse(null, [new ToolCall('c1', 'ask_inventory_agent', ['question' => 'q1'])]),
            new AiChatResponse('a1', []),
            new AiChatResponse(null, [new ToolCall('c2', 'ask_sales_agent', ['question' => 'q2'])]),
            new AiChatResponse('a2', []),
            new AiChatResponse(null, [new ToolCall('c3', 'ask_anomaly_agent', ['question' => 'q3'])]),
            new AiChatResponse('a3', []),
            new AiChatResponse(null, [new ToolCall('c4', 'ask_inventory_agent', ['question' => 'q4'])]),
            new AiChatResponse('Végső összefoglaló a 3 ügynök után.', []),
        ]);
        $copilot = new AiCopilot($provider, $db, [], 8);

        $result = $copilot->answerStreaming('Kérdés', function (AiStreamEvent $e) {});

        $this->assertTrue($result->success);
        $this->assertCount(3, $result->agentsUsed);
    }
}
