<?php

declare(strict_types=1);

/** Egy teljes AgentRunner::run() lefutásának strukturált eredménye. */
final class AgentRunResult
{
    /** @param string[] $toolsUsed egyedi eszköznevek, hívási sorrendben */
    private function __construct(
        public readonly bool $success,
        public readonly ?string $answer,
        public readonly array $toolsUsed,
        public readonly int $iterations,
        public readonly ?string $error,
    ) {
    }

    /** @param string[] $toolsUsed */
    public static function ok(string $answer, array $toolsUsed, int $iterations): self
    {
        return new self(true, $answer, $toolsUsed, $iterations, null);
    }

    /** @param string[] $toolsUsed */
    public static function fail(string $error, array $toolsUsed, int $iterations): self
    {
        return new self(false, null, $toolsUsed, $iterations, $error);
    }
}
