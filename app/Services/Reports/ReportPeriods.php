<?php

namespace App\Services\Reports;

use Carbon\CarbonImmutable;
use Illuminate\Validation\ValidationException;

/**
 * A report's period, the period it's compared with, and how both read.
 *
 * Presets: last_month and last_3_months are full calendar months before this
 * one; this_month runs from the 1st to yesterday. A period of whole calendar
 * months compares with the same number of months before it (October with
 * September); any other period with the same number of days before it.
 */
final class ReportPeriods
{
    public const PRESETS = ['last_month', 'this_month', 'last_3_months', 'custom'];

    /** @return array{0: CarbonImmutable, 1: CarbonImmutable} */
    public static function resolve(string $preset, ?string $start = null, ?string $end = null, ?CarbonImmutable $today = null): array
    {
        $today ??= CarbonImmutable::today();
        $thisMonth = $today->startOfMonth();

        [$from, $to] = match ($preset) {
            'last_month' => [$thisMonth->subMonthNoOverflow(), $thisMonth->subDay()],
            'last_3_months' => [$thisMonth->subMonthsNoOverflow(3), $thisMonth->subDay()],
            'this_month' => [$thisMonth, $today->subDay()->max($thisMonth)],
            default => [CarbonImmutable::parse((string) $start)->startOfDay(), CarbonImmutable::parse((string) $end)->startOfDay()],
        };

        if ($from->gt($to)) {
            throw ValidationException::withMessages(['start' => 'The start date must be on or before the end date.']);
        }
        if ($to->gt($today)) {
            throw ValidationException::withMessages(['end' => 'The end date can\'t be in the future.']);
        }
        if ($from->diffInDays($to) + 1 > 16 * 31) {
            throw ValidationException::withMessages(['start' => 'Choose a period of at most 16 months.']);
        }

        return [$from, $to];
    }

    /** @return array{0: CarbonImmutable, 1: CarbonImmutable}|null */
    public static function compare(CarbonImmutable $start, CarbonImmutable $end, string $compare): ?array
    {
        return match ($compare) {
            'none' => null,
            'year' => [$start->subYearNoOverflow(), $end->subYearNoOverflow()],
            default => self::wholeMonths($start, $end)
                ? [$start->subMonthsNoOverflow(self::months($start, $end)), $start->subDay()]
                : [$start->subDays((int) $start->diffInDays($end) + 1), $start->subDay()],
        };
    }

    /** "October 2026", "August – October 2026", "1 – 15 October 2026", "20 December 2025 – 5 January 2026". */
    public static function label(CarbonImmutable $start, CarbonImmutable $end): string
    {
        if (self::wholeMonths($start, $end)) {
            if ($start->isSameMonth($end)) {
                return $start->format('F Y');
            }

            return $start->isSameYear($end)
                ? $start->format('F').' – '.$end->format('F Y')
                : $start->format('F Y').' – '.$end->format('F Y');
        }

        if ($start->isSameDay($end)) {
            return $start->format('j F Y');
        }
        if ($start->isSameMonth($end)) {
            return $start->format('j').' – '.$end->format('j F Y');
        }

        return $start->isSameYear($end)
            ? $start->format('j F').' – '.$end->format('j F Y')
            : $start->format('j F Y').' – '.$end->format('j F Y');
    }

    public static function wholeMonths(CarbonImmutable $start, CarbonImmutable $end): bool
    {
        return $start->day === 1 && $end->isLastOfMonth();
    }

    private static function months(CarbonImmutable $start, CarbonImmutable $end): int
    {
        return ($end->year - $start->year) * 12 + $end->month - $start->month + 1;
    }
}
