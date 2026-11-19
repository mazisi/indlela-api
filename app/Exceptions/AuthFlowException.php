<?php

namespace App\Exceptions;

use Illuminate\Http\JsonResponse;
use RuntimeException;

/**
 * A refused step in the sign-in / reset flow. Renders the same shape as a
 * Laravel 422 (message + errors[field]) plus an `error_type` the FE can branch
 * on — the contract workforce-app's Login page (ported to indlela) reads.
 */
class AuthFlowException extends RuntimeException
{
    public function __construct(
        string $message,
        public readonly string $errorType,
        public readonly string $field = 'email',
        public readonly int $status = 422,
        /** @var array<string, mixed> */
        public readonly array $extra = [],
    ) {
        parent::__construct($message);
    }

    public function render(): JsonResponse
    {
        return response()->json([
            'message' => $this->getMessage(),
            'errors' => [$this->field => [$this->getMessage()]],
            'error_type' => $this->errorType,
        ] + $this->extra, $this->status);
    }
}
