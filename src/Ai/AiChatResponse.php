<?php

declare(strict_types=1);

require_once __DIR__ . '/ToolCall.php';

/**
 * Egy AiProviderInterface::chat() hívás normalizált eredménye — minden
 * provider (LocalProvider, majd később Anthropic/OpenAI) ugyanebbe az
 * alakba fordítja a saját, natív válasz-formátumát, hogy az AgentRunner
 * SOSE lásson provider-specifikus struktúrát.
 */
final class AiChatResponse
{
    /** @param ToolCall[] $toolCalls */
    public function __construct(
        public readonly ?string $content,
        public readonly array $toolCalls,
    ) {
    }

    public function hasToolCalls(): bool
    {
        return $this->toolCalls !== [];
    }
}
