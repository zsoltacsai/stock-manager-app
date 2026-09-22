<?php

declare(strict_types=1);

/**
 * Egy provider elérhetőségi állapota — lásd OllamaHealth. Négy,
 * a felületen is megkülönböztetett állapot (a kör 13. pontja):
 * 'disabled' (AI ki van kapcsolva), 'unavailable' (a provider nem
 * érhető el), 'model_error' (a provider elérhető, de a konfigurált
 * modell nincs meg), 'available'.
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
}
