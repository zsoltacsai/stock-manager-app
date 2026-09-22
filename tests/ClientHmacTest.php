<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class ClientHmacTest extends TestCase
{
    public function testCanonicalStringUsesExactDocumentedFieldOrderAndLfSeparator(): void
    {
        $canonical = ClientHmac::canonicalString('post', '/api/sale.php?a=1', '1700000000', 'abc123', 'body-content');
        $expected = "POST\n/api/sale.php?a=1\n1700000000\nabc123\n" . hash('sha256', 'body-content');
        $this->assertSame($expected, $canonical);
    }

    public function testCanonicalStringUppercasesMethod(): void
    {
        $canonical = ClientHmac::canonicalString('get', '/api/x.php', '1', 'n', '');
        $this->assertStringStartsWith("GET\n", $canonical);
    }

    public function testSignIsDeterministicForSameInputs(): void
    {
        $key = ClientHmac::deriveSigningKey('my-secret');
        $canonical = 'GET' . "\n" . '/api/x.php' . "\n" . '1700000000' . "\n" . 'nonce1' . "\n" . hash('sha256', '');
        $this->assertSame(ClientHmac::sign($canonical, $key), ClientHmac::sign($canonical, $key));
    }

    public function testDifferentSecretsProduceDifferentSignatures(): void
    {
        $canonical = 'GET' . "\n" . '/api/x.php' . "\n" . '1700000000' . "\n" . 'nonce1' . "\n" . hash('sha256', '');
        $sigA = ClientHmac::sign($canonical, ClientHmac::deriveSigningKey('secret-a'));
        $sigB = ClientHmac::sign($canonical, ClientHmac::deriveSigningKey('secret-b'));
        $this->assertNotSame($sigA, $sigB);
    }

    public function testVerifyAcceptsAValidSignature(): void
    {
        $key = ClientHmac::deriveSigningKey('correct-secret');
        $canonical = ClientHmac::canonicalString('POST', '/api/sale.php', '1700000000', 'noncex', '{"a":1}');
        $sig = ClientHmac::sign($canonical, $key);
        $this->assertTrue(ClientHmac::verify($canonical, $sig, $key));
    }

    public function testVerifyRejectsATamperedBody(): void
    {
        // A gyakorlatban: a Kliens az EREDETI törzsre írja alá az
        // aláírást, egy módosított törzs (más sha256) más kanonikus
        // sztringet, tehát más aláírást eredményezne.
        $key = ClientHmac::deriveSigningKey('secret');
        $canonicalOriginal = ClientHmac::canonicalString('POST', '/api/sale.php', '1700000000', 'noncex', '{"a":1}');
        $sig = ClientHmac::sign($canonicalOriginal, $key);

        $canonicalTampered = ClientHmac::canonicalString('POST', '/api/sale.php', '1700000000', 'noncex', '{"a":999}');
        $this->assertFalse(ClientHmac::verify($canonicalTampered, $sig, $key));
    }

    public function testVerifyRejectsATamperedPath(): void
    {
        $key = ClientHmac::deriveSigningKey('secret');
        $canonicalOriginal = ClientHmac::canonicalString('POST', '/api/sale.php', '1700000000', 'noncex', 'body');
        $sig = ClientHmac::sign($canonicalOriginal, $key);

        $canonicalTampered = ClientHmac::canonicalString('POST', '/api/customer-gdpr-delete.php', '1700000000', 'noncex', 'body');
        $this->assertFalse(ClientHmac::verify($canonicalTampered, $sig, $key));
    }

    public function testVerifyRejectsATamperedQueryString(): void
    {
        $key = ClientHmac::deriveSigningKey('secret');
        $canonicalOriginal = ClientHmac::canonicalString('GET', '/api/customer-detail.php?id=1', '1700000000', 'noncex', '');
        $sig = ClientHmac::sign($canonicalOriginal, $key);

        $canonicalTampered = ClientHmac::canonicalString('GET', '/api/customer-detail.php?id=2', '1700000000', 'noncex', '');
        $this->assertFalse(ClientHmac::verify($canonicalTampered, $sig, $key));
    }

    public function testVerifyRejectsATamperedTimestamp(): void
    {
        $key = ClientHmac::deriveSigningKey('secret');
        $canonicalOriginal = ClientHmac::canonicalString('GET', '/api/x.php', '1700000000', 'noncex', '');
        $sig = ClientHmac::sign($canonicalOriginal, $key);

        $canonicalTampered = ClientHmac::canonicalString('GET', '/api/x.php', '1700000001', 'noncex', '');
        $this->assertFalse(ClientHmac::verify($canonicalTampered, $sig, $key));
    }

    public function testVerifyRejectsAWrongSigningKey(): void
    {
        $canonical = ClientHmac::canonicalString('GET', '/api/x.php', '1700000000', 'noncex', '');
        $sig = ClientHmac::sign($canonical, ClientHmac::deriveSigningKey('right-secret'));
        $this->assertFalse(ClientHmac::verify($canonical, $sig, ClientHmac::deriveSigningKey('wrong-secret')));
    }

    public function testVerifyRejectsAnEmptySignature(): void
    {
        $key = ClientHmac::deriveSigningKey('secret');
        $canonical = ClientHmac::canonicalString('GET', '/api/x.php', '1700000000', 'noncex', '');
        $this->assertFalse(ClientHmac::verify($canonical, '', $key));
    }

    public function testDeriveSigningKeyIsSha256HexOfTheRawSecret(): void
    {
        $this->assertSame(hash('sha256', 'abc'), ClientHmac::deriveSigningKey('abc'));
        $this->assertSame(64, strlen(ClientHmac::deriveSigningKey('anything')));
    }

    public function testPathAndQueryFromServerSuperglobalBuildsApiPrefixedPath(): void
    {
        $backup = $_SERVER;
        $_SERVER['SCRIPT_NAME'] = '/api/sale.php';
        $_SERVER['QUERY_STRING'] = 'foo=bar&baz=qux';
        try {
            $this->assertSame('/api/sale.php?foo=bar&baz=qux', ClientHmac::pathAndQueryFromServerSuperglobal());
        } finally {
            $_SERVER = $backup;
        }
    }

    public function testPathAndQueryFromServerSuperglobalOmitsQuestionMarkWhenNoQueryString(): void
    {
        $backup = $_SERVER;
        $_SERVER['SCRIPT_NAME'] = '/api/auth-status.php';
        $_SERVER['QUERY_STRING'] = '';
        try {
            $this->assertSame('/api/auth-status.php', ClientHmac::pathAndQueryFromServerSuperglobal());
        } finally {
            $_SERVER = $backup;
        }
    }
}
