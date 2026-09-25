<?php

/**
 * B-10 — egy táblázatkezelő (XLSX/XLS) NUMERIKUS cellájának gépi
 * formátumú értékét (pont tizedesjel, ezres-elválasztó nélkül, esetleg
 * exponenssel — pl. "1234.5", "1.5E+3", "12.345") az import köztes CSV-jébe
 * olyan kanonikus alakban írja, amit ProductRowNormalizer::parseNumberStrict()
 * kontextus nélkül is EGYÉRTELMŰEN értelmez:
 *   - exponens → kifejtett tizedes alak ("1.5E+3" → "1500", "1.0E-2" → "0.01");
 *   - pontosan 3 tizedesjegyű, 1–3 jegyű egészrészű érték ("12.345") →
 *     egy záró 0-val kiegészítve ("12.3450") — különben ugyanúgy nézne
 *     ki, mint egy ezres-csoportosított szöveges "12.345" (= 12345), és a
 *     parser kétértelműként elutasítaná.
 * Szöveges és hibacellákat (pl. "#N/A") NEM kap — azok változatlanul
 * mennek tovább, és a parser dönt róluk.
 */
final class SpreadsheetNumber
{
    public static function canonical(string $machine): string
    {
        $s = trim($machine);
        if (!preg_match('/^-?\d+(\.\d+)?([eE][+-]?\d+)?$/', $s)) {
            return $s;
        }
        if (stripos($s, 'e') !== false) {
            $value = (float) $s;
            if (!is_finite($value)) {
                return $s;
            }
            $s = rtrim(rtrim(sprintf('%.10F', $value), '0'), '.');
            if ($s === '-0') {
                $s = '0';
            }
        }
        if (preg_match('/^-?[1-9]\d{0,2}\.\d{3}$/', $s)) {
            $s .= '0';
        }
        return $s;
    }
}
