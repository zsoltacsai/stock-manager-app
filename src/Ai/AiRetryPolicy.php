<?php

declare(strict_types=1);

require_once __DIR__ . '/AiProviderException.php';

/**
 * Fázis 10 — a kör 9. pontja: MINIMÁLIS, korlátozott (bounded) újra-
 * próbálkozás VALÓDI, átmeneti provider-hibákra — a Fázis 9 óta ilyen
 * mechanizmus NEM létezett (lásd a kör 2. pontja "baseline reproduction"
 * megállapítása).
 *
 * KIZÁRÓLAG a NEM-streamelt kérés-végrehajtást (`executeRequest()`/
 * `executeGet()`) fedi le mindhárom Provider-ben — SZÁNDÉKOSAN NEM a
 * streamelt (`executeStreamingRequest()`) útvonalat: ha egy streamelt
 * válaszból MÁR eljutott legalább egy darab (pl. egy `text_delta`) a
 * hívóhoz/böngészőhöz, egy "csendes" újrapróbálkozás onnantól
 * MEGDUPLÁZNÁ/összekeverné a már látott tartalmat — ez rosszabb lenne,
 * mint egy egyszeri, tiszta hibaüzenet. Egy nem-streamelt kérés ezzel
 * szemben EGYETLEN atomikus curl_exec — vagy TELJES egészében sikeres,
 * vagy semmi nem jutott még a hívóhoz, tehát biztonságosan
 * megismételhető.
 *
 * KIZÁRÓLAG a ténylegesen ÁTMENETI `AiProviderException::$kind` értékekre
 * próbálkozik újra (lásd RETRYABLE_KINDS) — SOSE hitelesítési hibára,
 * kérés-formátum hibára, konfigurációs hibára vagy egyéb determinisztikus
 * 4xx-re (a kör 9. pontja explicit tiltása: "Do NOT blindly retry:
 * authentication failures, malformed requests, deterministic tool
 * errors").
 *
 * Az újrapróbálkozás KIZÁRÓLAG a Provider SAJÁT HTTP-hívását ismétli meg
 * — a `ToolRegistry::execute()` (tényleges eszköz-végrehajtás) ÉS az
 * `ActionExecutor` (üzleti mutáció) EZEN a rétegen KÍVÜL esik, ezért egy
 * újrapróbálkozás SOSE futtathat le kétszer egy eszközt vagy üzleti
 * műveletet — lásd a kör 9. pontja "No AI retry may bypass proposal/
 * execution idempotency" elve, ami itt szerkezetileg (nem futásidőben
 * ellenőrzött szabályként) garantált.
 */
final class AiRetryPolicy
{
    /** Legfeljebb ennyi TELJES kísérlet (az első + legfeljebb 2 újrapróbálkozás). */
    private const MAX_ATTEMPTS = 3;

    /** Exponenciális várakozás alapja — 200ms, majd 400ms a két újrapróbálkozás előtt. */
    private const BASE_DELAY_MICROS = 200_000;

    /** A várakozások ÖSSZEGE sose lépheti túl ezt — "no retry storms". */
    private const MAX_TOTAL_DELAY_MICROS = 2_000_000;

    private const RETRYABLE_KINDS = ['rate_limit', 'timeout', 'unavailable'];

    /**
     * @template T
     * @param callable():T $attempt
     * @return T
     */
    public static function run(callable $attempt)
    {
        $totalDelayMicros = 0;

        for ($attemptNumber = 1; $attemptNumber <= self::MAX_ATTEMPTS; $attemptNumber++) {
            try {
                return $attempt();
            } catch (AiProviderException $e) {
                $isLastAttempt = $attemptNumber >= self::MAX_ATTEMPTS;
                if ($isLastAttempt || !in_array($e->kind, self::RETRYABLE_KINDS, true)) {
                    throw $e;
                }

                $delay = self::BASE_DELAY_MICROS * (2 ** ($attemptNumber - 1));
                if ($totalDelayMicros + $delay > self::MAX_TOTAL_DELAY_MICROS) {
                    throw $e;
                }
                $totalDelayMicros += $delay;
                usleep($delay);
            }
        }

        // Elméletileg elérhetetlen (a ciklus utolsó iterációja mindig
        // vagy visszatér, vagy dob) — a statikus elemzőknek kell.
        throw new AiProviderException('Váratlan állapot az AiRetryPolicy::run()-ban.', 'unavailable');
    }
}
