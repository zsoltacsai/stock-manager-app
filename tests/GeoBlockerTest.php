<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class GeoBlockerTest extends TestCase
{
    public function testIsPublicIpRejectsPrivateAndLoopback(): void
    {
        $this->assertFalse(GeoBlocker::isPublicIp('127.0.0.1'));
        $this->assertFalse(GeoBlocker::isPublicIp('192.168.1.10'));
        $this->assertFalse(GeoBlocker::isPublicIp('10.0.0.5'));
        $this->assertFalse(GeoBlocker::isPublicIp('::1'));
        $this->assertTrue(GeoBlocker::isPublicIp('8.8.8.8'));
    }

    public function testCheckAllowsEverythingWhenDisabled(): void
    {
        $result = GeoBlocker::check(['geo_block_enabled' => false], '8.8.8.8');
        $this->assertTrue($result['allowed']);
    }

    public function testCheckAllowsPrivateIpEvenWhenEnabled(): void
    {
        $result = GeoBlocker::check(['geo_block_enabled' => true, 'geo_block_countries' => 'DE'], '192.168.1.5');
        $this->assertTrue($result['allowed']);
    }

    public function testCheckAllowsExactIpOnAllowList(): void
    {
        $result = GeoBlocker::check([
            'geo_block_enabled' => true,
            'geo_block_countries' => 'DE',
            'geo_block_allow_ips' => '203.0.113.7, 198.51.100.0/24',
        ], '203.0.113.7');
        $this->assertTrue($result['allowed']);
        $this->assertStringContainsString('engedélyezési listán', $result['reason']);
    }

    public function testCheckAllowsCidrRangeOnAllowList(): void
    {
        $result = GeoBlocker::check([
            'geo_block_enabled' => true,
            'geo_block_countries' => 'DE',
            'geo_block_allow_ips' => '198.51.100.0/24',
        ], '198.51.100.42');
        $this->assertTrue($result['allowed']);
    }

    public function testCheckAllowsAllWhenNoCountryConfigured(): void
    {
        $result = GeoBlocker::check(['geo_block_enabled' => true, 'geo_block_countries' => ''], '8.8.8.8');
        $this->assertTrue($result['allowed']);
    }

    // Megjegyzés: a "nem az allow-listán/CIDR-en lévő, publikus IP, ország-
    // szűréssel bekapcsolva" eset a GeoBlocker::lookupCountry()-n keresztül
    // valódi hálózati hívást (ip-api.com) indítana — ezt a tesztkészlet
    // szándékosan nem hívja meg, hogy a tesztek gyorsak és hálózatfüggetlenek
    // maradjanak.
}
