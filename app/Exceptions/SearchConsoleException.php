<?php

namespace App\Exceptions;

use Illuminate\Http\JsonResponse;
use RuntimeException;

/**
 * A Search Console request that can't go ahead in the workspace's current
 * state: 409 `{message, error_type}`, the FE branches on error_type.
 */
class SearchConsoleException extends RuntimeException
{
    public function __construct(string $message, public readonly string $errorType, public readonly int $status = 409)
    {
        parent::__construct($message);
    }

    /** No Google account connected, or its access was revoked/expired. */
    public static function googleNotConnected(?string $message = null): self
    {
        return new self($message ?? 'Connect a Google account with access to Search Console first.', 'google_not_connected');
    }

    public static function notLinked(): self
    {
        return new self('This site is not linked to a Search Console property yet.', 'gsc_not_linked');
    }

    public function render(): JsonResponse
    {
        return response()->json(['message' => $this->getMessage(), 'error_type' => $this->errorType], $this->status);
    }
}
