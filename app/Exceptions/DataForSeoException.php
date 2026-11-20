<?php

namespace App\Exceptions;

use Illuminate\Http\JsonResponse;
use RuntimeException;

/**
 * DataForSEO refused or failed a request (HTTP error, or a status_code other
 * than 20000 in its envelope). `$retryable` says whether trying again later
 * can help (rate limits, 5xx, timeouts) or not (auth, funds, bad input).
 * Rendered as 502 `{message, error_type: dataforseo_error}` with a neutral
 * message (the provider's own stays in the log and on the check).
 */
class DataForSeoException extends RuntimeException
{
    public function __construct(string $message, public readonly ?int $statusCode = null, public readonly bool $retryable = true)
    {
        parent::__construct($message);
    }

    /** DataForSEO status codes that won't change on a retry. */
    private const PERMANENT = [40100, 40200, 40203, 40210, 40400, 40401, 40402, 40403, 40501, 40502, 40503, 40505, 40506, 50100];

    public static function fromStatus(int $code, string $message, string $what): self
    {
        return new self("DataForSEO refused {$what}: {$message} ({$code}).", $code, ! in_array($code, self::PERMANENT, true));
    }

    public function render(): JsonResponse
    {
        return response()->json(['message' => "Rank checks couldn't be reached. Try again shortly.", 'error_type' => 'dataforseo_error'], 502);
    }
}
