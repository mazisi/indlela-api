<?php

namespace App\Services\Sms;

use RuntimeException;
use Twilio\Rest\Client;

class TwilioSmsSender implements SmsSender
{
    public function __construct(private readonly Client $client, private readonly string $from) {}

    public static function fromConfig(): self
    {
        $sid = (string) config('services.twilio.account_sid');
        $token = (string) config('services.twilio.auth_token');
        $from = (string) config('services.twilio.phone_number');

        if ($sid === '' || $token === '' || $from === '') {
            throw new RuntimeException('Twilio is not configured: set TWILIO_ACCOUNT_SID, TWILIO_AUTH_TOKEN and TWILIO_PHONE_NUMBER.');
        }

        return new self(new Client($sid, $token), $from);
    }

    public function send(string $to, string $message): void
    {
        $this->client->messages->create(self::toE164($to), [
            'from' => $this->from,
            'body' => $message,
        ]);
    }

    /** South African cell numbers are stored as 0XXXXXXXXX. */
    public static function toE164(string $number): string
    {
        $digits = preg_replace('/\D/', '', $number) ?? '';

        if (str_starts_with($digits, '0') && strlen($digits) === 10) {
            return '+27'.substr($digits, 1);
        }

        return '+'.ltrim($digits, '+');
    }
}
