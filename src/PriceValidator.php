<?php

/**
 * Egységes ár-validáció minden termék-ár mezőre (nettó/bruttó eladási ár,
 * beszerzési ár) — 1.1.1, lásd README "Ár-validáció" szakasza. A backend az
 * EGYETLEN hiteles forrás (a kliens-oldali ellenőrzés, ha van, csak UX-
 * segítség, sose helyettesíti ezt) — minden ÍRÁSI útvonalnak (kézi
 * termékszerkesztés, CSV/JutaSoft import, WooCommerce-behúzás) ugyanezt a
 * szabályt kell alkalmaznia, hogy ne csúszhasson szét egymástól függetlenül
 * módosítható validációs logika a különböző útvonalak között.
 *
 * Üzleti szabály: NEGATÍV ár SOSE fogadható el (nincs olyan valós eset,
 * amikor egy terméknek negatív ára lenne). NULLA ár VISZONT megengedett —
 * ez már a bevezetés ELŐTT is előfordult (pl. promóciós/ajándéktermékek),
 * és az import-preview.php meglévő "zero_price" számlálója is csak
 * informatív jelzésnek szánta, sose blokkoló hibának — ezt a meglévő
 * viselkedést a validáció NEM változtatja meg.
 */
class PriceValidator
{
    /**
     * @param mixed $value — lehet már float/int, vagy egy még nem
     *        castolt, felhasználó/import-forrásból származó nyers érték.
     *        NEM numerikus (pl. "abc", null) érték is ÉRVÉNYTELENNEK
     *        számít — enélkül egy hibás cast (pl. (float) "abc" === 0.0)
     *        csendben "nullaárrá" silányítana egy ténylegesen hibás
     *        bemenetet, ami inkább ELUTASÍTANDÓ, mint hallgatólagosan
     *        elfogadott lenne.
     */
    public static function isValid($value): bool
    {
        if (is_bool($value) || $value === null || $value === '') {
            return false;
        }
        if (!is_numeric($value)) {
            return false;
        }
        return (float) $value >= 0;
    }

    /**
     * @return string|null null, ha érvényes, egyébként egy felhasználónak
     *         mutatható hibaüzenet (a $fieldLabel-lel).
     */
    public static function describeError($value, string $fieldLabel): ?string
    {
        if (self::isValid($value)) {
            return null;
        }
        if (!is_numeric($value)) {
            return "Érvénytelen $fieldLabel — számot kell megadni.";
        }
        return "Érvénytelen $fieldLabel — negatív ár nem adható meg.";
    }
}
