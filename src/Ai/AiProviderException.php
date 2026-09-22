<?php

declare(strict_types=1);

/**
 * Egy AI-provider hívásának hibája — SOSE jut nyersen a HTTP-válaszba
 * (lásd webroot/api/ai-inventory.php: csak a $safeMessage kerül oda,
 * ha egyáltalán odakerül), de az AgentRunner/hívó számára megkülönbözteti
 * a hibatípusokat (pl. napló/diagnosztika céljából).
 */
final class AiProviderException extends RuntimeException
{
    public function __construct(
        string $message,
        public readonly string $kind, // 'unavailable' | 'timeout' | 'malformed_response' | 'http_error'
    ) {
        parent::__construct($message);
    }
}
