<?php

declare(strict_types=1);

require_once __DIR__ . '/InvoiceConsistencyAssertions.php';

use PHPUnit\Framework\TestCase;

/**
 * N-5 (post-remediation audit) — a számla-XML GENERÁLÁS helyessége, a
 * szolgáltatói HTTP-viselkedéstől elválasztva: a Számlázz.hu Agent XML-t a
 * SzamlazzClient saját (privát) builderével, a NAV InvoiceData XML-t a
 * NavInvoiceXmlBuilder-rel állítjuk elő, és a tényleges XML-t parse-oljuk.
 * A szolgáltatói HTTP-út (sale.php → InvoiceService → provider → loopback
 * stub) külön teszt: InvoiceSaleValueConsistencyHttpTest.
 *
 * Elvárás minden esetre: a számlasorok összege fillérre = az eladás közös
 * ÁFA-bontása (Database::vatBreakdown(), ugyanaz, amit a napi zárás és az
 * értékesítési riport használ), összesen és ÁFA-kulcsonként; minden soron
 * nettó + ÁFA = bruttó és egységár × mennyiség = nettó.
 */
final class VatAllocationInvoiceTest extends TestCase
{
    use InvoiceConsistencyAssertions;

    private const BUYER = ['nev' => 'Teszt Vevő', 'irsz' => '1111', 'telepules' => 'Budapest', 'cim' => 'Fő utca 1.'];

    private function szamlazzXml(array $items): string
    {
        $client = new SzamlazzClient([
            'agent_key' => 't', 'endpoint' => 'http://127.0.0.1:9/nem-hivodik', 'e_invoice' => true, 'download_pdf' => false, 'send_email' => false,
            'payment_method' => 'Készpénz', 'currency' => 'HUF', 'language' => 'hu', 'default_vat_rate' => '27', 'unit_label' => 'db',
            'default_buyer' => self::BUYER, 'pdf_dir' => sys_get_temp_dir(),
        ]);
        $m = new ReflectionMethod(SzamlazzClient::class, 'buildInvoiceXml');
        $m->setAccessible(true);
        return $m->invoke($client, self::BUYER, $items, 'n5-test');
    }

    private function navXml(array $items): string
    {
        return NavInvoiceXmlBuilder::build([
            'invoice_number' => 'N5-0001',
            'supplier' => ['tax_number' => '12345678', 'name' => 'Teszt Kft', 'zip' => '6720', 'city' => 'Szeged', 'address' => 'Kossuth Lajos sugárút 12.'],
            'buyer' => self::BUYER + ['adoszam' => null],
            'items' => $items,
            'payment_method' => 'Készpénz',
        ]);
    }

    /** @param list<array{0:float,1:int,2:string}> $spec [kedvezmény előtti egységár, db, kulcs] */
    private static function lines(array $spec): array
    {
        return array_map(fn ($l) => ['name' => 'Tétel ' . $l[2], 'unit_price' => $l[0], 'qty' => $l[1], 'vat_rate' => $l[2]], $spec);
    }

    /** Egy eladás (érték + kedvezmény előtti sorok) mindkét számla-XML-je = a közös bontás. */
    private function assertBothInvoicesMatchSale(float $value, array $lines, string $label): array
    {
        $breakdown = Database::vatBreakdown($value, $lines);
        $items = VatAllocation::invoiceItems($value, $lines);

        $sz = self::szamlazzXmlLines($this->szamlazzXml($items));
        $this->assertInvoiceLinesWellFormed($sz, "$label / Számlázz.hu");
        $this->assertInvoiceMatchesBreakdown($sz, $breakdown, "$label / Számlázz.hu");

        $nav = self::navXmlLines($this->navXml($items));
        $this->assertInvoiceLinesWellFormed($nav, "$label / NAV");
        $this->assertInvoiceMatchesBreakdown($nav, $breakdown, "$label / NAV");

        $totals = VatAllocation::totals($items);
        $this->assertSame([self::cents($breakdown['net']), self::cents($breakdown['vat']), self::cents($breakdown['gross'])],
            [self::cents($totals['net']), self::cents($totals['vat']), self::cents($totals['gross'])], "$label: tükör-összegek");
        return $sz;
    }

    // ------------------------------------------------------------------
    // Kötelező N-5 reprodukció
    // ------------------------------------------------------------------

    public function testN5ReproductionThreeTimesTenWithTwentyCouponGeneratesATenForintInvoice(): void
    {
        // Egy sor, 3 db × 10 Ft, 20 Ft kupon → az eladás értéke 10.00.
        $lines = self::lines([[10.0, 3, '27']]);
        $sz = $this->assertBothInvoicesMatchSale(10.0, $lines, '3×10 / 20 kupon (egy sor)');
        $sum = self::sumLines($sz);
        $this->assertSame(['net' => 787, 'vat' => 213, 'gross' => 1000], ['net' => $sum['net'], 'vat' => $sum['vat'], 'gross' => $sum['gross']], 'Számla: bruttó 10.00, nettó 7.87, ÁFA 2.13 (korábban 9.99 / 7.86).');

        // A tényleges XML-sorok: a 3 db két alsorra bomlik, 1 fillérnyi egységár-eltéréssel.
        $this->assertSame([
            ['mennyiseg' => '2', 'nettoEgysegar' => '2.62', 'afakulcs' => '27', 'nettoErtek' => '5.24', 'afaErtek' => '1.42', 'bruttoErtek' => '6.66'],
            ['mennyiseg' => '1', 'nettoEgysegar' => '2.63', 'afakulcs' => '27', 'nettoErtek' => '2.63', 'afaErtek' => '0.71', 'bruttoErtek' => '3.34'],
        ], array_column($sz, 'raw'));

        // Ugyanez három külön 1 db-os sorral (az audit kosara).
        $sz3 = $this->assertBothInvoicesMatchSale(10.0, self::lines([[10.0, 1, '27'], [10.0, 1, '27'], [10.0, 1, '27']]), '3×10 / 20 kupon (három sor)');
        $this->assertSame(['3.34', '3.33', '3.33'], array_column(array_column($sz3, 'raw'), 'bruttoErtek'));
        $this->assertSame(1000, self::sumLines($sz3)['gross']);
        $this->assertSame(787, self::sumLines($sz3)['net']);
    }

    public function testMultiQuantityLineWithoutDiscountNoLongerLosesACent(): void
    {
        // Kedvezmény nélkül is: 3 × 10 Ft 27% — a riport nettója 23.62, a régi
        // egységár-alapú számla 3 × 7.87 = 23.61 lett volna.
        $sz = $this->assertBothInvoicesMatchSale(30.0, self::lines([[10.0, 3, '27']]), '3×10 kedvezmény nélkül');
        $this->assertSame(['net' => 2362, 'vat' => 638, 'gross' => 3000], array_intersect_key(self::sumLines($sz), ['net' => 1, 'vat' => 1, 'gross' => 1]));
    }

    // ------------------------------------------------------------------
    // Esetek: sorok, kulcsok, kedvezmény, utalvány, szélsőértékek
    // ------------------------------------------------------------------

    /** @return array<string, array{0: float, 1: list<array{0:float,1:int,2:string}>}> */
    public static function saleCases(): array
    {
        return [
            'egy sor, kedvezmény nélkül' => [1270.0, [[1270.0, 1, '27']]],
            'több azonos sor' => [30.0, [[10.0, 1, '27'], [10.0, 1, '27'], [10.0, 1, '27']]],
            'több különböző árú sor' => [1234.0, [[199.0, 2, '27'], [349.0, 1, '27'], [487.0, 1, '27']]],
            'több ÁFA-kulcs (27/18/5/0/AAM/TAM)' => [3000.0, [[999.0, 1, '27'], [555.0, 1, '18'], [444.0, 1, '5'], [333.0, 1, '0'], [390.0, 1, 'AAM'], [279.0, 1, 'TAM']]],
            'fix kupon' => [980.0, [[333.0, 3, '27'], [1.0, 1, '5']]],
            'százalékos kupon (10%)' => [899.1, [[333.0, 3, '27']]],
            'pontbeváltás (kedvezmény)' => [985.0, [[500.0, 2, '27']]],
            'kupon + pontbeváltás' => [955.0, [[500.0, 2, '27'], [7.0, 3, '18']]],
            'utalvány (teljes érték = eladás értéke)' => [1270.0, [[1270.0, 1, '27']]],
            'kupon + utalvány, vegyes kulcs' => [1000.0, [[635.0, 2, '27'], [99.0, 3, '5'], [1.0, 1, 'AAM']]],
            'kis tételek 1/2/3 Ft' => [5.0, [[1.0, 1, '27'], [2.0, 1, '27'], [3.0, 1, '27']]],
            '1 Ft-os tétel, 7 db, 2 Ft kedvezmény' => [5.0, [[1.0, 7, '27']]],
            '2 Ft × 3, 18%' => [6.0, [[2.0, 3, '18']]],
            '3 Ft × 7, 5%, 1 Ft kupon' => [20.0, [[3.0, 7, '5']]],
            'kerekítés-nehéz: 99.99 × 7, 33% kedvezmény' => [468.95, [[99.99, 7, '27']]],
            'kerekítés-nehéz vegyes: 0.99 × 13 + 1.01 × 11' => [21.5, [[0.99, 13, '27'], [1.01, 11, '18']]],
            'nulla értékű eladás (100% kedvezmény)' => [0.0, [[10.0, 3, '27']]],
            'AAM egyedül, több db' => [100.0, [[33.34, 3, 'AAM']]],
            'TAM és 0% együtt' => [10.0, [[5.0, 3, 'TAM'], [5.0, 1, '0']]],
        ];
    }

    /** @dataProvider saleCases */
    public function testInvoiceEqualsSaleAllocationForEveryCase(float $value, array $spec): void
    {
        $this->assertBothInvoicesMatchSale($value, self::lines($spec), $this->dataName());
    }

    public function testMixedVatAllocationKeepsEachRatesShare(): void
    {
        $lines = self::lines([[999.0, 1, '27'], [555.0, 1, '18'], [444.0, 1, '5'], [333.0, 1, '0'], [390.0, 1, 'AAM'], [279.0, 1, 'TAM']]);
        $sz = $this->assertBothInvoicesMatchSale(2500.0, $lines, 'vegyes kulcs, kedvezménnyel');
        $byRate = self::sumLines($sz)['by_rate'];
        foreach (['0', 'AAM', 'TAM'] as $exempt) {
            $this->assertSame(0, $byRate[$exempt]['vat'], "$exempt: nincs ÁFA");
            $this->assertSame($byRate[$exempt]['gross'], $byRate[$exempt]['net']);
        }
        // Az arányos kedvezmény kulcsonként: 2500 / 3000 a kedvezmény előtti sor-bruttókból.
        $gross = array_map(fn ($r) => $r['gross'], $byRate);
        ksort($gross);
        $expected = ['27' => 83250, '18' => 46250, '5' => 37000, '0' => 27750, 'AAM' => 32500, 'TAM' => 23250];
        ksort($expected);
        $this->assertSame($expected, $gross, 'kulcsonkénti bruttó');
        // Sor-szintű nettó: 832.50/1.27 = 655.51, 462.50/1.18 = 391.95, 370.00/1.05 = 352.38.
        $this->assertSame([65551, 39195, 35238], [$byRate['27']['net'], $byRate['18']['net'], $byRate['5']['net']]);
    }

    public function testSeededRandomSalesMatchTheSharedAllocationExactly(): void
    {
        mt_srand(20260925);
        $rates = ['27', '18', '5', '0', 'AAM', 'TAM'];
        for ($n = 0; $n < 150; $n++) {
            $spec = [];
            $subtotal = 0.0;
            for ($i = 0, $k = mt_rand(1, 5); $i < $k; $i++) {
                $price = mt_rand(0, 3) === 0 ? mt_rand(1, 3) : mt_rand(1, 250000) / 100;
                $qty = mt_rand(1, 9);
                $spec[] = [$price, $qty, $rates[mt_rand(0, 5)]];
                $subtotal += $price * $qty;
            }
            $value = round($subtotal * mt_rand(0, 100) / 100, 2);
            $this->assertBothInvoicesMatchSale($value, self::lines($spec), "véletlen #$n (" . json_encode([$value, $spec]) . ')');
        }
    }

    // ------------------------------------------------------------------
    // Maradék fillér, determinisztikusság, reprezentáció
    // ------------------------------------------------------------------

    public function testResidualCentAllocationIsDeterministic(): void
    {
        $lines = self::lines([[10.0, 1, '27'], [10.0, 1, '27'], [10.0, 1, '27'], [7.0, 3, '18']]);
        $first = VatAllocation::invoiceItems(37.77, $lines);
        for ($i = 0; $i < 20; $i++) {
            $this->assertSame($first, VatAllocation::invoiceItems(37.77, $lines), 'Ugyanarra a bemenetre mindig ugyanaz a sor kapja a maradékot.');
        }
        $this->assertSame($this->szamlazzXml($first), $this->szamlazzXml(VatAllocation::invoiceItems(37.77, $lines)));
    }

    public function testSplitRepresentationUsesAtMostThreeSubLinesPerSaleLine(): void
    {
        foreach ([[1.0, 7], [2.0, 9], [99.99, 7], [0.01, 5], [1234.56, 3]] as [$price, $qty]) {
            foreach (['27', '18', '5', '0', 'AAM'] as $rate) {
                foreach ([1.0, 0.77, 0.5] as $ratio) {
                    $value = round($price * $qty * $ratio, 2);
                    $items = VatAllocation::invoiceItems($value, self::lines([[$price, $qty, $rate]]));
                    $this->assertLessThanOrEqual(3, count($items));
                    $this->assertSame($qty, (int) array_sum(array_column($items, 'qty')), 'A mennyiség nem változik.');
                    foreach ($items as $it) {
                        $this->assertGreaterThanOrEqual(0, self::cents($it['line_vat']), 'Nincs negatív ÁFA-jú alsor.');
                    }
                }
            }
        }
        // Osztható értéknél nincs bontás.
        $this->assertCount(1, VatAllocation::invoiceItems(30.0, self::lines([[10.0, 3, '0']])));
    }

    public function testGiftCardPaidSaleInvoiceUsesTheFullSaleValue(): void
    {
        // B-06: a teljesen utalvánnyal fizetett eladás értéke a tételek értéke
        // (az utalvány fizetési eszköz) — a számla sem 0 Ft-os.
        $db = tests_new_database();
        $saleId = $db->insertSale(0.0, 'Készpénz', null, null, 0, 0, null, 0.0, 1270.0, null);
        $db->insertSaleItem($saleId, ['product_id' => null, 'name' => 'Utalványos', 'qty' => 1, 'unit_price' => 1270, 'vat_rate' => '27']);
        $sale = $db->getSaleWithItems($saleId);
        $value = Database::saleGrossValue($sale);
        $this->assertSame(1270.0, $value);
        $sz = $this->assertBothInvoicesMatchSale($value, $sale['items'], 'utalvány');
        $this->assertSame(127000, self::sumLines($sz)['gross']);
    }

    public function testStornoOfAnAllocatedInvoiceReversesExactlyTheIssuedAmounts(): void
    {
        $items = VatAllocation::invoiceItems(10.0, self::lines([[10.0, 3, '27']]));
        $reversed = VatAllocation::negateItems($items);
        $orig = self::sumLines(self::navXmlLines($this->navXml($items)));
        $storno = self::sumLines(self::navXmlLines($this->navXml($reversed)));
        foreach (['net', 'vat', 'gross'] as $k) {
            $this->assertSame(-$orig[$k], $storno[$k], "sztornó $k = −eredeti");
        }
        $totals = VatAllocation::totals($reversed);
        $this->assertSame([-787, -213, -1000], [self::cents($totals['net']), self::cents($totals['vat']), self::cents($totals['gross'])]);
    }

    public function testLegacyNonAllocatedItemsStillRenderAsBeforeForStoredPayloads(): void
    {
        // Egy a javítás előtt kiállított számla tárolt payloadja (egységáras
        // tétel, allokáció nélkül) változatlanul renderelődik — a sztornója így
        // pontosan a ténylegesen kiállított összegeket fordítja vissza.
        $legacy = [['name' => 'Régi', 'qty' => 3, 'unit_price_gross' => 3.33, 'vat_rate' => '27']];
        $sz = self::szamlazzXmlLines($this->szamlazzXml($legacy));
        $this->assertSame(['mennyiseg' => '3', 'nettoEgysegar' => '2.62', 'afakulcs' => '27', 'nettoErtek' => '7.86', 'afaErtek' => '2.13', 'bruttoErtek' => '9.99'], $sz[0]['raw']);
        $reversed = self::sumLines(self::navXmlLines($this->navXml(VatAllocation::negateItems($legacy))));
        $this->assertSame([-786, -213, -999], [$reversed['net'], $reversed['vat'], $reversed['gross']]);
    }
}
