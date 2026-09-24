<?php

declare(strict_types=1);

require_once __DIR__ . '/AiUsage.php';

/**
 * Fázis 9 — a kör 15. pontja: KÖZPONTOSÍTOTT, determinisztikus
 * ár-metaadat-tábla — SOSE szórva szét az üzleti logikában. Az árak
 * KIZÁRÓLAG a providerek SAJÁT, hivatalosan közzétett, TÉNYLEGESEN
 * ELLENŐRZÖTT árlistái alapján kerülhetnek ide.
 *
 * ŐSZINTE KORLÁT (SZÁNDÉKOSAN, lásd a kör 15. pontja explicit elve:
 * "Do not claim current prices without current verification"): a jelen
 * FountainTrade-példány konfigurált Anthropic/OpenAI modellnevei
 * (`claude-sonnet-5`, `gpt-6-sol` — lásd Settings::DEFAULTS) olyan
 * modell-verziók, amelyekhez ÉLŐ, hivatalosan közzétett, MEGBÍZHATÓAN
 * ellenőrizhető árlista NEM állt rendelkezésre ennek a fázisnak az
 * implementációja idején — kitalált/becsült dollárösszeget beírni a
 * táblába PONTOSAN az az eset, amit a kör 15. pontja explicit tilt.
 * Ezért a táblában ANTHROPIC/OPENAI bejegyzés JELENLEG NINCS —
 * `estimate()` ezekre `null`-t ad vissza (a kör 15. pontja: "usage
 * remains available, cost estimate is marked unavailable"), a
 * token-használat (AiUsage) ATTÓL FÜGGETLENÜL továbbra is teljes
 * egészében elérhető/naplózott. Egy admin, aki ellenőrzött, hatályos
 * árat szeretne látni, ide, EGY helyre veheti fel (forrás/
 * hatálybalépés-dátum kötelező dokumentálásával), anélkül, hogy bármi
 * mást a kódbázisban módosítania kellene.
 *
 * Az árak USD/1M token egységben lennének megadva (a providerek
 * hivatalos árlistái is így közlik), ha/amikor felkerülnek.
 */
final class AiPricing
{
    /**
     * @var array<int,array{provider:string,modelPrefix:string,inputPerMillion:float,outputPerMillion:float,cachedInputPerMillion:?float,source:string,effectiveDate:string}>
     */
    private const TABLE = [
        // Ollama — helyi futtatás, NINCS provider-oldali, per-token
        // API-díj (lásd a kör 21. pontja: "cost may be 'helyi / nincs
        // API díj' only if this is clearly an application policy").
        // EZ egy alkalmazás-szintű SZABÁLY (nem a helyi hardver/áram
        // tényleges költségéről szóló állítás) — ezért az input/output
        // ár explicit 0.0, DOKUMENTÁLTAN, nem "ismeretlen"-ként. Ez az
        // EGYETLEN bejegyzés, amit e fázis implementációja idején
        // ténylegesen, megbízhatóan ki lehetett jelenteni.
        ['provider' => 'local', 'modelPrefix' => '', 'inputPerMillion' => 0.0, 'outputPerMillion' => 0.0, 'cachedInputPerMillion' => 0.0, 'source' => 'alkalmazás-szabály: helyi futtatás, nincs API-díj', 'effectiveDate' => '2026-09-24'],
    ];

    /**
     * @return array{cost:float,source:string,effective_date:string}|null null, ha nincs megbízható árazási adat.
     */
    public static function estimate(string $provider, string $model, AiUsage $usage): ?array
    {
        $entry = self::findEntry($provider, $model);
        if ($entry === null) {
            return null;
        }
        if ($usage->inputTokens === null && $usage->outputTokens === null) {
            return null;
        }

        $inputTokens = $usage->inputTokens ?? 0;
        $outputTokens = $usage->outputTokens ?? 0;
        $cachedTokens = $usage->cachedInputTokens ?? 0;
        // A cache-elt bemeneti tokenek a rendes bemeneti díj HELYETT (nem
        // MELLETT) az olcsóbb cache-díjjal számolandók — lásd a
        // providerek hivatalos árazási dokumentációja.
        $billableInputTokens = max(0, $inputTokens - $cachedTokens);

        $cost = ($billableInputTokens / 1_000_000) * $entry['inputPerMillion']
            + ($outputTokens / 1_000_000) * $entry['outputPerMillion'];
        if ($cachedTokens > 0 && $entry['cachedInputPerMillion'] !== null) {
            $cost += ($cachedTokens / 1_000_000) * $entry['cachedInputPerMillion'];
        }

        return [
            'cost' => round($cost, 6),
            'source' => $entry['source'],
            'effective_date' => $entry['effectiveDate'],
        ];
    }

    /** @return array{provider:string,modelPrefix:string,inputPerMillion:float,outputPerMillion:float,cachedInputPerMillion:?float,source:string,effectiveDate:string}|null */
    private static function findEntry(string $provider, string $model): ?array
    {
        $modelLower = strtolower($model);
        foreach (self::TABLE as $entry) {
            if ($entry['provider'] !== $provider) {
                continue;
            }
            if ($entry['modelPrefix'] === '' || str_starts_with($modelLower, strtolower($entry['modelPrefix']))) {
                return $entry;
            }
        }
        return null;
    }

    public static function isKnownModel(string $provider, string $model): bool
    {
        return self::findEntry($provider, $model) !== null;
    }
}
