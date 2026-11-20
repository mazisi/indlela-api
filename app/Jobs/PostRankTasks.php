<?php

namespace App\Jobs;

use App\Exceptions\DataForSeoException;
use App\Models\RankCheck;
use App\Models\Workspace;
use App\Services\RankTracking\RankChecker;
use App\Services\RankTracking\RankSpend;
use App\Services\RankTracking\SerpApi;
use App\Support\CurrentWorkspace;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Post one batch (≤ 100) of a workspace's pending standard checks to
 * DataForSEO's queue in one task_post call; each check gets its task id
 * (collected later by rank-tracking:collect) or fails with DataForSEO's
 * message.
 *
 * Exactly once per check: the job claims its rows (posted_at set where it
 * was empty) before calling, so a duplicate job or a retry never posts a
 * check twice; a failed call releases the claim for the retry. Unique per
 * batch (its check ids), never per workspace or site: two batches of one
 * workspace must both run.
 *
 * Under the platform's monthly spend cap: the claim takes only as many
 * checks as fit (RankSpend, read fresh under the budget lock); the rest of
 * the batch fails as "Skipped: rank checks paused" and one warning a day is
 * logged with the spend and the cap.
 */
class PostRankTasks implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    /** @var list<int> */
    public array $backoff = [60, 300];

    public int $timeout = 60;

    public int $uniqueFor = 3600;

    /** @param  list<int>  $checkIds */
    public function __construct(public readonly array $checkIds, public readonly int $workspaceId)
    {
        $this->onConnection(config('indlela.rank_tracking.queue_connection'));
    }

    public function uniqueId(): string
    {
        return 'rank-post-'.sha1(implode(',', $this->checkIds));
    }

    /** The error on a check left unposted because rank checks are paused (not set up, or capped). */
    public const SKIPPED = 'Skipped: rank checks paused';

    public function handle(SerpApi $api, RankChecker $checker, RankSpend $spend, CurrentWorkspace $current): void
    {
        $workspace = Workspace::find($this->workspaceId);
        if (! $workspace || $this->checkIds === []) {
            return;
        }

        $current->using($workspace, function () use ($api, $checker, $spend) {
            if (! $api->configured()) {
                $this->skipUnclaimed();

                return;
            }

            $claimed = $spend->serialised(function () use ($spend) {
                $fit = $spend->checksThatFit((float) config('indlela.rank_tracking.price_standard'));
                $claimed = $this->claim($fit);
                if ($fit !== null && ($skipped = $this->skipUnclaimed()) > 0) {
                    self::warnCapped($spend, $skipped);
                }

                return $claimed;
            });
            $spend->forget();
            if ($claimed === []) {
                return;
            }
            $checks = RankCheck::query()->with('keyword')->whereKey($claimed)->get()->keyBy('id');

            $tasks = $checks->map(fn (RankCheck $c) => $checker->task($c, $c->keyword))->values()->all();

            try {
                $results = $api->postTasks($tasks);
            } catch (DataForSeoException $e) {
                $this->releaseClaim($claimed);
                if (! $e->retryable) {
                    $checks->each(fn (RankCheck $c) => $checker->fail($c->refresh(), $e->getMessage()));

                    return;
                }

                throw $e;
            }

            foreach ($results as $result) {
                $check = $checks->get((int) $result['tag']);
                if (! $check) {
                    continue;
                }
                if ($result['status_code'] === 20100 && $result['id']) {
                    $check->forceFill(['task_id' => $result['id'], 'cost' => round($check->cost + $result['cost'], 6)])->save();
                } else {
                    $checker->fail($check, "DataForSEO refused the check: {$result['status_message']} ({$result['status_code']}).", $result['cost']);
                }
                $checks->forget($check->id);
            }

            // Not in DataForSEO's answer at all.
            $checks->each(fn (RankCheck $c) => $checker->fail($c, 'DataForSEO did not accept the check.'));
        });
    }

    /**
     * Mark this batch's unposted pending checks as posted now, at most
     * $limit of them (lowest ids first; null = all).
     *
     * @return list<int> the ids claimed
     */
    private function claim(?int $limit = null): array
    {
        if ($limit === 0) {
            return [];
        }
        $placeholders = implode(',', array_fill(0, count($this->checkIds), '?'));
        $rows = DB::select(
            "UPDATE rank_checks SET posted_at = ?, updated_at = ? WHERE id IN (
                SELECT id FROM rank_checks WHERE id IN ({$placeholders}) AND workspace_id = ? AND status = ? AND posted_at IS NULL AND task_id IS NULL
                ORDER BY id".($limit !== null ? ' LIMIT '.(int) $limit : '').' FOR UPDATE
            ) RETURNING id',
            [now(), now(), ...$this->checkIds, $this->workspaceId, RankCheck::PENDING],
        );

        return array_map(fn (object $row) => (int) $row->id, $rows);
    }

    /**
     * This batch's checks still unposted fail as skipped (none left pending).
     *
     * @return int checks skipped
     */
    private function skipUnclaimed(): int
    {
        return RankCheck::query()->withoutGlobalScope('workspace')->whereKey($this->checkIds)->where('workspace_id', $this->workspaceId)
            ->pending()->whereNull('posted_at')->whereNull('task_id')
            ->update(['status' => RankCheck::FAILED, 'error' => self::SKIPPED, 'completed_at' => now(), 'updated_at' => now()]);
    }

    /** One warning a day (a run) when the spend cap stops posting. */
    private static function warnCapped(RankSpend $spend, int $skipped): void
    {
        if (Cache::add('rank-spend-cap-warned:'.today()->toDateString(), true, now()->endOfDay())) {
            Log::warning('Rank tracking: the monthly spend cap is reached; checks were left unposted.', [
                'spend_usd' => $spend->fresh(), 'cap_usd' => $spend->cap(), 'skipped' => $skipped,
            ]);
        }
    }

    /** @param  list<int>  $ids */
    private function releaseClaim(array $ids): void
    {
        RankCheck::query()->whereKey($ids)->pending()->whereNull('task_id')->update(['posted_at' => null]);
    }

    public function failed(?Throwable $e): void
    {
        $count = RankCheck::query()->withoutGlobalScope('workspace')->whereKey($this->checkIds)->where('workspace_id', $this->workspaceId)
            ->pending()->whereNull('task_id')
            ->update(['status' => RankCheck::FAILED, 'error' => mb_substr($e?->getMessage() ?: 'Posting the checks to DataForSEO failed.', 0, 500), 'completed_at' => now(), 'updated_at' => now()]);
        Log::warning("Rank tracking: {$count} check(s) could not be posted to DataForSEO.", ['error' => $e?->getMessage()]);
    }
}
