<?php

namespace App\Models;

use App\Traits\BelongsToWorkspace;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One SERP check of a keyword on a day (unique per keyword and day).
 * pending → done | failed. Position null = not in the top 100 (when done).
 */
class RankCheck extends Model
{
    use BelongsToWorkspace;

    public const PENDING = 'pending';

    public const DONE = 'done';

    public const FAILED = 'failed';

    public const STANDARD = 'standard';

    public const LIVE = 'live';

    protected $attributes = ['status' => self::PENDING, 'mode' => self::STANDARD, 'cost' => 0];

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'checked_on' => 'date',
            'position' => 'integer',
            'matching_urls' => 'array',
            'serp_features' => 'array',
            'organic_domains' => 'array',
            'result_count' => 'integer',
            'cost' => 'float',
            'posted_at' => 'datetime',
            'completed_at' => 'datetime',
        ];
    }

    public function keyword(): BelongsTo
    {
        return $this->belongsTo(RankKeyword::class, 'keyword_id')->withTrashed();
    }

    public function scopePending(Builder $query): Builder
    {
        return $query->where($this->qualifyColumn('status'), self::PENDING);
    }
}
