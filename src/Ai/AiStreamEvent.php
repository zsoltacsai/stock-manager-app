<?php

declare(strict_types=1);

/**
 * Fázis 9 — a kör 3. pontja: a KIZÁRÓLAGOS, providerfüggetlen
 * "alkalmazás-szintű" streamelt esemény-alak. Provider-natív nyers
 * esemény (Ollama NDJSON-sor, Anthropic SSE content_block_delta,
 * OpenAI response.output_text.delta stb.) SOSE jut túl a Provider saját
 * osztályán — minden Provider ezekre az ESEMÉNYTÍPUSOKRA fordítja le a
 * sajátját, mielőtt az AgentRunner-en, majd az SSE-végponton keresztül
 * a böngészőhöz jutna (lásd a kör 3. pontja: "Provider-specific raw
 * events must never reach frontend code").
 *
 * SZIGORÚ whitelist ($TYPES) — egy ismeretlen típusú eseményt SOSE ad
 * tovább egyetlen Provider sem, ez zárja ki, hogy egy jövőbeli
 * provider-verzió véletlenül nyers/nem várt adatot csempésszen a
 * böngésző felé.
 */
final class AiStreamEvent
{
    public const TYPE_AGENT_STARTED = 'agent_started';
    public const TYPE_TEXT_DELTA = 'text_delta';
    public const TYPE_TOOL_CALL_STARTED = 'tool_call_started';
    public const TYPE_TOOL_CALL_ARGUMENTS_DELTA = 'tool_call_arguments_delta';
    public const TYPE_TOOL_CALL_COMPLETED = 'tool_call_completed';
    public const TYPE_AGENT_COMPLETED = 'agent_completed';
    public const TYPE_USAGE = 'usage';
    public const TYPE_FINAL = 'final';
    public const TYPE_ERROR = 'error';
    /** A HTTP-végpont (webroot/api/ai-agent-stream.php) SAJÁT, záró eseménye — lásd ott a docblokkja. */
    public const TYPE_DONE = 'done';

    public const TYPES = [
        self::TYPE_AGENT_STARTED,
        self::TYPE_TEXT_DELTA,
        self::TYPE_TOOL_CALL_STARTED,
        self::TYPE_TOOL_CALL_ARGUMENTS_DELTA,
        self::TYPE_TOOL_CALL_COMPLETED,
        self::TYPE_AGENT_COMPLETED,
        self::TYPE_USAGE,
        self::TYPE_FINAL,
        self::TYPE_ERROR,
        self::TYPE_DONE,
    ];

    /** @param array<string,mixed> $payload bounded, JSON-szerializálható — SOSE nyers provider-payload/credential. */
    private function __construct(
        public readonly string $type,
        public readonly array $payload,
    ) {
        if (!in_array($type, self::TYPES, true)) {
            throw new InvalidArgumentException("Érvénytelen AiStreamEvent típus: $type");
        }
    }

    public static function make(string $type, array $payload = []): self
    {
        return new self($type, $payload);
    }

    public static function textDelta(string $text): self
    {
        return new self(self::TYPE_TEXT_DELTA, ['text' => $text]);
    }

    public static function toolCallStarted(string $id, string $name, ?string $label = null): self
    {
        return new self(self::TYPE_TOOL_CALL_STARTED, ['id' => $id, 'name' => $name, 'label' => $label]);
    }

    public static function toolCallCompleted(string $id, string $name, bool $success): self
    {
        return new self(self::TYPE_TOOL_CALL_COMPLETED, ['id' => $id, 'name' => $name, 'success' => $success]);
    }

    public static function agentStarted(string $agent, ?string $label = null): self
    {
        return new self(self::TYPE_AGENT_STARTED, ['agent' => $agent, 'label' => $label]);
    }

    public static function agentCompleted(string $agent, bool $success): self
    {
        return new self(self::TYPE_AGENT_COMPLETED, ['agent' => $agent, 'success' => $success]);
    }

    public static function usage(AiUsage $usage): self
    {
        return new self(self::TYPE_USAGE, $usage->toArray());
    }

    public static function final(string $answer): self
    {
        return new self(self::TYPE_FINAL, ['answer' => $answer]);
    }

    /** @param string $message MÁR biztonságos, felhasználónak mutatható üzenet — SOSE nyers kivétel-szöveg. */
    public static function error(string $message): self
    {
        return new self(self::TYPE_ERROR, ['message' => $message]);
    }

    /** @return array{type:string,payload:array<string,mixed>} */
    public function toArray(): array
    {
        return ['type' => $this->type, 'payload' => $this->payload];
    }
}
