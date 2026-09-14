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

    // ---- invoiceReference (MODIFY/STORNO, 1.1.0) ----

    public function testCreateHasNoInvoiceReferenceBlock(): void
    {
        $doc = $this->parse(NavInvoiceXmlBuilder::build($this->sampleParams()));
        $this->assertCount(0, $doc->xpath('//d:invoiceReference'), 'CREATE-nél (invoice_reference nélkül) TILOS invoiceReference blokkot írni.');
    }

    public function testModifyInvoiceReferenceFieldsPresent(): void
    {
        $doc = $this->parse(NavInvoiceXmlBuilder::build($this->sampleParams([
            'invoice_reference' => [
                'original_invoice_number' => 'FT-NAV-2026-000001',
                'modification_index' => 2,
            ],
        ])));

        $this->assertSame('FT-NAV-2026-000001', $this->xpathValue($doc, '//d:invoiceReference/d:originalInvoiceNumber'));
        $this->assertSame('false', $this->xpathValue($doc, '//d:invoiceReference/d:modifyWithoutMaster'), 'modifyWithoutMaster alapértelmezetten false (a Stock Manager sose hivatkozik NAV-nál ismeretlen eredetire).');
        $this->assertSame('2', $this->xpathValue($doc, '//d:invoiceReference/d:modificationIndex'));
    }

    public function testModifyWithoutMasterCanBeExplicitlyTrue(): void
    {
        $doc = $this->parse(NavInvoiceXmlBuilder::build($this->sampleParams([
            'invoice_reference' => [
                'original_invoice_number' => 'FT-NAV-2026-000001',
                'modification_index' => 1,
                'modify_without_master' => true,
            ],
        ])));

        $this->assertSame('true', $this->xpathValue($doc, '//d:invoiceReference/d:modifyWithoutMaster'));
    }

    /**
     * invoiceData.xsd InvoiceType xs:sequence — az invoiceReference az
     * invoice ELSŐ gyermeke, MEGELŐZI az invoiceHead-et (lásd
     * NavInvoiceXmlBuilder::writeInvoiceReference() docblockja). Ezt a
     * konkrét sorrendet a document-order alapú xpath-index ellenőrzi.
     */
    public function testInvoiceReferencePrecedesInvoiceHeadInDocumentOrder(): void
    {
        $xml = NavInvoiceXmlBuilder::build($this->sampleParams([
            'invoice_reference' => ['original_invoice_number' => 'FT-NAV-2026-000001', 'modification_index' => 1],
        ]));

        $referencePos = strpos($xml, '<invoiceReference>');
        $headPos = strpos($xml, '<invoiceHead>');

        $this->assertNotFalse($referencePos);
        $this->assertNotFalse($headPos);
        $this->assertLessThan($headPos, $referencePos, 'Az invoiceReference-nek MEG KELL előznie az invoiceHead-et a dokumentumban.');
    }

    /**
     * VALÓDI NAV sandbox hívással igazolt kötelező mező (lásd
     * NavInvoiceXmlBuilder::writeLineModificationReference() docblockja)
     * — enélkül a NAV "Tételsort tartalmazó módosító okirat esetén a
     * tételsor módosítás jellegének megadása kötelező" hibával utasítja
     * el a kérést.
     */
    public function testModifyLinesIncludeLineModificationReferenceWithCreateOperation(): void
    {
        $doc = $this->parse(NavInvoiceXmlBuilder::build($this->sampleParams([
            'invoice_reference' => ['original_invoice_number' => 'FT-NAV-2026-000001', 'modification_index' => 1, 'line_number_offset' => 1],
        ])));

        $this->assertSame('2', $this->xpathValue($doc, '//d:line/d:lineModificationReference/d:lineNumberReference'), 'lineNumberReference = offset(1) + a dokumentum saját lineNumber-e(1) = 2.');
        // A NAV MÁSODIK sandbox-körben konkrétan ABORTED-del utasította el
        // a 'MODIFY' lineOperation-t — kizárólag 'CREATE' fogadható el.
        $this->assertSame('CREATE', $this->xpathValue($doc, '//d:line/d:lineModificationReference/d:lineOperation'));
    }

    public function testCreateLinesHaveNoLineModificationReference(): void
    {
        $doc = $this->parse(NavInvoiceXmlBuilder::build($this->sampleParams()));
        $this->assertCount(0, $doc->xpath('//d:lineModificationReference'), 'CREATE-nél (invoice_reference nélkül) TILOS lineModificationReference-t írni.');
    }

    public function testLineModificationReferenceImmediatelyFollowsLineNumber(): void
    {
        $xml = NavInvoiceXmlBuilder::build($this->sampleParams([
            'invoice_reference' => ['original_invoice_number' => 'FT-NAV-2026-000001', 'modification_index' => 1, 'line_number_offset' => 0],
        ]));
        $lineNumberPos = strpos($xml, '<lineNumber>');
        $refPos = strpos($xml, '<lineModificationReference>');
        $exprPos = strpos($xml, '<lineExpressionIndicator>');
        $this->assertNotFalse($refPos);
        $this->assertLessThan($refPos, $lineNumberPos);
        $this->assertLessThan($exprPos, $refPos, 'A LineType xs:sequence szerint a lineModificationReference közvetlenül a lineNumber UTÁN, a lineExpressionIndicator ELŐTT áll.');
    }

    public function testLineNumberOffsetAppliesPerLineForMultipleItems(): void
    {
        $doc = $this->parse(NavInvoiceXmlBuilder::build($this->sampleParams([
            'items' => [
                ['name' => 'Termék A', 'qty' => 1, 'unit_price_gross' => 1270.0, 'vat_rate' => '27'],
                ['name' => 'Termék B', 'qty' => 1, 'unit_price_gross' => 500.0, 'vat_rate' => '27'],
            ],
            'invoice_reference' => ['original_invoice_number' => 'FT-NAV-2026-000001', 'modification_index' => 1, 'line_number_offset' => 3],
        ])));

        $refs = $doc->xpath('//d:line/d:lineModificationReference/d:lineNumberReference');
        $this->assertSame(['4', '5'], array_map('strval', $refs));
    }

    public function testStornoUsesSameInvoiceReferenceStructureAsModify(): void
    {
        // A kör 7. pontja szerint a STORNO invoiceReference-blokkja
        // strukturálisan AZONOS a MODIFY-éval — a builder maga nem tesz
        // különbséget MODIFY/STORNO között (az envelope-szintű
        // invoiceOperation dönti el, lásd NavClient::manageInvoiceOperation()),
        // ezt a builder-szintű egyenértékűséget rögzíti ez a teszt.
        $doc = $this->parse(NavInvoiceXmlBuilder::build($this->sampleParams([
            'invoice_reference' => ['original_invoice_number' => 'FT-NAV-2026-000042', 'modification_index' => 3],
        ])));

        $this->assertSame('FT-NAV-2026-000042', $this->xpathValue($doc, '//d:invoiceReference/d:originalInvoiceNumber'));
        $this->assertSame('3', $this->xpathValue($doc, '//d:invoiceReference/d:modificationIndex'));
    }
}
