<?php

declare(strict_types=1);

/**
 * Fázis 9 — a kör 14. pontja: normalizált, providerfüggetlen token-
 * használati adat egyetlen AiProviderInterface::chat()/chatStream()
 * híváshoz. MINDEN mező NULLABLE — ha egy provider (vagy egy konkrét
 * válasz) nem ad meg egy adott számot, az érték `null` marad, SOSE
 * kerül kitalálásra/becslésre (a kör 14. pontja explicit tiltása: "Do
 * not invent usage figures... For providers that do not expose a
 * field: store null/unknown").
 *
 * A provider-specifikus mezőnév-feloldás (pl. Ollama `eval_count`,
 * Anthropic `usage.output_tokens`, OpenAI `usage.output_tokens_details.
 * reasoning_tokens`) KIZÁRÓLAG az adott Provider osztályban történik —
 * ez az osztály csak a MÁR feloldott, normalizált számokat tárolja.
 */
final class AiUsage
{
    public function __construct(
        public readonly ?int $inputTokens = null,
        public readonly ?int $outputTokens = null,
        public readonly ?int $totalTokens = null,
        public readonly ?int $reasoningTokens = null,
        public readonly ?int $cachedInputTokens = null,
    ) {
    }

    /**
     * Egy AgentRunner-futás több provider-hívást is indíthat (több
     * iteráció) — ez adja össze a részleteket egyetlen, a teljes
     * futásra vonatkozó összesítéssé. Ha MINDKÉT oldal null egy adott
     * mezőn, az eredmény is null marad (SOSE 0-t hazudik egy ismeretlen
     * értékre).
     */
    public static function merge(?self $a, ?self $b): ?self
    {
        if ($a === null) {
            return $b;
        }
        if ($b === null) {
            return $a;
        }
        return new self(
            self::sumNullable($a->inputTokens, $b->inputTokens),
            self::sumNullable($a->outputTokens, $b->outputTokens),
            self::sumNullable($a->totalTokens, $b->totalTokens),
            self::sumNullable($a->reasoningTokens, $b->reasoningTokens),
            self::sumNullable($a->cachedInputTokens, $b->cachedInputTokens),
        );
    }

    private static function sumNullable(?int $a, ?int $b): ?int
    {
        if ($a === null && $b === null) {
            return null;
        }
        return ($a ?? 0) + ($b ?? 0);
    }

    /** @return array<string,int|null> */
    public function toArray(): array
    {
        return [
            'input_tokens' => $this->inputTokens,
            'output_tokens' => $this->outputTokens,
            'total_tokens' => $this->totalTokens,
            'reasoning_tokens' => $this->reasoningTokens,
            'cached_input_tokens' => $this->cachedInputTokens,
        ];
    }
}
