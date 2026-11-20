<?php

namespace App\Models;

use App\Traits\Auditable;
use App\Traits\BelongsToWorkspace;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A site's monthly report: on `day_of_month` (1–28) at `send_hour` (0–23,
 * Africa/Johannesburg, the app timezone) the previous calendar month's
 * report is generated and emailed to `recipients`. One per site;
 * `last_run_at` keeps it to one run a month.
 */
class ReportSchedule extends Model
{
    use Auditable, BelongsToWorkspace;

    public const DEFAULT_SEND_HOUR = 8;

    protected $attributes = ['is_active' => true, 'day_of_month' => 1, 'send_hour' => self::DEFAULT_SEND_HOUR, 'compare' => 'previous'];

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'day_of_month' => 'integer',
            'send_hour' => 'integer',
            'recipients' => 'array',
            'sections' => 'array',
            'last_run_at' => 'datetime',
        ];
    }

    /** The run's bookkeeping isn't history. */
    protected function auditExclude(): array
    {
        return ['last_run_at', 'last_report_id'];
    }

    public function hasRunThisMonth(?CarbonImmutable $now = null): bool
    {
        $now ??= CarbonImmutable::now();

        return $this->last_run_at !== null && $this->last_run_at->greaterThanOrEqualTo($now->startOfMonth());
    }

    /**
     * When it next runs (null when paused): this month's day and hour if
     * still ahead and not run yet, else next month's.
     */
    public function nextRunAt(?CarbonImmutable $now = null): ?CarbonImmutable
    {
        if (! $this->is_active) {
            return null;
        }
        $now ??= CarbonImmutable::now();
        $thisMonth = $now->startOfMonth()->setDay($this->day_of_month)->setTime($this->send_hour, 0);

        if (! $this->hasRunThisMonth($now) && $now->lessThan($thisMonth)) {
            return $thisMonth;
        }

        return $now->startOfMonth()->addMonthNoOverflow()->setDay($this->day_of_month)->setTime($this->send_hour, 0);
    }

    /** The day of nextRunAt(). */
    public function nextRunOn(?CarbonImmutable $now = null): ?CarbonImmutable
    {
        return $this->nextRunAt($now)?->startOfDay();
    }

    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class)->withTrashed();
    }

    public function lastReport(): BelongsTo
    {
        return $this->belongsTo(Report::class, 'last_report_id');
    }
}
