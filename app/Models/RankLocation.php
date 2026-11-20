<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * A DataForSEO location (country, region, city, …). Global reference data,
 * not tenant data: no workspace.
 */
class RankLocation extends Model
{
    public $timestamps = false;

    public $incrementing = false;

    protected $primaryKey = 'code';

    protected $guarded = [];

    protected function casts(): array
    {
        return ['code' => 'integer', 'parent_code' => 'integer', 'fetched_at' => 'datetime'];
    }
}
