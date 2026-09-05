<?php

/**
 * Egyszerű, függőségmentes Excel-export — a "SpreadsheetML" (Excel 2003
 * XML) formátumot írja, amit minden Excel/LibreOffice/Google Sheets
 * megnyit .xls kiterjesztéssel is. Nincs szükség a `zip` kiterjesztésre
 * (valódi .xlsx-hez az kellene) — csak a már amúgy is kötelező
 * `xmlwriter`-re (lásd SzamlazzClient.php).
 *
 * MEGJEGYZÉS: mivel a fájl tartalma XML, nem a klasszikus bináris .xls,
 * egy újabb Excel megnyitáskor egy "a fájlformátum és a kiterjesztés nem
 * egyezik" figyelmeztetést mutathat — ez ártalmatlan, "Igen"-nel simán
 * megnyílik. Ez egy elterjedt, régóta bevett technika .xls export
 * generálására natív bináris író könyvtár nélkül.
 */
class SimpleXlsWriter
{
    /**
     * @param string $filename letöltési fájlnév (pl. "arucikkek.xls")
     * @param string[] $headers oszlopfejlécek
     * @param array<int, array<int, string|int|float|null>> $rows soronkénti cellaértékek, a headers sorrendjében
     */
    public static function output(string $filename, array $headers, array $rows): void
    {
        header('Content-Type: application/vnd.ms-excel; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . $filename . '"');

        $xw = new XMLWriter();
        $xw->openURI('php://output');
        $xw->startDocument('1.0', 'UTF-8');
        $xw->writePi('mso-application', 'progid="Excel.Sheet"');

        $xw->startElement('Workbook');
        $xw->writeAttribute('xmlns', 'urn:schemas-microsoft-com:office:spreadsheet');
        $xw->writeAttributeNs('xmlns', 'ss', null, 'urn:schemas-microsoft-com:office:spreadsheet');

        $xw->startElement('Worksheet');
        $xw->writeAttributeNs('ss', 'Name', null, 'Munka1');
        $xw->startElement('Table');

        self::writeRow($xw, $headers);
        foreach ($rows as $row) {
            self::writeRow($xw, $row);
        }

        $xw->endElement(); // Table
        $xw->endElement(); // Worksheet
        $xw->endElement(); // Workbook
        $xw->endDocument();
        $xw->flush();
    }

    private static function writeRow(XMLWriter $xw, array $cells): void
    {
        $xw->startElement('Row');
        foreach ($cells as $cell) {
            $xw->startElement('Cell');
            $xw->startElement('Data');
            $isNumeric = (is_int($cell) || is_float($cell));
            $xw->writeAttributeNs('ss', 'Type', null, $isNumeric ? 'Number' : 'String');
            $xw->text((string) ($cell ?? ''));
            $xw->endElement(); // Data
            $xw->endElement(); // Cell
        }
        $xw->endElement(); // Row
    }
}
