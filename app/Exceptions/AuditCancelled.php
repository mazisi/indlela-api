<?php

namespace App\Exceptions;

use RuntimeException;

/** Thrown by the crawler between batches when its audit was cancelled; the job stops quietly. */
class AuditCancelled extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('The audit was cancelled.');
    }
}
