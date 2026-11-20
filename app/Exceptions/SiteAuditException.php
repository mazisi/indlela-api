<?php

namespace App\Exceptions;

use Illuminate\Http\JsonResponse;
use RuntimeException;

/**
 * A site audit request that can't go ahead in the audit's or site's current
 * state: 409 `{message, error_type}`, the FE branches on error_type.
 */
class SiteAuditException extends RuntimeException
{
    public function __construct(string $message, public readonly string $errorType, public readonly int $status = 409)
    {
        parent::__construct($message);
    }

    public static function running(): self
    {
        return new self('An audit of this site is already queued or running.', 'audit_running');
    }

    public static function notRunning(): self
    {
        return new self('This audit has already finished.', 'audit_not_running');
    }

    public function render(): JsonResponse
    {
        return response()->json(['message' => $this->getMessage(), 'error_type' => $this->errorType], $this->status);
    }
}
