<?php

declare(strict_types=1);

/*
 * AI-08 — a több AI teszt-osztály által használt, szkriptelt hamis providerek
 * közös, explicit teszt-támogató rétege. Korábban az AiAgentRunnerTest.php
 * definiálta őket, így a többi AI teszt-osztály önállóan futtatva
 * "Class FakeAiProvider not found" hibával állt le. Minden felhasználó
 * teszt-osztály maga require_once-olja ezt a fájlt.
 */

require_once __DIR__ . "/../bootstrap.php";

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
