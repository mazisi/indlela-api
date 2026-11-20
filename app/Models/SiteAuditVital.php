<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** A PageSpeed Insights result for one URL and strategy. Reached through its audit. */
class SiteAuditVital extends Model
{
    public const DONE = 'done';

    public const FAILED = 'failed';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['lab' => 'array', 'field' => 'array', 'performance_score' => 'integer'];
    }
}
