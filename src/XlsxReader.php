<?php

class XlsxReader
{
    // Egy .xlsx belső XML-részének max. kicsomagolt mérete — egy néhány KB-os,
    // szándékosan roppant tömöríthető ("zip bomb") payloaddal becsomagolt
    // fájl enélkül GB-os memóriafoglalást okozhatna kicsomagoláskor, még
    // mielőtt bármi mást ellenőriznénk. Egy valódi, akár több tízezer soros
    // termékkatalógus XML-je is jóval e alatt marad.
    private const MAX_ENTRY_UNCOMPRESSED_BYTES = 100 * 1024 * 1024;

    public static function readRows(string $path): array
    {
        $zip = new ZipArchive();
        if ($zip->open($path) !== true) {
            throw new RuntimeException('Az .xlsx fájlt nem sikerült megnyitni (érvénytelen vagy sérült fájl).');
        }
        // Egy valódi .xlsx néhány tucat belső fájlból áll — egy ennél
        // jóval nagyobb bejegyzésszám gyanús (pl. egy másfajta zip-bomb
        // variáns sok apró, de számban töméntelen bejegyzéssel).
        if ($zip->numFiles > 500) {
            $zip->close();
            throw new RuntimeException('Az .xlsx fájl szerkezete gyanús (túl sok belső bejegyzés).');
        }

        try {
            $sharedStrings = self::readSharedStrings($zip);
            $sheetPath = self::firstSheetPath($zip);
            $sheetXml = self::safeGetFromName($zip, $sheetPath);
            if ($sheetXml === false) {
                throw new RuntimeException('Az .xlsx fájlban nem található munkalap.');
            }
            return self::parseSheet($sheetXml, $sharedStrings);
        } finally {
            $zip->close();
        }
    }

    /**
     * Ugyanaz, mint a ZipArchive::getFromName(), de előbb a kicsomagolt
     * méretet ellenőrzi (ZipArchive::statName() a tömörített adatot nem
     * csomagolja ki, csak a központi könyvtár bejegyzését olvassa) — így
     * egy túl nagyra kicsomagolódó bejegyzést sose csomagolunk ki ténylegesen.
     */
    private static function safeGetFromName(ZipArchive $zip, string $name)
    {
        $stat = $zip->statName($name);
        if ($stat === false) {
            return false;
        }
        if ($stat['size'] > self::MAX_ENTRY_UNCOMPRESSED_BYTES) {
            throw new RuntimeException('Az .xlsx fájl egyik belső része gyanúsan nagy — a fájl feldolgozása megszakítva.');
        }
        return $zip->getFromName($name);
    }

    private static function readSharedStrings(ZipArchive $zip): array
    {
        $xml = self::safeGetFromName($zip, 'xl/sharedStrings.xml');
        if ($xml === false) {
            return [];
        }
        $doc = self::loadXml($xml);
        $strings = [];
        foreach ($doc->getElementsByTagName('si') as $si) {
            $strings[] = self::textOfSi($si);
        }
        return $strings;
    }

    private static function textOfSi(DOMElement $si): string
    {
        $text = '';
        foreach ($si->getElementsByTagName('t') as $t) {
            $text .= $t->textContent;
        }
        return $text;
    }

    private static function firstSheetPath(ZipArchive $zip): string
    {
        $workbookXml = self::safeGetFromName($zip, 'xl/workbook.xml');
        $relsXml = self::safeGetFromName($zip, 'xl/_rels/workbook.xml.rels');

        if ($workbookXml !== false && $relsXml !== false) {
            $wb = self::loadXml($workbookXml);
            $sheets = $wb->getElementsByTagName('sheet');
            if ($sheets->length > 0) {
                $rId = $sheets->item(0)->getAttributeNS('http://schemas.openxmlformats.org/officeDocument/2006/relationships', 'id');
                if ($rId === '') {
                    foreach (iterator_to_array($sheets->item(0)->attributes) as $attr) {
                        if (str_ends_with($attr->nodeName, ':id')) {
                            $rId = $attr->nodeValue;
                            break;
                        }
                    }
                }
                if ($rId !== '') {
                    $rels = self::loadXml($relsXml);
                    foreach ($rels->getElementsByTagName('Relationship') as $rel) {
                        if ($rel->getAttribute('Id') === $rId) {
                            $target = $rel->getAttribute('Target');
                            $target = ltrim($target, '/');
                            return str_starts_with($target, 'xl/') ? $target : 'xl/' . $target;
                        }
                    }
                }
            }
        }

        return 'xl/worksheets/sheet1.xml';
    }

    private static function parseSheet(string $xml, array $sharedStrings): array
    {
        $doc = self::loadXml($xml);
        $rows = [];

        foreach ($doc->getElementsByTagName('row') as $rowEl) {
            $row = [];
            $maxCol = -1;
            foreach ($rowEl->getElementsByTagName('c') as $cellEl) {
                $ref = $cellEl->getAttribute('r');
                $col = $ref !== '' ? self::columnIndex($ref) : $maxCol + 1;
                $type = $cellEl->getAttribute('t');

                if ($type === 'inlineStr') {
                    $isNode = $cellEl->getElementsByTagName('is')->item(0);
                    $value = $isNode ? self::textOfSi($isNode) : '';
                } else {
                    $vNode = $cellEl->getElementsByTagName('v')->item(0);
                    $raw = $vNode ? $vNode->textContent : '';
                    if ($type === 's') {
                        $idx = (int) $raw;
                        $value = $sharedStrings[$idx] ?? '';
                    } else {
                        $value = $raw;
                    }
                }

                $row[$col] = $value;
                if ($col > $maxCol) {
                    $maxCol = $col;
                }
            }

            if ($maxCol < 0) {
                continue;
            }
            $dense = [];
            for ($i = 0; $i <= $maxCol; $i++) {
                $dense[] = $row[$i] ?? '';
            }
            if (implode('', $dense) === '') {
                continue;
            }
            $rows[] = $dense;
        }

        return $rows;
    }

    private static function columnIndex(string $cellRef): int
    {
        $letters = '';
        for ($i = 0; $i < strlen($cellRef); $i++) {
            $ch = $cellRef[$i];
            if ($ch < 'A' || $ch > 'Z') {
                break;
            }
            $letters .= $ch;
        }
        $index = 0;
        for ($i = 0; $i < strlen($letters); $i++) {
            $index = $index * 26 + (ord($letters[$i]) - ord('A') + 1);
        }
        return $index - 1;
    }

    private static function loadXml(string $xml): DOMDocument
    {
        $doc = new DOMDocument();
        $prevErrors = libxml_use_internal_errors(true);
        try {
            if (!$doc->loadXML($xml, LIBXML_NONET)) {
                throw new RuntimeException('Az .xlsx fájl egyik XML része érvénytelen.');
            }
        } finally {
            libxml_use_internal_errors($prevErrors);
        }
        return $doc;
    }
}
