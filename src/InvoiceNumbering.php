<?php

/**
 * EGYETLEN, központi hely a számlaszám-formátumhoz — lásd a FountainTrade
 * 1.1.0 MODIFY/STORNO előkészítő körének 6. pontja: "A számlaszám
 * formátuma legyen központilag konfigurálható/definiált, ne szétszórt
 * stringekben." Sem a Database, sem egy jövőbeli provider/XML-builder nem
 * állíthatja elő közvetlenül a végleges string-alakot — mindig ezen az
 * osztályon KERESZTÜL, egy már lefoglalt, nyers sorszámból (lásd
 * Database::allocateInvoiceNumber()).
 *
 * A prefix "FT" a FountainTrade rebrand után — a korábbi NAV-számlák
 * "SM-NAV-..." formátuma (a "Stock Manager" korábbi nevéből) VÁLTOZATLAN
 * marad (a migráció nem generálja újra a meglévő számokat, lásd
 * Database::migrateV22InvoiceOperationsBody() docblockja) — ez a
 * formátum KIZÁRÓLAG az EZUTÁN lefoglalt sorszámokra vonatkozik.
 */
final class InvoiceNumbering
{
    private const PREFIXES = [
        'nav'      => 'FT-NAV',
        'szamlazz' => 'FT-SZLA',
    ];

    /**
     * @param int $number egy MÁR lefoglalt, nyers sorszám (lásd
     *   Database::allocateInvoiceNumber()) — ez a metódus SOSE foglal le
     *   újat, csak formáz.
     * @param ?string $year alapértelmezetten a jelenlegi év — SZÁNDÉKOSAN
     *   csak olvashatósági/dekoratív célú (a sorozat maga SOSE áll vissza
     *   évfordulókor, folyamatosan növekszik, lásd az osztály docblockja
     *   és a migráció "NAV numbering" indoklása) — egy decemberi
     *   allokálású, de januári NAV-feldolgozású számla emiatt a
     *   ALLOKÁLÁS évét mutatja, ugyanaz a viselkedés, mint a korábbi,
     *   id-alapú formátumnál volt.
     */
    public static function format(string $provider, int $number, ?string $year = null): string
    {
        if (!isset(self::PREFIXES[$provider])) {
            throw new InvalidArgumentException("Ismeretlen számlázási szolgáltató a számlaszám-formázáshoz: $provider");
        }
        if ($number < 1) {
            throw new InvalidArgumentException("Érvénytelen (nem pozitív) sorszám: $number");
        }
        $year ??= date('Y');
        return sprintf('%s-%s-%06d', self::PREFIXES[$provider], $year, $number);
    }
}
