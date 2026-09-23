<?php

declare(strict_types=1);

/**
 * Egy AiCopilot::answer() lefutásának strukturált eredménye — lásd
 * AgentRunResult (ugyanaz a minta egyetlen agent-futásra), ez itt a
 * Fázis 6 "Copilot result model" (kör 7. pontja) kiegészített változata:
 * a végleges szintézis MELLETT azt is megőrzi, mely domain-agentek
 * vettek részt, és azoknak (egyenként) mi lett az eredménye — az UI
 * ez alapján tudja megjeleníteni pl. "Agents used: Sales, Inventory".
 *
 * SOSE tartalmaz provider-natív struktúrát (nyers Ollama/Anthropic/OpenAI
 * választ) — kizárólag a MÁR meglévő AgentRunResult-okból származó,
 * providerfüggetlen mezőket.
 */
final class CopilotRunResult
{
    /**
     * @param string[] $agentsUsed egyedi agent-nevek ('inventory'|'sales'|'anomaly'), hívási sorrendben
     * @param array<string,array{success:bool,tools_used:string[],error:?string}> $agentResults agent-nevenkénti részeredmény
     * @param string[] $toolsUsed a részt vevő agentek ÁLTAL használt eszközök uniója, duplikátum nélkül
     */
    private function __construct(
        public readonly bool $success,
        public readonly ?string $answer,
        public readonly array $agentsUsed,
        public readonly array $agentResults,
        public readonly array $toolsUsed,
        public readonly int $iterations,
        public readonly ?string $error,
    ) {
    }

    /**
     * @param string[] $agentsUsed
     * @param array<string,array{success:bool,tools_used:string[],error:?string}> $agentResults
     */
    public static function fromRun(AgentRunResult $runResult, array $agentsUsed, array $agentResults): self
    {
        $toolsUsed = [];
        foreach ($agentResults as $result) {
            foreach ($result['tools_used'] as $t) {
                if (!in_array($t, $toolsUsed, true)) {
                    $toolsUsed[] = $t;
                }
            }
        }

        return new self(
            $runResult->success,
            $runResult->answer,
            $agentsUsed,
            $agentResults,
            $toolsUsed,
            $runResult->iterations,
            $runResult->error
        );
    }
}
