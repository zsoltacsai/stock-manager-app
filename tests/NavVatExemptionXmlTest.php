<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * B-09 (correctness audit) regresszió — a NAV Online Számla v3 XML ÁFA-
 * leképezése.
 *
 * A Stock Manager ÁFA-kódjai (termék-, beszerzés-, kassza-űrlap és
 * Beállítások választói): '27', '18', '5', '0', 'AAM', 'TAM'. Korábban
 * minden nem numerikus kód csendben <vatPercentage>0.0000</vatPercentage>
 * lett (adóköteles 0%). Most: numerikus → vatPercentage (változatlan),
 * AAM/TAM → <vatExemption><case/><reason/></vatExemption>
 * (DetailedReasonType), minden más → kivétel (a NAV-provider végleges,
 * nem újrapróbálható hibaként kezeli, NAV-hívás nélkül).
 *
 * FONTOS KORLÁT: ez strukturális (XML-elemzéses) teszt a v3 invoiceData
 * VatRateType szerkezetére — élő NAV (teszt)környezetben NEM lett
 * ellenőrizve.
 */
final class NavVatExemptionXmlTest extends TestCase
{
    private const NS = 'http://schemas.nav.gov.hu/OSA/3.0/data';

    private function build(array $items): DOMXPath
    {
        $xml = NavInvoiceXmlBuilder::build([
            'invoice_number' => 'SMTEST-VAT-1',
            'supplier' => ['tax_number' => '12345678', 'name' => 'Teszt Kft', 'zip' => '6720', 'city' => 'Szeged', 'address' => 'Fő utca 1.'],
            'buyer' => ['nev' => 'Teszt Vevő', 'irsz' => '1111', 'telepules' => 'Budapest', 'cim' => 'Fő utca 1.', 'adoszam' => null],
            'items' => $items,
            'payment_method' => 'Készpénz',
        ]);
        $doc = new DOMDocument();
        $this->assertTrue($doc->loadXML($xml), 'Jól formált XML');
        $xp = new DOMXPath($doc);
        $xp->registerNamespace('d', self::NS);
        return $xp;
    }

    private function item(string $vat, float $gross = 1000.0): array
    {
        return ['name' => "Tétel $vat", 'qty' => 1, 'unit_price_gross' => $gross, 'vat_rate' => $vat];
    }

    /** A VatRateType xs:choice — pontosan EGY gyermekelem. */
    private function choiceChildren(DOMXPath $xp, DOMNode $vatRate): array
    {
        $names = [];
        foreach ($vatRate->childNodes as $child) {
            if ($child instanceof DOMElement) {
                $names[] = $child->localName;
            }
        }
        return $names;
    }

    public static function numericRates(): array
    {
        return [['27', '0.2700'], ['18', '0.1800'], ['5', '0.0500'], ['0', '0.0000']];
    }

    /** @dataProvider numericRates */
    public function testNumericRatesStayVatPercentage(string $rate, string $expected): void
    {
        $xp = $this->build([$this->item($rate)]);
        $lineRate = $xp->query('//d:line/d:lineAmountsNormal/d:lineVatRate')->item(0);
        $this->assertSame(['vatPercentage'], $this->choiceChildren($xp, $lineRate));
        $this->assertSame($expected, $xp->evaluate('string(//d:line//d:lineVatRate/d:vatPercentage)'));
        $this->assertSame(0, $xp->query('//d:vatExemption')->length);
    }

    public static function exemptionCodes(): array
    {
        return [['AAM', 'Alanyi adómentes'], ['TAM', 'Tárgyi adómentes']];
    }

    /** @dataProvider exemptionCodes */
    public function testExemptionCodesUseVatExemptionNotZeroPercent(string $code, string $reason): void
    {
        $xp = $this->build([$this->item($code)]);

        $lineRate = $xp->query('//d:line/d:lineAmountsNormal/d:lineVatRate')->item(0);
        $this->assertSame(['vatExemption'], $this->choiceChildren($xp, $lineRate), 'Nem lehet vatPercentage mellette (xs:choice).');
        $exemption = $xp->query('d:vatExemption', $lineRate)->item(0);
        $this->assertSame(['case', 'reason'], $this->choiceChildren($xp, $exemption), 'DetailedReasonType: case, majd reason.');
        $this->assertSame($code, $xp->evaluate('string(d:case)', $exemption));
        $this->assertSame($reason, $xp->evaluate('string(d:reason)', $exemption));

        $this->assertSame('1000.00', $xp->evaluate('string(//d:lineNetAmount)'), 'Adómentes tételnél nettó = bruttó.');
        $this->assertSame('0.00', $xp->evaluate('string(//d:lineVatAmount)'));

        $summaryRate = $xp->query('//d:summaryByVatRate/d:vatRate')->item(0);
        $this->assertSame(['vatExemption'], $this->choiceChildren($xp, $summaryRate));
        $this->assertSame($code, $xp->evaluate('string(//d:summaryByVatRate/d:vatRate/d:vatExemption/d:case)'));
        $this->assertSame('0.00', $xp->evaluate('string(//d:summaryByVatRate/d:vatRateVatData/d:vatRateVatAmount)'));
    }

    public function testLowercaseExemptionCodeIsRecognised(): void
    {
        $xp = $this->build([$this->item(' aam ')]);
        $this->assertSame('AAM', $xp->evaluate('string(//d:line//d:vatExemption/d:case)'));
    }

    public function testMixedInvoiceKeepsZeroPercentAndExemptionsInSeparateSummaryGroups(): void
    {
        $xp = $this->build([$this->item('27', 1270.0), $this->item('0'), $this->item('AAM'), $this->item('TAM'), $this->item('AAM', 500.0)]);

        $groups = $xp->query('//d:summaryByVatRate');
        $this->assertSame(4, $groups->length, '27%, 0%, AAM, TAM — négy külön csoport.');
        $this->assertSame('1500.00', $xp->evaluate("string(//d:summaryByVatRate[d:vatRate/d:vatExemption/d:case='AAM']/d:vatRateNetData/d:vatRateNetAmount)"));
        $this->assertSame('1000.00', $xp->evaluate("string(//d:summaryByVatRate[d:vatRate/d:vatPercentage='0.0000']/d:vatRateNetData/d:vatRateNetAmount)"));
        $this->assertSame('270.00', $xp->evaluate('string(//d:invoiceSummary//d:invoiceVatAmount)'));
        $this->assertSame('4770.00', $xp->evaluate('string(//d:invoiceGrossAmount)'));
    }

    public static function invalidCodes(): array
    {
        return [[''], ['ABC'], ['27%'], ['-5'], ['150'], ['KBAET'], ['#N/A']];
    }

    /** @dataProvider invalidCodes */
    public function testUnknownOrInvalidVatCodeIsRejectedNotSentAsZeroPercent(string $code): void
    {
        $this->expectException(InvalidArgumentException::class);
        NavInvoiceXmlBuilder::build([
            'invoice_number' => 'X', 'supplier' => ['tax_number' => '12345678', 'name' => 'T', 'zip' => '1', 'city' => 'x', 'address' => 'x'],
            'buyer' => ['nev' => 'V', 'irsz' => '1', 'telepules' => 'x', 'cim' => 'x'], 'items' => [$this->item($code)],
        ]);
    }

    public function testEveryVatCodeOfferedInTheUiIsMapped(): void
    {
        $offered = [];
        foreach (['webroot/termekek.php', 'webroot/beszerzes.php', 'webroot/index.php', 'webroot/beallitasok.php'] as $file) {
            preg_match_all('/<select id="(?:p-vat|manual-item-vat|szamlazz-default-vat)">(.*?)<\/select>/s', file_get_contents(dirname(__DIR__) . '/' . $file), $selects);
            $this->assertNotEmpty($selects[1], "ÁFA-választó: $file");
            foreach ($selects[1] as $select) {
                preg_match_all('/<option value="([^"]*)"/', $select, $m);
                foreach ($m[1] as $code) {
                    $offered[$code] = true;
                }
            }
        }
        $this->assertEqualsCanonicalizing(['27', '18', '5', '0', 'AAM', 'TAM'], array_map('strval', array_keys($offered)));
        foreach (array_keys($offered) as $code) {
            $category = NavInvoiceXmlBuilder::vatCategory((string) $code);
            $this->assertSame(is_numeric($code) ? 'percentage' : 'exemption', $category['type'], "Kód: $code");
        }
    }

    public function testProviderTreatsUnknownVatCodeAsPermanentFailureWithoutCallingNav(): void
    {
        $calls = 0;
        $transport = function () use (&$calls) {
            $calls++;
            return ['status' => 500, 'body' => ''];
        };
        $navConfig = ['nav_login' => 't', 'nav_password' => 't', 'nav_signer_key' => 'teszt-signer-key-1234567890', 'nav_exchange_key' => 'ABCDEFGHIJKLMNOP', 'nav_tax_number' => '12345678', 'nav_test_mode' => true];
        $supplier = ['tax_number' => '12345678', 'name' => 'Teszt Kft', 'zip' => '6720', 'city' => 'Szeged', 'address' => 'Fő utca 1.'];
        $provider = new NavInvoiceProvider($navConfig, $supplier, new NavTokenCache(sys_get_temp_dir() . '/sm_navtoken_vat_' . bin2hex(random_bytes(4)) . '.json'), fn () => new NavClient($navConfig, $transport));

        $row = ['payload_json' => json_encode(['buyer' => ['nev' => 'V', 'irsz' => '1111', 'telepules' => 'Bp', 'cim' => 'x'], 'items' => [$this->item('XYZ')], 'payment_method' => 'Készpénz', 'supplier' => $supplier]), 'invoice_number' => 'SM-VAT-1', 'currency' => 'HUF'];
        $result = $provider->submit($row);

        $this->assertSame('permanent', $result['outcome']);
        $this->assertStringContainsString('ÁFA-kód', $result['error']);
        $this->assertSame(0, $calls, 'Hibás ÁFA-kóddal a NAV felé semmi nem megy ki.');
    }
}
