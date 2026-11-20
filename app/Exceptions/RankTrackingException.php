<?php

namespace App\Exceptions;

use Illuminate\Http\JsonResponse;
use RuntimeException;

/**
 * A rank tracking request that can't go ahead: 409 (429 for the daily
 * check-now limit) `{message, error_type}`; the FE branches on error_type.
 * Messages never mention the provider, credentials or money.
 */
class RankTrackingException extends RuntimeException
{
    public function __construct(string $message, public readonly string $errorType, public readonly int $status = 409)
    {
        parent::__construct($message);
    }

    /** The platform's rank checks are off (not set up, or the monthly spend cap is reached). */
    public static function paused(): self
    {
        return new self('Rank checks are paused for now. Your keywords are safe and checks resume automatically.', 'rank_checks_paused');
    }

    public static function checkLimitReached(int $limit): self
    {
        return new self("You've used today's {$limit} instant checks. They reset at midnight.", 'check_limit_reached', 429);
    }

    /** The location list is being downloaded in the background (first use). */
    public static function locationsLoading(): self
    {
        return new self('Locations are still loading. Try again in a few minutes.', 'locations_loading');
    }

    public static function checkRunning(): self
    {
        return new self('A live check of this keyword is already running.', 'check_running');
    }

    public function render(): JsonResponse
    {
        return response()->json(['message' => $this->getMessage(), 'error_type' => $this->errorType], $this->status);
    }
}
