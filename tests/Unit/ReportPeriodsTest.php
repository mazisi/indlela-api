<?php

namespace Tests\Unit;

use App\Services\Reports\ReportPeriods;
use Carbon\CarbonImmutable;
use PHPUnit\Framework\TestCase;

class ReportPeriodsTest extends TestCase
{
    private function d(string $date): CarbonImmutable
    {
        return CarbonImmutable::parse($date);
    }

    public function test_presets(): void
    {
        $today = $this->d('2026-03-01');
        $this->assertSame(['2026-02-01', '2026-02-28'], array_map(fn ($d) => $d->toDateString(), ReportPeriods::resolve('last_month', today: $today)));
        $this->assertSame(['2025-12-01', '2026-02-28'], array_map(fn ($d) => $d->toDateString(), ReportPeriods::resolve('last_3_months', today: $today)));
        $this->assertSame(['2026-03-01', '2026-03-01'], array_map(fn ($d) => $d->toDateString(), ReportPeriods::resolve('this_month', today: $today)), 'the 1st: just today');
    }

    public function test_compare_whole_months_by_months_else_by_days(): void
    {
        $fmt = fn (?array $p) => $p ? [$p[0]->toDateString(), $p[1]->toDateString()] : null;

        $this->assertSame(['2026-02-01', '2026-02-28'], $fmt(ReportPeriods::compare($this->d('2026-03-01'), $this->d('2026-03-31'), 'previous')));
        $this->assertSame(['2025-10-01', '2025-12-31'], $fmt(ReportPeriods::compare($this->d('2026-01-01'), $this->d('2026-03-31'), 'previous')));
        $this->assertSame(['2026-02-19', '2026-03-04'], $fmt(ReportPeriods::compare($this->d('2026-03-05'), $this->d('2026-03-18'), 'previous')));
        $this->assertSame(['2025-03-01', '2025-03-31'], $fmt(ReportPeriods::compare($this->d('2026-03-01'), $this->d('2026-03-31'), 'year')));
        $this->assertNull(ReportPeriods::compare($this->d('2026-03-01'), $this->d('2026-03-31'), 'none'));
    }

    public function test_labels(): void
    {
        $this->assertSame('October 2026', ReportPeriods::label($this->d('2026-10-01'), $this->d('2026-10-31')));
        $this->assertSame('August – October 2026', ReportPeriods::label($this->d('2026-08-01'), $this->d('2026-10-31')));
        $this->assertSame('November 2025 – January 2026', ReportPeriods::label($this->d('2025-11-01'), $this->d('2026-01-31')));
        $this->assertSame('5 – 18 October 2026', ReportPeriods::label($this->d('2026-10-05'), $this->d('2026-10-18')));
        $this->assertSame('20 September – 5 October 2026', ReportPeriods::label($this->d('2026-09-20'), $this->d('2026-10-05')));
        $this->assertSame('20 December 2025 – 5 January 2026', ReportPeriods::label($this->d('2025-12-20'), $this->d('2026-01-05')));
        $this->assertSame('3 October 2026', ReportPeriods::label($this->d('2026-10-03'), $this->d('2026-10-03')));
    }
}
