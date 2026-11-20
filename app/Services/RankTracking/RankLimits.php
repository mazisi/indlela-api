<?php

namespace App\Services\RankTracking;

use App\Models\RankCheck;
use App\Models\RankKeyword;
use App\Models\Workspace;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * A workspace's rank-tracking beta limits: active keywords (per workspace,
 * Workspace::rankKeywordLimit) and "check now" requests a calendar day
 * (rank_tracking.check_now_daily_limit). Counted without the workspace
 * scope, so they hold in queued work too.
 */
class RankLimits
{
    /** pg_advisory_xact_lock keys (with the workspace id). */
    private const KEYWORDS_LOCK = 7_340_102;

    private const CHECK_NOW_LOCK = 7_340_103;

    /** Active, non-deleted keywords (keyword x location x device) of the workspace. */
    public function keywordsTracked(int $workspaceId): int
    {
        return RankKeyword::query()->inWorkspace($workspaceId)->where('is_active', true)->count();
    }

    public function keywordLimit(int $workspaceId): int
    {
        return Workspace::query()->findOrFail($workspaceId)->rankKeywordLimit();
    }

    /** 422 on $field when $adding more active keywords would take the workspace over its limit. */
    public function ensureRoomFor(int $workspaceId, int $adding, string $field): void
    {
        if ($adding <= 0) {
            return;
        }
        $limit = $this->keywordLimit($workspaceId);
        $left = max(0, $limit - $this->keywordsTracked($workspaceId));
        if ($adding > $left) {
            throw ValidationException::withMessages([$field => "This workspace can track up to {$limit} keywords. You have {$left} left."]);
        }
    }

    /**
     * Runs $work in a transaction holding the workspace's keyword lock, so
     * two requests can't both take the last places.
     *
     * @template T
     *
     * @param  callable(): T  $work
     * @return T
     */
    public function keywordsSerialised(int $workspaceId, callable $work): mixed
    {
        return DB::transaction(function () use ($workspaceId, $work) {
            DB::select('SELECT pg_advisory_xact_lock(?, ?)', [self::KEYWORDS_LOCK, $workspaceId]);

            return $work();
        });
    }

    public function checkNowLimit(): int
    {
        return (int) config('indlela.rank_tracking.check_now_daily_limit');
    }

    /** "Check now" requests the workspace made today (SAST), answered or not. */
    public function checksNowToday(int $workspaceId): int
    {
        return (int) RankCheck::query()->inWorkspace($workspaceId)
            ->where('checked_on', today()->toDateString())->sum('live_requests');
    }

    /** Takes the workspace's check-now lock until the surrounding transaction ends. */
    public function lockCheckNow(int $workspaceId): void
    {
        DB::select('SELECT pg_advisory_xact_lock(?, ?)', [self::CHECK_NOW_LOCK, $workspaceId]);
    }
}
