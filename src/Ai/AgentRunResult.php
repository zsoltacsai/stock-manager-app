<?php

declare(strict_types=1);

require_once __DIR__ . '/AiUsage.php';

/**
 * Egy teljes AgentRunner::run()/runStreaming() lefutásának strukturált
 * eredménye.
 *
 * Fázis 9 — a kör 14/16/20. pontja: OPCIONÁLIS, ADDITÍV mezők (usage/
 * streamed/limitReached/wasCompacted) — a MEGLÉVŐ, pozicionális
 * ok()/fail() hívási helyek (InventoryAgent/SalesAgent/AnomalyAgent/
 * AiCopilot, tesztek) VÁLTOZATLANUL fordulnak/futnak.
 */
final class AgentRunResult
{
    /** @param string[] $toolsUsed egyedi eszköznevek, hívási sorrendben */
    private function __construct(
        public readonly bool $success,
        public readonly ?string $answer,
        public readonly array $toolsUsed,
        public readonly int $iterations,
        public readonly ?string $error,
        public readonly ?AiUsage $usage = null,
        public readonly bool $streamed = false,
        public readonly ?string $limitReached = null,
        public readonly bool $wasCompacted = false,
    ) {
    }

    /** @param string[] $toolsUsed */
    public static function ok(
        string $answer,
        array $toolsUsed,
        int $iterations,
        ?AiUsage $usage = null,
        bool $streamed = false,
        bool $wasCompacted = false,
    ): self {
        return new self(true, $answer, $toolsUsed, $iterations, null, $usage, $streamed, null, $wasCompacted);
    }

    /** @param string[] $toolsUsed */
    public static function fail(
        string $error,
        array $toolsUsed,
        int $iterations,
        ?AiUsage $usage = null,
        bool $streamed = false,
        ?string $limitReached = null,
        bool $wasCompacted = false,
    ): self {
        return new self(false, null, $toolsUsed, $iterations, $error, $usage, $streamed, $limitReached, $wasCompacted);
    }
}
