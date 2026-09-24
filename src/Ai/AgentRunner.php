<?php

declare(strict_types=1);

require_once __DIR__ . '/AiProviderInterface.php';
require_once __DIR__ . '/AiStreamingProviderInterface.php';
require_once __DIR__ . '/AiProviderException.php';
require_once __DIR__ . '/ToolRegistry.php';
require_once __DIR__ . '/ConversationManager.php';
require_once __DIR__ . '/AgentRunResult.php';
require_once __DIR__ . '/AiContextLimits.php';
require_once __DIR__ . '/AiCostLimits.php';
require_once __DIR__ . '/AiStreamEvent.php';
require_once __DIR__ . '/AiToolLabels.php';
require_once __DIR__ . '/AiUsage.php';

/**
 * Generikus, provider-független agent-végrehajtó — SEM az Ollamáról, SEM
 * egy konkrét agentről (pl. Inventory) nem tud semmit, kizárólag az
 * AiProviderInterface-en és a neki átadott ToolRegistry-n keresztül
 * dolgozik. Egy jövőbeli SalesAgent/AnomalyAgent ugyanezt az osztályt
 * használja majd, csak más system prompttal és más regisztrált
 * eszközökkel (lásd README "AI asszisztens" szakasza).
 *
 * Fázis 9 — a kör 3/4. pontja: `runStreaming()` az additív streamelési
 * belépési pont — a MEGLÉVŐ `run()` EGYETLEN sora sem módosult, minden
 * MEGLÉVŐ hívási hely (InventoryAgent/SalesAgent/AnomalyAgent/
 * AiDailyIntelligence) VÁLTOZATLANUL a szinkron `run()`-t hívja, ha nem
 * kér kifejezetten streamelést. A kettő KÖZÖS, provider-független
 * garanciája: egy eszköz-hívás KIZÁRÓLAG akkor kerül a ToolRegistry
 * elé, ha a provider (chat() VAGY chatStream()) egy TELJES, validált
 * ToolCall-listát adott vissza — a streamelt "élő gépelés" (text_delta/
 * tool_call_arguments_delta) SOSE befolyásolja ezt (lásd a kör 4.
 * pontja: "Only then pass the normalized ToolCall into existing
 * ToolRegistry validation/execution").
 */
final class AgentRunner
{
    /** A modell által kért, NEM regisztrált eszköz neve sose kerül tovább nyersen — ez a rögzített helyettesítő. */
    public const UNKNOWN_TOOL_EVENT_NAME = 'unknown_tool';

    public function __construct(
        private readonly AiProviderInterface $provider,
        private readonly ToolRegistry $registry,
        private readonly int $maxIterations = 5,
        private readonly ?AiContextLimits $contextLimits = null,
        private readonly ?AiCostLimits $costLimits = null,
        // A kör 18. pontja — "streaming enabled" admin-kapcsoló: ha
        // false, runStreaming() SOSE próbál provider-natív streamelést
        // (még ha a provider egyébként AiStreamingProviderInterface-t
        // is vállal) — MINDIG a kör 9. pontja szerinti szinkron
        // visszaesésre kényszerít, UGYANAZON az SSE-transzporton
        // keresztül (lásd webroot/api/ai-agent-stream.php).
        private readonly bool $streamingEnabled = true,
    ) {
        if ($this->maxIterations < 1) {
            throw new InvalidArgumentException('A maxIterations legalább 1 kell legyen.');
        }
    }

    /**
     * A tools_used lista (naplózás/UI) KIZÁRÓLAG whitelistelt, regisztrált
     * eszközneveket tartalmazhat — egy ismeretlen (a modell által kitalált
     * vagy prompt-injekcióval sugallt) név sose válik "megbízható" adattá.
     *
     * @param string[] $toolsUsed
     */
    private function recordToolUse(array &$toolsUsed, ToolCall $call): void
    {
        if ($this->registry->has($call->name) && !in_array($call->name, $toolsUsed, true)) {
            $toolsUsed[] = $call->name;
        }
    }

    public function run(string $systemInstruction, string $userMessage): AgentRunResult
    {
        $conversation = new ConversationManager($systemInstruction, $userMessage, $this->contextLimits);
        $toolsUsed = [];
        $usage = null;
        $toolCallCount = 0;
        $costLimits = $this->costLimits ?? new AiCostLimits();

        for ($iteration = 1; $iteration <= $this->maxIterations; $iteration++) {
            if ($toolCallCount >= $costLimits->maxToolCalls) {
                return AgentRunResult::fail(
                    'Az AI-kérés elérte a megengedett eszköz-hívási korlátot — próbáld szűkebb/pontosabb kérdéssel.',
                    $toolsUsed, $iteration, $usage, false, 'tool_call_limit', $conversation->wasCompacted()
                );
            }

            try {
                $response = $this->provider->chat($conversation->toArray(), $this->registry->all());
            } catch (AiProviderException $e) {
                return AgentRunResult::fail('Az AI-modell jelenleg nem érhető el.', $toolsUsed, $iteration, $usage, false, null, $conversation->wasCompacted(), $e->kind);
            }
            $usage = AiUsage::merge($usage, $response->usage);

            if (!$response->hasToolCalls()) {
                if ($response->content === null || trim($response->content) === '') {
                    return AgentRunResult::fail('Az AI-modell nem adott érdemi választ.', $toolsUsed, $iteration, $usage, false, null, $conversation->wasCompacted());
                }
                return AgentRunResult::ok($response->content, $toolsUsed, $iteration, $usage, false, $conversation->wasCompacted());
            }

            $conversation->addAssistantMessage($response->content, $response->toolCalls);

            foreach ($response->toolCalls as $call) {
                $this->recordToolUse($toolsUsed, $call);
                $result = $this->registry->execute($call);
                $conversation->addToolResult($result);
                $toolCallCount++;
            }
            // A ciklus folytatódik — a modell a következő körben már látja
            // az eszköz-eredményeket, és vagy egy végleges választ ad, vagy
            // (pl. további adat kell) újabb eszköz-hívást kér.
        }

        return AgentRunResult::fail(
            'Az AI-asszisztens nem tudott végleges választ adni a megengedett lépésszámon belül — próbáld egyszerűbb/pontosabb kérdéssel.',
            $toolsUsed,
            $this->maxIterations,
            $usage,
            false,
            null,
            $conversation->wasCompacted()
        );
    }

    /**
     * A kör 3/6. pontja — UGYANAZ az üzleti logika, mint run(), KIEGÉSZÍTVE
     * élő, alkalmazás-szintű progresszió-eseményekkel. Ha a provider NEM
     * vállalja az `AiStreamingProviderInterface`-t (VAGY a hívó egyszerűen
     * nem streamel), a kör 9. pontja szerint AUTOMATIKUSAN a szinkron
     * `chat()`-re esik vissza, és a teljes választ EGYETLEN `text_delta`
     * eseményként adja tovább — az UI-nak/hívónak SOSE kell tudnia,
     * MELYIK ág futott ténylegesen.
     *
     * @param callable(AiStreamEvent):void $onEvent
     */
    public function runStreaming(string $systemInstruction, string $userMessage, callable $onEvent, string $agentLabel = ''): AgentRunResult
    {
        $conversation = new ConversationManager($systemInstruction, $userMessage, $this->contextLimits);
        $toolsUsed = [];
        $usage = null;
        $toolCallCount = 0;
        $costLimits = $this->costLimits ?? new AiCostLimits();
        $streamedAtLeastOnce = false;

        $onEvent(AiStreamEvent::agentStarted($agentLabel !== '' ? $agentLabel : 'agent', AiToolLabels::forAgent($agentLabel)));

        for ($iteration = 1; $iteration <= $this->maxIterations; $iteration++) {
            if ($toolCallCount >= $costLimits->maxToolCalls) {
                $onEvent(AiStreamEvent::error('Elérted a megengedett eszköz-hívási korlátot.'));
                return AgentRunResult::fail(
                    'Az AI-kérés elérte a megengedett eszköz-hívási korlátot — próbáld szűkebb/pontosabb kérdéssel.',
                    $toolsUsed, $iteration, $usage, $streamedAtLeastOnce, 'tool_call_limit', $conversation->wasCompacted()
                );
            }

            try {
                if ($this->streamingEnabled && $this->provider instanceof AiStreamingProviderInterface) {
                    $streamedAtLeastOnce = true;
                    $response = $this->provider->chatStream($conversation->toArray(), $this->registry->all(), $onEvent);
                } else {
                    // A kör 9. pontja — automatikus, ÁTLÁTSZÓ visszaesés:
                    // a teljes választ EGY text_delta eseményként adjuk
                    // tovább, hogy a hívó UI-oldali kódja SOSE kelljen
                    // különbséget tegyen streamelt/nem-streamelt provider közt.
                    $response = $this->provider->chat($conversation->toArray(), $this->registry->all());
                    if ($response->content !== null && trim($response->content) !== '') {
                        $onEvent(AiStreamEvent::textDelta($response->content));
                    }
                    if ($response->usage !== null) {
                        $onEvent(AiStreamEvent::usage($response->usage));
                    }
                }
            } catch (AiProviderException $e) {
                $onEvent(AiStreamEvent::error('Az AI-modell jelenleg nem érhető el.'));
                return AgentRunResult::fail('Az AI-modell jelenleg nem érhető el.', $toolsUsed, $iteration, $usage, $streamedAtLeastOnce, null, $conversation->wasCompacted(), $e->kind);
            }
            $usage = AiUsage::merge($usage, $response->usage);

            if (!$response->hasToolCalls()) {
                if ($response->content === null || trim($response->content) === '') {
                    $onEvent(AiStreamEvent::error('Az AI-modell nem adott érdemi választ.'));
                    return AgentRunResult::fail('Az AI-modell nem adott érdemi választ.', $toolsUsed, $iteration, $usage, $streamedAtLeastOnce, null, $conversation->wasCompacted());
                }
                $onEvent(AiStreamEvent::final($response->content));
                $onEvent(AiStreamEvent::agentCompleted($agentLabel !== '' ? $agentLabel : 'agent', true));
                return AgentRunResult::ok($response->content, $toolsUsed, $iteration, $usage, $streamedAtLeastOnce, $conversation->wasCompacted());
            }

            $conversation->addAssistantMessage($response->content, $response->toolCalls);

            foreach ($response->toolCalls as $call) {
                $this->recordToolUse($toolsUsed, $call);
                $eventToolName = $this->registry->has($call->name) ? $call->name : self::UNKNOWN_TOOL_EVENT_NAME;
                // A kör 4/5. pontja — a tool_call_started/completed
                // ESEMÉNYEK IDE, a TÉNYLEGES ToolRegistry-végrehajtás köré
                // kötve keletkeznek (SOSE a provider stream-parszolása
                // közben) — ez garantálja, hogy a UI-nak mutatott
                // "folyamatban" állapot mindig a VALÓDI végrehajtást
                // tükrözi, nem egy provider-oldali, esetleg korábbi
                // esemény időzítését.
                $onEvent(AiStreamEvent::toolCallStarted($call->id, $eventToolName, AiToolLabels::forTool($eventToolName)));
                $result = $this->registry->execute($call);
                $onEvent(AiStreamEvent::toolCallCompleted($call->id, $eventToolName, $result->success));
                $conversation->addToolResult($result);
                $toolCallCount++;
            }
        }

        $onEvent(AiStreamEvent::error('Az AI-asszisztens nem tudott végleges választ adni a megengedett lépésszámon belül.'));
        $onEvent(AiStreamEvent::agentCompleted($agentLabel !== '' ? $agentLabel : 'agent', false));
        return AgentRunResult::fail(
            'Az AI-asszisztens nem tudott végleges választ adni a megengedett lépésszámon belül — próbáld egyszerűbb/pontosabb kérdéssel.',
            $toolsUsed,
            $this->maxIterations,
            $usage,
            $streamedAtLeastOnce,
            null,
            $conversation->wasCompacted()
        );
    }
}
