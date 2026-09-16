<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * A Dashboard fejléc dátum/névnap-logikájának determinisztikus tesztjei —
 * MINDEN teszt egy explicit, rögzített dátummal dolgozik (nem a "mai nap"-
 * pal), hogy a teszt eredménye sose függjön attól, mikor fut le a suite.
 */
final class HungarianNameDaysTest extends TestCase
{
    public function testFormatsTheExactExampleFromTheSpec(): void
    {
        // A kör saját példája: "Szerda, szeptember 16." — Edit névnappal.
        $ts = strtotime('2026-09-16');
        $this->assertSame('Szerda, szeptember 16.', HungarianNameDays::formatHungarianDate($ts));
        $this->assertSame('Edit, Ciprián', HungarianNameDays::getNameDay(9, 16));
    }

    public function testDeterministicSwitchToADifferentDate(): void
    {
        // "holnapi/másik dátumra váltás" — két KÜLÖNBÖZŐ, explicit dátum,
        // bizonyítva, hogy a logika ténylegesen a paraméterből (nem egy
        // rejtett "ma" állapotból) számol.
        $this->assertSame('Csütörtök, szeptember 17.', HungarianNameDays::formatHungarianDate(strtotime('2026-09-17')));
        $this->assertSame('Zsófia, Róbert', HungarianNameDays::getNameDay(9, 17));

        $this->assertSame('Péntek, január 1.', HungarianNameDays::formatHungarianDate(strtotime('2027-01-01')));
        $this->assertSame('Alpár, Fruzsina, Bazil', HungarianNameDays::getNameDay(1, 1));
    }

    public function testJanuary23And24HaveNoAssignedNameDayAndReturnNullNotAFabricatedName(): void
    {
        $this->assertNull(HungarianNameDays::getNameDay(1, 23));
        $this->assertNull(HungarianNameDays::getNameDay(1, 24));
    }

    public function testLeapDayFebruary29HasNoAssignedNameDay(): void
    {
        // Ugyanaz az elv, mint január 23-24-nél: a forrásban erre a napra
        // nincs hitelesen megállapítható bejegyzés, ezért NEM rendelünk
        // hozzá saját döntés alapján kitalált nevet (pl. "Előd") — a
        // Dashboard ilyenkor egyszerűen nem jelenít meg "Névnap:" sort
        // (lásd dashboard.js renderHeader()).
        $this->assertNull(HungarianNameDays::getNameDay(2, 29));
        // A dátum-formázás (hét napja/hónap/nap) ettől FÜGGETLENÜL
        // változatlanul működik szökőnapon is — ez nem igényel névnapot.
        $this->assertSame('Vasárnap, február 29.', HungarianNameDays::formatHungarianDate(strtotime('2032-02-29')));
    }

    public function testFebruary28AndMarch1AreUnaffectedByTheLeapDayNameDayChange(): void
    {
        $this->assertSame('Elemér, Oszvald, Román', HungarianNameDays::getNameDay(2, 28));
        $this->assertSame('Albin, Albina, Leonita', HungarianNameDays::getNameDay(3, 1));
    }

    public function testEveryCalendarDayOfANonLeapYearHasAResolvableFormattedDate(): void
    {
        // 2027 nem szökőév — az év mind a 365 napjára le kell tudni futnia
        // formatHungarianDate()-nek hiba nélkül (nincs olyan hónap/nap
        // kombináció, amire a hét napjának/hónapnak a leképezése hiányozna).
        $date = new DateTimeImmutable('2027-01-01');
        for ($i = 0; $i < 365; $i++) {
            $formatted = HungarianNameDays::formatHungarianDate($date->getTimestamp());
            $this->assertMatchesRegularExpression('/^[A-ZÁÉÍÓÖŐÚÜŰ][a-záéíóöőúüű]+, [a-záéíóöőúüű]+ \d{1,2}\.$/u', $formatted);
            $date = $date->modify('+1 day');
        }
    }

    public function testEveryCalendarDayOfALeapYearHasAResolvableFormattedDateIncludingFebruary29(): void
    {
        // 2032 szökőév (366 nap) — ugyanaz a lefedettség-bizonyíték, mint a
        // nem szökőévi teszt, de itt KIFEJEZETTEN áthalad február 29-én is:
        // formatHungarianDate()-nek szökőnapon is hiba nélkül kell futnia,
        // annak ellenére, hogy getNameDay(2, 29) null-t ad.
        $date = new DateTimeImmutable('2032-01-01');
        $sawFeb29 = false;
        for ($i = 0; $i < 366; $i++) {
            if ((int) $date->format('n') === 2 && (int) $date->format('j') === 29) {
                $sawFeb29 = true;
            }
            $formatted = HungarianNameDays::formatHungarianDate($date->getTimestamp());
            $this->assertMatchesRegularExpression('/^[A-ZÁÉÍÓÖŐÚÜŰ][a-záéíóöőúüű]+, [a-záéíóöőúüű]+ \d{1,2}\.$/u', $formatted);
            $date = $date->modify('+1 day');
        }
        $this->assertTrue($sawFeb29, 'A 366 napos huroknak ténylegesen át kellett haladnia február 29-én — ellenőrzés, hogy a teszt maga ne hamis pozitívot adjon.');
    }

    public function testGetNameDayReturnsNullForOutOfRangeDay(): void
    {
        $this->assertNull(HungarianNameDays::getNameDay(4, 31)); // április csak 30 napos
    }
}
