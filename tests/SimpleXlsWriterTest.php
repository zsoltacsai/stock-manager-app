<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class SimpleXlsWriterTest extends TestCase
{
    private function capture(string $filename, array $headers, array $rows): string
    {
        ob_start();
        SimpleXlsWriter::output($filename, $headers, $rows);
        return ob_get_clean();
    }

    public function testOutputIsWellFormedXmlWithExpectedValues(): void
    {
        $xml = $this->capture('teszt.xls', ['Név', 'Ár'], [['Termék A', 1500], ['Termék B', 2000.5]]);

        $doc = new DOMDocument();
        $this->assertTrue($doc->loadXML($xml), 'A generált .xls tartalomnak érvényes XML-nek kell lennie.');

        $this->assertStringContainsString('<Data ss:Type="String">Név</Data>', $xml);
        $this->assertStringContainsString('<Data ss:Type="Number">1500</Data>', $xml);
        $this->assertStringContainsString('<Data ss:Type="Number">2000.5</Data>', $xml);
    }

    public function testOutputEscapesHtmlSpecialCharacters(): void
    {
        $xml = $this->capture('teszt.xls', ['Név'], [['<script>alert(1)</script>']]);

        $doc = new DOMDocument();
        $this->assertTrue($doc->loadXML($xml), 'Speciális karakterek mellett is érvényes XML-nek kell maradnia.');
        $this->assertStringNotContainsString('<script>', $xml);
        $this->assertStringContainsString('&lt;script&gt;', $xml);
    }

    public function testNullCellsBecomeEmptyStrings(): void
    {
        $xml = $this->capture('teszt.xls', ['Oszlop'], [[null]]);
        $doc = new DOMDocument();
        $this->assertTrue($doc->loadXML($xml));
    }
}
