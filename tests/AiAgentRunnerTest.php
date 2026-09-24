<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Determinisztikus, szkriptelt válaszokat adó hamis provider — az
 * AgentRunner tesztjei SOSE függnek egy valódi Ollama-telepítéstől (lásd
 * a kör 14. pontja: "Do not make tests depend on a real Ollama
 * installation"). Minden chat() hívás a $script következő elemét adja
 * vissza (egy AiChatResponse-t, vagy egy AiProviderException-t, ha a
 * hívó egy kivétel-hibát akar szimulálni).
 */
final class FakeAiProvider implements AiProviderInterface
{
    /** @var array<int,AiChatResponse|AiProviderException> */
    private array $script;
    private int $callIndex = 0;
    /** @var array<int,array> minden chat()-nek átadott üzenet-lista, hívási sorrendben */
    public array $receivedMessages = [];

    /**
     * @param array<int,AiChatResponse|AiProviderException> $script
     * @param string $providerName Fázis 10 — opcionális, alapból 'fake'
     *   (a MEGLÉVŐ hívási helyek változatlanok maradnak); egy VALÓS
     *   providernévre (pl. 'anthropic') állítva a scriptelt válaszokban
     *   szereplő AiUsage-ből az AiPricing::estimate() ténylegesen valódi,
     *   ellenőrzött dollár-becslést tud adni — ez kell a költség-korlát
     *   (nem csak a hívásszám-korlát) determinisztikus teszteléséhez.
     */
    public function __construct(array $script, private readonly string $providerName = 'fake')
    {
        $this->script = $script;
    }

    public function name(): string
    {
        return $this->providerName;
    }

    public function chat(array $messages, array $tools): AiChatResponse
    {
        $this->receivedMessages[] = $messages;
        if (!array_key_exists($this->callIndex, $this->script)) {
            throw new RuntimeException('FakeAiProvider: nincs több szkriptelt válasz (call #' . $this->callIndex . ').');
        }
        $next = $this->script[$this->callIndex];
        $this->callIndex++;
        if ($next instanceof AiProviderException) {
            throw $next;
        }
        return $next;
    }

    public function checkAvailability(): AiAvailability
    {
        return AiAvailability::available();
    }

    public function callCount(): int
    {
        return $this->callIndex;
    }
}

/**
 * Fázis 10 — a kör 7. pontja: a MEGLÉVŐ FakeAiProvider SOSE implementálja
 * az AiStreamingProviderInterface-t, ezért a `runStreaming()` "admin
 * kikapcsolta a streamelést" ágát (streamingEnabled=false, DE a provider
 * MAGA képes lenne streamelni) eddig SEMMI nem különböztette meg a
 * "provider egyáltalán nem streamelés-képes" ágtól — ez a fake bizonyítja
 * a kettő közötti VALÓDI, önálló elágazást (lásd AgentRunner::
 * runStreaming() `$this->streamingEnabled && $provider instanceof
 * AiStreamingProviderInterface` feltétele).
 */
final class FakeStreamingAiProvider implements AiProviderInterface, AiStreamingProviderInterface
{
    public int $chatStreamCallCount = 0;
    public int $chatCallCount = 0;

    public function __construct(private readonly AiChatResponse $response)
    {
    }

    public function name(): string
    {
        return 'fake_streaming';
    }

    public function chat(array $messages, array $tools): AiChatResponse
    {
        $this->chatCallCount++;
        return $this->response;
    }

    public function chatStream(array $messages, array $tools, callable $onEvent): AiChatResponse
    {
        $this->chatStreamCallCount++;
        if ($this->response->content !== null) {
            $onEvent(AiStreamEvent::textDelta($this->response->content));
        }
        return $this->response;
    }

    public function checkAvailability(): AiAvailability
    {
        return AiAvailability::available();
    }
}

final class AiAgentRunnerTest extends TestCase
{
    private function tool(string $name, callable $handler): ToolDefinition
    {
        return new ToolDefinition($name, "teszt: $name", ['type' => 'object', 'properties' => []], $handler);
    }

    public function testSimpleAnswerWithoutAnyToolCall(): void
    {
        $provider = new FakeAiProvider([
            new AiChatResponse('Ez egy egyszerű válasz.', []),
        ]);
        $registry = new ToolRegistry();
        $runner = new AgentRunner($provider, $registry, 5);

        $result = $runner->run('system prompt', 'kérdés');

        $this->assertTrue($result->success);
        $this->assertSame('Ez egy egyszerű válasz.', $result->answer);
        $this->assertSame([], $result->toolsUsed);
        $this->assertSame(1, $result->iterations);
        $this->assertSame(1, $provider->callCount());
    }

    public function testSingleToolCallThenFinalAnswer(): void
    {
        $provider = new FakeAiProvider([
            new AiChatResponse(null, [new ToolCall('c1', 'get_number', [])]),
            new AiChatResponse('A szám 42.', []),
        ]);
        $registry = new ToolRegistry();
        $registry->register($this->tool('get_number', fn (array $a) => ['number' => 42]));
        $runner = new AgentRunner($provider, $registry, 5);

        $result = $runner->run('sys', 'mi a szám?');

        $this->assertTrue($result->success);
        $this->assertSame('A szám 42.', $result->answer);
        $this->assertSame(['get_number'], $result->toolsUsed);
        $this->assertSame(2, $result->iterations);

        // A második chat()-hívásnak már látnia kell a tool eredményét.
        $secondCallMessages = $provider->receivedMessages[1];
        $toolMessages = array_values(array_filter($secondCallMessages, fn ($m) => $m['role'] === 'tool'));
        $this->assertCount(1, $toolMessages);
        $this->assertStringContainsString('42', $toolMessages[0]['content']);
    }

    public function testMultipleToolCallsInOneIteration(): void
    {
        $provider = new FakeAiProvider([
            new AiChatResponse(null, [
                new ToolCall('c1', 'tool_a', []),
                new ToolCall('c2', 'tool_b', []),
            ]),
            new AiChatResponse('Kész.', []),
        ]);
        $registry = new ToolRegistry();
        $registry->register($this->tool('tool_a', fn (array $a) => ['a' => 1]));
        $registry->register($this->tool('tool_b', fn (array $a) => ['b' => 2]));
        $runner = new AgentRunner($provider, $registry, 5);

        $result = $runner->run('sys', 'kérdés');

        $this->assertTrue($result->success);
        $this->assertSame(['tool_a', 'tool_b'], $result->toolsUsed);
    }

    public function testMultipleIterationsOfToolCallsBeforeFinalAnswer(): void
    {
        $provider = new FakeAiProvider([
            new AiChatResponse(null, [new ToolCall('c1', 'step_one', [])]),
            new AiChatResponse(null, [new ToolCall('c2', 'step_two', [])]),
            new AiChatResponse('Végleges válasz mindkét lépés után.', []),
        ]);
        $registry = new ToolRegistry();
        $registry->register($this->tool('step_one', fn (array $a) => ['ok' => true]));
        $registry->register($this->tool('step_two', fn (array $a) => ['ok' => true]));
        $runner = new AgentRunner($provider, $registry, 5);

        $result = $runner->run('sys', 'kérdés');

        $this->assertTrue($result->success);
        $this->assertSame(3, $result->iterations);
        $this->assertSame(['step_one', 'step_two'], $result->toolsUsed);
    }

    public function testIterationLimitIsEnforcedAndFailsGracefully(): void
    {
        // Egy "makacs" provider, ami SOSE ad végleges választ, mindig
        // újabb eszköz-hívást kér — az AgentRunner-nek meg kell állnia a
        // maxIterations-nál, nem szabad végtelen ciklusba futnia.
        $script = [];
        for ($i = 0; $i < 10; $i++) {
            $script[] = new AiChatResponse(null, [new ToolCall("c$i", 'loop_tool', [])]);
        }
        $provider = new FakeAiProvider($script);
        $registry = new ToolRegistry();
        $registry->register($this->tool('loop_tool', fn (array $a) => ['again' => true]));
        $runner = new AgentRunner($provider, $registry, 3);

        $result = $runner->run('sys', 'kérdés');

        $this->assertFalse($result->success);
        $this->assertSame(3, $result->iterations);
        $this->assertSame(3, $provider->callCount());
        $this->assertNotNull($result->error);
    }

    public function testUnknownToolRequestedByProviderIsRejectedButRunContinues(): void
    {
        $provider = new FakeAiProvider([
            new AiChatResponse(null, [new ToolCall('c1', 'nonexistent_tool', [])]),
            new AiChatResponse('Rendben, az az eszköz nem elérhető, de ezt tudom mondani.', []),
        ]);
        $registry = new ToolRegistry(); // szándékosan üres — semmi sincs regisztrálva
        $runner = new AgentRunner($provider, $registry, 5);

        $result = $runner->run('sys', 'kérdés');

        $this->assertTrue($result->success);
        // Az ismeretlen eszköz nevét SOSE tartjuk "használtként" nyilván
        // (lásd testMaliciousUnknownToolNameIsNeverRecordedAsUsed), a
        // végrehajtás maga ToolResult::fail()-t adott — ez a második
        // chat()-hívás üzeneteiben ellenőrizhető.
        $this->assertSame([], $result->toolsUsed);
        $secondCallMessages = $provider->receivedMessages[1];
        $toolMessages = array_values(array_filter($secondCallMessages, fn ($m) => $m['role'] === 'tool'));
        $this->assertStringContainsString('Ismeretlen eszköz', $toolMessages[0]['content']);
    }

    public function testMaliciousUnknownToolNameIsNeverRecordedAsUsed(): void
    {
        $payload = '<svg/onload=alert(document.domain)>';
        $executed = false;
        $provider = new FakeAiProvider([
            new AiChatResponse(null, [new ToolCall('c1', $payload, []), new ToolCall('c2', 'real_tool', [])]),
            new AiChatResponse('Kész.', []),
        ]);
        $registry = new ToolRegistry();
        $registry->register($this->tool('real_tool', function (array $a) use (&$executed) {
            $executed = true;
            return ['ok' => true];
        }));
        $runner = new AgentRunner($provider, $registry, 5);

        $result = $runner->run('sys', 'kérdés');

        $this->assertTrue($result->success);
        $this->assertSame(['real_tool'], $result->toolsUsed, 'Csak a whitelistelt, ténylegesen regisztrált eszköz kerülhet a tools_used listába.');
        $this->assertTrue($executed);
    }

    public function testRunStreamingMaliciousUnknownToolNameNeverReachesEventsOrToolsUsed(): void
    {
        $payload = '<svg/onload=alert(document.domain)>';
        $provider = new FakeAiProvider([
            new AiChatResponse(null, [new ToolCall('c1', $payload, [])]),
            new AiChatResponse('Folytatva.', []),
        ]);
        $runner = new AgentRunner($provider, new ToolRegistry(), 5);

        $events = [];
        $result = $runner->runStreaming('sys', 'kérdés', function (AiStreamEvent $e) use (&$events) {
            $events[] = $e;
        }, 'inventory');

        $this->assertTrue($result->success);
        $this->assertSame([], $result->toolsUsed);
        $toolEvents = array_values(array_filter($events, fn (AiStreamEvent $e) => str_starts_with($e->type, 'tool_call_')));
        $this->assertNotEmpty($toolEvents);
        foreach ($toolEvents as $e) {
            $this->assertSame(AgentRunner::UNKNOWN_TOOL_EVENT_NAME, $e->payload['name']);
        }
        foreach ($events as $e) {
            $this->assertStringNotContainsString('<svg', (string) json_encode($e->payload), 'A nyers, modell által kitalált eszköznév sose kerülhet a böngésző felé menő eseménybe.');
        }
    }

    public function testToolFailureIsFedBackToModelAndRunCanStillSucceed(): void
    {
        $provider = new FakeAiProvider([
            new AiChatResponse(null, [new ToolCall('c1', 'failing_tool', [])]),
            new AiChatResponse('A lekérdezés nem sikerült, de ezt tudom mondani helyette.', []),
        ]);
        $registry = new ToolRegistry();
        $registry->register($this->tool('failing_tool', function (array $a) {
            throw new InvalidArgumentException('Hiányzó paraméter.');
        }));
        $runner = new AgentRunner($provider, $registry, 5);

        $result = $runner->run('sys', 'kérdés');

        $this->assertTrue($result->success);
        $this->assertSame(['failing_tool'], $result->toolsUsed);
    }

    public function testProviderFailureIsHandledGracefully(): void
    {
        $provider = new FakeAiProvider([
            new AiProviderException('kapcsolódási hiba', 'unavailable'),
        ]);
        $registry = new ToolRegistry();
        $runner = new AgentRunner($provider, $registry, 5);

        $result = $runner->run('sys', 'kérdés');

        $this->assertFalse($result->success);
        $this->assertStringNotContainsString('kapcsolódási hiba', (string) $result->error);
        $this->assertSame('Az AI-modell jelenleg nem érhető el.', $result->error);
    }

    public function testEmptyFinalContentIsTreatedAsFailure(): void
    {
        $provider = new FakeAiProvider([
            new AiChatResponse('', []),
        ]);
        $registry = new ToolRegistry();
        $runner = new AgentRunner($provider, $registry, 5);

        $result = $runner->run('sys', 'kérdés');

        $this->assertFalse($result->success);
    }

    public function testConstructorRejectsInvalidMaxIterations(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new AgentRunner(new FakeAiProvider([]), new ToolRegistry(), 0);
    }

    // -----------------------------------------------------------------
    // Fázis 9 — runStreaming() — a FakeAiProvider SOSE implementálja az
    // AiStreamingProviderInterface-t, tehát ezek a tesztek KIZÁRÓLAG a
    // "transzparens fallback" ágat (egyetlen chat()-hívásból szintetizált
    // text_delta+usage) bizonyítják — a VALÓDI, hálózati-szintű
    // provider-streamelést a 3 dedikált Provider-tesztfájl (Ai
    // LocalProviderStreamingTest/AiAnthropicProviderStreamingTest/
    // AiOpenAiProviderStreamingTest) és a cross-provider streamelt
    // Copilot-teszt (AiCopilotStreamingCrossProviderRegressionTest) adja.
    // -----------------------------------------------------------------

    public function testRunStreamingEmitsFullEventLifecycleForToolCallThenFinal(): void
    {
        $provider = new FakeAiProvider([
            new AiChatResponse(null, [new ToolCall('c1', 'get_number', [])]),
            new AiChatResponse('A szám 42.', []),
        ]);
        $registry = new ToolRegistry();
        $registry->register($this->tool('get_number', fn (array $a) => ['number' => 42]));
        $runner = new AgentRunner($provider, $registry, 5);

        $events = [];
        $result = $runner->runStreaming('sys', 'mi a szám?', function (AiStreamEvent $e) use (&$events) {
            $events[] = $e;
        }, 'inventory');

        $this->assertTrue($result->success);
        $this->assertSame('A szám 42.', $result->answer);
        $this->assertFalse($result->streamed, 'A FakeAiProvider nem streamelés-képes — a fallback-ágnak streamed=false-t kell jeleznie.');

        $types = array_map(fn (AiStreamEvent $e) => $e->type, $events);
        $this->assertSame('agent_started', $types[0]);
        $this->assertContains('tool_call_started', $types);
        $this->assertContains('tool_call_completed', $types);
        $this->assertContains('text_delta', $types, 'A fallback-ágnak a teljes választ EGYETLEN text_delta eseményként kell szintetizálnia.');
        $this->assertContains('final', $types);
        $this->assertSame('agent_completed', end($types));

        // A tool_call_started ELŐBB kell, mint a tool_call_completed — az
        // esemény-sorrend a TÉNYLEGES végrehajtást kell, hogy tükrözze.
        $startedIndex = array_search('tool_call_started', $types, true);
        $completedIndex = array_search('tool_call_completed', $types, true);
        $this->assertLessThan($completedIndex, $startedIndex);
    }

    public function testRunStreamingNeverExecutesToolBeyondCostLimitMaxToolCalls(): void
    {
        $callCount = 0;
        $script = [];
        for ($i = 0; $i < 5; $i++) {
            $script[] = new AiChatResponse(null, [new ToolCall("c$i", 'counted_tool', [])]);
        }
        $provider = new FakeAiProvider($script);
        $registry = new ToolRegistry();
        $registry->register($this->tool('counted_tool', function (array $a) use (&$callCount) {
            $callCount++;
            return ['ok' => true];
        }));
        // maxIterations bőven elég lenne (10), de a maxToolCalls=2 korlátnak
        // KELL megállítania a futást ELŐBB — ez bizonyítja, hogy a két
        // korlát (iteráció vs. tényleges eszköz-végrehajtás-darabszám)
        // egymástól FÜGGETLENÜL érvényesül.
        $runner = new AgentRunner($provider, $registry, 10, null, new AiCostLimits(2, null));

        $events = [];
        $result = $runner->runStreaming('sys', 'kérdés', function (AiStreamEvent $e) use (&$events) {
            $events[] = $e;
        }, 'inventory');

        $this->assertFalse($result->success);
        $this->assertSame('tool_call_limit', $result->limitReached);
        $this->assertLessThanOrEqual(2, $callCount, 'A tényleges eszköz-VÉGREHAJTÁS sose lépheti túl a beállított korlátot.');

        $types = array_map(fn (AiStreamEvent $e) => $e->type, $events);
        $this->assertContains('error', $types, 'A limit elérésekor egy error eseménynek kell érkeznie, mielőtt a hívás leáll.');
    }

    public function testRunStreamingEmitsErrorEventOnProviderFailure(): void
    {
        $provider = new FakeAiProvider([
            new AiProviderException('kapcsolódási hiba', 'unavailable'),
        ]);
        $registry = new ToolRegistry();
        $runner = new AgentRunner($provider, $registry, 5);

        $events = [];
        $result = $runner->runStreaming('sys', 'kérdés', function (AiStreamEvent $e) use (&$events) {
            $events[] = $e;
        }, 'inventory');

        $this->assertFalse($result->success);
        $this->assertSame('Az AI-modell jelenleg nem érhető el.', $result->error);

        $types = array_map(fn (AiStreamEvent $e) => $e->type, $events);
        $this->assertContains('error', $types);
        // A hiba-üzenetnek a böngésző felé is a BIZTONSÁGOS, előre
        // meghatározott szöveget kell hordoznia — SOSE a nyers kivétel
        // szövegét ("kapcsolódási hiba"), ami provider-belső részletet
        // szivárogtatna ki.
        $errorEvent = array_values(array_filter($events, fn (AiStreamEvent $e) => $e->type === 'error'))[0];
        $this->assertStringNotContainsString('kapcsolódási hiba', $errorEvent->payload['message']);
    }

    public function testRunStreamingUnknownToolIsRejectedButRunContinuesWithoutExecution(): void
    {
        $executed = false;
        $provider = new FakeAiProvider([
            new AiChatResponse(null, [new ToolCall('c1', 'nem_letezo_eszkoz', [])]),
            new AiChatResponse('Folytatva a hiba után is.', []),
        ]);
        $registry = new ToolRegistry();
        $registry->register($this->tool('valos_eszkoz', function (array $a) use (&$executed) {
            $executed = true;
            return ['ok' => true];
        }));
        $runner = new AgentRunner($provider, $registry, 5);

        $events = [];
        $result = $runner->runStreaming('sys', 'kérdés', function (AiStreamEvent $e) use (&$events) {
            $events[] = $e;
        }, 'inventory');

        $this->assertTrue($result->success);
        $this->assertFalse($executed, 'Egy ismeretlen eszköznév SOSE futtathat le semmilyen VALÓDI, regisztrált eszközt.');
    }

    public function testStreamingEnabledFalseForcesFallbackEvenWhenProviderCanStream(): void
    {
        // Fázis 10 — a kör 7/18. pontja: EGY, ténylegesen streamelés-
        // képes provider (chatStream() implementálva), DE
        // streamingEnabled=false az AgentRunner konstruktorában —
        // ennek KELL, hogy kikényszerítse a szinkron chat()-visszaesést,
        // a provider saját chatStream()-jét EGYSZER SE hívva meg.
        $provider = new FakeStreamingAiProvider(new AiChatResponse('Kikapcsolt streamelés válasza.', []));
        $registry = new ToolRegistry();
        $runner = new AgentRunner($provider, $registry, 5, null, null, false);

        $events = [];
        $result = $runner->runStreaming('sys', 'kérdés', function (AiStreamEvent $e) use (&$events) {
            $events[] = $e;
        }, 'inventory');

        $this->assertTrue($result->success);
        $this->assertFalse($result->streamed, 'streamingEnabled=false esetén a válasznak SOSE szabad streamedként jelentkeznie.');
        $this->assertSame(0, $provider->chatStreamCallCount, 'A provider SAJÁT chatStream()-je SOSE hívódhat meg, ha az admin kikapcsolta a streamelést.');
        $this->assertSame(1, $provider->chatCallCount);

        $types = array_map(fn (AiStreamEvent $e) => $e->type, $events);
        $this->assertContains('text_delta', $types, 'A kikapcsolt streamelés ágának is szintetizálnia kell egy text_delta eseményt (UX-átlátszóság).');
    }

    public function testStreamingEnabledTrueActuallyUsesProviderChatStreamWhenCapable(): void
    {
        // A pozitív eset ellenőrzése is szükséges — enélkül a fenti
        // teszt önmagában nem bizonyítaná, hogy a flag ténylegesen
        // MINDKÉT irányban helyesen működik.
        $provider = new FakeStreamingAiProvider(new AiChatResponse('Bekapcsolt streamelés válasza.', []));
        $registry = new ToolRegistry();
        $runner = new AgentRunner($provider, $registry, 5, null, null, true);

        $result = $runner->runStreaming('sys', 'kérdés', function (AiStreamEvent $e) {}, 'inventory');

        $this->assertTrue($result->success);
        $this->assertTrue($result->streamed);
        $this->assertSame(1, $provider->chatStreamCallCount);
        $this->assertSame(0, $provider->chatCallCount, 'Ha a provider ténylegesen streamelt, a szinkron chat()-nek SOSE szabad meghívódnia ugyanahhoz a körhöz.');
    }
}
