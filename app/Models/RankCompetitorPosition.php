<?php

namespace App\Models;

use App\Traits\BelongsToWorkspace;
use Illuminate\Database\Eloquent\Model;

/**
 * A competitor's best organic position in one rank check (null = not in
 * the top 100). Unique per check and competitor. Data, not audited.
 */
class RankCompetitorPosition extends Model
{
    use BelongsToWorkspace;

    public $timestamps = false;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['checked_on' => 'date', 'position' => 'integer'];
    }
}
