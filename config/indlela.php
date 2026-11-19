<?php

/*
|--------------------------------------------------------------------------
| Indlela business rules
|--------------------------------------------------------------------------
|
| Ported from workforce-api's config/workforce.php. They are config rather
| than constants so ops can tune them per environment, but the defaults ARE
| the agreed behaviour: change them deliberately.
|
*/

return [

    'auth' => [
        // Wrong passwords in a row before the account is locked.
        'max_failed_attempts' => (int) env('AUTH_MAX_FAILED_ATTEMPTS', 5),

        // Sign-in one-time PIN. When disabled, a correct password signs the
        // user straight in and their PIN channel is ignored.
        'pin_enabled' => (bool) env('AUTH_PIN_ENABLED', true),
        'pin_ttl_minutes' => (int) env('AUTH_PIN_TTL_MINUTES', 5),
        'pin_max_attempts' => (int) env('AUTH_PIN_MAX_ATTEMPTS', 3),
        'pin_max_resends' => (int) env('AUTH_PIN_MAX_RESENDS', 5),
        'pin_resend_cooldown_seconds' => (int) env('AUTH_PIN_RESEND_COOLDOWN', 30),

        // Self-service password reset link.
        'reset_ttl_minutes' => (int) env('AUTH_RESET_TTL_MINUTES', 60),
        // Set-password link for users invited into a workspace.
        'invite_ttl_hours' => (int) env('AUTH_INVITE_TTL_HOURS', 72),
    ],

    // Who may open /horizon outside the local environment (comma-separated emails).
    'horizon_admin_emails' => env('HORIZON_ADMIN_EMAILS', ''),

    'sms' => [
        // log = write the SMS to the log (local/dev); twilio = send for real.
        'driver' => env('SMS_DRIVER', 'log'),
    ],

];
