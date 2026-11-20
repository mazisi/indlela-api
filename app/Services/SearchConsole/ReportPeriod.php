<?php

namespace App\Services\SearchConsole;

use App\Models\Site;
use Carbon\CarbonImmutable;
use Illuminate\Validation\ValidationException;

/**
 * A report's date range and the range it's compared with. By default the
 * 28 days ending at the site's latest data (yesterday before the first sync);
 * compared with the period just before (`previous`), the same dates a year
 * earlier (`year`) or nothing (`none`).
 */
final class ReportPeriod
{
    public function __construct(
        public readonly CarbonImmutable $start,
        public readonly CarbonImmutable $end,
        public readonly ?CarbonImmutable $compareStart = null,
        public readonly ?CarbonImmutable $compareEnd = null,
    ) {}

    public static function resolve(Site $site, ?string $start, ?string $end, string $compare = 'previous'): self
    {
        $days = (int) config('indlela.search_console.default_period_days');
        $latest = $site->gsc_data_through ? CarbonImmutable::parse($site->gsc_data_through) : CarbonImmutable::yesterday();

        $endDate = $end ? CarbonImmutable::parse($end) : ($start ? $latest->max(CarbonImmutable::parse($start)) : $latest);
        $startDate = $start ? CarbonImmutable::parse($start) : $endDate->subDays($days - 1);

        if ($startDate->gt($endDate)) {
            throw ValidationException::withMessages(['start' => 'The start date must be on or before the end date.']);
        }

        $length = (int) $startDate->diffInDays($endDate) + 1;
        if ($length > 16 * 31) {
            throw ValidationException::withMessages(['start' => 'Choose a period of at most 16 months (all Search Console keeps).']);
        }

        return match ($compare) {
            'none' => new self($startDate, $endDate),
            'year' => new self($startDate, $endDate, $startDate->subYearNoOverflow(), $endDate->subYearNoOverflow()),
            default => new self($startDate, $endDate, $startDate->subDays($length), $startDate->subDay()),
        };
    }

    public function days(): int
    {
        return (int) $this->start->diffInDays($this->end) + 1;
    }

    public function hasCompare(): bool
    {
        return $this->compareStart !== null;
    }

    /** Scale a per-28-days threshold to this period's length. */
    public function scaled(int|float $per28Days): float
    {
        return $per28Days * $this->days() / 28;
    }

    /** @return array{period: array{start: string, end: string}, compare_period: array{start: string, end: string}|null} */
    public function toArray(): array
    {
        return [
            'period' => ['start' => $this->start->toDateString(), 'end' => $this->end->toDateString()],
            'compare_period' => $this->hasCompare()
                ? ['start' => $this->compareStart->toDateString(), 'end' => $this->compareEnd->toDateString()]
                : null,
        ];
    }
}
