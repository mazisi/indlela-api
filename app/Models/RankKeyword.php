<?php

namespace App\Models;

use App\Traits\Auditable;
use App\Traits\BelongsToWorkspace;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A tracked keyword: one keyword x location x device of a site, checked
 * daily or weekly (on `weekly_day`, 0 = Sunday). Its workspace is its site's.
 */
class RankKeyword extends Model
{
    use Auditable, BelongsToWorkspace, SoftDeletes;

    public const CODE = 'code';

    public const COORDINATE = 'coordinate';

    public const DESKTOP = 'desktop';

    public const MOBILE = 'mobile';

    public const DEVICES = [self::DESKTOP, self::MOBILE];

    public const DAILY = 'daily';

    public const WEEKLY = 'weekly';

    public const FREQUENCIES = [self::DAILY, self::WEEKLY];

    protected $attributes = ['language_code' => 'en', 'frequency' => self::WEEKLY, 'weekly_day' => 0, 'is_active' => true, 'is_key' => false];

    protected $guarded = ['id'];

    protected static function booted(): void
    {
        // A keyword lives in its site's workspace, whatever the request's is.
        static::creating(function (RankKeyword $keyword) {
            $keyword->workspace_id = Site::withoutGlobalScope('workspace')->withTrashed()->whereKey($keyword->site_id)->value('workspace_id');
        });
    }

    protected function casts(): array
    {
        return [
            'location_code' => 'integer',
            'weekly_day' => 'integer',
            'tags' => 'array',
            'is_active' => 'boolean',
            'is_key' => 'boolean',
        ];
    }

    public static function locationKey(string $type, int|string|null $code, ?string $coordinate): string
    {
        return $type === self::CODE ? "code:{$code}" : "coord:{$coordinate}";
    }

    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class)->withTrashed();
    }

    public function checks(): HasMany
    {
        return $this->hasMany(RankCheck::class, 'keyword_id');
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where($this->qualifyColumn('is_active'), true);
    }

    /** Daily keywords, weekly ones on $day's weekday, and any never checked yet (their first check). */
    public function scopeDueOn(Builder $query, CarbonInterface $day): Builder
    {
        return $query->where(fn (Builder $q) => $q
            ->where($this->qualifyColumn('frequency'), self::DAILY)
            ->orWhere($this->qualifyColumn('weekly_day'), $day->dayOfWeek)
            ->orWhereNotExists(fn ($s) => $s->from('rank_checks')->whereColumn('rank_checks.keyword_id', $this->qualifyColumn('id'))));
    }

    /** The API's `location`: {type, code, coordinate ("lat,lng,radius"), name}. */
    public function locationArray(): array
    {
        return ['type' => $this->location_type, 'code' => $this->location_code, 'coordinate' => $this->location_coordinate, 'name' => $this->location_name];
    }
}
