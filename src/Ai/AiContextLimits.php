<?php

declare(strict_types=1);

/**
 * Fázis 9 — a kör 10. pontja: EGY agent-futás (ConversationManager
 * élettartama) determinisztikus kontextus-korlátai, EGY helyen. A
 * MEGLÉVŐ ConversationManager SOSE fér hozzá közvetlenül a Settings-hez
 * (marad tisztán, DB-/config-független, tesztelhető osztály, lásd
 * PurchaseDecisionService azonos elve) — ez az érték-objektum a híd:
 * `fromSettings()` olvassa ki az admin-beállításokat, a hívó (AgentRunner)
 * adja tovább a ConversationManager konstruktorának.
 *
 * Minden mező OPCIONÁLIS, alapértelmezett értékkel — egy MEGLÉVŐ, korlát
 * NÉLKÜLI `new ConversationManager($system, $user)` hívási hely
 * VÁLTOZATLANUL, biztonságos beépített alapértelmezésekkel fut tovább.
 */
final class AiContextLimits
{
    public function __construct(
        public readonly int $maxMessages = 60,
        public readonly int $maxUserInputChars = 4000,
        public readonly int $maxToolResultChars = 4000,
        public readonly int $maxTotalContextChars = 60000,
    ) {
    }

    public static function fromSettings(array $appSettings): self
    {
        return new self(
            max(4, (int) ($appSettings['ai_max_context_messages'] ?? 60)),
            max(200, (int) ($appSettings['ai_max_input_chars'] ?? 4000)),
            max(500, (int) ($appSettings['ai_max_tool_result_chars'] ?? 4000)),
            max(4000, (int) ($appSettings['ai_max_total_context_chars'] ?? 60000)),
        );
    }
}
