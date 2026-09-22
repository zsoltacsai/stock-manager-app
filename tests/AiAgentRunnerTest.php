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

    /** @param array<int,AiChatResponse|AiProviderException> $script */
    public function __construct(array $script)
    {
        $this->script = $script;
    }

    public function name(): string
    {
        return 'fake';
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
        // Az ismeretlen eszköz nevét is "használtként" tartjuk nyilván (a
        // hívási kísérlet ténye), de a végrehajtás maga ToolResult::fail()-t
        // adott — ez a második chat()-hívás üzeneteiben ellenőrizhető.
        $secondCallMessages = $provider->receivedMessages[1];
        $toolMessages = array_values(array_filter($secondCallMessages, fn ($m) => $m['role'] === 'tool'));
        $this->assertStringContainsString('Ismeretlen eszköz', $toolMessages[0]['content']);
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
}
