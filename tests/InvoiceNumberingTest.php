<?php

declare(strict_types=1);

require_once __DIR__ . '/../src/InvoiceNumbering.php';

use PHPUnit\Framework\TestCase;

final class InvoiceNumberingTest extends TestCase
{
    public function testFormatProducesExpectedShapeForNav(): void
    {
        $this->assertSame('FT-NAV-2026-000001', InvoiceNumbering::format('nav', 1, '2026'));
        $this->assertSame('FT-NAV-2026-042000', InvoiceNumbering::format('nav', 42000, '2026'));
    }

    public function testFormatProducesExpectedShapeForSzamlazz(): void
    {
        $this->assertSame('FT-SZLA-2026-000007', InvoiceNumbering::format('szamlazz', 7, '2026'));
    }

    public function testFormatDefaultsToCurrentYearWhenOmitted(): void
    {
        $this->assertSame('FT-NAV-' . date('Y') . '-000001', InvoiceNumbering::format('nav', 1));
    }

    public function testFormatRejectsUnknownProvider(): void
    {
        $this->expectException(InvalidArgumentException::class);
        InvoiceNumbering::format('unknown-provider', 1);
    }

    public function testFormatRejectsNonPositiveNumber(): void
    {
        $this->expectException(InvalidArgumentException::class);
        InvoiceNumbering::format('nav', 0);
    }
}
