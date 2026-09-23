<?php

declare(strict_types=1);

/**
 * Egy provider elérhetőségi állapota — lásd OllamaHealth/AnthropicHealth.
 * A felületen is megkülönböztetett állapotok (a kör 13. pontja, Fázis 2-vel
 * bővítve): 'disabled' (AI ki van kapcsolva), 'unavailable' (a provider
 * hálózatilag nem érhető el), 'not_configured' (Anthropic-nál: nincs
 * megadva API-kulcs), 'auth_error' (a megadott hitelesítő adat érvénytelen
 * — pl. Anthropic 401), 'model_error' (a provider elérhető, de a
 * konfigurált modell nincs meg/nem támogatott), 'available'.
 */
final class AiAvailability
{
    private function __construct(
        public readonly string $status,
        public readonly ?string $message,
    ) {
    }

    public static function disabled(): self
    {
        return new self('disabled', null);
    }

    public static function available(): self
    {
        return new self('available', null);
    }

    public static function unavailable(string $message): self
    {
        return new self('unavailable', $message);
    }

    public static function modelError(string $message): self
    {
        return new self('model_error', $message);
    }

    public static function notConfigured(string $message): self
    {
        return new self('not_configured', $message);
    }

    public static function authError(string $message): self
    {
        return new self('auth_error', $message);
    }
}
