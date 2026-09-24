<?php

declare(strict_types=1);

require_once __DIR__ . '/AiUsage.php';

/**
 * Fázis 9 — a kör 15. pontja: KÖZPONTOSÍTOTT, determinisztikus
 * ár-metaadat-tábla — SOSE szórva szét az üzleti logikában. Az árak
 * KIZÁRÓLAG a providerek SAJÁT, hivatalosan közzétett, TÉNYLEGESEN
 * ELLENŐRZÖTT árlistái alapján kerülhetnek ide.
 *
 * Fázis 9-ben ez a tábla KIZÁRÓLAG a `local` (Ollama, $0) bejegyzést
 * tartalmazta — a konfigurált Anthropic/OpenAI modellnevekhez
 * (`claude-sonnet-5`, `gpt-6-sol`) akkor NEM állt rendelkezésre élő,
 * hivatalosan ellenőrzött árlista, kitalált számot pedig a kör 15.
 * pontja explicit tiltott volna.
 *
 * Fázis 10-ben (2026-09-24) EZ a korlát — KIZÁRÓLAG erre a két,
 * ténylegesen konfigurált modellre — feloldásra került: a hivatalos
 * providerdokumentáció-kutatás (lásd a Fázis 10 kör 1./11. pontja)
 * mindkét modellt SZÓ SZERINT megtalálta a providerek SAJÁT,
 * elsődleges árazási oldalán:
 * - Anthropic "Sonnet 5" — claude.com/pricing (az anthropic.com/pricing
 *   erre irányít át) — $2/M bemenet, $10/M kimenet, $0.20/M
 *   cache-olvasás, $2.50/M cache-írás.
 * - OpenAI "gpt-6-sol" — developers.openai.com/api/docs/pricing
 *   (a platform.openai.com/docs/pricing erre irányít át) — $2/M
 *   bemenet, $10/M kimenet, $0.20/M cache-olvasás. UGYANEZT az árat egy
 *   FÜGGETLEN, sajtóhír-alapú keresés is megerősítette (a GPT-6 Sol
 *   2026-09-23-i, kb. 50%-os árcsökkentéséről).
 *
 * `cachedInputPerMillion` KIZÁRÓLAG a cache-OLVASÁS árát tükrözi — az
 * `AiUsage` struktúra jelenleg NEM különbözteti meg a cache-ÍRÁS
 * tokenjeit (Anthropic `cache_creation_input_tokens`-je) a normál
 * bemeneti tokenektől, ezért egy esetleges cache-írás a (drágább,
 * $2.50/M) írási ár helyett tévesen a normál bemeneti áron
 * (alulbecsülve) számolódna — ez egy ISMERT, dokumentált korlát, nem egy
 * hallgatólagos hiba (lásd README "Ismert korlátok", Fázis 10 szakasz).
 *
 * HA egy admin a fentiektől ELTÉRŐ Anthropic/OpenAI modellt konfigurál
 * (pl. egy jövőbeli modellváltás), `estimate()` arra a modellre ismét
 * `null`-t ad — a token-használat (AiUsage) attól függetlenül továbbra is
 * teljes egészében elérhető/naplózott. Az árak USD/1M token egységben
 * szerepelnek, a providerek hivatalos árlistáinak megfelelően.
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

        // Fázis 10 — élő, hivatalos providerdokumentáció-kutatással
        // ellenőrizve (lásd az osztály fenti docblokkja a forrásokért).
        // KIZÁRÓLAG erre a pontos modellnévre illeszkedik (str_starts_with
        // prefix-egyezés) — egy eltérő jövőbeli modellnév automatikusan
        // "nem ismert" marad, amíg valaki ide fel nem veszi a saját,
        // ellenőrzött árát.
        ['provider' => 'anthropic', 'modelPrefix' => 'claude-sonnet-5', 'inputPerMillion' => 2.0, 'outputPerMillion' => 10.0, 'cachedInputPerMillion' => 0.20, 'source' => 'Anthropic hivatalos árlista — claude.com/pricing ("Sonnet 5")', 'effectiveDate' => '2026-09-24'],
        ['provider' => 'openai', 'modelPrefix' => 'gpt-6-sol', 'inputPerMillion' => 2.0, 'outputPerMillion' => 10.0, 'cachedInputPerMillion' => 0.20, 'source' => 'OpenAI hivatalos árlista — developers.openai.com/api/docs/pricing ("gpt-6-sol")', 'effectiveDate' => '2026-09-24'],
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
