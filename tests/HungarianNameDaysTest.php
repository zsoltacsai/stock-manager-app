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

    public function testLeapDayFebruary29HasAFixedModernNameDay(): void
    {
        $this->assertSame('Előd', HungarianNameDays::getNameDay(2, 29));
        $this->assertSame('Vasárnap, február 29.', HungarianNameDays::formatHungarianDate(strtotime('2032-02-29')));
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

    public function testGetNameDayReturnsNullForOutOfRangeDay(): void
    {
        $this->assertNull(HungarianNameDays::getNameDay(4, 31)); // április csak 30 napos
    }
}
