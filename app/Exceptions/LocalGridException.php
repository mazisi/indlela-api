<?php

namespace App\Exceptions;

use Illuminate\Http\JsonResponse;
use RuntimeException;

/**
 * A local grid request that can't go ahead: 409 (429 for the daily limits,
 * 502 when a business search couldn't be made) `{message, error_type}`; the
 * FE branches on error_type. Messages never mention the provider,
 * credentials or money.
 */
class LocalGridException extends RuntimeException
{
    public function __construct(string $message, public readonly string $errorType, public readonly int $status = 409)
    {
        parent::__construct($message);
    }

    /** The platform's scans are off (not set up, or the monthly spend cap is reached). */
    public static function paused(): self
    {
        return new self('Local grid scans are paused for now. Your grids are safe and scans resume automatically.', 'local_grid_paused');
    }

    public static function scanRunning(): self
    {
        return new self('A scan of this grid is already running.', 'scan_running');
    }

    public static function scanLimit(int $limit): self
    {
        return new self("You've used today's {$limit} grid scans. They reset at midnight.", 'scan_limit', 429);
    }

    public static function searchLimit(int $limit): self
    {
        return new self("You've used today's {$limit} business searches. They reset at midnight.", 'search_limit', 429);
    }

    public static function searchFailed(): self
    {
        return new self("The business search couldn't be completed. Try again shortly.", 'search_failed', 502);
    }

    public static function inactive(): self
    {
        return new self('This grid is paused. Resume it to scan.', 'grid_inactive');
    }

    public function render(): JsonResponse
    {
        return response()->json(['message' => $this->getMessage(), 'error_type' => $this->errorType], $this->status);
    }
}
