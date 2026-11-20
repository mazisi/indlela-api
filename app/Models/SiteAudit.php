<?php

namespace App\Models;

use App\Traits\BelongsToWorkspace;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One crawl of a site and its results: pages, links, issues, vitals.
 * Status runs queued → crawling → analysing → done (or failed / cancelled);
 * vitals_status runs separately (pending → done | unavailable).
 */
class SiteAudit extends Model
{
    use BelongsToWorkspace;

    public const QUEUED = 'queued';

    public const CRAWLING = 'crawling';

    public const ANALYSING = 'analysing';

    public const DONE = 'done';

    public const FAILED = 'failed';

    public const CANCELLED = 'cancelled';

    /** Statuses of an audit still in progress (only one per site at a time). */
    public const RUNNING = [self::QUEUED, self::CRAWLING, self::ANALYSING];

    public const MANUAL = 'manual';

    public const SCHEDULED = 'scheduled';

    public const VITALS_PENDING = 'pending';

    public const VITALS_DONE = 'done';

    public const VITALS_UNAVAILABLE = 'unavailable';

    protected $attributes = ['status' => self::QUEUED, 'trigger' => self::MANUAL, 'pages_crawled' => 0, 'pages_found' => 0, 'vitals_status' => self::VITALS_PENDING];

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
            'pruned_at' => 'datetime',
            'counts' => 'array',
            'comparison' => 'array',
            'crawl_meta' => 'array',
            'health_score' => 'integer',
            'page_limit' => 'integer',
            'pages_crawled' => 'integer',
            'pages_found' => 'integer',
        ];
    }

    public function isRunning(): bool
    {
        return in_array($this->status, self::RUNNING, true);
    }

    public function scopeRunning(Builder $query): Builder
    {
        return $query->whereIn($this->qualifyColumn('status'), self::RUNNING);
    }

    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class)->withTrashed();
    }

    public function triggeredBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'triggered_by')->withTrashed();
    }

    public function previousAudit(): BelongsTo
    {
        return $this->belongsTo(self::class, 'previous_audit_id');
    }

    public function pages(): HasMany
    {
        return $this->hasMany(SiteAuditPage::class, 'audit_id');
    }

    public function links(): HasMany
    {
        return $this->hasMany(SiteAuditLink::class, 'audit_id');
    }

    public function issues(): HasMany
    {
        return $this->hasMany(SiteAuditIssue::class, 'audit_id');
    }

    public function vitals(): HasMany
    {
        return $this->hasMany(SiteAuditVital::class, 'audit_id');
    }
}
