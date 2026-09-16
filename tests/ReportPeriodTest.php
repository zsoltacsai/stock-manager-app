<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class ReportPeriodTest extends TestCase
{
    public function testTodayAndYesterdayResolveToSingleDayRanges(): void
    {
        $today = ReportPeriod::resolve('today');
        $this->assertSame(date('Y-m-d'), $today['from']);
        $this->assertSame(date('Y-m-d'), $today['to']);

        $yesterday = ReportPeriod::resolve('yesterday');
        $expected = date('Y-m-d', strtotime('-1 day'));
        $this->assertSame($expected, $yesterday['from']);
        $this->assertSame($expected, $yesterday['to']);
    }

    public function testLast7And30DaysIncludeTodayAsUpperBound(): void
    {
        $last7 = ReportPeriod::resolve('last_7_days');
        $this->assertSame(date('Y-m-d'), $last7['to']);
        $this->assertSame(date('Y-m-d', strtotime('-6 days')), $last7['from']);

        $last30 = ReportPeriod::resolve('last_30_days');
        $this->assertSame(date('Y-m-d', strtotime('-29 days')), $last30['from']);
    }

    public function testThisMonthAndLastMonthResolveToCalendarMonthBoundaries(): void
    {
        $thisMonth = ReportPeriod::resolve('this_month');
        $this->assertSame(date('Y-m-01'), $thisMonth['from']);
        $this->assertSame(date('Y-m-d'), $thisMonth['to']);

        $lastMonth = ReportPeriod::resolve('last_month');
        $expectedFrom = (new DateTimeImmutable('first day of last month'))->format('Y-m-d');
        $expectedTo = (new DateTimeImmutable('last day of last month'))->format('Y-m-d');
        $this->assertSame($expectedFrom, $lastMonth['from']);
        $this->assertSame($expectedTo, $lastMonth['to']);
    }

    public function testCustomPeriodAcceptsValidRange(): void
    {
        $r = ReportPeriod::resolve('custom', '2026-01-01', '2026-01-31');
        $this->assertSame('2026-01-01', $r['from']);
        $this->assertSame('2026-01-31', $r['to']);
    }

    public function testCustomPeriodRejectsMissingDates(): void
    {
        $this->expectException(InvalidArgumentException::class);
        ReportPeriod::resolve('custom', null, null);
    }

    public function testCustomPeriodRejectsFromAfterTo(): void
    {
        $this->expectException(InvalidArgumentException::class);
        ReportPeriod::resolve('custom', '2026-05-01', '2026-01-01');
    }

    public function testCustomPeriodRejectsInvalidCalendarDate(): void
    {
        $this->expectException(InvalidArgumentException::class);
        ReportPeriod::resolve('custom', '2026-02-30', '2026-03-01');
    }

    public function testCustomPeriodRejectsSpanOverFiveYears(): void
    {
        $this->expectException(InvalidArgumentException::class);
        ReportPeriod::resolve('custom', '2000-01-01', '2026-01-01');
    }

    public function testUnknownPeriodIsRejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        ReportPeriod::resolve('not-a-real-period');
    }
}
