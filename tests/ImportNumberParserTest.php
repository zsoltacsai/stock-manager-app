<?php

declare(strict_types=1);

require_once __DIR__ . '/../src/SpreadsheetNumber.php';
require_once __DIR__ . '/../src/XlsxReader.php';

use PHPUnit\Framework\TestCase;

/**
 * B-10 (correctness audit) regresszió — ProductRowNormalizer::parseNumberStrict().
 *
 * Kontextusok (a második paraméter):
 *   - AUTO (null): a forrás tizedesjele NEM ismert — ez a jelenlegi import-
 *     profilok (Axel Pro CSV, Jutasoft) szerződése. Csak az egyértelmű
 *     alakok elfogadottak; a kétértelmű "1.234"/"1,234" elutasított.
 *   - HU (','): a profil rögzíti, hogy a tizedesjel vessző (a pont/szóköz
 *     ezres-elválasztó).
 *   - EN ('.'): a profil rögzíti, hogy a tizedesjel pont (a vessző ezres-
 *     elválasztó).
 *   - XLSX/XLS numerikus cella: gépi érték → SpreadsheetNumber::canonical()
 *     → AUTO parser (egyértelmű kanonikus alak).
 * Az "ERR" érték: explicit validációs hiba (a sor elutasításra kerül, lásd
 * validationError()) — SOHA nem csendes 0. Az "EMPTY": üres cella (a
 * meglévő szerződés szerint a mező 0).
 */
final class ImportNumberParserTest extends TestCase
{
    public static function contractTable(): array
    {
        //            bemenet          AUTO           HU (',')       EN ('.')
        return [
            '1234'         => ['1234',          1234.0,        1234.0,        1234.0],
            '1.234'        => ['1.234',         'ERR',         1234.0,        1.234],
            '1,234'        => ['1,234',         'ERR',         1.234,         1234.0],
            '1.234,56'     => ['1.234,56',      1234.56,       1234.56,       'ERR'],
            '1,234.56'     => ['1,234.56',      1234.56,       'ERR',         1234.56],
            '12.345.678'   => ['12.345.678',    12345678.0,    12345678.0,    'ERR'],
            '1,234,567'    => ['1,234,567',     1234567.0,     'ERR',         1234567.0],
            '1.5E+3'       => ['1.5E+3',        1500.0,        1500.0,        1500.0],
            '1.0E-2'       => ['1.0E-2',        0.01,          0.01,          0.01],
            '#N/A'         => ['#N/A',          'ERR',         'ERR',         'ERR'],
            'üres cella'   => ['',              'EMPTY',       'EMPTY',       'EMPTY'],
            'csak szóköz'  => ['   ',           'EMPTY',       'EMPTY',       'EMPTY'],
            'negatív'      => ['-5',            -5.0,          -5.0,          -5.0],
            'negatív tört' => ['-12,5',         -12.5,         -12.5,         'ERR'],
            'szöveg'       => ['abc',           'ERR',         'ERR',         'ERR'],
            'HU szóközös'  => ['1 234,56',      1234.56,       1234.56,       'ERR'],
            'NBSP'         => ["1\u{00A0}234", 1234.0,        1234.0,        1234.0],
            'pénznem'      => ['1 200 Ft',      1200.0,        1200.0,        1200.0],
            'HUF elöl'     => ['HUF 1200',      1200.0,        1200.0,        1200.0],
            ',- végű'      => ['1200,-',        1200.0,        1200.0,        1200.0],
            '0,125'        => ['0,125',         0.125,         0.125,         'ERR'],
            '1234.567'     => ['1234.567',      1234.567,      'ERR',         1234.567],
            'rossz csop.'  => ['1.23.456',      'ERR',         'ERR',         'ERR'],
            'indiai csop.' => ['12,34,567',     'ERR',         'ERR',         'ERR'],
            '#VALUE!'      => ['#VALUE!',       'ERR',         'ERR',         'ERR'],
            '#DIV/0!'      => ['#DIV/0!',       'ERR',         'ERR',         'ERR'],
            'NaN'          => ['NaN',           'ERR',         'ERR',         'ERR'],
            'INF'          => ['INF',           'ERR',         'ERR',         'ERR'],
            'túlcsordulás' => ['1e999',         'ERR',         'ERR',         'ERR'],
            'dupla előjel' => ['--5',           'ERR',         'ERR',         'ERR'],
            'hátsó mínusz' => ['5-',            'ERR',         'ERR',         'ERR'],
        ];
    }

    private static function outcome(array $result): float|string
    {
        if ($result['error'] !== null) {
            return 'ERR';
        }
        return $result['value'] === null ? 'EMPTY' : $result['value'];
    }

    /** @dataProvider contractTable */
    public function testParserContract(string $input, float|string $auto, float|string $hu, float|string $en): void
    {
        $this->assertSame($auto, self::outcome(ProductRowNormalizer::parseNumberStrict($input)), "AUTO: '$input'");
        $this->assertSame($hu, self::outcome(ProductRowNormalizer::parseNumberStrict($input, ',')), "HU: '$input'");
        $this->assertSame($en, self::outcome(ProductRowNormalizer::parseNumberStrict($input, '.')), "EN: '$input'");
    }

    public function testErrorsCarryAHumanReadableReason(): void
    {
        $this->assertStringContainsString('kétértelmű', ProductRowNormalizer::parseNumberStrict('1.234')['error']);
        $this->assertStringContainsString('hibaérték', ProductRowNormalizer::parseNumberStrict('#N/A')['error']);
    }

    public static function spreadsheetCells(): array
    {
        // XLSX <v> tartalma numerikus cellánál (gépi formátum) → kanonikus → érték
        return [
            ['12.345', 12.345], ['1234', 1234.0], ['1.5E+3', 1500.0], ['1.0E-2', 0.01],
            ['-12.345', -12.345], ['0.125', 0.125], ['1234.5', 1234.5], ['2.9999999999999996', 2.9999999999999996],
        ];
    }

    /** @dataProvider spreadsheetCells */
    public function testSpreadsheetNumericCellsAreUnambiguous(string $machine, float $expected): void
    {
        $result = ProductRowNormalizer::parseNumberStrict(SpreadsheetNumber::canonical($machine));
        $this->assertNull($result['error'], "Gépi cella: $machine");
        $this->assertEqualsWithDelta($expected, $result['value'], 1e-9);
    }

    public function testRealXlsxFileWithNumericTextAndErrorCells(): void
    {
        $path = sys_get_temp_dir() . '/sm_b10_' . bin2hex(random_bytes(5)) . '.xlsx';
        $zip = new ZipArchive();
        $zip->open($path, ZipArchive::CREATE);
        $zip->addFromString('[Content_Types].xml', '<?xml version="1.0"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"/>');
        $zip->addFromString('xl/workbook.xml', '<?xml version="1.0"?><workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"><sheets><sheet name="S" sheetId="1" r:id="rId1"/></sheets></workbook>');
        $zip->addFromString('xl/_rels/workbook.xml.rels', '<?xml version="1.0"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/></Relationships>');
        $zip->addFromString('xl/worksheets/sheet1.xml', '<?xml version="1.0"?><worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><sheetData>'
            . '<row r="1"><c r="A1" t="inlineStr"><is><t>n</t></is></c><c r="B1" t="n"><v>12.345</v></c><c r="C1"><v>1.5E+3</v></c><c r="D1" t="e"><v>#N/A</v></c><c r="E1" t="inlineStr"><is><t>1.234</t></is></c></row>'
            . '</sheetData></worksheet>');
        $zip->close();

        try {
            $rows = XlsxReader::readRows($path);
        } finally {
            @unlink($path);
        }
        [, $numeric, $sci, $error, $text] = $rows[0];
        $this->assertSame(12.345, ProductRowNormalizer::parseNumberStrict($numeric)['value'], 'Numerikus 12.345 cella: 12,345 — nem 12345, és nem kétértelmű.');
        $this->assertSame(1500.0, ProductRowNormalizer::parseNumberStrict($sci)['value']);
        $this->assertNotNull(ProductRowNormalizer::parseNumberStrict($error)['error'], 'Hibacella nem lehet 0.');
        $this->assertNotNull(ProductRowNormalizer::parseNumberStrict($text)['error'], 'SZÖVEGES "1.234" kétértelmű marad.');
    }

    // ------------------------------------------------------------------
    // Beépülés a normalize()/validationError() import-útvonalba
    // ------------------------------------------------------------------

    private function profile(array $overrides = []): array
    {
        $profiles = require __DIR__ . '/../src/ImportProfiles.php';
        return array_merge($profiles['axel_pro'], $overrides);
    }

    public function testNaPriceRowIsRejectedNotImportedAsZero(): void
    {
        $n = ProductRowNormalizer::normalize(['name' => 'Hibás', 'price' => '#N/A', 'net_price' => '1000', 'stock_qty' => '5'], $this->profile());
        $this->assertStringContainsString('bruttó ár', (string) ProductRowNormalizer::validationError($n));
        $this->assertStringContainsString('#N/A', (string) ProductRowNormalizer::validationError($n));
    }

    public function testAmbiguousStockIsRejected(): void
    {
        $n = ProductRowNormalizer::normalize(['name' => 'Kétértelmű', 'price' => '1270', 'stock_qty' => '1.234'], $this->profile());
        $this->assertStringContainsString('készlet', (string) ProductRowNormalizer::validationError($n));
    }

    public function testFractionalStockIsRejectedNotRounded(): void
    {
        $n = ProductRowNormalizer::normalize(['name' => 'Tört', 'price' => '1270', 'stock_qty' => '2,5'], $this->profile());
        $this->assertStringContainsString('egész', (string) ProductRowNormalizer::validationError($n));
    }

    public function testDeclaredDecimalSeparatorResolvesAmbiguity(): void
    {
        $n = ProductRowNormalizer::normalize(['name' => 'HU', 'price' => '1.234', 'stock_qty' => '12.345'], $this->profile(['decimal_separator' => ',']));
        $this->assertNull(ProductRowNormalizer::validationError($n));
        $this->assertSame(1234.0, $n['price']);
        $this->assertSame(12345, $n['stock_qty']);
    }

    public function testValidRowStillImportsAndEmptyFieldsKeepTheExistingZeroContract(): void
    {
        $n = ProductRowNormalizer::normalize(['name' => 'Jó', 'price' => '1 270 Ft', 'net_price' => '', 'stock_qty' => '12.345.678', 'purchase_price_net' => '800,50'], $this->profile());
        $this->assertNull(ProductRowNormalizer::validationError($n));
        $this->assertSame([1270.0, 0.0, 12345678, 800.5], [$n['price'], $n['net_price'], $n['stock_qty'], $n['purchase_price_net']]);
    }

    public function testNegativePriceIsStillRejectedAndNegativeStockStillAllowed(): void
    {
        $neg = ProductRowNormalizer::normalize(['name' => 'N', 'price' => '-100', 'stock_qty' => '-3'], $this->profile());
        $this->assertStringContainsString('negatív ár', (string) ProductRowNormalizer::validationError($neg));
        $this->assertSame(-3, $neg['stock_qty']);
    }

    public function testImportCommitFlowRejectsBadRowsAndKeepsGoodOnes(): void
    {
        // Ugyanaz a ciklus, mint webroot/api/import-commit.php-ban.
        $db = tests_new_database();
        $rows = [
            ['name' => 'Rendben', 'barcode' => '5990000001001', 'price' => '1 270', 'stock_qty' => '5'],
            ['name' => 'NA ár', 'barcode' => '5990000001002', 'price' => '#N/A', 'stock_qty' => '5'],
            ['name' => 'Kétértelmű készlet', 'barcode' => '5990000001003', 'price' => '100', 'stock_qty' => '1,234'],
        ];
        $imported = [];
        $rejected = [];
        foreach ($rows as $row) {
            $n = ProductRowNormalizer::normalize($row, $this->profile());
            if (($error = ProductRowNormalizer::validationError($n)) !== null) {
                $rejected[] = $error;
                continue;
            }
            $imported[] = $db->importUpsertProduct($n)['id'];
        }
        $this->assertCount(1, $imported);
        $this->assertCount(2, $rejected);
        $this->assertNull($db->findProductByBarcode('5990000001002'), 'A #N/A árú termék nem jöhet létre 0 Ft-tal.');
    }

    // ------------------------------------------------------------------
    // Property / fuzz
    // ------------------------------------------------------------------

    public function testFuzzMalformedInputNeverThrowsAndNeverYieldsNonFiniteValues(): void
    {
        mt_srand(20260925);
        $violations = [];
        $alphabet = str_split("0123456789.,-+ eE#/NA%Ft€'\t") ;
        for ($i = 0; $i < 5000; $i++) {
            $len = mt_rand(0, 24);
            $s = '';
            for ($j = 0; $j < $len; $j++) {
                $s .= $alphabet[mt_rand(0, count($alphabet) - 1)];
            }
            if (mt_rand(0, 9) === 0) {
                $s .= random_bytes(mt_rand(1, 4)); // érvénytelen UTF-8 / bináris szemét
            }
            foreach ([null, ',', '.'] as $ctx) {
                $r = ProductRowNormalizer::parseNumberStrict($s, $ctx);
                if ($r['error'] === null && $r['value'] !== null && !is_finite($r['value'])) {
                    $violations[] = 'nem véges: ' . bin2hex($s);
                }
                if ($r['error'] !== null && $r['value'] !== null) {
                    $violations[] = 'hiba mellett érték: ' . bin2hex($s);
                }
            }
        }
        $this->assertSame([], $violations);
    }

    public function testPropertyGroupedIntegersRoundTripInEveryUnambiguousFormat(): void
    {
        mt_srand(4242);
        $violations = [];
        for ($i = 0; $i < 2000; $i++) {
            $n = mt_rand(0, 999_999_999);
            $cents = mt_rand(0, 99);
            $hu = number_format($n, 0, ',', '.') . ',' . sprintf('%02d', $cents);   // 12.345.678,90
            $en = number_format($n, 0, '.', ',') . '.' . sprintf('%02d', $cents);   // 12,345,678.90
            $sp = number_format($n, 0, ',', ' ') . ',' . sprintf('%02d', $cents);   // 12 345 678,90
            $expected = $n + $cents / 100;
            foreach ([[$hu, null], [$en, null], [$sp, null], [$hu, ','], [$en, '.']] as [$formatted, $ctx]) {
                $r = ProductRowNormalizer::parseNumberStrict($formatted, $ctx);
                if ($r['error'] !== null || abs((float) $r['value'] - $expected) > 0.001) {
                    $violations[] = $formatted . ' (' . var_export($ctx, true) . ')';
                }
            }
        }
        $this->assertSame([], $violations);
    }

    public function testPropertyAmbiguousShapeIsAlwaysRejectedWithoutContext(): void
    {
        $accepted = [];
        for ($lead = 1; $lead <= 999; $lead += 7) {
            foreach (['.', ','] as $sep) {
                $s = $lead . $sep . sprintf('%03d', mt_rand(0, 999));
                if (ProductRowNormalizer::parseNumberStrict($s)['error'] === null) {
                    $accepted[] = $s;
                }
            }
        }
        $this->assertSame([], $accepted);
    }
}
