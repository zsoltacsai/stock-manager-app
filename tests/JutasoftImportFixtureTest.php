<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * 1.1.1 — valódi, reprezentatív Jutasoft "Raktárkészlet nyomtatás" export
 * fixture (tests/fixtures/jutasoft_export.csv), a korábban validált
 * exportstruktúra alapján: 6 sornyi riport-metaadat a fejléc előtt, majd
 * termék-sorok (teljes azonosítóval, csak cikkszámmal, és egy negatív
 * (hibás) árral), végül két összesítő/ÁFA-bontás sor azonosító nélkül. Ez a
 * teszt a TELJES import-pipeline-t futtatja végig (CsvImporter::readRows()
 * → ProductRowNormalizer::normalize()/shouldSkip()/validationError()) a
 * valós fájlon, nem csak elszigetelt inline stringeken (lásd
 * ProductRowNormalizerTest.php azokért) — annak bizonyítására, hogy a
 * teljes lánc ugyanazt a mappinget adja, mint amit korábban egy valódi
 * exporton validáltunk.
 */
final class JutasoftImportFixtureTest extends TestCase
{
    private function profile(): array
    {
        $profiles = require __DIR__ . '/../src/ImportProfiles.php';
        return $profiles['jutasoft'];
    }

    private function parseFixture(): array
    {
        $profile = $this->profile();
        return CsvImporter::readRows(__DIR__ . '/fixtures/jutasoft_export.csv', $profile['field_map'], $profile['skip_lines']);
    }

    public function testHeaderIsReadAfterSkippingSixMetadataLines(): void
    {
        $parsed = $this->parseFixture();
        $this->assertSame(
            ['Vonalkód', 'Kód', 'Megnevezés', 'Típus', 'Készlet', 'M.Egys', 'Besz.ár', 'Egys.ár', 'ÁFA%'],
            $parsed['header']
        );
        $this->assertCount(9, $parsed['matched_fields'], 'A profil field_map minden mezőjének meg kell találnia a párját a fejlécben.');
        $this->assertSame([], $parsed['unmatched_fields']);
    }

    public function testAllDataAndSummaryRowsAreParsed(): void
    {
        $parsed = $this->parseFixture();
        // 5 valódi termék-sor + 2 összesítő sor = 7 sor a CsvImporter szintjén
        // — a normalizálás/szűrés egy KÉSŐBBI lépés (lásd lentebb), a nyers
        // beolvasás minden nem üres sort visszaad.
        $this->assertCount(7, $parsed['rows']);
    }

    public function testSummaryRowsAreSkippedButRealRowsWithOnlyCikkszamAreKept(): void
    {
        $profile = $this->profile();
        $parsed = $this->parseFixture();

        $kept = [];
        $skipped = [];
        foreach ($parsed['rows'] as $row) {
            $normalized = ProductRowNormalizer::normalize($row, $profile);
            if (ProductRowNormalizer::shouldSkip($normalized, $profile)) {
                $skipped[] = $normalized['name'];
            } else {
                $kept[] = $normalized['name'];
            }
        }

        $this->assertCount(2, $skipped, 'A két összesítő/ÁFA-bontás sornak ki kell esnie (nincs se vonalkód, se kód).');
        $this->assertSame(['Eladási érték 27%-os ÁFA kulcs:', 'Összesen:'], $skipped);

        $this->assertCount(5, $kept);
        $this->assertContains(
            'Hegesztőpálca 2mm',
            $kept,
            'A csak cikkszámmal (vonalkód nélkül) rendelkező sor NEM eshet ki — van azonosítója.'
        );
    }

    public function testPurchasePriceIsTreatedAsNetNotGross(): void
    {
        // A "Besz.ár" (purchase_price_net) oszlop a Jutasoft exportban NETTÓ
        // érték — a normalizáló NEM alkalmaz rá ÁFA-átszámítást (ellentétben
        // az eladási árral, aminél a net_price a bruttó+ÁFA%-ból származik,
        // ha nincs külön nettó eladási ár oszlop). Ez a regresszió explicit
        // bizonyítja, hogy a "Besz.ár" mező VÁLTOZATLANUL, konvertálás
        // nélkül kerül a purchase_price_net mezőbe.
        $profile = $this->profile();
        $parsed = $this->parseFixture();

        $normalized = array_map(fn($row) => ProductRowNormalizer::normalize($row, $profile), $parsed['rows']);
        $drill = current(array_filter($normalized, fn($n) => $n['name'] === 'Csavarhúzó készlet 6 részes'));

        $this->assertNotFalse($drill);
        $this->assertSame(1200.0, $drill['purchase_price_net'], 'A Besz.ár (1200) NETTÓ értékként, ÁFA-konverzió nélkül kerül be.');
        $this->assertSame(1900.0, $drill['price'], 'Az Egys.ár (bruttó eladási ár) változatlanul kerül át.');
    }

    public function testNegativePurchasePriceRowFailsValidation(): void
    {
        $profile = $this->profile();
        $parsed = $this->parseFixture();

        $normalized = array_map(fn($row) => ProductRowNormalizer::normalize($row, $profile), $parsed['rows']);
        $damaged = current(array_filter($normalized, fn($n) => str_contains($n['name'], 'Sérült tétel')));

        $this->assertNotFalse($damaged);
        $this->assertFalse(ProductRowNormalizer::shouldSkip($damaged, $profile), 'Van azonosítója — NEM a shouldSkip() szűri ki, hanem az ár-validáció.');
        $this->assertSame(-100.0, $damaged['purchase_price_net']);

        $error = ProductRowNormalizer::validationError($damaged);
        $this->assertNotNull($error, 'A negatív beszerzési árnak el kell buknia a validáción.');
        $this->assertStringContainsString('negatív', $error);
    }

    public function testMixedValidAndInvalidRowsYieldExactlyExpectedAcceptRejectCounts(): void
    {
        // Végponttól-végpontig: 5 érdemi termék-sor közül 4 érvényes, 1
        // (negatív beszerzési árú) elutasítandó — pontosan azt a "98 valid +
        // 2 invalid → 98 imported + 2 rejected" mintát reprodukálja, amit az
        // import-commit.php-beli soronkénti (nem all-or-nothing) feldolgozás
        // garantál.
        $profile = $this->profile();
        $parsed = $this->parseFixture();

        $accepted = 0;
        $rejected = 0;
        $skippedAsNonProduct = 0;
        foreach ($parsed['rows'] as $row) {
            $normalized = ProductRowNormalizer::normalize($row, $profile);
            if (ProductRowNormalizer::shouldSkip($normalized, $profile)) {
                $skippedAsNonProduct++;
                continue;
            }
            if (ProductRowNormalizer::validationError($normalized) !== null) {
                $rejected++;
                continue;
            }
            $accepted++;
        }

        $this->assertSame(2, $skippedAsNonProduct);
        $this->assertSame(1, $rejected);
        $this->assertSame(4, $accepted);
    }
}
