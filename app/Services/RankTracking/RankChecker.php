<?php

namespace App\Services\RankTracking;

use App\Exceptions\RankTrackingException;
use App\Jobs\CheckKeywordNow;
use App\Jobs\PostRankTasks;
use App\Models\RankCheck;
use App\Models\RankKeyword;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Creating checks (the nightly schedule and "check now"), the DataForSEO
 * task they become, and storing a finished result.
 *
 * A check row is unique per keyword and day, so whatever runs twice never
 * checks (or pays for) a keyword twice on the same day; a live check reuses
 * that day's row and replaces its result.
 */
class RankChecker
{
    public function __construct(
        private readonly SerpApi $api,
        private readonly RankSpend $spend,
        private readonly RankLimits $limits,
    ) {}

    /** 409 rank_checks_paused while the platform's DataForSEO account isn't set up. */
    public function ensureConfigured(): void
    {
        if (! $this->api->configured()) {
            throw RankTrackingException::paused();
        }
    }

    /**
     * For every active keyword (every workspace) of an active site due on
     * $day without a check that day: a pending standard check, then one
     * PostRankTasks job per workspace and 100 checks.
     *
     * @return array{checks: int, batches: int}
     */
    public function scheduleDue(CarbonInterface $day): array
    {
        $date = $day->toDateString();
        $checks = 0;
        $batches = 0;

        RankKeyword::query()->withoutGlobalScope('workspace')->active()->dueOn($day)
            ->whereHas('site', fn ($q) => $q->withoutGlobalScope('workspace')->whereNull('sites.deleted_at')->where('sites.is_active', true))
            ->whereNotExists(fn ($q) => $q->from('rank_checks')->whereColumn('rank_checks.keyword_id', 'rank_keywords.id')->where('rank_checks.checked_on', $date))
            ->select(['id', 'workspace_id', 'site_id'])
            ->orderBy('workspace_id')->orderBy('id')
            ->chunkById(1000, function (Collection $keywords) use ($date, &$checks, &$batches) {
                $now = now();
                RankCheck::query()->insertOrIgnore($keywords->map(fn (RankKeyword $k) => [
                    'workspace_id' => $k->workspace_id, 'site_id' => $k->site_id, 'keyword_id' => $k->id, 'checked_on' => $date,
                    'mode' => RankCheck::STANDARD, 'status' => RankCheck::PENDING, 'cost' => 0, 'created_at' => $now, 'updated_at' => $now,
                ])->all());

                // Only the rows this run made (a live check may have taken a keyword's day meanwhile).
                $created = RankCheck::query()->withoutGlobalScope('workspace')
                    ->whereIn('keyword_id', $keywords->pluck('id'))->where('checked_on', $date)
                    ->where('mode', RankCheck::STANDARD)->pending()->whereNull('posted_at')->whereNull('task_id')
                    ->orderBy('id')->get(['id', 'workspace_id']);

                foreach ($created->groupBy('workspace_id') as $workspaceId => $rows) {
                    foreach ($rows->pluck('id')->chunk((int) config('indlela.rank_tracking.batch_size')) as $ids) {
                        PostRankTasks::dispatch($ids->values()->all(), (int) $workspaceId);
                        $batches++;
                    }
                }
                $checks += $created->count();
            }, 'id');

        return ['checks' => $checks, 'batches' => $batches];
    }

    /**
     * "Check now": today's check becomes a pending live check and is queued.
     * 409 rank_checks_paused when not set up or a live check wouldn't fit
     * under the platform's monthly spend cap; 409 check_running while a live
     * check of the keyword is pending today; 429 check_limit_reached past the
     * workspace's daily limit. The request is counted (live_requests) before
     * the call, so a live call that fails still counts: it's billed.
     */
    public function checkNow(RankKeyword $keyword): RankCheck
    {
        $this->ensureConfigured();

        $check = Cache::lock("rank-check-now:{$keyword->id}", 10)->block(5, function () use ($keyword) {
            return $this->spend->serialised(fn () => DB::transaction(function () use ($keyword) {
                if ($this->spend->checksThatFit((float) config('indlela.rank_tracking.price_live')) === 0) {
                    throw RankTrackingException::paused();
                }

                $this->limits->lockCheckNow($keyword->workspace_id);
                $check = RankCheck::query()->where('keyword_id', $keyword->id)->where('checked_on', today()->toDateString())->lockForUpdate()->first();
                if ($check && $check->mode === RankCheck::LIVE && $check->status === RankCheck::PENDING) {
                    throw RankTrackingException::checkRunning();
                }

                $limit = $this->limits->checkNowLimit();
                if ($this->limits->checksNowToday($keyword->workspace_id) >= $limit) {
                    throw RankTrackingException::checkLimitReached($limit);
                }

                $check ??= new RankCheck(['workspace_id' => $keyword->workspace_id, 'site_id' => $keyword->site_id, 'keyword_id' => $keyword->id, 'checked_on' => today()->toDateString()]);
                // Any cost already paid today stays in the ledger; the live check's is added.
                $check->forceFill([
                    'mode' => RankCheck::LIVE, 'status' => RankCheck::PENDING, 'task_id' => null, 'position' => null, 'url' => null,
                    'matching_urls' => null, 'serp_features' => null, 'result_count' => null, 'error' => null,
                    'posted_at' => now(), 'completed_at' => null, 'live_requests' => (int) $check->live_requests + 1,
                ])->save();

                return $check;
            }));
        });
        $this->spend->forget();

        CheckKeywordNow::dispatch($check->id, $check->workspace_id, $keyword->id, $check->posted_at->getTimestamp());

        return $check->refresh();
    }

    /** The task_post / live body of a check. */
    public function task(RankCheck $check, RankKeyword $keyword): array
    {
        $task = [
            'keyword' => $keyword->keyword,
            'language_code' => $keyword->language_code,
            'device' => $keyword->device,
            'os' => $keyword->device === RankKeyword::MOBILE ? 'android' : 'windows',
            'depth' => (int) config('indlela.rank_tracking.depth'),
            'tag' => (string) $check->id,
        ];

        if ($keyword->location_type === RankKeyword::COORDINATE) {
            $task['location_coordinate'] = $keyword->location_coordinate;
        } else {
            $task['location_code'] = $keyword->location_code;
        }

        if ($check->mode === RankCheck::STANDARD) {
            $task['priority'] = 1;
        }

        return $task;
    }

    /** Store a finished SERP on its check (status done). */
    public function complete(RankCheck $check, ?array $result, float $cost): void
    {
        $keyword = $check->keyword()->with(['site' => fn ($q) => $q->withoutGlobalScope('workspace')])->first();
        $parsed = SerpParser::parse($result, (string) $keyword?->site?->domain, (int) config('indlela.rank_tracking.depth'));

        $check->forceFill([
            ...$parsed,
            'status' => RankCheck::DONE,
            'cost' => round($check->cost + $cost, 6),
            'error' => null,
            'completed_at' => now(),
        ])->save();
    }

    public function fail(RankCheck $check, string $error, float $cost = 0.0): void
    {
        $check->forceFill([
            'status' => RankCheck::FAILED,
            'error' => mb_substr($error, 0, 500),
            'cost' => round($check->cost + $cost, 6),
            'completed_at' => now(),
        ])->save();
    }

    /**
     * Pending checks older than the timeout (posted or not) become failed.
     *
     * @return int checks failed
     */
    public function failStale(): int
    {
        $hours = (int) config('indlela.rank_tracking.pending_timeout_hours');

        return RankCheck::query()->withoutGlobalScope('workspace')->pending()
            ->where('created_at', '<', now()->subHours($hours))
            ->where(fn ($q) => $q->whereNull('posted_at')->orWhere('posted_at', '<', now()->subHours($hours)))
            ->update(['status' => RankCheck::FAILED, 'error' => "No result from DataForSEO within {$hours} hours.", 'completed_at' => now(), 'updated_at' => now()]);
    }
}
