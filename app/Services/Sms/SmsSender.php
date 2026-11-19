<?php

namespace App\Services\Sms;

interface SmsSender
{
    /**
     * Send a plain-text SMS. Throws on a delivery failure the caller must
     * surface — a sign-in PIN that silently never arrives locks the user out.
     */
    public function send(string $to, string $message): void;
}
