<?php

namespace App\Services\RankTracking;

/**
 * What a workspace sees of its rank-tracking allowance: keywords tracked
 * against its limit, today's "check now" requests against the daily limit,
 * and whether rank checks are paused (the platform's DataForSEO account
 * isn't set up, or its monthly spend cap is reached). Never money, balance
 * or provider details: those are the platform's, not the workspace's.
 */
class RankUsage
{
    public function __construct(
        private readonly SerpApi $api,
        private readonly RankLimits $limits,
        private readonly RankSpend $spend,
    ) {}

    /** @return array{keywords_tracked: int, keyword_limit: int, checks_now_today: int, check_now_limit: int, paused: bool} */
    public function summary(int $workspaceId): array
    {
        return [
            'keywords_tracked' => $this->limits->keywordsTracked($workspaceId),
            'keyword_limit' => $this->limits->keywordLimit($workspaceId),
            'checks_now_today' => $this->limits->checksNowToday($workspaceId),
            'check_now_limit' => $this->limits->checkNowLimit(),
            'paused' => $this->paused(),
        ];
    }

    public function paused(): bool
    {
        return ! $this->api->configured() || $this->spend->reached();
    }
}
