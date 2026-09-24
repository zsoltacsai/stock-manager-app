<?php

declare(strict_types=1);

require_once __DIR__ . '/AiUsage.php';

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
        // Fázis 9 — lásd AgentRunResult azonos docblokkja: OPCIONÁLIS,
        // ADDITÍV mezők, egyenesen a szintézist végző AgentRunner-futás
        // AgentRunResult-jából átemelve (NEM a résztvevő SUB-agentek
        // usage-éből összesítve — a szintézis-hívás usage-e a mérvadó,
        // ugyanaz az elv, mint a nem-streamelt CopilotRunResult::$answer
        // is a szintézis-válaszból jön, nem a sub-agentekéből).
        public readonly ?AiUsage $usage = null,
        public readonly bool $streamed = false,
        public readonly ?string $limitReached = null,
        public readonly bool $wasCompacted = false,
        // Fázis 10 — lásd AgentRunResult::$failureCategory azonos
        // docblokkja.
        public readonly ?string $failureCategory = null,
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
            $runResult->error,
            $runResult->usage,
            $runResult->streamed,
            $runResult->limitReached,
            $runResult->wasCompacted,
            $runResult->failureCategory
        );
    }
}
