<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * NavClient tesztek — a HTTP réteg MINDEN esetben mockolt (a valódi NAV
 * sandbox hívás egy külön, kifejezetten erre a célra készült, ebbe a
 * tesztcsomagba NEM tartozó ellenőrzés, lásd a Phase 5A záró jelentést) —
 * itt kizárólag a kliens saját logikáját (kérés-építés, aláírás-számítás,
 * válasz-értelmezés, hibakezelés) ellenőrizzük, gyorsan és hálózat
 * nélkül, egy injektált callable HTTP-transporttal.
 */
final class NavClientTest extends TestCase
{
    private function fakeCfg(array $overrides = []): array
    {
        return array_merge([
            'nav_login'        => 'teszt_login',
            'nav_password'     => 'teszt_jelszo',
            'nav_signer_key'   => 'ce-8f5e-215119fa7dd621DLMRHRLH2S',
            'nav_exchange_key' => 'ABCDEFGHIJKLMNOP', // pontosan 16 byte — AES-128 kulcshossz
            'nav_tax_number'   => '12345678',
            'nav_test_mode'    => true,
        ], $overrides);
    }

    private function xmlResponse(string $funcCode, array $extraTags = [], ?string $errorCode = null, ?string $message = null): string
    {
        $body = "<funcCode>$funcCode</funcCode>";
        if ($errorCode !== null) {
            $body .= "<errorCode>$errorCode</errorCode>";
        }
        if ($message !== null) {
            $body .= '<message>' . htmlspecialchars($message, ENT_XML1) . '</message>';
        }
        foreach ($extraTags as $tag => $value) {
            $body .= "<$tag>$value</$tag>";
        }
        return '<?xml version="1.0" encoding="UTF-8"?><TokenExchangeResponse xmlns:common="http://schemas.nav.gov.hu/NTCA/1.0/common" xmlns="http://schemas.nav.gov.hu/OSA/3.0/api">'
            . '<common:header/><common:result>' . $body . '</common:result></TokenExchangeResponse>';
    }

    // ---- Authentication (tokenExchange) ----

    public function testTokenExchangeSuccessDecodesToken(): void
    {
        $exchangeKey = 'ABCDEFGHIJKLMNOP';
        $plainToken = 'plaintext-exchange-token-value';
        $encrypted = openssl_encrypt($plainToken, 'aes-128-ecb', $exchangeKey, OPENSSL_RAW_DATA);
        $encoded = base64_encode($encrypted);

        $body = '<?xml version="1.0" encoding="UTF-8"?><TokenExchangeResponse xmlns:common="http://schemas.nav.gov.hu/NTCA/1.0/common" xmlns="http://schemas.nav.gov.hu/OSA/3.0/api">'
            . '<common:result><funcCode>OK</funcCode></common:result>'
            . "<encodedExchangeToken>$encoded</encodedExchangeToken>"
            . '<tokenValidityFrom>2026-09-09T10:00:00.000Z</tokenValidityFrom>'
            . '<tokenValidityTo>2026-09-09T10:05:00.000Z</tokenValidityTo>'
            . '</TokenExchangeResponse>';

        $client = new NavClient($this->fakeCfg(['nav_exchange_key' => $exchangeKey]), function (string $url, string $xml) use ($body) {
            return ['status' => 200, 'body' => $body];
        });

        $result = $client->tokenExchange();

        $this->assertTrue($result['success']);
        $this->assertSame($plainToken, $result['token']);
        $this->assertSame('2026-09-09T10:00:00.000Z', $result['valid_from']);
        $this->assertSame('2026-09-09T10:05:00.000Z', $result['valid_to']);
    }

    public function testTokenExchangeInvalidCredentialsSurfacesNavErrorCode(): void
    {
        $body = $this->xmlResponse('ERROR', [], 'INVALID_SECURITY_USER', 'Invalid user or password.');
        $client = new NavClient($this->fakeCfg(), function () use ($body) {
            return ['status' => 400, 'body' => $body];
        });

        $result = $client->testConnection();

        $this->assertFalse($result['success']);
        $this->assertSame('INVALID_SECURITY_USER', $result['nav_error_code']);
        $this->assertSame('Invalid user or password.', $result['error']);
        $this->assertSame(400, $result['http_status']);
    }

    public function testTokenExchangeInvalidSignatureSurfacesNavErrorCode(): void
    {
        $body = $this->xmlResponse('ERROR', [], 'INVALID_REQUEST_SIGNATURE');
        $client = new NavClient($this->fakeCfg(), function () use ($body) {
            return ['status' => 500, 'body' => $body];
        });

        $result = $client->testConnection();

        $this->assertFalse($result['success']);
        $this->assertSame('INVALID_REQUEST_SIGNATURE', $result['nav_error_code']);
    }

    public function testMalformedResponseFailsGracefullyWithoutThrowing(): void
    {
        $client = new NavClient($this->fakeCfg(), function () {
            return ['status' => 200, 'body' => 'ez itt nem XML, hanem sima szoveg <<< >>>'];
        });

        $result = $client->testConnection();

        $this->assertFalse($result['success']);
        $this->assertNotEmpty($result['error']);
    }

    // ---- NAV client (transport-level) ----

    public function testTransportTimeoutFailsGracefullyWithoutThrowing(): void
    {
        $client = new NavClient($this->fakeCfg(), function () {
            throw new RuntimeException('NAV kapcsolati hiba: Operation timed out after 20000 milliseconds');
        });

        $result = $client->testConnection();

        $this->assertFalse($result['success']);
        $this->assertStringContainsString('timed out', $result['error']);
    }

    public function testHttpErrorStatusIsSurfacedNotThrown(): void
    {
        $client = new NavClient($this->fakeCfg(), function () {
            return ['status' => 404, 'body' => ''];
        });

        $result = $client->testConnection();

        $this->assertFalse($result['success']);
        $this->assertSame(404, $result['http_status']);
    }

    // ---- manageInvoice request signature (spec worked example) ----

    public function testManageInvoiceRequestSignatureMatchesNavSpecWorkedExample(): void
    {
        // A NAV specifikáció saját fiktív számpéldája (1.5.1 szakasz) —
        // ez a teszt bizonyítja, hogy a NavClient TÉNYLEGESEN ezt a
        // (tokenExchange-től eltérő!) képletet alkalmazza manageInvoice
        // hívásnál, nem az egyszerűbb "1.5.2" képletet.
        $capturedXml = null;
        $client = new NavClient(
            $this->fakeCfg(['nav_signer_key' => 'ce-8f5e-215119fa7dd621DLMRHRLH2S']),
            function (string $url, string $xml) use (&$capturedXml) {
                $capturedXml = $xml;
                return ['status' => 200, 'body' => $this->xmlResponse('OK', ['transactionId' => 'TXN123'])];
            }
        );

        $client->manageInvoiceCreate('decoded-token', 'invoice-xml-payload', 1);

        $this->assertNotNull($capturedXml);
        preg_match('/<common:requestId>(.*?)<\/common:requestId>/', $capturedXml, $reqIdM);
        preg_match('/<common:timestamp>(.*?)<\/common:timestamp>/', $capturedXml, $tsM);
        preg_match('/cryptoType="SHA3-512">(.*?)<\/common:requestSignature>/', $capturedXml, $sigM);

        $requestId = $reqIdM[1];
        $timestamp = $tsM[1];
        $actualSignature = $sigM[1];

        $tsForSig = preg_replace('/[-:T.Z]/', '', substr($timestamp, 0, 19));
        $dataBase64 = base64_encode('invoice-xml-payload');
        $indexHash = strtoupper(hash('sha3-512', 'CREATE' . $dataBase64));
        $expectedBase = $requestId . $tsForSig . 'ce-8f5e-215119fa7dd621DLMRHRLH2S' . $indexHash;
        $expectedSignature = strtoupper(hash('sha3-512', $expectedBase));

        $this->assertSame($expectedSignature, $actualSignature);
    }

    public function testManageInvoiceMissingTransactionIdIsTreatedAsFailure(): void
    {
        $client = new NavClient($this->fakeCfg(), function () {
            return ['status' => 200, 'body' => $this->xmlResponse('OK')];
        });

        $result = $client->manageInvoiceCreate('token', 'xml', 1);

        $this->assertFalse($result['success']);
        $this->assertNull($result['transaction_id']);
    }

    // ---- queryTransactionStatus ----

    public function testQueryTransactionStatusProcessing(): void
    {
        $client = new NavClient($this->fakeCfg(), function () {
            return ['status' => 200, 'body' => $this->xmlResponse('OK', ['invoiceStatus' => 'PROCESSING'])];
        });

        $result = $client->queryTransactionStatus('TXN123');

        $this->assertTrue($result['success']);
        $this->assertFalse($result['pending']);
        $this->assertSame('PROCESSING', $result['invoice_status']);
    }

    public function testQueryTransactionStatusDone(): void
    {
        $client = new NavClient($this->fakeCfg(), function () {
            return ['status' => 200, 'body' => $this->xmlResponse('OK', ['invoiceStatus' => 'DONE'])];
        });

        $result = $client->queryTransactionStatus('TXN123');

        $this->assertTrue($result['success']);
        $this->assertSame('DONE', $result['invoice_status']);
    }

    public function testQueryTransactionStatusAborted(): void
    {
        $client = new NavClient($this->fakeCfg(), function () {
            return ['status' => 200, 'body' => $this->xmlResponse('OK', ['invoiceStatus' => 'ABORTED'])];
        });

        $result = $client->queryTransactionStatus('TXN123');

        $this->assertTrue($result['success']);
        $this->assertSame('ABORTED', $result['invoice_status']);
    }

    public function testQueryTransactionStatusPendingWhenProcessingResultsAbsent(): void
    {
        // funcCode=OK, de sem invoiceStatus, sem processingResults — a
        // specifikáció szerint ez azt jelenti, a feldolgozás még túl
        // korai fázisban van, NEM hiba.
        $client = new NavClient($this->fakeCfg(), function () {
            return ['status' => 200, 'body' => $this->xmlResponse('OK')];
        });

        $result = $client->queryTransactionStatus('TXN123');

        $this->assertTrue($result['success']);
        $this->assertTrue($result['pending']);
        $this->assertNull($result['invoice_status']);
    }

    public function testQueryTransactionStatusUnknownTransactionIsError(): void
    {
        $body = $this->xmlResponse('ERROR', [], 'STATUS_QUERY_NOT_ALLOWED', 'Unknown or not-yet-visible transaction.');
        $client = new NavClient($this->fakeCfg(), function () use ($body) {
            return ['status' => 500, 'body' => $body];
        });

        $result = $client->queryTransactionStatus('NONEXISTENT');

        $this->assertFalse($result['success']);
        $this->assertSame('STATUS_QUERY_NOT_ALLOWED', $result['nav_error_code']);
    }
}
