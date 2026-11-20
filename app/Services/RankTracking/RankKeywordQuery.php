<?php

namespace App\Services\RankTracking;

use App\Models\RankCheck;
use App\Models\RankKeyword;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Facades\DB;

/**
 * Keywords with their latest result and its changes, as one query: every
 * rank-tracking list and report starts here. Built from the RankKeyword
 * model, so the workspace scope and soft deletes apply.
 *
 * Per keyword (LEFT JOIN LATERAL on rank_checks, done checks only):
 * - cur: the latest done check (position, url, matching_urls, serp_features);
 * - prev: the done check before it (previous_position, url_changed);
 * - d7 / d30: the latest done check at least 7 / 30 days before cur;
 * - last: the latest check of any status (last_checked_on, last_check_status);
 * - best_position: the best position ever recorded.
 *
 * change_Nd = position then − position now, so positive = moved up.
 * "Not in the top 100" counts as position 101 in a change (dropping out is a
 * drop, coming in a gain); null when there is nothing to compare with.
 */
final class RankKeywordQuery
{
    public static function notRanking(): int
    {
        return (int) config('indlela.rank_tracking.depth') + 1;
    }

    /** @param  int|list<int>  $siteIds */
    public static function for(int|array $siteIds, bool $activeOnly = false): Builder
    {
        $nr = self::notRanking();
        $change = fn (string $then) => "CASE WHEN {$then}.checked_on IS NULL OR cur.checked_on IS NULL THEN NULL ELSE COALESCE({$then}.position, {$nr}) - COALESCE(cur.position, {$nr}) END";

        return RankKeyword::query()
            ->whereIn('rank_keywords.site_id', (array) $siteIds)
            ->when($activeOnly, fn (Builder $q) => $q->where('rank_keywords.is_active', true))
            ->leftJoinLateral(self::done()->addSelect(['c.url', 'c.matching_urls', 'c.serp_features']), 'cur')
            ->leftJoinLateral(self::done()->addSelect('c.url')->whereColumn('c.checked_on', '<', 'cur.checked_on'), 'prev')
            ->leftJoinLateral(self::done()->whereRaw('c.checked_on <= cur.checked_on - 7'), 'd7')
            ->leftJoinLateral(self::done()->whereRaw('c.checked_on <= cur.checked_on - 30'), 'd30')
            ->leftJoinLateral(self::checks()->select(['c.checked_on', 'c.status'])->orderByDesc('c.checked_on')->limit(1), 'last')
            ->select('rank_keywords.*')
            ->addSelect([
                'cur.position as position', 'cur.checked_on as position_on', 'cur.url as url',
                'cur.matching_urls as matching_urls', 'cur.serp_features as serp_features',
                'prev.position as previous_position', 'prev.checked_on as previous_on', 'prev.url as previous_url',
                'd7.position as position_7d', 'last.checked_on as last_checked_on', 'last.status as last_check_status',
            ])
            ->selectRaw("{$change('prev')} AS change_1d")
            ->selectRaw("{$change('d7')} AS change_7d")
            ->selectRaw("{$change('d30')} AS change_30d")
            ->selectRaw("(SELECT MIN(b.position) FROM rank_checks b WHERE b.keyword_id = rank_keywords.id AND b.status = '".RankCheck::DONE."') AS best_position");
    }

    /** Keep only keywords in a position bucket (by their latest result). */
    public static function bucket(Builder $query, string $bucket): Builder
    {
        return match ($bucket) {
            'top3' => $query->where('cur.position', '<=', 3),
            'top10' => $query->where('cur.position', '<=', 10),
            'top100' => $query->whereNotNull('cur.position'),
            'not_ranking' => $query->whereNotNull('cur.checked_on')->whereNull('cur.position'),
        };
    }

    /** Keywords whose latest result has two or more of the site's URLs. */
    public static function cannibalised(Builder $query): Builder
    {
        return $query->whereRaw("jsonb_array_length(COALESCE(cur.matching_urls::jsonb, '[]'::jsonb)) >= 2");
    }

    /** The latest done check of the outer keyword (position, checked_on; add columns as needed). */
    private static function done(): QueryBuilder
    {
        return self::checks()->select(['c.position', 'c.checked_on'])->where('c.status', RankCheck::DONE)->orderByDesc('c.checked_on')->limit(1);
    }

    private static function checks(): QueryBuilder
    {
        return DB::table('rank_checks as c')->whereColumn('c.keyword_id', 'rank_keywords.id');
    }
}
