<?php

declare(strict_types=1);

require_once __DIR__ . '/AiProviderInterface.php';
require_once __DIR__ . '/AiProviderException.php';
require_once __DIR__ . '/ToolRegistry.php';
require_once __DIR__ . '/ConversationManager.php';
require_once __DIR__ . '/AgentRunResult.php';

/**
 * Generikus, provider-független agent-végrehajtó — SEM az Ollamáról, SEM
 * egy konkrét agentről (pl. Inventory) nem tud semmit, kizárólag az
 * AiProviderInterface-en és a neki átadott ToolRegistry-n keresztül
 * dolgozik. Egy jövőbeli SalesAgent/AnomalyAgent ugyanezt az osztályt
 * használja majd, csak más system prompttal és más regisztrált
 * eszközökkel (lásd README "AI asszisztens" szakasza).
 */
final class AgentRunner
{
    public function __construct(
        private readonly AiProviderInterface $provider,
        private readonly ToolRegistry $registry,
        private readonly int $maxIterations = 5,
    ) {
        if ($this->maxIterations < 1) {
            throw new InvalidArgumentException('A maxIterations legalább 1 kell legyen.');
        }
    }

    public function run(string $systemInstruction, string $userMessage): AgentRunResult
    {
        $conversation = new ConversationManager($systemInstruction, $userMessage);
        $toolsUsed = [];

        for ($iteration = 1; $iteration <= $this->maxIterations; $iteration++) {
            try {
                $response = $this->provider->chat($conversation->toArray(), $this->registry->all());
            } catch (AiProviderException $e) {
                return AgentRunResult::fail('Az AI-modell jelenleg nem érhető el.', $toolsUsed, $iteration);
            }

            if (!$response->hasToolCalls()) {
                if ($response->content === null || trim($response->content) === '') {
                    return AgentRunResult::fail('Az AI-modell nem adott érdemi választ.', $toolsUsed, $iteration);
                }
                return AgentRunResult::ok($response->content, $toolsUsed, $iteration);
            }

            $conversation->addAssistantMessage($response->content, $response->toolCalls);

            foreach ($response->toolCalls as $call) {
                if (!in_array($call->name, $toolsUsed, true)) {
                    $toolsUsed[] = $call->name;
                }
                $result = $this->registry->execute($call);
                $conversation->addToolResult($result);
            }
            // A ciklus folytatódik — a modell a következő körben már látja
            // az eszköz-eredményeket, és vagy egy végleges választ ad, vagy
            // (pl. további adat kell) újabb eszköz-hívást kér.
        }

        return AgentRunResult::fail(
            'Az AI-asszisztens nem tudott végleges választ adni a megengedett lépésszámon belül — próbáld egyszerűbb/pontosabb kérdéssel.',
            $toolsUsed,
            $this->maxIterations
        );
    }
}
