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

    /*
    | Google Search Console. The report thresholds are the product's
    | definitions of a "quick win", "low CTR" and "decaying content"; the
    | impression/click minimums are per 28 days and scale with the period.
    */
    'search_console' => [
        // Syncs run on the long-job connection (config/queue.php, Horizon's supervisor-long).
        'queue_connection' => env('GSC_QUEUE_CONNECTION', 'redis-long'),
        // First sync of a site: how far back (GSC keeps 16 months).
        'backfill_months' => (int) env('GSC_BACKFILL_MONTHS', 16),
        // Later syncs re-fetch this many days before the latest date held (GSC lags 2-3 days and revises).
        'refetch_days' => (int) env('GSC_REFETCH_DAYS', 5),
        // Query/page rows are fetched in date chunks of this many days, paged by row_limit.
        'chunk_days' => 7,
        'row_limit' => 25000,
        // Reports default to the 28 days ending at the latest date with data.
        'default_period_days' => 28,

        // Keywords one push from page one's top: average position in the band, enough impressions.
        'quick_wins' => [
            'min_position' => 5,
            'max_position' => 15,
            'min_impressions' => 100,
            // potential_clicks = extra monthly clicks at this position, from the expected CTR curve.
            'target_position' => 3,
        ],

        // Pages that rank on page one but get fewer clicks than their position should earn.
        'low_ctr' => [
            'max_position' => 10,
            'min_impressions' => 200,
            // Flagged when ctr < expected_ctr x margin.
            'margin' => 0.7,
        ],

        // Pages losing traffic: last 28 days vs the 28 before, or falling month after month.
        'decay' => [
            'min_clicks' => 20,
            'drop_pct' => 20,
            'decline_months' => 3,
        ],

        // Expected organic CTR by (rounded) average position, from public CTR studies
        // (Advanced Web Ranking / Sistrix, blended). Beyond the last entry the last value applies.
        'expected_ctr' => [
            1 => 0.28, 2 => 0.155, 3 => 0.11, 4 => 0.08, 5 => 0.065,
            6 => 0.05, 7 => 0.04, 8 => 0.032, 9 => 0.027, 10 => 0.023,
            11 => 0.016, 12 => 0.013, 13 => 0.011, 14 => 0.0095, 15 => 0.008,
            16 => 0.007, 17 => 0.006, 18 => 0.0055, 19 => 0.005, 20 => 0.0045,
        ],
    ],

];
