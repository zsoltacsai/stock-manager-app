<?php

declare(strict_types=1);

/**
 * Fázis 9 — a kör 16. pontja: EGY AgentRunner-futás (nem a teljes
 * Copilot-fan-out — lásd AiCopilot.php a KERESZT-ügynök-hívásos
 * költség-védelemért) determinisztikus, biztonságos korlátai.
 *
 * `maxToolCalls` — a kör 16. pontja "max tool calls" pontja: EGY agent
 * futása (a saját maxIterations körén belül) legfeljebb ennyi tényleges
 * eszköz-VÉGREHAJTÁST tehet — nem pénzköltség-alapú, tisztán
 * darabszám-korlát, ezért NEM igényel árazási adatot (lásd AiPricing),
 * mindig kiértékelhető.
 *
 * `maxEstimatedCostPerRequest` — OPCIONÁLIS (null = nincs korlát), a
 * kör 15/16. pontja: dollár-alapú felső korlát. Mivel a tényleges
 * dollár-becsléshez provider+modell-specifikus árazás kell (lásd
 * AiPricing), ezt EZ az osztály nem maga értékeli ki — a hívó (jelenleg:
 * AiCopilot, a saját ügynök-hívásai között) olvassa ki ÉS érvényesíti,
 * AiPricing segítségével.
 */
final class AiCostLimits
{
    public function __construct(
        public readonly int $maxToolCalls = 20,
        public readonly ?float $maxEstimatedCostPerRequest = null,
    ) {
    }

    public static function fromSettings(array $appSettings): self
    {
        $maxCost = null;
        if (isset($appSettings['ai_max_estimated_cost_per_request']) && $appSettings['ai_max_estimated_cost_per_request'] !== null && $appSettings['ai_max_estimated_cost_per_request'] !== '') {
            $parsed = (float) $appSettings['ai_max_estimated_cost_per_request'];
            $maxCost = $parsed > 0 ? $parsed : null;
        }
        return new self(max(1, (int) ($appSettings['ai_max_tool_calls'] ?? 20)), $maxCost);
    }
}
