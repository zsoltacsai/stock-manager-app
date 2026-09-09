<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * NavInvoiceProvider tesztek — a HTTP réteg MINDIG mockolt (injektált
 * NavClient $clientFactory-n keresztül) — itt a provider SAJÁT döntéseit
 * (payload-építés, enqueue idempotencia, submit()/checkStatus() kimenet-
 * osztályozás, timeout → 'uncertain', SOSE vak retry) ellenőrizzük, nem a
 * NavClient saját logikáját (azt lásd NavClientTest.php).
 */
final class NavInvoiceProviderTest extends TestCase
{
    private function fakeNavConfig(): array
    {
        return [
            'nav_login' => 'teszt', 'nav_password' => 'teszt', 'nav_signer_key' => 'teszt-signer-key-1234567890',
            'nav_exchange_key' => 'ABCDEFGHIJKLMNOP', 'nav_tax_number' => '12345678', 'nav_test_mode' => true,
        ];
    }

    private function fakeSupplierConfig(): array
    {
        return ['tax_number' => '12345678', 'name' => 'Teszt Kft', 'zip' => '6720', 'city' => 'Szeged', 'address' => 'Fő utca 1.'];
    }

    private function tempTokenCache(): NavTokenCache
    {
        return new NavTokenCache(sys_get_temp_dir() . '/sm_navtoken_test_' . bin2hex(random_bytes(6)) . '.json');
    }

    private function sampleContext(): array
    {
        return [
            'buyer' => ['nev' => 'Teszt Vevő', 'irsz' => '1111', 'telepules' => 'Budapest', 'cim' => 'Kossuth utca 1.', 'adoszam' => null],
            'items' => [['name' => 'Termék', 'qty' => 1, 'unit_price_gross' => 1270.0, 'vat_rate' => '27']],
            'payment_method' => 'Készpénz',
            'totals' => ['net' => 1000.0, 'vat' => 270.0, 'gross' => 1270.0, 'currency' => 'HUF'],
        ];
    }

    private function tokenExchangeSuccessBody(): string
    {
        $encoded = base64_encode(openssl_encrypt('token', 'aes-128-ecb', 'ABCDEFGHIJKLMNOP', OPENSSL_RAW_DATA));
        return '<?xml version="1.0"?><TokenExchangeResponse xmlns:common="http://schemas.nav.gov.hu/NTCA/1.0/common" xmlns="http://schemas.nav.gov.hu/OSA/3.0/api">'
            . '<common:result><funcCode>OK</funcCode></common:result>'
            . "<encodedExchangeToken>$encoded</encodedExchangeToken>"
            . '<tokenValidityFrom>2026-01-01T10:00:00.000Z</tokenValidityFrom>'
            . '<tokenValidityTo>2026-01-01T10:05:00.000Z</tokenValidityTo>'
            . '</TokenExchangeResponse>';
    }

    private function envelopeBody(string $funcCode, array $extra = [], ?string $errorCode = null, ?string $message = null): string
    {
        $body = "<funcCode>$funcCode</funcCode>";
        if ($errorCode !== null) $body .= "<errorCode>$errorCode</errorCode>";
        if ($message !== null) $body .= '<message>' . htmlspecialchars($message, ENT_XML1) . '</message>';
        foreach ($extra as $tag => $value) $body .= "<$tag>$value</$tag>";
        return '<?xml version="1.0"?><Response xmlns:common="http://schemas.nav.gov.hu/NTCA/1.0/common" xmlns="http://schemas.nav.gov.hu/OSA/3.0/api"><common:result>' . $body . '</common:result></Response>';
    }

    /** Egy NavClient-et épít, aminek a tokenExchange MINDIG sikeres (fix token), a manageInvoice/queryTransactionStatus válaszát pedig a $responses sorban adja vissza, hívási sorrendben. */
    private function providerWithScriptedResponses(array $responses): NavInvoiceProvider
    {
        $tokenBody = $this->tokenExchangeSuccessBody();
        $call = 0;
        $transport = function (string $url, string $xml) use (&$call, $responses, $tokenBody) {
            if (str_contains($url, 'tokenExchange')) {
                return ['status' => 200, 'body' => $tokenBody];
            }
            $r = $responses[$call] ?? ['status' => 500, 'body' => ''];
            $call++;
            return $r;
        };
        $clientFactory = fn () => new NavClient($this->fakeNavConfig(), $transport);
        return new NavInvoiceProvider($this->fakeNavConfig(), $this->fakeSupplierConfig(), $this->tempTokenCache(), $clientFactory);
    }

    // ---- enqueue() ----

    public function testEnqueueCreatesQueuedInvoiceWithPayload(): void
    {
        $db = tests_new_database();
        $saleId = $db->insertSale(1270.0, 'Készpénz');
        $provider = new NavInvoiceProvider($this->fakeNavConfig(), $this->fakeSupplierConfig(), $this->tempTokenCache());

        $provider->enqueue($db, $saleId, $this->sampleContext());

        $row = $db->findInvoiceBySaleAndProvider($saleId, 'nav');
        $this->assertNotNull($row);
        $this->assertSame('queued', $row['status']);
        $this->assertNotEmpty($row['invoice_number']);
        $this->assertSame(1000.0, (float) $row['net_total']);

        $payload = json_decode($row['payload_json'], true);
        $this->assertSame('Teszt Vevő', $payload['buyer']['nev']);
        $this->assertSame('Teszt Kft', $payload['supplier']['name']);

        // Secrets sose kerülhetnek a payload_json-ba.
        $this->assertStringNotContainsString('nav_password', $row['payload_json']);
        $this->assertStringNotContainsString('nav_signer_key', $row['payload_json']);
    }

    public function testEnqueueTwiceForSameSaleIsIdempotent(): void
    {
        $db = tests_new_database();
        $saleId = $db->insertSale(1270.0, 'Készpénz');
        $provider = new NavInvoiceProvider($this->fakeNavConfig(), $this->fakeSupplierConfig(), $this->tempTokenCache());

        $provider->enqueue($db, $saleId, $this->sampleContext());
        $provider->enqueue($db, $saleId, $this->sampleContext());

        $stmt = $db->pdo()->prepare('SELECT COUNT(*) FROM invoices WHERE sale_id = ? AND provider = ?');
        $stmt->execute([$saleId, 'nav']);
        $this->assertSame(1, (int) $stmt->fetchColumn());
    }

    // ---- submit() ----

    public function testSubmitSuccessReturnsTransactionId(): void
    {
        $manageBody = $this->envelopeBody('OK', ['transactionId' => 'TXN123']);
        $provider = $this->providerWithScriptedResponses([['status' => 200, 'body' => $manageBody]]);

        $row = ['payload_json' => json_encode(['buyer' => $this->sampleContext()['buyer'], 'items' => $this->sampleContext()['items'], 'payment_method' => 'Készpénz', 'supplier' => $this->fakeSupplierConfig()]), 'invoice_number' => 'SM-TEST-1', 'currency' => 'HUF'];

        $result = $provider->submit($row);

        $this->assertSame('submitted', $result['outcome']);
        $this->assertSame('TXN123', $result['transaction_id']);
    }

    public function testSubmitTokenExchangeFailureIsRetryable(): void
    {
        $transport = fn (string $url, string $xml) => ['status' => 401, 'body' => $this->envelopeBody('ERROR', [], 'INVALID_SECURITY_USER')];
        $clientFactory = fn () => new NavClient($this->fakeNavConfig(), $transport);
        $provider = new NavInvoiceProvider($this->fakeNavConfig(), $this->fakeSupplierConfig(), $this->tempTokenCache(), $clientFactory);

        $row = ['payload_json' => json_encode(['buyer' => $this->sampleContext()['buyer'], 'items' => $this->sampleContext()['items'], 'payment_method' => 'Készpénz', 'supplier' => $this->fakeSupplierConfig()]), 'invoice_number' => 'SM-TEST-1', 'currency' => 'HUF'];

        $result = $provider->submit($row);

        // INVALID_SECURITY_USER a NON_RETRYABLE listán van -> 'permanent'.
        $this->assertSame('permanent', $result['outcome']);
    }

    public function testSubmitHttp500IsRetryable(): void
    {
        $provider = $this->providerWithScriptedResponses([['status' => 500, 'body' => $this->envelopeBody('ERROR', [], 'INTERNAL_SERVER_ERROR')]]);
        $row = ['payload_json' => json_encode(['buyer' => $this->sampleContext()['buyer'], 'items' => $this->sampleContext()['items'], 'payment_method' => 'Készpénz', 'supplier' => $this->fakeSupplierConfig()]), 'invoice_number' => 'SM-TEST-1', 'currency' => 'HUF'];

        $result = $provider->submit($row);

        $this->assertSame('retry', $result['outcome']);
    }

    public function testSubmitBusinessValidationErrorIsPermanent(): void
    {
        $provider = $this->providerWithScriptedResponses([['status' => 200, 'body' => $this->envelopeBody('ERROR', [], 'SCHEMA_VIOLATION', 'Hibás mező.')]]);
        $row = ['payload_json' => json_encode(['buyer' => $this->sampleContext()['buyer'], 'items' => $this->sampleContext()['items'], 'payment_method' => 'Készpénz', 'supplier' => $this->fakeSupplierConfig()]), 'invoice_number' => 'SM-TEST-1', 'currency' => 'HUF'];

        $result = $provider->submit($row);

        $this->assertSame('permanent', $result['outcome']);
    }

    /**
     * A LEGFONTOSABB teszt: manageInvoice timeout (a HTTP-hívás maga
     * dob, sose kapunk választ) — SOSE szabad ezt sima retry-nak
     * tekinteni, mert a NAV ténylegesen megkaphatta a kérést. Az
     * egyetlen elfogadható kimenet: 'uncertain'.
     */
    public function testSubmitTimeoutAfterRequestMayHaveReachedNavIsUncertainNotRetry(): void
    {
        $tokenBody = $this->tokenExchangeSuccessBody();
        $transport = function (string $url, string $xml) use ($tokenBody) {
            if (str_contains($url, 'tokenExchange')) {
                return ['status' => 200, 'body' => $tokenBody];
            }
            throw new RuntimeException('NAV kapcsolati hiba: Operation timed out after 20000 milliseconds');
        };
        $clientFactory = fn () => new NavClient($this->fakeNavConfig(), $transport);
        $provider = new NavInvoiceProvider($this->fakeNavConfig(), $this->fakeSupplierConfig(), $this->tempTokenCache(), $clientFactory);

        $row = ['payload_json' => json_encode(['buyer' => $this->sampleContext()['buyer'], 'items' => $this->sampleContext()['items'], 'payment_method' => 'Készpénz', 'supplier' => $this->fakeSupplierConfig()]), 'invoice_number' => 'SM-TEST-1', 'currency' => 'HUF'];

        $result = $provider->submit($row);

        $this->assertSame('uncertain', $result['outcome'], 'Egy manageInvoice timeout SOSE eredményezhet automatikus vak retry-t — bizonytalan állapotba kell kerülnie.');
        $this->assertNotSame('retry', $result['outcome']);
    }

    // ---- checkStatus() ----

    public function testCheckStatusDone(): void
    {
        $provider = $this->providerWithScriptedResponses([['status' => 200, 'body' => $this->envelopeBody('OK', ['invoiceStatus' => 'DONE'])]]);
        $result = $provider->checkStatus(['provider_ref' => 'TXN123']);
        $this->assertSame('done', $result['outcome']);
    }

    public function testCheckStatusAborted(): void
    {
        $provider = $this->providerWithScriptedResponses([['status' => 200, 'body' => $this->envelopeBody('OK', ['invoiceStatus' => 'ABORTED'])]]);
        $result = $provider->checkStatus(['provider_ref' => 'TXN123']);
        $this->assertSame('failed', $result['outcome']);
    }

    public function testCheckStatusProcessingIsPending(): void
    {
        $provider = $this->providerWithScriptedResponses([['status' => 200, 'body' => $this->envelopeBody('OK', ['invoiceStatus' => 'PROCESSING'])]]);
        $result = $provider->checkStatus(['provider_ref' => 'TXN123']);
        $this->assertSame('pending', $result['outcome']);
    }

    public function testCheckStatusNetworkFailureIsRetryNotUncertain(): void
    {
        $tokenBody = $this->tokenExchangeSuccessBody();
        $transport = function (string $url) use ($tokenBody) {
            if (str_contains($url, 'tokenExchange')) return ['status' => 200, 'body' => $tokenBody];
            throw new RuntimeException('NAV kapcsolati hiba: timeout');
        };
        $clientFactory = fn () => new NavClient($this->fakeNavConfig(), $transport);
        $provider = new NavInvoiceProvider($this->fakeNavConfig(), $this->fakeSupplierConfig(), $this->tempTokenCache(), $clientFactory);

        $result = $provider->checkStatus(['provider_ref' => 'TXN123']);

        // Egy státusz-lekérdezésnek nincs mellékhatása -> sose 'uncertain'.
        $this->assertSame('retry', $result['outcome']);
    }

    // ---- recoverUncertain() lapozás ----

    /**
     * A NAV a queryTransactionList lapméretét maga határozza meg — a
     * keresett tranzakció NEM garantáltan az 1. oldalon van. Ez a teszt
     * egy olyan mock választ szimulál, ahol a 2 oldalas eredmény 1.
     * oldalán NINCS egyező tranzakció, csak a 2.-on — bizonyítandó, hogy
     * recoverUncertain() ténylegesen továbblapoz, nem áll meg az 1.
     * oldal után.
     */
    public function testRecoverUncertainFindsMatchOnlyOnSecondPage(): void
    {
        $invoiceNumber = 'SM-NAV-2026-TESTPAGE2';
        $originalXml = '<InvoiceData><invoiceNumber>' . $invoiceNumber . '</invoiceNumber></InvoiceData>';

        $listPage1 = '<?xml version="1.0"?><QueryTransactionListResponse xmlns:common="http://schemas.nav.gov.hu/NTCA/1.0/common" xmlns="http://schemas.nav.gov.hu/OSA/3.0/api">'
            . '<common:result><funcCode>OK</funcCode></common:result>'
            . '<transactionListResult><currentPage>1</currentPage><availablePage>2</availablePage>'
            . '<transaction><insDate>2026-01-01T10:00:00Z</insDate><transactionId>TXN-OTHER-1</transactionId><requestStatus>DONE</requestStatus></transaction>'
            . '<transaction><insDate>2026-01-01T10:00:05Z</insDate><transactionId>TXN-OTHER-2</transactionId><requestStatus>DONE</requestStatus></transaction>'
            . '</transactionListResult></QueryTransactionListResponse>';
        $listPage2 = '<?xml version="1.0"?><QueryTransactionListResponse xmlns:common="http://schemas.nav.gov.hu/NTCA/1.0/common" xmlns="http://schemas.nav.gov.hu/OSA/3.0/api">'
            . '<common:result><funcCode>OK</funcCode></common:result>'
            . '<transactionListResult><currentPage>2</currentPage><availablePage>2</availablePage>'
            . '<transaction><insDate>2026-01-01T10:00:10Z</insDate><transactionId>TXN-MATCH</transactionId><requestStatus>DONE</requestStatus></transaction>'
            . '</transactionListResult></QueryTransactionListResponse>';

        $statusForOther = '<?xml version="1.0"?><QueryTransactionStatusResponse xmlns:common="http://schemas.nav.gov.hu/NTCA/1.0/common" xmlns="http://schemas.nav.gov.hu/OSA/3.0/api">'
            . '<common:result><funcCode>OK</funcCode></common:result>'
            . '<processingResults><processingResult><index>1</index><invoiceStatus>DONE</invoiceStatus><compressedContentIndicator>false</compressedContentIndicator>'
            . '<originalRequest>' . base64_encode('<InvoiceData><invoiceNumber>SM-NAV-2026-UNRELATED</invoiceNumber></InvoiceData>') . '</originalRequest></processingResult></processingResults>'
            . '</QueryTransactionStatusResponse>';
        $statusForMatch = '<?xml version="1.0"?><QueryTransactionStatusResponse xmlns:common="http://schemas.nav.gov.hu/NTCA/1.0/common" xmlns="http://schemas.nav.gov.hu/OSA/3.0/api">'
            . '<common:result><funcCode>OK</funcCode></common:result>'
            . '<processingResults><processingResult><index>1</index><invoiceStatus>DONE</invoiceStatus><compressedContentIndicator>false</compressedContentIndicator>'
            . '<originalRequest>' . base64_encode($originalXml) . '</originalRequest></processingResult></processingResults>'
            . '</QueryTransactionStatusResponse>';

        $tokenBody = $this->tokenExchangeSuccessBody();
        $statusQueriesForTxn = [];
        $transport = function (string $url, string $xml) use (&$statusQueriesForTxn, $tokenBody, $listPage1, $listPage2, $statusForOther, $statusForMatch) {
            if (str_contains($url, 'tokenExchange')) {
                return ['status' => 200, 'body' => $tokenBody];
            }
            if (str_contains($url, 'queryTransactionList')) {
                preg_match('/<page>(\d+)<\/page>/', $xml, $m);
                $page = (int) ($m[1] ?? 1);
                return ['status' => 200, 'body' => $page === 1 ? $listPage1 : $listPage2];
            }
            if (str_contains($url, 'queryTransactionStatus')) {
                preg_match('/<transactionId>(.*?)<\/transactionId>/', $xml, $m);
                $txnId = $m[1] ?? '';
                $statusQueriesForTxn[] = $txnId;
                return ['status' => 200, 'body' => $txnId === 'TXN-MATCH' ? $statusForMatch : $statusForOther];
            }
            return ['status' => 500, 'body' => ''];
        };
        $clientFactory = fn () => new NavClient($this->fakeNavConfig(), $transport);
        $provider = new NavInvoiceProvider($this->fakeNavConfig(), $this->fakeSupplierConfig(), $this->tempTokenCache(), $clientFactory);

        $row = ['invoice_number' => $invoiceNumber, 'updated_at' => date('Y-m-d H:i:s', time() - 60)];
        $result = $provider->recoverUncertain($row);

        $this->assertSame('submitted', $result['outcome']);
        $this->assertSame('TXN-MATCH', $result['transaction_id']);
        $this->assertContains('TXN-MATCH', $statusQueriesForTxn, 'A 2. oldalon található tranzakciót ténylegesen le kellett kérdezni.');
    }

    public function testRecoverUncertainStopsAtMaxPagesEvenIfAvailablePageIsHigher(): void
    {
        $callsPerPage = [];
        $listBody = fn (int $page) => '<?xml version="1.0"?><QueryTransactionListResponse xmlns:common="http://schemas.nav.gov.hu/NTCA/1.0/common" xmlns="http://schemas.nav.gov.hu/OSA/3.0/api">'
            . '<common:result><funcCode>OK</funcCode></common:result>'
            . "<transactionListResult><currentPage>$page</currentPage><availablePage>50</availablePage></transactionListResult>"
            . '</QueryTransactionListResponse>';

        $tokenBody = $this->tokenExchangeSuccessBody();
        $transport = function (string $url, string $xml) use (&$callsPerPage, $tokenBody, $listBody) {
            if (str_contains($url, 'tokenExchange')) return ['status' => 200, 'body' => $tokenBody];
            if (str_contains($url, 'queryTransactionList')) {
                preg_match('/<page>(\d+)<\/page>/', $xml, $m);
                $page = (int) ($m[1] ?? 1);
                $callsPerPage[] = $page;
                return ['status' => 200, 'body' => $listBody($page)];
            }
            return ['status' => 500, 'body' => ''];
        };
        $clientFactory = fn () => new NavClient($this->fakeNavConfig(), $transport);
        $provider = new NavInvoiceProvider($this->fakeNavConfig(), $this->fakeSupplierConfig(), $this->tempTokenCache(), $clientFactory);

        $row = ['invoice_number' => 'SM-NAV-2026-NEVERFOUND', 'updated_at' => date('Y-m-d H:i:s', time() - 60)];
        $result = $provider->recoverUncertain($row);

        $this->assertSame('still_uncertain', $result['outcome']);
        $this->assertLessThanOrEqual(5, count($callsPerPage), 'Az availablePage=50 ellenére a lapozásnak MEG kell állnia a felső korlátnál (5), nem szabad korlátlanul lekérnie az összes oldalt.');
    }
}
