<?php

namespace App\Services\RankTracking;

use App\Models\CompetitorAlert;
use App\Models\RankCheck;
use App\Models\RankCompetitorPosition;
use App\Models\RankKeyword;
use App\Models\SiteCompetitor;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Competitor positions and alerts, from the hosts a check stored
 * (rank_checks.organic_domains): no extra SERP is fetched.
 *
 * - A competitor's position in a check = the best position whose host is
 *   its domain or a subdomain of it; null = not in the top 100.
 * - Positions are written when a check finishes (record) and, for a
 *   competitor added, restored or resumed, from the site's checks of the
 *   last rank_tracking.competitor_backfill_days days (backfill). Both upsert
 *   per check and competitor, so running them twice changes nothing.
 * - An alert: a finished check of a key keyword where a competitor is now
 *   ahead of the site (their position < ours, not ranking = 101) but was not
 *   at the keyword's previous done check that has its position. No previous
 *   = no alert. Backfills never alert.
 */
class CompetitorTracker
{
    /** @param  list<?string>|null  $domains  rank_checks.organic_domains */
    public static function positionIn(?array $domains, string $domain): ?int
    {
        foreach ($domains ?? [] as $i => $host) {
            if (is_string($host) && SerpParser::matches($host, $domain)) {
                return $i + 1;
            }
        }

        return null;
    }

    /** A finished check: its site's active competitors' positions, then any alerts. */
    public function record(RankCheck $check): void
    {
        if ($check->status !== RankCheck::DONE || $check->organic_domains === null) {
            return;
        }
        $competitors = SiteCompetitor::query()->withoutGlobalScope('workspace')
            ->where('site_id', $check->site_id)->active()->get(['id', 'domain']);
        if ($competitors->isEmpty()) {
            return;
        }

        $positions = $competitors->mapWithKeys(fn (SiteCompetitor $c) => [$c->id => self::positionIn($check->organic_domains, $c->domain)]);
        $this->upsert($positions->map(fn (?int $position, int $competitorId) => self::row($check, $competitorId, $position))->values()->all());

        $keyword = RankKeyword::query()->withoutGlobalScope('workspace')->withTrashed()->find($check->keyword_id, ['id', 'is_key']);
        if ($keyword?->is_key) {
            $this->alert($check, $positions);
        }
    }

    /** Fill in a competitor's positions from its site's recent checks; returns the checks read. */
    public function backfill(SiteCompetitor $competitor): int
    {
        $since = today()->subDays((int) config('indlela.rank_tracking.competitor_backfill_days') - 1)->toDateString();
        $count = 0;

        RankCheck::query()->withoutGlobalScope('workspace')
            ->where('site_id', $competitor->site_id)->where('workspace_id', $competitor->workspace_id)
            ->where('status', RankCheck::DONE)->whereNotNull('organic_domains')
            ->where('checked_on', '>=', $since)
            ->select(['id', 'workspace_id', 'site_id', 'keyword_id', 'checked_on', 'organic_domains'])
            ->chunkById(500, function (Collection $checks) use ($competitor, &$count) {
                $this->upsert($checks->map(fn (RankCheck $c) => self::row($c, $competitor->id, self::positionIn($c->organic_domains, $competitor->domain)))->all());
                $count += $checks->count();
            });

        return $count;
    }

    /** @param  Collection<int, ?int>  $positions  competitor id => position now */
    private function alert(RankCheck $check, Collection $positions): void
    {
        $nr = RankKeywordQuery::notRanking();
        $ours = $check->position;

        // Each competitor's position at the keyword's latest earlier done check that has it.
        $previous = DB::table('rank_competitor_positions as p')
            ->join('rank_checks as c', 'c.id', '=', 'p.check_id')
            ->where('p.keyword_id', $check->keyword_id)
            ->whereIn('p.competitor_id', $positions->keys())
            ->where('c.status', RankCheck::DONE)
            ->where('c.checked_on', '<', $check->checked_on->toDateString())
            ->selectRaw('DISTINCT ON (p.competitor_id) p.competitor_id, p.position AS theirs, c.position AS ours')
            ->orderBy('p.competitor_id')->orderByDesc('c.checked_on')
            ->get()->keyBy('competitor_id');

        $alerts = [];
        foreach ($positions as $competitorId => $theirs) {
            $before = $previous->get($competitorId);
            if (! $before || ($theirs ?? $nr) >= ($ours ?? $nr)) {
                continue;
            }
            if (($before->theirs ?? $nr) < ($before->ours ?? $nr)) {
                continue; // Already ahead last time.
            }
            $alerts[] = [
                'workspace_id' => $check->workspace_id, 'site_id' => $check->site_id, 'keyword_id' => $check->keyword_id,
                'competitor_id' => $competitorId, 'check_id' => $check->id, 'checked_on' => $check->checked_on->toDateString(),
                'our_position' => $ours, 'their_position' => $theirs,
                'previous_our_position' => $before->ours === null ? null : (int) $before->ours,
                'previous_their_position' => $before->theirs === null ? null : (int) $before->theirs,
                'created_at' => now(),
            ];
        }

        DB::transaction(function () use ($check, $alerts) {
            // A live check replacing the day's result: un-emailed alerts of the old result no longer hold.
            CompetitorAlert::query()->withoutGlobalScope('workspace')->where('check_id', $check->id)->whereNull('emailed_at')->delete();
            if ($alerts !== []) {
                CompetitorAlert::query()->insertOrIgnore($alerts);
            }
        });
    }

    /** @param  list<array<string, mixed>>  $rows */
    private function upsert(array $rows): void
    {
        if ($rows !== []) {
            RankCompetitorPosition::query()->upsert($rows, ['check_id', 'competitor_id'], ['position', 'checked_on']);
        }
    }

    private static function row(RankCheck $check, int $competitorId, ?int $position): array
    {
        return [
            'workspace_id' => $check->workspace_id, 'site_id' => $check->site_id, 'competitor_id' => $competitorId,
            'keyword_id' => $check->keyword_id, 'check_id' => $check->id,
            'checked_on' => $check->checked_on->toDateString(), 'position' => $position,
        ];
    }
}
