<?php

namespace App\Exceptions;

use Illuminate\Http\JsonResponse;
use RuntimeException;

/**
 * A report request that can't go ahead in the report's current state:
 * 409 `{message, error_type}`; the FE branches on error_type.
 */
class ReportException extends RuntimeException
{
    public function __construct(string $message, public readonly string $errorType, public readonly int $status = 409)
    {
        parent::__construct($message);
    }

    public static function notReady(): self
    {
        return new self('The report is not ready yet. Wait for it to finish generating, or generate it again.', 'report_not_ready');
    }

    public static function noSchedule(): self
    {
        return new self('Set up the monthly report first: its recipients, sections and note are what "Send now" uses.', 'no_schedule');
    }

    public function render(): JsonResponse
    {
        return response()->json(['message' => $this->getMessage(), 'error_type' => $this->errorType], $this->status);
    }
}
