<?php

declare(strict_types=1);

require_once __DIR__ . '/AiCostLimits.php';

/**
 * Egy AI-futás (egyetlen felhasználói kérés) közös, szerveroldali kerete.
 *
 * AI-01 — TOOL-HÍVÁSI KERET: az `ai_max_tool_calls` beállítás a TELJES
 * futásra vonatkozik (minden forduló, minden egy válaszon belüli tool-hívás,
 * a Copilot delegáló toolja ÉS az általa indított al-ügynökök tool-hívásai
 * együtt). A keretet az AgentRunner MINDEN egyes tool-végrehajtás ELŐTT
 * fogyasztja; ha elfogyott, a tool nem fut le, a futás kontrollált
 * 'tool_call_limit' hibával áll le. Egy provider-újrapróbálás (AiRetryPolicy,
 * csak a HTTP-hívás) vagy a streamelés nem nyit új keretet — a keret a
 * futás objektumhoz tartozik, egy új kérés új keretet kap.
 *
 * AI-09 — JOGOSULTSÁGI CHECKPOINT: az opcionális `$authorizationCheck` a
 * futás közben újraértékeli, hogy a kérést indító szereplő (dolgozó /
 * kliens-gép / kliens-munkamenet) még jogosult-e (lásd AiRunGuard). Az
 * AgentRunner minden provider-hívás ÉS minden tool-végrehajtás előtt
 * meghívja; hamis érték esetén a futás 'authorization_revoked' hibával
 * leáll, további tool nem fut.
 */
final class AiRunContext
{
    private int $toolCallsUsed = 0;

    public function __construct(
        public readonly int $maxToolCalls,
        private readonly ?Closure $authorizationCheck = null,
    ) {
        if ($maxToolCalls < 1) {
            throw new InvalidArgumentException('A tool-hívási keret legalább 1 kell legyen.');
        }
    }

    /** Ugyanabból a beállításból (`ai_max_tool_calls`), mint az AiCostLimits — nincs második limit. */
    public static function fromSettings(array $appSettings, ?Closure $authorizationCheck = null): self
    {
        return new self(AiCostLimits::fromSettings($appSettings)->maxToolCalls, $authorizationCheck);
    }

    /** Egy tool-végrehajtás lefoglalása a keretből; hamis, ha a keret elfogyott (a tool ekkor NEM futhat le). */
    public function tryConsumeToolCall(): bool
    {
        if ($this->toolCallsUsed >= $this->maxToolCalls) {
            return false;
        }
        $this->toolCallsUsed++;
        return true;
    }

    public function toolCallsUsed(): int
    {
        return $this->toolCallsUsed;
    }

    public function isStillAuthorized(): bool
    {
        if ($this->authorizationCheck === null) {
            return true;
        }
        try {
            return (bool) ($this->authorizationCheck)();
        } catch (Throwable $e) {
            // Ha a jogosultság nem ellenőrizhető (pl. DB-hiba), a futás nem folytatódhat.
            error_log('[fountaintrade] AI jogosultsági checkpoint sikertelen: ' . $e->getMessage());
            return false;
        }
    }
}
