<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * NavClient::queryInvoiceDigest()/queryInvoiceData() tesztek — a HTTP réteg
 * MINDEN esetben mockolt (lásd NavClientTest.php docblockja, ugyanaz az
 * elv), itt kizárólag a kérés-építést és a válasz-értelmezést ellenőrizzük.
 * A TÉNYLEGES lapozási CIKLUST (több oldal egymás után lekérdezve) a
 * NavIncomingInvoiceSyncTest bizonyítja — ez a fájl csak azt igazolja, hogy
 * EGY konkrét oldalra adott NAV-válasz helyesen értelmeződik.
 */
final class NavClientIncomingTest extends TestCase
{
    private function fakeCfg(): array
    {
        return [
            'nav_login' => 'teszt_login', 'nav_password' => 'teszt_jelszo',
            'nav_signer_key' => 'ce-8f5e-215119fa7dd621DLMRHRLH2S', 'nav_exchange_key' => 'ABCDEFGHIJKLMNOP',
            'nav_tax_number' => '12345678', 'nav_test_mode' => true,
        ];
    }

    private function digestResponseXml(int $currentPage, int $availablePage, array $digests): string
    {
        $digestXml = '';
        foreach ($digests as $d) {
            $digestXml .= '<invoiceDigest>';
            foreach ($d as $tag => $value) {
                $digestXml .= "<$tag>" . htmlspecialchars((string) $value, ENT_XML1) . "</$tag>";
            }
            $digestXml .= '</invoiceDigest>';
        }

        return '<?xml version="1.0" encoding="UTF-8"?>'
            . '<QueryInvoiceDigestResponse xmlns:common="http://schemas.nav.gov.hu/NTCA/1.0/common" xmlns="http://schemas.nav.gov.hu/OSA/3.0/api">'
            . '<common:result><funcCode>OK</funcCode></common:result>'
            . "<currentPage>$currentPage</currentPage><availablePage>$availablePage</availablePage>"
            . "<invoiceDigestResult><currentPage>$currentPage</currentPage><availablePage>$availablePage</availablePage>$digestXml</invoiceDigestResult>"
            . '</QueryInvoiceDigestResponse>';
    }

    private function sampleDigest(array $overrides = []): array
    {
        return array_merge([
            'invoiceNumber' => 'SUPP-2026-001',
            'invoiceOperation' => 'CREATE',
            'invoiceCategory' => 'NORMAL',
            'invoiceIssueDate' => '2026-09-01',
            'supplierTaxNumber' => '87654321',
            'supplierName' => 'Beszállító Kft',
            'currency' => 'HUF',
            'invoiceNetAmount' => '10000',
            'invoiceVatAmount' => '2700',
            'insDate' => '2026-09-01T10:00:00.000Z',
        ], $overrides);
    }

    // ---- queryInvoiceDigest ----

    public function testQueryInvoiceDigestSendsInsDateWindowAndDirection(): void
    {
        $captured = null;
        $client = new NavClient($this->fakeCfg(), function (string $url, string $xml) use (&$captured) {
            $captured = ['url' => $url, 'xml' => $xml];
            return ['status' => 200, 'body' => $this->digestResponseXml(1, 1, [])];
        });

        $client->queryInvoiceDigest('2026-08-01T00:00:00Z', '2026-09-01T00:00:00Z', 'INBOUND', 1);

        $this->assertStringContainsString('/queryInvoiceDigest', $captured['url']);
        $this->assertStringContainsString('<invoiceDirection>INBOUND</invoiceDirection>', $captured['xml']);
        $this->assertStringContainsString('<dateTimeFrom>2026-08-01T00:00:00Z</dateTimeFrom>', $captured['xml']);
        $this->assertStringContainsString('<dateTimeTo>2026-09-01T00:00:00Z</dateTimeTo>', $captured['xml']);
        $this->assertStringContainsString('<page>1</page>', $captured['xml']);
    }

    public function testQueryInvoiceDigestParsesFullFieldSet(): void
    {
        $digest = $this->sampleDigest([
            'batchIndex' => '2', 'supplierGroupMemberTaxNumber' => '11111111', 'customerTaxNumber' => '12345678',
            'customerName' => 'Vevő Kft', 'paymentMethod' => 'TRANSFER', 'paymentDate' => '2026-09-15',
            'invoiceAppearance' => 'ELECTRONIC', 'source' => 'XML', 'invoiceDeliveryDate' => '2026-09-01',
            'invoiceNetAmountHUF' => '10000', 'invoiceVatAmountHUF' => '2700', 'transactionId' => 'TX123',
            'index' => '1', 'originalInvoiceNumber' => 'SUPP-2026-000', 'modificationIndex' => '1',
            'completenessIndicator' => 'true',
        ]);
        $client = new NavClient($this->fakeCfg(), fn () => ['status' => 200, 'body' => $this->digestResponseXml(1, 1, [$digest])]);

        $result = $client->queryInvoiceDigest('2026-08-01T00:00:00Z', '2026-09-01T00:00:00Z', 'INBOUND', 1);

        $this->assertTrue($result['success']);
        $this->assertCount(1, $result['invoices']);
        $row = $result['invoices'][0];
        $this->assertSame('SUPP-2026-001', $row['invoice_number']);
        $this->assertSame('2', $row['batch_index']);
        $this->assertSame('CREATE', $row['invoice_operation']);
        $this->assertSame('NORMAL', $row['invoice_category']);
        $this->assertSame('87654321', $row['supplier_tax_number']);
        $this->assertSame('11111111', $row['supplier_group_member_tax_number']);
        $this->assertSame('Beszállító Kft', $row['supplier_name']);
        $this->assertSame('12345678', $row['customer_tax_number']);
        $this->assertSame('Vevő Kft', $row['customer_name']);
        $this->assertSame('TRANSFER', $row['payment_method']);
        $this->assertSame('2026-09-15', $row['payment_date']);
        $this->assertSame('HUF', $row['currency']);
        $this->assertSame('10000', $row['invoice_net_amount']);
        $this->assertSame('2700', $row['invoice_vat_amount']);
        $this->assertSame('TX123', $row['transaction_id']);
        $this->assertSame('SUPP-2026-000', $row['original_invoice_number']);
        $this->assertSame('1', $row['modification_index']);
        $this->assertSame('2026-09-01T10:00:00.000Z', $row['ins_date']);
        $this->assertSame('true', $row['completeness_indicator']);
        $this->assertSame('1', $result['current_page']);
        $this->assertSame('1', $result['available_page']);
    }

    public function testQueryInvoiceDigestEmptyResultReturnsEmptyArray(): void
    {
        $client = new NavClient($this->fakeCfg(), fn () => ['status' => 200, 'body' => $this->digestResponseXml(1, 1, [])]);

        $result = $client->queryInvoiceDigest('2026-08-01T00:00:00Z', '2026-09-01T00:00:00Z', 'INBOUND', 1);

        $this->assertTrue($result['success']);
        $this->assertSame([], $result['invoices']);
    }

    public function testQueryInvoiceDigestSecondPageReturnsDistinctInvoices(): void
    {
        $page1 = $this->digestResponseXml(1, 2, [$this->sampleDigest(['invoiceNumber' => 'SUPP-2026-001'])]);
        $page2 = $this->digestResponseXml(2, 2, [$this->sampleDigest(['invoiceNumber' => 'SUPP-2026-002'])]);

        $client = new NavClient($this->fakeCfg(), function (string $url, string $xml) use ($page1, $page2) {
            $requestedPage = (preg_match('/<page>(\d+)<\/page>/', $xml, $m) === 1) ? (int) $m[1] : 1;
            return ['status' => 200, 'body' => $requestedPage === 2 ? $page2 : $page1];
        });

        $result1 = $client->queryInvoiceDigest('2026-08-01T00:00:00Z', '2026-09-01T00:00:00Z', 'INBOUND', 1);
        $result2 = $client->queryInvoiceDigest('2026-08-01T00:00:00Z', '2026-09-01T00:00:00Z', 'INBOUND', 2);

        $this->assertSame('SUPP-2026-001', $result1['invoices'][0]['invoice_number']);
        $this->assertSame('2', $result1['available_page']);
        $this->assertSame('SUPP-2026-002', $result2['invoices'][0]['invoice_number']);
        $this->assertSame('2', $result2['current_page']);
    }

    public function testQueryInvoiceDigestMalformedResponseSurfacesError(): void
    {
        $body = '<?xml version="1.0"?><QueryInvoiceDigestResponse xmlns:common="http://schemas.nav.gov.hu/NTCA/1.0/common" xmlns="http://schemas.nav.gov.hu/OSA/3.0/api">'
            . '<common:result><funcCode>ERROR</funcCode><errorCode>SCHEMA_VIOLATION</errorCode><message>Rossz kérés.</message></common:result>'
            . '</QueryInvoiceDigestResponse>';
        $client = new NavClient($this->fakeCfg(), fn () => ['status' => 400, 'body' => $body]);

        $result = $client->queryInvoiceDigest('2026-08-01T00:00:00Z', '2026-09-01T00:00:00Z', 'INBOUND', 1);

        $this->assertFalse($result['success']);
        $this->assertSame('SCHEMA_VIOLATION', $result['nav_error_code']);
        $this->assertSame([], $result['invoices']);
    }

    public function testQueryInvoiceDigestNetworkFailureSurfacesAsFailure(): void
    {
        $client = new NavClient($this->fakeCfg(), function () {
            throw new RuntimeException('NAV kapcsolati hiba: connection refused');
        });

        $result = $client->queryInvoiceDigest('2026-08-01T00:00:00Z', '2026-09-01T00:00:00Z', 'INBOUND', 1);

        $this->assertFalse($result['success']);
        $this->assertNull($result['http_status']);
        $this->assertStringContainsString('connection refused', $result['error']);
    }

    // ---- queryInvoiceData ----

    public function testQueryInvoiceDataSendsInvoiceNumberQuery(): void
    {
        $captured = null;
        $body = '<?xml version="1.0"?><QueryInvoiceDataResponse xmlns:common="http://schemas.nav.gov.hu/NTCA/1.0/common" xmlns="http://schemas.nav.gov.hu/OSA/3.0/api">'
            . '<common:result><funcCode>OK</funcCode></common:result>'
            . '<invoiceDataResult><invoiceData>' . base64_encode('<InvoiceData/>') . '</invoiceData><compressedContentIndicator>false</compressedContentIndicator></invoiceDataResult>'
            . '</QueryInvoiceDataResponse>';
        $client = new NavClient($this->fakeCfg(), function (string $url, string $xml) use (&$captured, $body) {
            $captured = ['url' => $url, 'xml' => $xml];
            return ['status' => 200, 'body' => $body];
        });

        $result = $client->queryInvoiceData('SUPP-2026-001', 'INBOUND', '87654321', 2);

        $this->assertStringContainsString('/queryInvoiceData', $captured['url']);
        $this->assertStringContainsString('<invoiceNumber>SUPP-2026-001</invoiceNumber>', $captured['xml']);
        $this->assertStringContainsString('<invoiceDirection>INBOUND</invoiceDirection>', $captured['xml']);
        $this->assertStringContainsString('<batchIndex>2</batchIndex>', $captured['xml']);
        $this->assertStringContainsString('<supplierTaxNumber>87654321</supplierTaxNumber>', $captured['xml']);
        $this->assertTrue($result['success']);
        $this->assertTrue($result['found']);
        $this->assertFalse($result['compressed']);
        $this->assertSame(base64_encode('<InvoiceData/>'), $result['invoice_data_base64']);
    }

    public function testQueryInvoiceDataOmitsOptionalFieldsWhenNotProvided(): void
    {
        $captured = null;
        $body = '<?xml version="1.0"?><QueryInvoiceDataResponse xmlns:common="http://schemas.nav.gov.hu/NTCA/1.0/common" xmlns="http://schemas.nav.gov.hu/OSA/3.0/api">'
            . '<common:result><funcCode>OK</funcCode></common:result>'
            . '</QueryInvoiceDataResponse>';
        $client = new NavClient($this->fakeCfg(), function (string $url, string $xml) use (&$captured, $body) {
            $captured = $xml;
            return ['status' => 200, 'body' => $body];
        });

        $result = $client->queryInvoiceData('SUPP-2026-001', 'INBOUND');

        $this->assertStringNotContainsString('<batchIndex>', $captured);
        $this->assertStringNotContainsString('<supplierTaxNumber>', $captured);
        $this->assertTrue($result['success']);
        $this->assertFalse($result['found']);
        $this->assertNull($result['invoice_data_base64']);
    }

    public function testQueryInvoiceDataCompressedIndicatorTrue(): void
    {
        $body = '<?xml version="1.0"?><QueryInvoiceDataResponse xmlns:common="http://schemas.nav.gov.hu/NTCA/1.0/common" xmlns="http://schemas.nav.gov.hu/OSA/3.0/api">'
            . '<common:result><funcCode>OK</funcCode></common:result>'
            . '<invoiceDataResult><invoiceData>' . base64_encode('compressed-bytes') . '</invoiceData><compressedContentIndicator>true</compressedContentIndicator></invoiceDataResult>'
            . '</QueryInvoiceDataResponse>';
        $client = new NavClient($this->fakeCfg(), fn () => ['status' => 200, 'body' => $body]);

        $result = $client->queryInvoiceData('SUPP-2026-001', 'INBOUND');

        $this->assertTrue($result['compressed']);
    }

    public function testQueryInvoiceDataMalformedResponseSurfacesError(): void
    {
        $body = '<?xml version="1.0"?><QueryInvoiceDataResponse xmlns:common="http://schemas.nav.gov.hu/NTCA/1.0/common" xmlns="http://schemas.nav.gov.hu/OSA/3.0/api">'
            . '<common:result><funcCode>ERROR</funcCode><errorCode>INVALID_REQUEST</errorCode><message>Nincs ilyen számla.</message></common:result>'
            . '</QueryInvoiceDataResponse>';
        $client = new NavClient($this->fakeCfg(), fn () => ['status' => 400, 'body' => $body]);

        $result = $client->queryInvoiceData('NOEXIST-001', 'INBOUND');

        $this->assertFalse($result['success']);
        $this->assertSame('INVALID_REQUEST', $result['nav_error_code']);
        $this->assertFalse($result['found']);
    }
}
