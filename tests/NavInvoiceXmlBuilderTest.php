<?php

declare(strict_types=1);

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * NavInvoiceXmlBuilder tesztek — a NAV invoiceData.xsd szerinti XML
 * ÉPÍTÉSÉT ellenőrzik (kötelező mezők jelenléte, nettó/ÁFA/bruttó
 * matematikai helyesség, több tétel/ÁFA-kulcs, magánszemély vevő), NEM
 * hívnak semmilyen NAV API-t.
 */
final class NavInvoiceXmlBuilderTest extends TestCase
{
    private function sampleParams(array $overrides = []): array
    {
        return array_merge([
            'invoice_number' => 'SMTEST-0001',
            'supplier' => [
                'tax_number' => '12345678',
                'name' => 'Teszt Kft',
                'zip' => '6720',
                'city' => 'Szeged',
                'address' => 'Kossuth Lajos sugárút 12.',
            ],
            'buyer' => [
                'nev' => 'Teszt Vevő',
                'irsz' => '1111',
                'telepules' => 'Budapest',
                'cim' => 'Fő utca 1.',
                'adoszam' => null,
            ],
            'items' => [
                ['name' => 'Termék A', 'qty' => 1, 'unit_price_gross' => 1270.0, 'vat_rate' => '27'],
            ],
            'payment_method' => 'Készpénz',
        ], $overrides);
    }

    private function parse(string $xml): SimpleXMLElement
    {
        $doc = simplexml_load_string($xml);
        $doc->registerXPathNamespace('d', 'http://schemas.nav.gov.hu/OSA/3.0/data');
        $doc->registerXPathNamespace('base', 'http://schemas.nav.gov.hu/OSA/3.0/base');
        return $doc;
    }

    private function xpathValue(SimpleXMLElement $doc, string $path): ?string
    {
        $nodes = $doc->xpath($path);
        return isset($nodes[0]) ? (string) $nodes[0] : null;
    }

    // ---- Kötelező mezők ----

    public function testRequiredTopLevelFieldsPresent(): void
    {
        $doc = $this->parse(NavInvoiceXmlBuilder::build($this->sampleParams()));

        $this->assertSame('SMTEST-0001', $this->xpathValue($doc, '//d:invoiceNumber'));
        $this->assertSame('NORMAL', $this->xpathValue($doc, '//d:invoiceCategory'));
        $this->assertSame('HUF', $this->xpathValue($doc, '//d:currencyCode'));
        $this->assertSame('1', $this->xpathValue($doc, '//d:exchangeRate'));
        $this->assertSame('PAPER', $this->xpathValue($doc, '//d:invoiceAppearance'));
        $this->assertSame('12345678', $this->xpathValue($doc, '//base:taxpayerId'));
    }

    public function testLineRequiredFieldsPresent(): void
    {
        $doc = $this->parse(NavInvoiceXmlBuilder::build($this->sampleParams()));

        $this->assertSame('1', $this->xpathValue($doc, '//d:line/d:lineNumber'));
        $this->assertSame('true', $this->xpathValue($doc, '//d:lineExpressionIndicator'));
        $this->assertSame('PRODUCT', $this->xpathValue($doc, '//d:lineNatureIndicator'));
        $this->assertSame('PIECE', $this->xpathValue($doc, '//d:unitOfMeasure'));
    }

    // ---- Matematikai helyesség ----

    #[DataProvider('vatRateProvider')]
    public function testNetVatGrossMathIsCorrectForEachVatRate(string $vatRate, float $gross, float $expectedNet, float $expectedVat): void
    {
        $doc = $this->parse(NavInvoiceXmlBuilder::build($this->sampleParams([
            'items' => [['name' => 'Tétel', 'qty' => 1, 'unit_price_gross' => $gross, 'vat_rate' => $vatRate]],
        ])));

        $this->assertEqualsWithDelta($expectedNet, (float) $this->xpathValue($doc, '//d:lineNetAmountData/d:lineNetAmount'), 0.005);
        $this->assertEqualsWithDelta($expectedVat, (float) $this->xpathValue($doc, '//d:lineVatData/d:lineVatAmount'), 0.005);
        $this->assertEqualsWithDelta($gross, (float) $this->xpathValue($doc, '//d:lineGrossAmountData/d:lineGrossAmountNormal'), 0.005);
        $this->assertEqualsWithDelta(((float) $vatRate) / 100, (float) $this->xpathValue($doc, '//d:lineVatRate/d:vatPercentage'), 0.0001);
    }

    public static function vatRateProvider(): array
    {
        return [
            '27% VAT'  => ['27', 1270.0, 1000.0, 270.0],
            '18% VAT'  => ['18', 590.0, 500.0, 90.0],
            '5% VAT'   => ['5', 630.0, 600.0, 30.0],
            '0% VAT'   => ['0', 300.0, 300.0, 0.0],
        ];
    }

    public function testMultipleItemsSummaryTotalsMatchSumOfLines(): void
    {
        $doc = $this->parse(NavInvoiceXmlBuilder::build($this->sampleParams([
            'items' => [
                ['name' => 'A', 'qty' => 2, 'unit_price_gross' => 1270.0, 'vat_rate' => '27'], // net 2000, vat 540
                ['name' => 'B', 'qty' => 1, 'unit_price_gross' => 590.0, 'vat_rate' => '18'],   // net 500, vat 90
                ['name' => 'C', 'qty' => 3, 'unit_price_gross' => 630.0, 'vat_rate' => '5'],    // net 1800, vat 90
            ],
        ])));

        $lines = $doc->xpath('//d:invoiceLines/d:line');
        $this->assertCount(3, $lines);

        $this->assertEqualsWithDelta(4300.0, (float) $this->xpathValue($doc, '//d:invoiceSummary/d:summaryNormal/d:invoiceNetAmount'), 0.005);
        $this->assertEqualsWithDelta(720.0, (float) $this->xpathValue($doc, '//d:invoiceSummary/d:summaryNormal/d:invoiceVatAmount'), 0.005);
        $this->assertEqualsWithDelta(5020.0, (float) $this->xpathValue($doc, '//d:invoiceSummary/d:summaryGrossData/d:invoiceGrossAmount'), 0.005);

        $vatGroups = $doc->xpath('//d:summaryByVatRate');
        $this->assertCount(3, $vatGroups, 'Három különböző ÁFA-kulcs esetén három summaryByVatRate csoportnak kell lennie.');
    }

    // ---- Vevő típusok ----

    public function testPrivatePersonBuyerHasNoTaxNumberAndCorrectVatStatus(): void
    {
        $doc = $this->parse(NavInvoiceXmlBuilder::build($this->sampleParams([
            'buyer' => ['nev' => 'Magánszemély Vevő', 'irsz' => '1111', 'telepules' => 'Budapest', 'cim' => 'Fő utca 1.', 'adoszam' => null],
        ])));

        $this->assertSame('PRIVATE_PERSON', $this->xpathValue($doc, '//d:customerInfo/d:customerVatStatus'));
        $this->assertEmpty($doc->xpath('//d:customerVatData'), 'Adószám nélküli magánszemélynél nem szabad customerVatData blokknak lennie.');
        // Élő NAV sandbox manageInvoice hívással igazolt üzleti szabály
        // (lásd NavInvoiceXmlBuilder::writeCustomerInfo() docblockja): a
        // NAV ABORTED-del elutasítja, ha PRIVATE_PERSON vevőnél
        // customerName/customerAddress is szerepel — ugyanígy a NAV saját
        // hivatalos mintája is kizárólag a customerVatStatus mezőt adja
        // meg ilyenkor.
        $this->assertEmpty($doc->xpath('//d:customerName'), 'PRIVATE_PERSON vevőnél a NAV élesben elutasítja a customerName megadását.');
        $this->assertEmpty($doc->xpath('//d:customerAddress'), 'PRIVATE_PERSON vevőnél a NAV élesben elutasítja a customerAddress megadását.');
    }

    public function testDomesticCompanyBuyerHasTaxNumberAndCorrectVatStatus(): void
    {
        $doc = $this->parse(NavInvoiceXmlBuilder::build($this->sampleParams([
            'buyer' => ['nev' => 'Cég Kft', 'irsz' => '1111', 'telepules' => 'Budapest', 'cim' => 'Fő utca 1.', 'adoszam' => '87654321-2-42'],
        ])));

        $this->assertSame('DOMESTIC', $this->xpathValue($doc, '//d:customerInfo/d:customerVatStatus'));
        $this->assertSame('87654321', $this->xpathValue($doc, '//d:customerInfo//base:taxpayerId'));
    }

    public function testHufCurrencyAlwaysUsesExchangeRateOne(): void
    {
        $doc = $this->parse(NavInvoiceXmlBuilder::build($this->sampleParams(['currency' => 'HUF'])));

        $this->assertSame('HUF', $this->xpathValue($doc, '//d:currencyCode'));
        $this->assertSame('1', $this->xpathValue($doc, '//d:exchangeRate'));
    }

    public function testProducedXmlIsWellFormed(): void
    {
        $xml = NavInvoiceXmlBuilder::build($this->sampleParams());
        libxml_use_internal_errors(true);
        $doc = simplexml_load_string($xml);
        $this->assertNotFalse($doc, 'A generált invoiceData XML-nek jólformáltnak kell lennie.');
    }
}
