<?php

require_once __DIR__ . '/PriceValidator.php';

class ProductRowNormalizer
{
    private const ALLOWED_VAT_RATES = ['27', '18', '5', '0'];

    /** A számmezők admin felé mutatott neve — lásd validationError(). */
    private const NUMBER_FIELD_LABELS = [
        'stock_qty'          => 'készlet',
        'purchase_price_net' => 'beszerzési ár',
        'net_price'          => 'nettó ár',
        'price'              => 'bruttó ár',
        'vat_rate'           => 'ÁFA%',
    ];

    public static function normalize(array $row, array $profile): array
    {
        // B-10: a profil opcionálisan rögzítheti a forrás tizedesjelét
        // ('decimal_separator' => ',' vagy '.'). Ha nem rögzíti (a jelenlegi
        // profilok egyike sem), a parser csak az egyértelmű alakokat fogadja
        // el — lásd parseNumberStrict().
        $decimalSeparator = $profile['decimal_separator'] ?? null;
        $errors = [];

        $parse = static function (string $field, string $raw) use ($decimalSeparator, &$errors): float {
            $result = self::parseNumberStrict($raw, $decimalSeparator);
            if ($result['error'] !== null) {
                $errors[$field] = ['raw' => $raw, 'reason' => $result['error']];
                return 0.0;
            }
            return $result['value'] ?? 0.0; // üres cella: a meglévő szerződés szerint 0
        };

        $stockQty    = $parse('stock_qty', (string) ($row['stock_qty'] ?? '0'));
        $purchaseNet = $parse('purchase_price_net', (string) ($row['purchase_price_net'] ?? '0'));
        $net         = $parse('net_price', (string) ($row['net_price'] ?? '0'));
        $gross       = $parse('price', (string) ($row['price'] ?? '0'));

        // A készlet a modellben EGÉSZ darabszám — ugyanaz a számnyelvtan,
        // de más szemantika, mint az áraknál: egy törtmennyiség nem
        // kerekíthető csendben (pl. "2,5" → 3). A lebegőpontos XLSX-
        // maradványok (2.9999999999999996) egésznek számítanak.
        if (!isset($errors['stock_qty']) && abs($stockQty - round($stockQty)) > 1e-6) {
            $errors['stock_qty'] = ['raw' => (string) ($row['stock_qty'] ?? ''), 'reason' => 'a készlet csak egész darabszám lehet'];
        }

        // Néhány forrás program (pl. Jutasoft) nem ad külön nettó eladási
        // ár oszlopot, csak bruttó árat és egy sortonkénti ÁFA%-ot — ilyen
        // esetben a nettó árat ebből számítjuk, mielőtt az egyébként is
        // meglévő bruttó/nettó arányból való visszakövetkeztetésre
        // (inferVatRate()) hagyatkoznánk, mert az itt nettó=0 mellett
        // úgyis csak a profil alapértelmezett ÁFA-kulcsát adná vissza.
        $vatRateField = trim((string) ($row['vat_rate'] ?? ''));
        if ($net <= 0 && $gross > 0 && $vatRateField !== '') {
            $vatPercent = $parse('vat_rate', rtrim($vatRateField, " %"));
            if ($vatPercent > 0) {
                $net = round($gross / (1 + $vatPercent / 100), 2);
            }
        }

        return [
            'name'               => trim($row['name'] ?? ''),
            'barcode'            => trim($row['barcode'] ?? ''),
            'cikkszam'           => trim($row['cikkszam'] ?? ''),
            'group_name'         => trim($row['group_name'] ?? ''),
            'unit'               => trim($row['unit'] ?? '') ?: 'db',
            'notes'              => trim($row['notes'] ?? ''),
            'stock_qty'          => (int) round($stockQty),
            'purchase_price_net' => $purchaseNet,
            'net_price'          => $net,
            'price'              => $gross,
            'currency'           => $profile['default_currency'] ?? 'HUF',
            'vat_rate'           => self::inferVatRate($net, $gross, $profile['default_vat_rate'] ?? '27'),
            'number_errors'      => $errors,
        ];
    }

    /**
     * Igaz, ha ezt a (már normalizált) sort NEM szabad tényleges
     * termékként kezelni — se előnézetben számolni "beszúrandó/
     * frissítendő" oszlopba, se ténylegesen importálni. Két oka lehet:
     *   1) nincs név (üres sor / hibás sor) — ez minden profilra érvényes.
     *   2) a profil kifejezetten jelezte (skip_rows_without_identifier),
     *      hogy vonalkód ÉS cikkszám hiányában is ki kell hagyni — ez a
     *      Jutasoft-riport végén lévő összesítő/ÁFA-bontás sorokat szűri
     *      ki, amiknek van "neve" (valójában egy összesítő felirat), de
     *      semmilyen termék-azonosítójuk nincs.
     */
    public static function shouldSkip(array $normalized, array $profile): bool
    {
        if ($normalized['name'] === '') {
            return true;
        }
        if (!empty($profile['skip_rows_without_identifier'])
            && $normalized['barcode'] === ''
            && $normalized['cikkszam'] === ''
        ) {
            return true;
        }
        return false;
    }

    /**
     * @return string|null null, ha a sor szám- és ár-mezői érvényesek,
     *         egyébként egy admin-nak mutatható rövid hibaüzenet. Lásd
     *         PriceValidator docblockja az üzleti szabályért (negatív
     *         TILOS, nulla megengedett). A hívónak (import-commit.php) ezt
     *         a sort NEM szabad importálnia — de a többi, érvényes sort
     *         igen (lásd ott a "soronkénti, nem all-or-nothing" viselkedés
     *         indoklását) —, és NEM keverendő össze shouldSkip()-pel: az
     *         egy MÁS okból (nem valódi termék-sor) hagy ki sorokat,
     *         csendben, hiba nélkül; ez itt egy VALÓDI termék-sort jelez
     *         HIBÁSNAK. B-10: egy nem értelmezhető szám (pl. "#N/A",
     *         kétértelmű "1.234", szöveg) is ide kerül, nem válik 0-vá.
     */
    public static function validationError(array $normalized): ?string
    {
        foreach ($normalized['number_errors'] ?? [] as $field => $error) {
            $label = self::NUMBER_FIELD_LABELS[$field] ?? $field;
            return "Érvénytelen $label ('{$error['raw']}') — {$error['reason']}.";
        }
        foreach ([
            'net_price'          => 'nettó ár',
            'price'               => 'bruttó ár',
            'purchase_price_net' => 'beszerzési ár',
        ] as $field => $label) {
            if (!PriceValidator::isValid($normalized[$field])) {
                return "Érvénytelen $label ({$normalized[$field]}) — negatív ár nem importálható.";
            }
        }
        return null;
    }

    /**
     * Visszafelé kompatibilis, kényelmi változat: az érvényes számot adja,
     * üres/érvénytelen bemenetre 0.0-t. Az import SAJÁT útvonala NEM ezt
     * használja (lásd normalize() → parseNumberStrict()), mert itt az
     * érvénytelen bemenet nem különböztethető meg a valódi 0-tól.
     */
    public static function parseNumber(string $raw): float
    {
        return self::parseNumberStrict($raw)['value'] ?? 0.0;
    }

    /**
     * B-10 — determinisztikus számértelmezés az import forrásaihoz (CSV
     * szövegként; XLSX/XLS numerikus cellák a SpreadsheetNumber kanonikus
     * alakjában). Eredmény: ['value' => ?float, 'error' => ?string];
     * value=null + error=null = üres cella.
     *
     * Elfogadott alakok ($decimalSeparator = null, azaz a forrás
     * tizedesjele NEM ismert — a jelenlegi profilok mind ilyenek):
     *   - egész: "1234", "-5", "+7";
     *   - egyértelmű tizedes: "1234,56", "1234.5", "0,125", "1234.567"
     *     (az egészrész 0-val kezdődik, 4+ jegyű, vagy a tizedesrész nem
     *     pontosan 3 jegyű);
     *   - vegyes elválasztó, a HÁTSÓ a tizedesjel: "1.234,56", "1,234.56";
     *   - ugyanaz az elválasztó többször = ezres csoportosítás:
     *     "12.345.678", "1,234,567"; szóköz/nem törhető szóköz/aposztróf
     *     csoportosítás: "1 234 567", "1 234,56";
     *   - exponens: "1.5E+3" = 1500, "1.0E-2" = 0.01, "1,5E3" = 1500;
     *   - pénznem-jel elöl/hátul: "1200 Ft", "HUF 1200", "1 200,- Ft", "€5".
     * Elutasított (hibaüzenettel): táblázat-hibaértékek ("#N/A",
     * "#VALUE!" …), érvénytelen csoportosítás ("1.23.456"), szöveg, NaN/INF,
     * és a KÉTÉRTELMŰ "1.234" / "1,234" (egyetlen elválasztó, 1–3 jegyű
     * egészrész, pontosan 3 tizedesjegy) — ez a forrás locale-jától
     * függően 1234 vagy 1,234 is lehet, ezért csak akkor értelmezhető, ha
     * a profil rögzíti a tizedesjelet ($decimalSeparator ',' vagy '.').
     */
    public static function parseNumberStrict(string $raw, ?string $decimalSeparator = null): array
    {
        $fail = static fn (string $reason): array => ['value' => null, 'error' => $reason];

        $s = trim(str_replace(["\u{00A0}", "\u{202F}", "\u{2009}", "\t"], ' ', $raw));
        if ($s === '') {
            return ['value' => null, 'error' => null];
        }
        if (strlen($s) > 64 || !mb_check_encoding($s, 'UTF-8')) {
            return $fail('nem értelmezhető szám');
        }
        if ($s[0] === '#') {
            return $fail('táblázatkezelő-hibaérték, nem szám');
        }

        // Pénznem-jel és a magyar ",-" kerekítés-jelölés.
        $s = (string) preg_replace('/^(?:HUF|EUR|Ft|€)\s*/iu', '', $s);
        $s = (string) preg_replace('/\s*(?:HUF|EUR|Ft\.?|€)$/iu', '', $s);
        $s = (string) preg_replace('/,-$/', '', trim($s));

        $negative = false;
        if ($s !== '' && ($s[0] === '-' || $s[0] === '+')) {
            $negative = $s[0] === '-';
            $s = ltrim(substr($s, 1));
        }
        if ($s === '') {
            return $fail('nem értelmezhető szám');
        }

        if (preg_match('/^(\d+)(?:[.,](\d+))?[eE]([+-]?\d{1,3})$/', $s, $m)) {
            $value = (float) ($m[1] . '.' . ($m[2] ?? '0') . 'E' . $m[3]);
            if (!is_finite($value)) {
                return $fail('nem értelmezhető szám');
            }
            return ['value' => $negative ? -$value : $value, 'error' => null];
        }

        if (!preg_match('/^\d(?:[\d., \']*\d)?$/', $s)) {
            return $fail('nem értelmezhető szám');
        }

        $hasDot = str_contains($s, '.');
        $hasComma = str_contains($s, ',');
        $hasSpaceGroup = str_contains($s, ' ') || str_contains($s, "'");

        if ($decimalSeparator === ',' || $decimalSeparator === '.') {
            $decimal = $decimalSeparator;
        } elseif ($hasDot && $hasComma) {
            $decimal = strrpos($s, '.') > strrpos($s, ',') ? '.' : ',';
        } elseif ($hasDot || $hasComma) {
            $sep = $hasDot ? '.' : ',';
            if (substr_count($s, $sep) > 1) {
                $decimal = null; // többszörös = ezres csoportosítás
            } else {
                [$intPart, $fracPart] = explode($sep, $s, 2);
                if (!$hasSpaceGroup && strlen($fracPart) === 3 && preg_match('/^[1-9]\d{0,2}$/', $intPart)) {
                    return $fail("kétértelmű szám — a forrás tizedesjele nem ismert, lehet 1000-es csoportosítás vagy tizedes");
                }
                $decimal = $sep;
            }
        } else {
            $decimal = null;
        }

        if ($decimal !== null && substr_count($s, $decimal) > 1) {
            return $fail('több tizedesjel');
        }
        [$intPart, $fracPart] = $decimal !== null && str_contains($s, $decimal)
            ? explode($decimal, $s, 2)
            : [$s, null];

        if ($fracPart !== null && !preg_match('/^\d+$/', $fracPart)) {
            return $fail('érvénytelen tizedesrész');
        }

        // Az egészrész: vagy csupa számjegy, vagy EGYFÉLE csoportosító
        // jellel szabályos 3-as csoportok (az első 1–3 jegyű).
        if (!preg_match('/^\d+$/', $intPart)) {
            $groupChars = array_values(array_unique(array_filter(str_split(preg_replace('/\d/', '', $intPart)))));
            if (count($groupChars) !== 1) {
                return $fail('érvénytelen ezres csoportosítás');
            }
            $groups = explode($groupChars[0], $intPart);
            if (!preg_match('/^[1-9]\d{0,2}$/', $groups[0])) {
                return $fail('érvénytelen ezres csoportosítás');
            }
            foreach (array_slice($groups, 1) as $group) {
                if (!preg_match('/^\d{3}$/', $group)) {
                    return $fail('érvénytelen ezres csoportosítás');
                }
            }
            $intPart = implode('', $groups);
        }

        $value = (float) ($intPart . ($fracPart !== null ? '.' . $fracPart : ''));
        if (!is_finite($value)) {
            return $fail('nem értelmezhető szám');
        }
        return ['value' => $negative ? -$value : $value, 'error' => null];
    }

    private static function inferVatRate(float $net, float $gross, string $default): string
    {
        if ($net <= 0) {
            return $default;
        }
        $percent = round((($gross / $net) - 1) * 100);
        foreach (self::ALLOWED_VAT_RATES as $allowed) {
            if (abs($percent - (float) $allowed) < 1.0) {
                return $allowed;
            }
        }
        return $default;
    }
}
