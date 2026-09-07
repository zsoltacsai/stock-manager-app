<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Auth::isRequestHttps() — a session-süti Secure jelzőjének eldöntése —
 * közvetlen, HTTP-kör nélküli tesztje. A metódus private, ezért
 * Reflection-nel hívjuk: ez a "teszteld a döntési logikát közvetlenül"
 * eset (lásd C5 javítás), mert a valódi reverse-proxy forgatókönyvet
 * (más gépről, TLS-t nem beszélő PHP beépített szerverrel) élesben nem
 * lehet HTTP-n keresztül reprodukálni ebben a teszt-környezetben — a
 * HttpSecurityTest.php-beli kliens mindig loopbackról (127.0.0.1)
 * csatlakozik a szerverhez, tehát egy "távoli, nem megbízható kliens"
 * REMOTE_ADDR-jét onnan nem lehetne valósághűen szimulálni.
 */
final class AuthTest extends TestCase
{
    protected function tearDown(): void
    {
        unset($_SERVER['HTTPS'], $_SERVER['REMOTE_ADDR'], $_SERVER['HTTP_X_FORWARDED_PROTO']);
    }

    private function invokeIsRequestHttps(): bool
    {
        $method = new ReflectionMethod(Auth::class, 'isRequestHttps');
        $method->setAccessible(true);
        return $method->invoke(null);
    }

    public function testDirectHttpsIsTrusted(): void
    {
        $_SERVER['HTTPS'] = 'on';
        $this->assertTrue($this->invokeIsRequestHttps());
    }

    public function testDirectHttpIsNotSecure(): void
    {
        unset($_SERVER['HTTPS']);
        $_SERVER['REMOTE_ADDR'] = '203.0.113.5';
        $this->assertFalse($this->invokeIsRequestHttps());
    }

    /**
     * Ismert PHP/IIS-csapda: nem-TLS kérés esetén a $_SERVER['HTTPS']
     * értéke szó szerint az "off" STRING lehet — egy sima
     * !empty($_SERVER['HTTPS']) ellenőrzés (a korábbi kód) ezt tévesen
     * igaznak venné, mert egy nem-üres string mindig "truthy".
     */
    public function testIisStyleHttpsOffStringIsNotTreatedAsHttps(): void
    {
        $_SERVER['HTTPS'] = 'off';
        $_SERVER['REMOTE_ADDR'] = '203.0.113.5';
        $this->assertFalse($this->invokeIsRequestHttps());
    }

    public function testTrustedLoopbackProxyForwardedHttpsIsTrusted(): void
    {
        unset($_SERVER['HTTPS']);
        $_SERVER['REMOTE_ADDR'] = '127.0.0.1';
        $_SERVER['HTTP_X_FORWARDED_PROTO'] = 'https';
        $this->assertTrue($this->invokeIsRequestHttps(), 'Egy ugyanazon a gépen futó (loopbackről érkező) reverse proxy X-Forwarded-Proto jelzésének meg kell bíznunk.');
    }

    public function testIpv6LoopbackProxyForwardedHttpsIsAlsoTrusted(): void
    {
        unset($_SERVER['HTTPS']);
        $_SERVER['REMOTE_ADDR'] = '::1';
        $_SERVER['HTTP_X_FORWARDED_PROTO'] = 'https';
        $this->assertTrue($this->invokeIsRequestHttps());
    }

    /**
     * A LEGFONTOSABB negatív teszt: egy TÁVOLI (nem loopback) klienstől
     * közvetlenül érkező X-Forwarded-Proto fejlécet SOSE szabad elfogadni
     * — különben egy tetszőleges internetes kliens saját maga jelenthetné
     * be hamisan "https"-nek a kérését, és ezzel befolyásolhatná a
     * biztonsági döntést (Secure-süti), miközben a kapcsolat valójában
     * lehet akár egyszerű, titkosítatlan HTTP is.
     */
    public function testUntrustedRemoteClientCannotForgeForwardedProto(): void
    {
        unset($_SERVER['HTTPS']);
        $_SERVER['REMOTE_ADDR'] = '203.0.113.5';
        $_SERVER['HTTP_X_FORWARDED_PROTO'] = 'https';
        $this->assertFalse($this->invokeIsRequestHttps(), 'Egy közvetlenül csatlakozó, NEM loopback kliens ne tudja meghamisítani az X-Forwarded-Proto fejlécet.');
    }

    public function testMissingRemoteAddrIsNotTrusted(): void
    {
        unset($_SERVER['HTTPS'], $_SERVER['REMOTE_ADDR']);
        $_SERVER['HTTP_X_FORWARDED_PROTO'] = 'https';
        $this->assertFalse($this->invokeIsRequestHttps());
    }
}
