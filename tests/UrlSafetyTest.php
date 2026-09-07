<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * SSRF-védelem (UrlSafety) közvetlen, hálózat-független tesztjei. Szándékosan
 * csak IP-literálokkal és a speciális "localhost" hostnévvel dolgozik — egy
 * valós hostnév DNS-feloldása hálózat-függő és instabil lenne egy automatizált
 * teszt-futtatásban, ezért az NEM ebben a fájlban van lefedve (lásd
 * tests/HttpSecurityTest.php megjegyzéseit a fennmaradó, éles hálózatot
 * igénylő esetekről: DNS-rebinding, átirányítás belső célra).
 */
final class UrlSafetyTest extends TestCase
{
    public function testRejectsLocalhostHostname(): void
    {
        $this->assertFalse(UrlSafety::isSafe('http://localhost/'));
        $this->assertFalse(UrlSafety::isSafe('http://sub.localhost/'));
    }

    public function testRejectsLoopbackIPv4(): void
    {
        $this->assertFalse(UrlSafety::isSafe('http://127.0.0.1/'));
        $this->assertFalse(UrlSafety::isSafe('http://127.1.2.3/'));
    }

    public function testRejectsPrivateIPv4Ranges(): void
    {
        $this->assertFalse(UrlSafety::isSafe('http://10.0.0.5/'));
        $this->assertFalse(UrlSafety::isSafe('http://172.16.0.5/'));
        $this->assertFalse(UrlSafety::isSafe('http://192.168.1.5/'));
    }

    public function testRejectsLinkLocalAndCloudMetadataIp(): void
    {
        // 169.254.169.254 a leggyakoribb felhő-metaadat végpont (AWS/GCP/Azure) —
        // ha egy SSRF-védelem ezt kifelejtené, egy támadó ezen keresztül
        // instance-hitelesítő adatokat szivárogtathatna ki.
        $this->assertFalse(UrlSafety::isSafe('http://169.254.169.254/latest/meta-data/'));
        $this->assertFalse(UrlSafety::isSafe('http://169.254.1.1/'));
    }

    public function testRejectsIPv6LoopbackAndPrivateRanges(): void
    {
        $this->assertFalse(UrlSafety::isSafe('http://[::1]/'));
        $this->assertFalse(UrlSafety::isSafe('http://[fe80::1]/'));
        $this->assertFalse(UrlSafety::isSafe('http://[fc00::1]/'));
        $this->assertFalse(UrlSafety::isSafe('http://[fd12:3456:789a::1]/'));
    }

    public function testAllowsSafePublicIpLiteralOverHttps(): void
    {
        // 93.184.216.34 az example.com egyik korábbi, régóta stabil, publikus
        // IP-je — literálként adjuk meg, hogy a teszt ne függjön DNS-től.
        [$safe, $error] = UrlSafety::check('https://93.184.216.34/webhook');
        $this->assertTrue($safe, 'Egy publikus IP-literál https-en biztonságosnak kell lennie: ' . $error);
    }

    public function testRejectsNonHttpSchemes(): void
    {
        $this->assertFalse(UrlSafety::isSafe('ftp://example.com/'));
        $this->assertFalse(UrlSafety::isSafe('file:///etc/passwd'));
        $this->assertFalse(UrlSafety::isSafe('gopher://127.0.0.1:6379/'));
    }

    public function testRejectsEmbeddedCredentials(): void
    {
        $this->assertFalse(UrlSafety::isSafe('http://user:pass@93.184.216.34/'));
    }

    public function testRejectsMalformedUrl(): void
    {
        $this->assertFalse(UrlSafety::isSafe('not a url'));
        $this->assertFalse(UrlSafety::isSafe('http://'));
        $this->assertFalse(UrlSafety::isSafe(''));
    }

    public function testPinnedCurlOptionsDisablesRedirectFollowing(): void
    {
        // Ez a mechanizmus zárja ki, hogy egy eleinte biztonságosnak
        // validált URL válasza belső célra irányítson át (a curl SOSE
        // követi az átirányítást, így egy 30x válasz csak válaszként
        // érkezik vissza, sose kerül ténylegesen lehívásra).
        $opts = UrlSafety::pinnedCurlOptions('https://93.184.216.34/webhook', '93.184.216.34');
        $this->assertFalse($opts[CURLOPT_FOLLOWLOCATION]);
        $this->assertArrayHasKey(CURLOPT_RESOLVE, $opts);
    }
}
