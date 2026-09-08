<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * A Jutasoft import-profil hozzáadásakor a ProductRowNormalizer két új
 * képességet kapott: (1) nettó ár levezetése bruttó ár + sortonkénti
 * ÁFA%-ból, ha nincs külön nettó ár oszlop; (2) shouldSkip(), ami a
 * "van neve, de nincs semmilyen termék-azonosítója" sorokat (pl. a
 * Jutasoft-riport végi összesítő/ÁFA-bontás sorai) is kiszűri, nem csak
 * az üres nevűeket. Mindkettő csak akkor lép életbe, ha a profil
 * ténylegesen ezt kéri — az Axel Pro profil viselkedése változatlan kell
 * maradjon.
 */
final class ProductRowNormalizerTest extends TestCase
{
    private function jutasoftProfile(): array
    {
        $profiles = require __DIR__ . '/../src/ImportProfiles.php';
        return $profiles['jutasoft'];
    }

    private function axelProProfile(): array
    {
        $profiles = require __DIR__ . '/../src/ImportProfiles.php';
        return $profiles['axel_pro'];
    }

    public function testJutasoftProfileIsImplementedWithFieldMap(): void
    {
        $profile = $this->jutasoftProfile();
        $this->assertTrue($profile['implemented']);
        $this->assertIsArray($profile['field_map']);
        $this->assertSame('Típus', $profile['field_map']['group_name'], 'A "Típus" oszlopnak a Csoport mezőre kell képződnie.');
    }

    public function testDerivesNetPriceFromGrossAndPerRowVatPercent(): void
    {
        // Jutasoft nem ad külön nettó eladási ár oszlopot, csak bruttót és
        // egy "27%" formátumú ÁFA-oszlopot soronként.
        $row = ['name' => 'Teszt termék', 'price' => '30600', 'vat_rate' => '27%'];
        $normalized = ProductRowNormalizer::normalize($row, $this->jutasoftProfile());

        $this->assertSame(30600.0, $normalized['price']);
        $this->assertEqualsWithDelta(24094.49, $normalized['net_price'], 0.01);
        $this->assertSame('27', $normalized['vat_rate'], 'A visszaszámolt nettó/bruttó arányból ugyanannak az ÁFA-kulcsnak kell adódnia.');
    }

    public function testAxelProProfileUnaffectedByVatRateDerivation(): void
    {
        // Az Axel Pro field_map-jében nincs 'vat_rate' kulcs, tehát ennek a
        // profilnak a viselkedése nem változhat: nettó ár hiányában a
        // net_price 0 marad, és a VAT az alapértelmezettre esik vissza —
        // pontosan úgy, mint a funkció bevezetése előtt.
        $row = ['name' => 'Teszt termék', 'price' => '1270'];
        $normalized = ProductRowNormalizer::normalize($row, $this->axelProProfile());

        $this->assertSame(0.0, $normalized['net_price']);
        $this->assertSame('27', $normalized['vat_rate']);
    }

    public function testShouldSkipTreatsEmptyNameAsSkippableForEveryProfile(): void
    {
        $normalized = ProductRowNormalizer::normalize(['name' => ''], $this->axelProProfile());
        $this->assertTrue(ProductRowNormalizer::shouldSkip($normalized, $this->axelProProfile()));
    }

    public function testShouldSkipDropsNamedRowsWithoutAnyIdentifierWhenProfileRequestsIt(): void
    {
        // Ez a Jutasoft-riport végi összesítő sorokat reprodukálja: van
        // "neve" (valójában egy összesítő felirat), de nincs se vonalkódja,
        // se kódja.
        $row = ['name' => 'Eladási érték 27%-os ÁFA kulcs:', 'barcode' => '', 'cikkszam' => ''];
        $normalized = ProductRowNormalizer::normalize($row, $this->jutasoftProfile());
        $this->assertTrue(ProductRowNormalizer::shouldSkip($normalized, $this->jutasoftProfile()));
    }

    public function testShouldSkipKeepsNamedRowsWithAnIdentifierEvenWhenProfileRequestsFiltering(): void
    {
        $row = ['name' => 'Valódi termék', 'barcode' => '1234567891132', 'cikkszam' => '1163'];
        $normalized = ProductRowNormalizer::normalize($row, $this->jutasoftProfile());
        $this->assertFalse(ProductRowNormalizer::shouldSkip($normalized, $this->jutasoftProfile()));
    }

    public function testShouldSkipDoesNotFilterByIdentifierForProfilesThatDontRequestIt(): void
    {
        // Az Axel Pro nem kéri a skip_rows_without_identifier szűrést —
        // egy név nélküli-azonosítós sor NÁLA nem eshet ki emiatt (csak az
        // üres név miatt eshetne ki, ami itt nem áll fenn).
        $row = ['name' => 'Termék vonalkód/cikkszám nélkül', 'barcode' => '', 'cikkszam' => ''];
        $normalized = ProductRowNormalizer::normalize($row, $this->axelProProfile());
        $this->assertFalse(ProductRowNormalizer::shouldSkip($normalized, $this->axelProProfile()));
    }

    public function testCsvImporterSkipsLeadingReportMetadataLinesBeforeHeader(): void
    {
        // A Jutasoft-export fejléce előtt riport-metaadat sorok vannak —
        // a skip_lines paraméternek át kell ugornia ezeket, mielőtt a
        // tényleges oszlopfejléc sort beolvasná.
        $csv = "Raktárkészlet nyomtatás\n"
             . "A lista típusa :,Raktárkészlet lista\n"
             . "Vonalkód,Megnevezés,Típus,Készlet\n"
             . "123456,Teszt termék,PÉLDA,5\n";
        $path = sys_get_temp_dir() . '/sm_import_test_' . bin2hex(random_bytes(6)) . '.csv';
        file_put_contents($path, $csv);

        try {
            $fieldMap = ['name' => 'Megnevezés', 'barcode' => 'Vonalkód', 'group_name' => 'Típus', 'stock_qty' => 'Készlet'];
            $parsed = CsvImporter::readRows($path, $fieldMap, 2);

            $this->assertSame(['Vonalkód', 'Megnevezés', 'Típus', 'Készlet'], $parsed['header']);
            $this->assertCount(1, $parsed['rows']);
            $this->assertSame('Teszt termék', $parsed['rows'][0]['name']);
            $this->assertSame('PÉLDA', $parsed['rows'][0]['group_name']);
        } finally {
            @unlink($path);
        }
    }
}
