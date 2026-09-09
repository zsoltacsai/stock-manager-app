<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * NavIncomingInvoiceSync tesztek — ablak-darabolás (35 napos korlát),
 * lapozás, race-safe deduplikáció, módosítás/sztornó kapcsolat, LAZY
 * részletnézet-lekérdezés (tételsorok + szállító ország), hiba-osztályozás.
 * A HTTP réteg mindenütt mockolt (NavClientTest.php/NavClientIncomingTest.php
 * elvét követve) — a VALÓS, több-folyamatos konkurrencia-bizonyítékot lásd
 * DatabaseTest.php::testIncomingInvoiceSyncClaimIsAtomicAcrossRealConcurrentProcesses().
 */
final class NavIncomingInvoiceSyncTest extends TestCase
{
    private function fakeCfg(): array
    {
        return [
            'nav_login' => 'teszt', 'nav_password' => 'teszt', 'nav_signer_key' => 'teszt-signer-key-1234567890',
            'nav_exchange_key' => 'ABCDEFGHIJKLMNOP', 'nav_tax_number' => '12345678', 'nav_test_mode' => true,
        ];
    }

    /** @param callable(string $xml): array{status:int,body:string} $handler */
    private function syncWith(Database $db, callable $handler): NavIncomingInvoiceSync
    {
        $clientFactory = fn () => new NavClient($this->fakeCfg(), function (string $url, string $xml) use ($handler) {
            return $handler($xml);
        });
        return new NavIncomingInvoiceSync($db, $clientFactory);
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
            . "<invoiceDigestResult><currentPage>$currentPage</currentPage><availablePage>$availablePage</availablePage>$digestXml</invoiceDigestResult>"
            . '</QueryInvoiceDigestResponse>';
    }

    private function errorResponseXml(string $errorCode, int $status = 400): array
    {
        $body = '<?xml version="1.0"?><QueryInvoiceDigestResponse xmlns:common="http://schemas.nav.gov.hu/NTCA/1.0/common" xmlns="http://schemas.nav.gov.hu/OSA/3.0/api">'
            . "<common:result><funcCode>ERROR</funcCode><errorCode>$errorCode</errorCode><message>hiba</message></common:result>"
            . '</QueryInvoiceDigestResponse>';
        return ['status' => $status, 'body' => $body];
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

    // ---- Ablak-darabolás (35 napos korlát) ----

    public function testSplitWindowsSingleWindowForShortRange(): void
    {
        $windows = NavIncomingInvoiceSync::splitWindows('2026-08-01T00:00:00Z', '2026-08-10T00:00:00Z');
        $this->assertCount(1, $windows);
        $this->assertSame('2026-08-01T00:00:00Z', $windows[0]['from']);
        $this->assertSame('2026-08-10T00:00:00Z', $windows[0]['to']);
    }

    public function testSplitWindowsProducesMultipleWindowsForOver35DayRange(): void
    {
        // 2026-01-01 .. 2026-04-01 = 90 nap -> ceil(90/35) = 3 ablak.
        $windows = NavIncomingInvoiceSync::splitWindows('2026-01-01T00:00:00Z', '2026-04-01T00:00:00Z');

        $this->assertCount(3, $windows);
        foreach ($windows as $w) {
            $days = (strtotime($w['to']) - strtotime($w['from'])) / 86400;
            $this->assertLessThanOrEqual(35, $days, 'egyik ablak sem lépheti túl a 35 napot');
        }
        // Hézagmentes, folytonos lefedés: az egyik ablak "to"-ja a következő "from"-ja.
        $this->assertSame($windows[0]['to'], $windows[1]['from']);
        $this->assertSame($windows[1]['to'], $windows[2]['from']);
        $this->assertSame('2026-01-01T00:00:00Z', $windows[0]['from']);
        $this->assertSame('2026-04-01T00:00:00Z', $windows[2]['to']);
    }

    public function testSplitWindowsEmptyWhenFromNotBeforeTo(): void
    {
        $this->assertSame([], NavIncomingInvoiceSync::splitWindows('2026-08-10T00:00:00Z', '2026-08-10T00:00:00Z'));
        $this->assertSame([], NavIncomingInvoiceSync::splitWindows('2026-08-10T00:00:00Z', '2026-08-01T00:00:00Z'));
    }

    // ---- Lapozás ----

    public function testRunOnceProcessesSecondPageOfAWindow(): void
    {
        $db = tests_new_database();
        $sync = $this->syncWith($db, function (string $xml) {
            $page = (preg_match('/<page>(\d+)<\/page>/', $xml, $m) === 1) ? (int) $m[1] : 1;
            if ($page === 1) {
                return ['status' => 200, 'body' => $this->digestResponseXml(1, 2, [$this->sampleDigest(['invoiceNumber' => 'SUPP-2026-001'])])];
            }
            return ['status' => 200, 'body' => $this->digestResponseXml(2, 2, [$this->sampleDigest(['invoiceNumber' => 'SUPP-2026-002'])])];
        });

        $result = $sync->runOnce('2026-08-01T00:00:00Z', '2026-08-05T00:00:00Z', 5);

        $this->assertSame('success', $result['outcome']);
        $rows = $db->listIncomingInvoices();
        $this->assertCount(2, $rows);
        $numbers = array_column($rows, 'invoice_number');
        $this->assertContains('SUPP-2026-001', $numbers);
        $this->assertContains('SUPP-2026-002', $numbers);
    }

    public function testRunOnceStopsWithFailedWhenPageLimitExceeded(): void
    {
        $db = tests_new_database();
        $callCount = 0;
        // A NAV MINDIG azt jelzi, hogy van egy KÖVETKEZŐ oldal is
        // (availablePage=999) — ez malformált/szokatlan válasz-szimuláció,
        // a MAX_PAGES_PER_WINDOW (50) korlátnak kell megállítania a ciklust,
        // NEM szabad végtelenül lapoznia.
        $sync = $this->syncWith($db, function (string $xml) use (&$callCount) {
            $callCount++;
            return ['status' => 200, 'body' => $this->digestResponseXml(1, 999, [])];
        });

        $result = $sync->runOnce('2026-08-01T00:00:00Z', '2026-08-05T00:00:00Z', 5);

        $this->assertSame('failed', $result['outcome']);
        $this->assertLessThanOrEqual(51, $callCount, 'a lapozásnak a biztonsági korlátnál meg kellett állnia');
        $this->assertStringContainsString('biztonsági korlát', (string) $result['error']);
    }

    // ---- Deduplikáció ----

    public function testRunOnceDoesNotDuplicateSameInvoiceOnRepeatedSync(): void
    {
        $db = tests_new_database();
        $sync = $this->syncWith($db, fn () => ['status' => 200, 'body' => $this->digestResponseXml(1, 1, [$this->sampleDigest()])]);

        $sync->runOnce('2026-08-01T00:00:00Z', '2026-08-05T00:00:00Z', 5);
        // Ugyanazt az ablakot, ugyanazt a digestet MÉG EGYSZER lekérdezzük
        // (pl. egy megszakadt/manuálisan újraindított sync) — a UNIQUE
        // (supplier_tax_number, invoice_number, batch_index) kulcsnak
        // véednie kell a duplikációt.
        $sync->runOnce('2026-08-01T00:00:00Z', '2026-08-05T00:00:00Z', 5);

        $rows = $db->listIncomingInvoices();
        $this->assertCount(1, $rows);
    }

    // ---- Cursor-előrehaladás ablakonként, korlátozott windows/run ----

    public function testRunOnceAdvancesCursorPerWindowAndRespectsMaxWindows(): void
    {
        $db = tests_new_database();
        $sync = $this->syncWith($db, fn () => ['status' => 200, 'body' => $this->digestResponseXml(1, 1, [])]);

        // 2026-01-01 .. 2026-04-01 -> 3 ablak (lásd fenti split-teszt), de
        // csak 2 ablakot engedünk feldolgozni egy futás alatt.
        $result = $sync->runOnce('2026-01-01T00:00:00Z', '2026-04-01T00:00:00Z', 2);

        $this->assertSame('success', $result['outcome']);
        $this->assertSame(2, $result['windows_processed']);
        $this->assertTrue($result['has_more']);
        // A cursor a MÁSODIK ablak végénél áll, NEM a harmadikénál (amit
        // nem dolgoztunk fel) és NEM a kezdőpontnál (részleges haladás
        // nem veszik el).
        $expectedWindows = NavIncomingInvoiceSync::splitWindows('2026-01-01T00:00:00Z', '2026-04-01T00:00:00Z');
        $this->assertSame($expectedWindows[1]['to'], $result['new_cursor']);
    }

    // ---- Hiba után megőrzött részleges haladás ----

    public function testRunOnceOnErrorPreservesProgressFromEarlierSuccessfulWindows(): void
    {
        $db = tests_new_database();
        $callIndex = 0;
        $sync = $this->syncWith($db, function (string $xml) use (&$callIndex) {
            $callIndex++;
            // Az 1. hívás (1. ablak) sikeres, a 2. hívás (2. ablak) hálózati hibát dob.
            if ($callIndex === 1) {
                return ['status' => 200, 'body' => $this->digestResponseXml(1, 1, [])];
            }
            throw new RuntimeException('NAV kapcsolati hiba: timeout');
        });

        $result = $sync->runOnce('2026-01-01T00:00:00Z', '2026-04-01T00:00:00Z', 5);

        $this->assertSame('retry', $result['outcome']);
        $this->assertSame(1, $result['windows_processed']);
        $expectedWindows = NavIncomingInvoiceSync::splitWindows('2026-01-01T00:00:00Z', '2026-04-01T00:00:00Z');
        $this->assertSame($expectedWindows[0]['to'], $result['new_cursor']);

        $state = $db->getIncomingInvoiceSyncState('nav');
        $this->assertSame($expectedWindows[0]['to'], $state['sync_cursor_ins_date']);
    }

    public function testRunOnceClassifiesNonRetryableErrorAsFailed(): void
    {
        $db = tests_new_database();
        $sync = $this->syncWith($db, fn () => $this->errorResponseXml('SCHEMA_VIOLATION'));

        $result = $sync->runOnce('2026-08-01T00:00:00Z', '2026-08-05T00:00:00Z', 5);

        $this->assertSame('failed', $result['outcome']);
    }

    public function testRunOnceClassifiesServerErrorAsRetry(): void
    {
        $db = tests_new_database();
        $sync = $this->syncWith($db, fn () => $this->errorResponseXml('UNKNOWN_TRANSIENT', 500));

        $result = $sync->runOnce('2026-08-01T00:00:00Z', '2026-08-05T00:00:00Z', 5);

        $this->assertSame('retry', $result['outcome']);
    }

    // ---- Módosítás/sztornó kapcsolat ----

    public function testModificationDigestStoresOriginalInvoiceNumberLink(): void
    {
        $db = tests_new_database();
        $original = $this->sampleDigest(['invoiceNumber' => 'SUPP-2026-001', 'invoiceOperation' => 'CREATE']);
        $modification = $this->sampleDigest([
            'invoiceNumber' => 'SUPP-2026-001-M1', 'invoiceOperation' => 'MODIFY',
            'originalInvoiceNumber' => 'SUPP-2026-001', 'modificationIndex' => '1',
        ]);
        $sync = $this->syncWith($db, fn () => ['status' => 200, 'body' => $this->digestResponseXml(1, 1, [$original, $modification])]);

        $sync->runOnce('2026-08-01T00:00:00Z', '2026-08-05T00:00:00Z', 5);

        $modRow = null;
        foreach ($db->listIncomingInvoices() as $row) {
            if ($row['invoice_number'] === 'SUPP-2026-001-M1') {
                $modRow = $row;
            }
        }
        $this->assertNotNull($modRow);
        $this->assertSame('MODIFY', $modRow['invoice_operation']);
        $this->assertSame('SUPP-2026-001', $modRow['original_invoice_number']);

        $originalRow = $db->findIncomingInvoiceBySupplierAndNumber('87654321', 'SUPP-2026-001');
        $this->assertNotNull($originalRow);
        $this->assertSame('CREATE', $originalRow['invoice_operation']);
    }

    // ---- LAZY részletnézet-lekérdezés ----

    private function sampleInvoiceDataXml(): string
    {
        return '<?xml version="1.0" encoding="UTF-8"?>'
            . '<InvoiceData xmlns="http://schemas.nav.gov.hu/OSA/3.0/data" xmlns:base="http://schemas.nav.gov.hu/OSA/3.0/base">'
            . '<invoiceNumber>SUPP-2026-001</invoiceNumber>'
            . '<invoiceMain><invoice><invoiceHead>'
            . '<supplierInfo><supplierName>Beszállító Kft</supplierName>'
            . '<supplierAddress><base:detailedAddress><base:countryCode>HU</base:countryCode><base:postalCode>6720</base:postalCode><base:city>Szeged</base:city></base:detailedAddress></supplierAddress>'
            . '</supplierInfo>'
            . '</invoiceHead>'
            . '<invoiceLines>'
            . '<line><lineNumber>1</lineNumber><lineDescription>Termék A</lineDescription><quantity>2</quantity><unitOfMeasure>PIECE</unitOfMeasure><unitPrice>1000.00</unitPrice>'
            . '<lineAmountsNormal><lineNetAmountData><lineNetAmount>2000.00</lineNetAmount></lineNetAmountData>'
            . '<lineVatRate><vatPercentage>0.2700</vatPercentage></lineVatRate>'
            . '<lineVatData><lineVatAmount>540.00</lineVatAmount></lineVatData>'
            . '<lineGrossAmountData><lineGrossAmountNormal>2540.00</lineGrossAmountNormal></lineGrossAmountData>'
            . '</lineAmountsNormal></line>'
            . '</invoiceLines>'
            . '</invoice></invoiceMain>'
            . '</InvoiceData>';
    }

    private function insertSampleIncomingInvoice(Database $db): array
    {
        $seedSync = $this->syncWith($db, fn () => ['status' => 200, 'body' => $this->digestResponseXml(1, 1, [$this->sampleDigest()])]);
        $seedSync->runOnce('2026-08-01T00:00:00Z', '2026-08-05T00:00:00Z', 5);

        $row = $db->findIncomingInvoiceBySupplierAndNumber('87654321', 'SUPP-2026-001');
        $this->assertNotNull($row);
        return $row;
    }

    public function testFetchAndStoreDetailParsesItemsAndSupplierCountry(): void
    {
        $db = tests_new_database();
        $row = $this->insertSampleIncomingInvoice($db);

        $xml = $this->sampleInvoiceDataXml();
        $body = '<?xml version="1.0"?><QueryInvoiceDataResponse xmlns:common="http://schemas.nav.gov.hu/NTCA/1.0/common" xmlns="http://schemas.nav.gov.hu/OSA/3.0/api">'
            . '<common:result><funcCode>OK</funcCode></common:result>'
            . '<invoiceDataResult><invoiceData>' . base64_encode($xml) . '</invoiceData><compressedContentIndicator>false</compressedContentIndicator></invoiceDataResult>'
            . '</QueryInvoiceDataResponse>';
        $sync = $this->syncWith($db, fn () => ['status' => 200, 'body' => $body]);

        $result = $sync->fetchAndStoreDetail($row);

        $this->assertSame('success', $result['outcome']);
        $this->assertSame(1, $result['items_count']);

        $updated = $db->getIncomingInvoiceById((int) $row['id']);
        $this->assertSame('HU', $updated['supplier_country']);
        $this->assertNotNull($updated['detail_fetched_at']);

        $items = $db->getIncomingInvoiceItems((int) $row['id']);
        $this->assertCount(1, $items);
        $this->assertSame('Termék A', $items[0]['description']);
        $this->assertSame('27', $items[0]['vat_rate']);
        $this->assertEquals(2000.00, (float) $items[0]['net_amount']);
        $this->assertEquals(540.00, (float) $items[0]['vat_amount']);
        $this->assertEquals(2540.00, (float) $items[0]['gross_amount']);
    }

    public function testFetchAndStoreDetailHandlesGzipCompressedContent(): void
    {
        $db = tests_new_database();
        $row = $this->insertSampleIncomingInvoice($db);

        $xml = $this->sampleInvoiceDataXml();
        $compressed = gzencode($xml);
        $body = '<?xml version="1.0"?><QueryInvoiceDataResponse xmlns:common="http://schemas.nav.gov.hu/NTCA/1.0/common" xmlns="http://schemas.nav.gov.hu/OSA/3.0/api">'
            . '<common:result><funcCode>OK</funcCode></common:result>'
            . '<invoiceDataResult><invoiceData>' . base64_encode($compressed) . '</invoiceData><compressedContentIndicator>true</compressedContentIndicator></invoiceDataResult>'
            . '</QueryInvoiceDataResponse>';
        $sync = $this->syncWith($db, fn () => ['status' => 200, 'body' => $body]);

        $result = $sync->fetchAndStoreDetail($row);

        $this->assertSame('success', $result['outcome']);
        $items = $db->getIncomingInvoiceItems((int) $row['id']);
        $this->assertCount(1, $items);
    }

    public function testFetchAndStoreDetailNotFoundWhenNavReturnsEmpty(): void
    {
        $db = tests_new_database();
        $row = $this->insertSampleIncomingInvoice($db);

        $body = '<?xml version="1.0"?><QueryInvoiceDataResponse xmlns:common="http://schemas.nav.gov.hu/NTCA/1.0/common" xmlns="http://schemas.nav.gov.hu/OSA/3.0/api">'
            . '<common:result><funcCode>OK</funcCode></common:result>'
            . '</QueryInvoiceDataResponse>';
        $sync = $this->syncWith($db, fn () => ['status' => 200, 'body' => $body]);

        $result = $sync->fetchAndStoreDetail($row);

        $this->assertSame('not_found', $result['outcome']);
    }
}
