<?php

declare(strict_types=1);

/**
 * N-5 — közös assertion-helperek: ugyanaz az üzleti eladás a számlán
 * (a TÉNYLEGESEN generált Számlázz.hu / NAV XML-ben), a riportban és az
 * eladás-szintű ÁFA-bontásban ugyanazzal a nettó/ÁFA/bruttó értékkel
 * jelenik-e meg. Minden összehasonlítás EGÉSZ FILLÉRBEN történik (nincs
 * lebegőpontos tolerancia): 1 fillér eltérés is bukás.
 *
 * Használat: `use InvoiceConsistencyAssertions;` egy TestCase-ben.
 */
trait InvoiceConsistencyAssertions
{
    protected static function cents($value): int
    {
        return (int) round(((float) $value) * 100);
    }

    /**
     * A Számlázz.hu Agent XML tételsorai (xmlszamla / tetelek / tetel).
     *
     * @return list<array{name:string, qty:float, unit_net:int, rate:string, net:int, vat:int, gross:int, raw:array<string,string>}>
     */
    protected static function szamlazzXmlLines(string $xml): array
    {
        $doc = simplexml_load_string($xml);
        if ($doc === false) {
            throw new RuntimeException('Érvénytelen Számlázz.hu XML.');
        }
        $lines = [];
        foreach ($doc->tetelek->tetel as $t) {
            $raw = [];
            foreach (['mennyiseg', 'nettoEgysegar', 'afakulcs', 'nettoErtek', 'afaErtek', 'bruttoErtek'] as $f) {
                $raw[$f] = (string) $t->{$f};
            }
            $lines[] = [
                'name' => (string) $t->megnevezes,
                'qty' => (float) $raw['mennyiseg'],
                'unit_net' => self::cents($raw['nettoEgysegar']),
                'rate' => $raw['afakulcs'],
                'net' => self::cents($raw['nettoErtek']),
                'vat' => self::cents($raw['afaErtek']),
                'gross' => self::cents($raw['bruttoErtek']),
                'raw' => $raw,
            ];
        }
        return $lines;
    }

    /**
     * A NAV InvoiceData XML tételsorai.
     *
     * @return list<array{qty:float, unit_net:int, rate:string, net:int, vat:int, gross:int, raw:array<string,string>}>
     */
    protected static function navXmlLines(string $xml): array
    {
        $doc = simplexml_load_string($xml);
        $doc->registerXPathNamespace('d', 'http://schemas.nav.gov.hu/OSA/3.0/data');
        $lines = [];
        foreach ($doc->xpath('//d:invoiceLines/d:line') as $line) {
            $line->registerXPathNamespace('d', 'http://schemas.nav.gov.hu/OSA/3.0/data');
            $v = static fn (string $p) => (string) ($line->xpath($p)[0] ?? '');
            $rate = $v('d:lineAmountsNormal/d:lineVatRate/d:vatPercentage');
            if ($rate === '') {
                $rate = $v('d:lineAmountsNormal/d:lineVatRate/d:vatExemption/d:case');
            } else {
                $rate = (string) (float) round(((float) $rate) * 100, 2);
            }
            $raw = [
                'quantity' => $v('d:quantity'),
                'unitPrice' => $v('d:unitPrice'),
                'lineNetAmount' => $v('d:lineAmountsNormal/d:lineNetAmountData/d:lineNetAmount'),
                'lineVatAmount' => $v('d:lineAmountsNormal/d:lineVatData/d:lineVatAmount'),
                'lineGrossAmountNormal' => $v('d:lineAmountsNormal/d:lineGrossAmountData/d:lineGrossAmountNormal'),
            ];
            $lines[] = [
                'qty' => (float) $raw['quantity'],
                'unit_net' => self::cents($raw['unitPrice']),
                'rate' => $rate,
                'net' => self::cents($raw['lineNetAmount']),
                'vat' => self::cents($raw['lineVatAmount']),
                'gross' => self::cents($raw['lineGrossAmountNormal']),
                'raw' => $raw,
            ];
        }
        return $lines;
    }

    /** @return array{net:int, vat:int, gross:int, by_rate: array<string, array{net:int, vat:int, gross:int}>} */
    protected static function sumLines(array $lines): array
    {
        $sum = ['net' => 0, 'vat' => 0, 'gross' => 0, 'by_rate' => []];
        foreach ($lines as $l) {
            $rate = self::normalizeRate((string) $l['rate']);
            foreach (['net', 'vat', 'gross'] as $k) {
                $sum[$k] += $l[$k];
                $sum['by_rate'][$rate][$k] = ($sum['by_rate'][$rate][$k] ?? 0) + $l[$k];
            }
        }
        ksort($sum['by_rate']);
        return $sum;
    }

    /** Egy VatAllocation::breakdown() / Database::vatBreakdown() eredmény egész fillérben, kulcsonként. */
    protected static function breakdownCents(array $breakdown): array
    {
        $lines = array_map(fn ($l) => ['rate' => $l['vat_rate'], 'net' => self::cents($l['net']), 'vat' => self::cents($l['vat']), 'gross' => self::cents($l['gross'])], $breakdown['lines']);
        $sum = self::sumLines($lines);
        // A breakdown saját összesítője is fillérre egyezzen a sorokéval.
        $sum['declared'] = ['net' => self::cents($breakdown['net']), 'vat' => self::cents($breakdown['vat']), 'gross' => self::cents($breakdown['gross'])];
        return $sum;
    }

    protected static function normalizeRate(string $rate): string
    {
        $rate = strtoupper(trim($rate));
        return is_numeric($rate) ? (string) (float) $rate : $rate;
    }

    /**
     * Minden számlasoron: nettó + ÁFA = bruttó, és egységár × mennyiség =
     * nettó sorérték (a két tizedesre ábrázolt egységárral), fillérre.
     */
    protected function assertInvoiceLinesWellFormed(array $lines, string $label = ''): void
    {
        $this->assertNotEmpty($lines, "$label: nincs számlasor");
        foreach ($lines as $i => $l) {
            $this->assertSame($l['gross'], $l['net'] + $l['vat'], "$label sor #$i: nettó + ÁFA = bruttó " . json_encode($l['raw']));
            $this->assertSame($l['net'], (int) round($l['unit_net'] * $l['qty']), "$label sor #$i: egységár × mennyiség = nettó " . json_encode($l['raw']));
            foreach ($l['raw'] as $field => $value) {
                if ($value !== '' && is_numeric($value) && !in_array($field, ['mennyiseg', 'quantity'], true)) {
                    $this->assertMatchesRegularExpression('/^-?\d+(\.\d{1,2})?$/', $value, "$label sor #$i: $field legfeljebb két tizedes");
                }
            }
        }
    }

    /**
     * A számla (XML-sorok) összege = az eladás közös ÁFA-bontása, összesen
     * ÉS ÁFA-kulcsonként:
     *   SUM(sor bruttó) == eladás bruttó, SUM(sor nettó) == eladás nettó,
     *   SUM(sor ÁFA) == eladás ÁFA, SUM(nettó + ÁFA) == SUM(bruttó).
     */
    protected function assertInvoiceMatchesBreakdown(array $invoiceLines, array $breakdown, string $label = ''): void
    {
        $inv = self::sumLines($invoiceLines);
        $exp = self::breakdownCents($breakdown);
        $this->assertSame($exp['declared'], ['net' => $exp['net'], 'vat' => $exp['vat'], 'gross' => $exp['gross']], "$label: a bontás összesítője = a sorai");
        $this->assertSame(['net' => $exp['net'], 'vat' => $exp['vat'], 'gross' => $exp['gross']], ['net' => $inv['net'], 'vat' => $inv['vat'], 'gross' => $inv['gross']], "$label: számla vs. eladás (nettó/ÁFA/bruttó, fillér)");
        $this->assertSame($inv['gross'], $inv['net'] + $inv['vat'], "$label: SUM(nettó + ÁFA) == SUM(bruttó)");
        $this->assertSame($exp['by_rate'], $inv['by_rate'], "$label: ÁFA-kulcsonkénti bontás");
    }

    /** Egy riport (napi zárás / értékesítési riport) két pillanatképe közötti különbség fillérben, kulcsonként. */
    protected static function reportDelta(array $before, array $after): array
    {
        $delta = ['net' => self::cents($after['total_net']) - self::cents($before['total_net']),
            'vat' => self::cents($after['total_vat']) - self::cents($before['total_vat']),
            'gross' => self::cents($after['total_gross']) - self::cents($before['total_gross']),
            'by_rate' => []];
        $rates = array_unique(array_merge(array_keys($before['by_vat_rate'] ?? []), array_keys($after['by_vat_rate'] ?? [])));
        foreach ($rates as $rate) {
            $row = [];
            foreach (['net', 'vat', 'gross'] as $k) {
                $row[$k] = self::cents($after['by_vat_rate'][$rate][$k] ?? 0) - self::cents($before['by_vat_rate'][$rate][$k] ?? 0);
            }
            if ($row !== ['net' => 0, 'vat' => 0, 'gross' => 0]) {
                $delta['by_rate'][self::normalizeRate((string) $rate)] = $row;
            }
        }
        ksort($delta['by_rate']);
        return $delta;
    }

    protected function assertInvoiceMatchesReportDelta(array $invoiceLines, array $delta, string $label = ''): void
    {
        $inv = self::sumLines($invoiceLines);
        $this->assertSame(['net' => $delta['net'], 'vat' => $delta['vat'], 'gross' => $delta['gross']], ['net' => $inv['net'], 'vat' => $inv['vat'], 'gross' => $inv['gross']], "$label: számla vs. riport (fillér)");
        $this->assertSame($delta['by_rate'], $inv['by_rate'], "$label: számla vs. riport ÁFA-kulcsonként");
    }
}
