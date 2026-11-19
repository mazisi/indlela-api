<?php

namespace App\Services\Sms;

use Illuminate\Support\Facades\Log;

/**
 * Local/dev driver: the SMS lands in the log (read it with `php artisan pail`).
 */
class LogSmsSender implements SmsSender
{
    public function send(string $to, string $message): void
    {
        Log::info('SMS (log driver)', ['to' => $to, 'message' => $message]);
    }
}
