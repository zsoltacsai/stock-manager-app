<?php

declare(strict_types=1);

require_once __DIR__ . '/ToolCall.php';
require_once __DIR__ . '/AiUsage.php';

/**
 * Egy AiProviderInterface::chat() hívás normalizált eredménye — minden
 * provider (LocalProvider, majd később Anthropic/OpenAI) ugyanebbe az
 * alakba fordítja a saját, natív válasz-formátumát, hogy az AgentRunner
 * SOSE lásson provider-specifikus struktúrát.
 *
 * Fázis 9 — a kör 14. pontja: OPCIONÁLIS, ADDITÍV `$usage` mező (alapból
 * `null`, lásd a kör "Do not invent usage figures" elve) — a MEGLÉVŐ,
 * kétparaméteres `new AiChatResponse($content, $toolCalls)` hívási
 * helyek (pl. tests/AiAgentRunnerTest.php FakeAiProvider) VÁLTOZATLANUL
 * fordulnak/futnak, egy záró, opcionális konstruktor-paraméter sose tör
 * meglévő pozicionális hívást.
 */
final class AiChatResponse
{
    /** @param ToolCall[] $toolCalls */
    public function __construct(
        public readonly ?string $content,
        public readonly array $toolCalls,
        public readonly ?AiUsage $usage = null,
    ) {
    }

    public function hasToolCalls(): bool
    {
        return $this->toolCalls !== [];
    }
}
