<?php

namespace App\Models;

use App\Traits\BelongsToWorkspace;
use Illuminate\Database\Eloquent\Model;

/**
 * One query on one page on one day (dimensions [date, query, page]). Reports
 * aggregate these; anonymised queries are missing, so sums are below the
 * daily totals. Written by the sync.
 */
class GscQueryPageDaily extends Model
{
    use BelongsToWorkspace;

    protected $table = 'gsc_query_page_daily';

    public $timestamps = false;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['date' => 'date', 'clicks' => 'integer', 'impressions' => 'integer', 'ctr' => 'float', 'position' => 'float'];
    }

    public static function hash(string $value): string
    {
        return md5($value);
    }
}
