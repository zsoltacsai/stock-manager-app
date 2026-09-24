<?php

declare(strict_types=1);

/**
 * Egy AI-provider hívásának hibája — SOSE jut nyersen a HTTP-válaszba
 * (lásd webroot/api/ai-inventory.php: csak a $safeMessage kerül oda,
 * ha egyáltalán odakerül), de az AgentRunner/hívó számára megkülönbözteti
 * a hibatípusokat (pl. napló/diagnosztika céljából).
 *
 * Fázis 10 — a kör 17. pontja: a `$kind` érték ELÉRHETŐ jelenleg
 * használt teljes készlete (a korábbi, Fázis 6-os docblokk elavult volt,
 * a Fázis 9 által hozzáadott 'rate_limit'/'auth_error' nélkül):
 * - 'configuration_error' — a HÍVÓ (admin) oldali beállítás hibás/
 *   érvénytelen (pl. ismeretlen `ai_provider` érték) — a hívás SOSE ért
 *   el a hálózatig. MEGKÜLÖNBÖZTETENDŐ az 'unavailable'-től: utóbbi egy
 *   VALÓDI, hálózati/provider-oldali elérhetetlenség.
 * - 'unavailable' — a provider hálózatilag nem érhető el (kapcsolódási
 *   hiba, DNS, connection refused stb.).
 * - 'timeout' — a kérés túllépte a beállított időkorlátot.
 * - 'auth_error' — a provider elutasította a hitelesítést (érvénytelen/
 *   hiányzó API-kulcs, HTTP 401/403).
 * - 'rate_limit' — a provider korlátozta a kérést (HTTP 429).
 * - 'http_error' — egyéb, a fentiekbe nem sorolható HTTP-szintű hiba
 *   (pl. 5xx) a streamelés/kérés MEGKEZDÉSE előtt.
 * - 'malformed_response' — a provider válasza szintaktikailag/
 *   szemantikailag érvénytelen vagy váratlanul félbeszakadt (hiányzó
 *   záró esemény streamelésnél).
 *
 * Ezt az értéket az `AgentRunner` a `AgentRunResult::$failureCategory`
 * mezőbe emeli át (lásd ott) — ez az EGYETLEN hely, ahol egy hiba
 * "kategóriája" (a felhasználónak mutatott, már biztonságos
 * `$result->error` szövegtől FÜGGETLENÜL) eljut a naplózásig/UI-ig.
 */
final class AiProviderException extends RuntimeException
{
    public function __construct(
        string $message,
        public readonly string $kind,
    ) {
        parent::__construct($message);
    }
}
