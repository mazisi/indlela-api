<?php

namespace App\Models;

use App\Traits\BelongsToWorkspace;
use Illuminate\Database\Eloquent\Model;

/**
 * A site's Search Console totals for one day (dimension [date]): the
 * accurate totals, anonymised queries included. Written by the sync.
 */
class GscDailyTotal extends Model
{
    use BelongsToWorkspace;

    public $timestamps = false;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['date' => 'date', 'clicks' => 'integer', 'impressions' => 'integer', 'ctr' => 'float', 'position' => 'float'];
    }
}
