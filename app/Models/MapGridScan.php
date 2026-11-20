<?php

namespace App\Models;

use App\Traits\BelongsToWorkspace;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One run of a grid: queued → running → done | failed, with a frozen copy
 * of the grid's settings (a grid changed later doesn't change its past
 * scans). `cost` is what DataForSEO charged (platform-only, never shown).
 */
class MapGridScan extends Model
{
    use BelongsToWorkspace;

    public const QUEUED = 'queued';

    public const RUNNING = 'running';

    public const DONE = 'done';

    public const FAILED = 'failed';

    /** Statuses of a scan still in progress (one per grid at a time). */
    public const IN_PROGRESS = [self::QUEUED, self::RUNNING];

    public const MANUAL = 'manual';

    public const SCHEDULED = 'scheduled';

    protected $attributes = ['status' => self::QUEUED, 'trigger' => self::MANUAL, 'points_total' => 0, 'points_done' => 0, 'points_found' => 0, 'cost' => 0];

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'scanned_on' => 'date',
            'center_lat' => 'float',
            'center_lng' => 'float',
            'size' => 'integer',
            'spacing_km' => 'float',
            'points_total' => 'integer',
            'points_done' => 'integer',
            'points_found' => 'integer',
            'arp' => 'float',
            'atrp' => 'float',
            'solv' => 'float',
            'found_pct' => 'float',
            'cost' => 'float',
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
        ];
    }

    public function grid(): BelongsTo
    {
        return $this->belongsTo(MapGrid::class, 'grid_id')->withTrashed();
    }

    public function points(): HasMany
    {
        return $this->hasMany(MapGridPoint::class, 'scan_id');
    }

    public function scopeInProgress(Builder $query): Builder
    {
        return $query->whereIn($this->qualifyColumn('status'), self::IN_PROGRESS);
    }

    public function inProgress(): bool
    {
        return in_array($this->status, self::IN_PROGRESS, true);
    }
}
