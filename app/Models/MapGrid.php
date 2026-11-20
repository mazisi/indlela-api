<?php

namespace App\Models;

use App\Traits\Auditable;
use App\Traits\BelongsToWorkspace;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A local map pack grid: one keyword checked from size x size points around
 * a centre, for one Google Maps listing (the business). The business is
 * fixed once saved (another listing = another grid). Its workspace is its
 * site's.
 */
class MapGrid extends Model
{
    use Auditable, BelongsToWorkspace, SoftDeletes;

    public const SIZES = [3, 5, 7];

    public const SPACINGS = [0.5, 1, 2, 3, 5];

    public const MOBILE = 'mobile';

    public const DESKTOP = 'desktop';

    public const DEVICES = [self::MOBILE, self::DESKTOP];

    public const NONE = 'none';

    public const WEEKLY = 'weekly';

    public const MONTHLY = 'monthly';

    public const SCHEDULES = [self::NONE, self::WEEKLY, self::MONTHLY];

    protected $attributes = ['device' => self::MOBILE, 'schedule' => self::NONE, 'is_active' => true];

    protected $guarded = ['id'];

    protected static function booted(): void
    {
        // A grid lives in its site's workspace, whatever the request's is.
        static::creating(function (MapGrid $grid) {
            $grid->workspace_id = Site::withoutGlobalScope('workspace')->withTrashed()->whereKey($grid->site_id)->value('workspace_id');
        });

        static::saving(function (MapGrid $grid) {
            $grid->business_key = self::businessKey($grid->business_place_id, $grid->business_cid, (string) $grid->business_title);
        });
    }

    protected function casts(): array
    {
        return [
            'business_lat' => 'float',
            'business_lng' => 'float',
            'center_lat' => 'float',
            'center_lng' => 'float',
            'size' => 'integer',
            'spacing_km' => 'float',
            'is_active' => 'boolean',
        ];
    }

    /** What makes two grids' businesses the same: the place id, else the cid, else the title. */
    public static function businessKey(?string $placeId, ?string $cid, string $title): string
    {
        return filled($placeId) ? "place:{$placeId}" : (filled($cid) ? "cid:{$cid}" : 'title:'.mb_strtolower(trim($title)));
    }

    protected function auditExclude(): array
    {
        return ['business_key'];
    }

    /** History names a grid by its keyword. */
    protected function transformAttributesForAudit(array $attributes): array
    {
        return $attributes + ['_label' => $attributes['keyword'] ?? null];
    }

    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class)->withTrashed();
    }

    public function scans(): HasMany
    {
        return $this->hasMany(MapGridScan::class, 'grid_id');
    }

    /** The latest scan, whatever its status. */
    public function latestScan(): HasOne
    {
        return $this->hasOne(MapGridScan::class, 'grid_id')->ofMany(['id' => 'max']);
    }

    /** The latest finished scan: the schedule counts from it. */
    public function latestDoneScan(): HasOne
    {
        return $this->hasOne(MapGridScan::class, 'grid_id')->ofMany(['id' => 'max'], fn (Builder $q) => $q->where('status', MapGridScan::DONE));
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where($this->qualifyColumn('is_active'), true);
    }

    public function business(): array
    {
        return [
            'title' => $this->business_title,
            'place_id' => $this->business_place_id,
            'cid' => $this->business_cid,
            'address' => $this->business_address,
            'lat' => $this->business_lat,
            'lng' => $this->business_lng,
        ];
    }

    /**
     * The day the next scheduled scan is due: 7 days (weekly) or a calendar
     * month (monthly) after the last finished scan, today when there is none;
     * null when not scheduled or paused. Uses `latestDoneScan` (loaded).
     */
    public function nextScanOn(): ?CarbonImmutable
    {
        if ($this->schedule === self::NONE || ! $this->is_active) {
            return null;
        }
        $last = $this->latestDoneScan?->scanned_on;
        if ($last === null) {
            return CarbonImmutable::today();
        }
        $last = CarbonImmutable::parse($last);

        return $this->schedule === self::WEEKLY ? $last->addDays(7) : $last->addMonthNoOverflow();
    }
}
