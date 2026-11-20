<?php

namespace App\Models;

use App\Traits\BelongsToWorkspace;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A competitor moved ahead of the site on a key keyword at this check (it
 * wasn't ahead at the keyword's previous check). Positions null = not in
 * the top 100. emailed_at = sent in a daily digest.
 */
class CompetitorAlert extends Model
{
    use BelongsToWorkspace;

    public const UPDATED_AT = null;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'checked_on' => 'date',
            'our_position' => 'integer',
            'their_position' => 'integer',
            'previous_our_position' => 'integer',
            'previous_their_position' => 'integer',
            'emailed_at' => 'datetime',
        ];
    }

    public function keyword(): BelongsTo
    {
        return $this->belongsTo(RankKeyword::class, 'keyword_id')->withTrashed();
    }

    public function competitor(): BelongsTo
    {
        return $this->belongsTo(SiteCompetitor::class, 'competitor_id')->withTrashed();
    }

    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class)->withTrashed();
    }
}
