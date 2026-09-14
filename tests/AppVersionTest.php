<?php

declare(strict_types=1);

require_once __DIR__ . '/../src/AppVersion.php';

use PHPUnit\Framework\TestCase;

final class AppVersionTest extends TestCase
{
    public function testIsValidSemverAcceptsCanonicalForm(): void
    {
        $this->assertTrue(AppVersion::isValidSemver('1.0.0'));
        $this->assertTrue(AppVersion::isValidSemver('12.34.56'));
    }

    public function testIsValidSemverRejectsNonCanonicalForms(): void
    {
        $this->assertFalse(AppVersion::isValidSemver('1.0'));
        $this->assertFalse(AppVersion::isValidSemver('1.0.0-beta'));
        $this->assertFalse(AppVersion::isValidSemver('v1.0.0'));
        $this->assertFalse(AppVersion::isValidSemver('1.0.0.0'));
        $this->assertFalse(AppVersion::isValidSemver('not-a-version'));
        $this->assertFalse(AppVersion::isValidSemver(''));
    }

    public function testCompareOrdersMajorMinorPatchCorrectly(): void
    {
        $this->assertSame(0, AppVersion::compare('1.0.0', '1.0.0'));
        $this->assertLessThan(0, AppVersion::compare('1.0.0', '1.0.1'));
        $this->assertGreaterThan(0, AppVersion::compare('1.0.1', '1.0.0'));
        $this->assertLessThan(0, AppVersion::compare('1.0.9', '1.1.0'));
        $this->assertGreaterThan(0, AppVersion::compare('2.0.0', '1.9.9'));
        $this->assertLessThan(0, AppVersion::compare('1.9.9', '10.0.0'));
    }

    public function testCompareThrowsOnInvalidInput(): void
    {
        $this->expectException(InvalidArgumentException::class);
        AppVersion::compare('not-a-version', '1.0.0');
    }

    public function testIsDowngradeDetectsOlderTarget(): void
    {
        $this->assertTrue(AppVersion::isDowngrade('1.1.0', '1.0.0'));
        $this->assertFalse(AppVersion::isDowngrade('1.0.0', '1.1.0'));
        $this->assertFalse(AppVersion::isDowngrade('1.0.0', '1.0.0'));
    }

    public function testCurrentVersionIsValidSemver(): void
    {
        $this->assertTrue(AppVersion::isValidSemver(AppVersion::CURRENT));
    }
}
