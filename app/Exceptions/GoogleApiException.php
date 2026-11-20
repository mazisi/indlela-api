<?php

namespace App\Exceptions;

use Illuminate\Http\Client\Response;
use Illuminate\Http\JsonResponse;
use RuntimeException;

/**
 * Google answered with an error we can't act on (quota, outage, a property
 * the account can't read). Shown to a person as 502 `google_api_error`; a
 * sync job fails and retries.
 */
class GoogleApiException extends RuntimeException
{
    public function __construct(string $message, public readonly int $googleStatus = 0)
    {
        parent::__construct($message);
    }

    public static function fromResponse(Response $response, string $doing): self
    {
        $reason = $response->json('error.message') ?? $response->json('error_description') ?? $response->json('error') ?? $response->reason();

        return new self(sprintf('Google refused %s (%d): %s', $doing, $response->status(), is_string($reason) ? $reason : 'unknown error'), $response->status());
    }

    public function render(): JsonResponse
    {
        return response()->json(['message' => $this->getMessage(), 'error_type' => 'google_api_error'], 502);
    }
}
