<?php

declare(strict_types=1);

require_once __DIR__ . '/../src/ReportPeriod.php';

use PHPUnit\Framework\TestCase;

/**
 * B-14 (correctness audit) regresszió — a Dashboard AI-kártyájának "ma"-ja.
 *
 * A dashboard.js korábban `new Date().toISOString().slice(0, 10)`-zel,
 * azaz UTC-dátummal kérte a napi AI-jelentést: Budapesten éjfél után télen
 * 1, nyáron 2 órán át a TEGNAPI jelentést mutatta "mai"-ként. A kanonikus
 * alkalmazás-időzóna a PHP alapértelmezett időzónája, amit a
 * webroot/api/_bootstrap.php állít be (a projektben nincs konfigurálható
 * időzóna). A "ma"-t mostantól a szerver dönti el (ReportPeriod::today()),
 * UGYANAZT, amit az AI napi jelentés workere is használ; a kliens csak a
 * `date=today` kulcsszót küldi.
 */
final class AppTimezoneTodayTest extends TestCase
{
    private string $previousTz;

    protected function setUp(): void
    {
        $this->previousTz = date_default_timezone_get();
        // Pontosan az az időzóna, amit a futó alkalmazás használ — a
        // _bootstrap.php-ból olvasva, nem a tesztben rögzítve.
        preg_match("/date_default_timezone_set\('([^']+)'\)/", file_get_contents(dirname(__DIR__) . '/webroot/api/_bootstrap.php'), $m);
        $this->assertNotEmpty($m[1] ?? null, 'A kanonikus időzóna-forrás a _bootstrap.php.');
        date_default_timezone_set($m[1]);
    }

    protected function tearDown(): void
    {
        date_default_timezone_set($this->previousTz);
    }

    public static function instants(): array
    {
        // UTC-időpont                  várt alkalmazás-nap  (UTC-nap, ami korábban jött ki)
        return [
            'tél, Budapest 00:00'       => ['2026-01-15T23:00:00Z', '2026-01-16', '2026-01-15'],
            'tél, Budapest 00:30'       => ['2026-01-15T23:30:00Z', '2026-01-16', '2026-01-15'],
            'tél, Budapest 00:59:59'    => ['2026-01-15T23:59:59Z', '2026-01-16', '2026-01-15'],
            'tél, Budapest 01:00'       => ['2026-01-16T00:00:00Z', '2026-01-16', '2026-01-16'],
            'nyár, Budapest 00:00'      => ['2026-07-15T22:00:00Z', '2026-07-16', '2026-07-15'],
            'nyár, Budapest 01:59'      => ['2026-07-15T23:59:00Z', '2026-07-16', '2026-07-15'],
            'nyár, Budapest 02:00'      => ['2026-07-16T00:00:00Z', '2026-07-16', '2026-07-16'],
            'normál nap délben'         => ['2026-09-25T10:00:00Z', '2026-09-25', '2026-09-25'],
            'nap vége, 23:59 Budapest'  => ['2026-09-25T21:59:00Z', '2026-09-25', '2026-09-25'],
            'hónapváltás'               => ['2026-01-31T23:30:00Z', '2026-02-01', '2026-01-31'],
            'évváltás'                  => ['2025-12-31T23:30:00Z', '2026-01-01', '2025-12-31'],
            'tavaszi óraátállítás napja' => ['2026-03-28T23:30:00Z', '2026-03-29', '2026-03-28'],
            'átállítás után 03:30 CEST' => ['2026-03-29T01:30:00Z', '2026-03-29', '2026-03-29'],
            'őszi óraátállítás napja'   => ['2026-10-24T22:30:00Z', '2026-10-25', '2026-10-24'],
        ];
    }

    /** @dataProvider instants */
    public function testTodayFollowsTheApplicationTimezone(string $utc, string $expectedAppDay, string $utcDay): void
    {
        $ts = (new DateTimeImmutable($utc))->getTimestamp();
        $this->assertSame($expectedAppDay, ReportPeriod::today($ts));
        $this->assertSame($utcDay, gmdate('Y-m-d', $ts), 'A korábbi (UTC-alapú) kliensdátum');
    }

    public function testWorkerAndEndpointUseTheSameTodayAndTheDashboardNoLongerComputesIt(): void
    {
        $root = dirname(__DIR__);
        $this->assertStringContainsString('$reportDate = ReportPeriod::today();', file_get_contents($root . '/webroot/api/ai-daily-intelligence-run.php'));
        $endpoint = file_get_contents($root . '/webroot/api/ai-daily-report.php');
        $this->assertMatchesRegularExpression("/\\\$requestedDate === 'today'\) \{\s*\\\$requestedDate = ReportPeriod::today\(\);/", $endpoint);

        $js = file_get_contents($root . '/webroot/dashboard.js');
        preg_match('/async function loadAiDailySummary\(\) \{(.*?)\n\}/s', $js, $m);
        $this->assertNotEmpty($m[1] ?? null);
        $code = (string) preg_replace('~^\s*//.*$~m', '', $m[1]); // kommentek nélkül
        $this->assertStringContainsString("/api/ai-daily-report.php?date=today", $code);
        $this->assertStringNotContainsString('toISOString', $code);
        $this->assertStringNotContainsString('new Date(', $code);
    }

    public function testTodayDefaultsToTheCurrentTime(): void
    {
        $this->assertSame(date('Y-m-d'), ReportPeriod::today());
    }
}
