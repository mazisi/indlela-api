<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Resend, Postmark, AWS, and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    'postmark' => [
        'key' => env('POSTMARK_API_KEY'),
    ],

    'resend' => [
        'key' => env('RESEND_API_KEY'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

    // Sign-in PINs by SMS (SMS_DRIVER=twilio).
    'twilio' => [
        'account_sid' => env('TWILIO_ACCOUNT_SID'),
        'auth_token' => env('TWILIO_AUTH_TOKEN'),
        'phone_number' => env('TWILIO_PHONE_NUMBER'),
    ],

    // Google Search Console over OAuth (one connection per workspace).
    'google' => [
        'client_id' => env('GOOGLE_CLIENT_ID'),
        'client_secret' => env('GOOGLE_CLIENT_SECRET'),
        'redirect_uri' => env('GOOGLE_REDIRECT_URI', 'http://localhost:8000/api/google/callback'),
    ],

    // PageSpeed Insights (site audit Core Web Vitals). Optional: without a key Google's
    // shared quota applies and runs often come back "unavailable".
    'pagespeed' => [
        'key' => env('PAGESPEED_API_KEY'),
    ],

    // DataForSEO SERP API (rank tracking), HTTP Basic auth. Sandbox answers with
    // sample data for free; set DATAFORSEO_SANDBOX=false for real (billed) checks.
    'dataforseo' => [
        'login' => env('DATAFORSEO_LOGIN'),
        'password' => env('DATAFORSEO_PASSWORD'),
        'sandbox' => (bool) env('DATAFORSEO_SANDBOX', true),
        'base_url' => env('DATAFORSEO_SANDBOX', true) ? 'https://sandbox.dataforseo.com' : 'https://api.dataforseo.com',
        // Free reference calls (location list, account balance) always use the
        // live host: the sandbox's location list is a one-country sample.
        'live_url' => 'https://api.dataforseo.com',
    ],

];
