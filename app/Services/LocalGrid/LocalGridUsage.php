<?php

namespace App\Services\LocalGrid;

/**
 * What a workspace sees of its local grid allowance: active grids, today's
 * manual scans and business searches against their limits, and whether
 * scans are paused. Never money, balance or provider details.
 */
class LocalGridUsage
{
    public function __construct(private readonly GridLimits $limits, private readonly GridScanner $scanner) {}

    /** @return array{grids: array{used: int, limit: int}, scans_today: array{used: int, limit: int}, searches_today: array{used: int, limit: int}, paused: bool} */
    public function summary(int $workspaceId): array
    {
        return [
            'grids' => ['used' => $this->limits->gridsUsed($workspaceId), 'limit' => $this->limits->gridLimit()],
            'scans_today' => ['used' => $this->limits->scansToday($workspaceId), 'limit' => $this->limits->scanNowLimit()],
            'searches_today' => ['used' => $this->limits->searchesToday($workspaceId), 'limit' => $this->limits->searchLimit()],
            'paused' => $this->scanner->paused(),
        ];
    }
}
