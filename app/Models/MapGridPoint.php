<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One cell of a scan (row 0 = north, col 0 = west): pending → done | failed.
 * rank 1–20 = the business's place in the map results there, null = not in
 * the top 20. No workspace of its own: only reached through its scan.
 */
class MapGridPoint extends Model
{
    public const PENDING = 'pending';

    public const DONE = 'done';

    public const FAILED = 'failed';

    public $timestamps = false;

    protected $attributes = ['status' => self::PENDING, 'cost' => 0];

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'row' => 'integer',
            'col' => 'integer',
            'lat' => 'float',
            'lng' => 'float',
            'rank' => 'integer',
            'top' => 'array',
            'results' => 'array',
            'cost' => 'float',
            'checked_at' => 'datetime',
        ];
    }

    public function scan(): BelongsTo
    {
        return $this->belongsTo(MapGridScan::class, 'scan_id');
    }
}
