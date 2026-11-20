<?php

namespace App\Models;

use App\Traits\BelongsToWorkspace;
use Illuminate\Database\Eloquent\Model;

/**
 * A business search (picking the Google Maps listing for a grid): the
 * workspace's daily allowance and what DataForSEO charged for it.
 */
class MapGridSearch extends Model
{
    use BelongsToWorkspace;

    public const PENDING = 'pending';

    public const DONE = 'done';

    public const FAILED = 'failed';

    protected $attributes = ['status' => self::PENDING, 'cost' => 0];

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['searched_on' => 'date', 'cost' => 'float'];
    }
}
