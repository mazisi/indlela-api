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

    // The platform (Indlela staff) workspace, by slug: the only workspace where
    // Platform › Usage (view_platform_usage, cross-workspace figures) exists.
    // Empty = no platform workspace: the page is off everywhere.
    'platform_workspace' => env('INDLELA_PLATFORM_WORKSPACE') ?: null,

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

    /*
    | Site audit (crawler, issues, PageSpeed Insights). Thresholds are the
    | product's definitions of each issue (App\Support\AuditIssues explains
    | them to users); the crawler numbers keep crawls polite and bounded.
    */
    'audit' => [
        // Crawls and PageSpeed runs go on the long-job connection (Horizon's supervisor-long).
        'queue_connection' => env('AUDIT_QUEUE_CONNECTION', 'redis-long'),
        // Sent as "IndlelaBot/1.0 (+APP_URL)"; robots.txt groups for IndlelaBot (else *) apply.
        'user_agent' => 'IndlelaBot/1.0',
        'robots_agent' => 'indlelabot',
        // Requests in flight at once, per-request timeout (s) and the pause between batches (ms).
        'concurrency' => (int) env('AUDIT_CONCURRENCY', 4),
        'timeout' => (int) env('AUDIT_TIMEOUT', 10),
        'delay_ms' => (int) env('AUDIT_DELAY_MS', 250),
        // Clicks from the home page; pages deeper than this are not crawled.
        'max_depth' => 10,
        // Pages per crawl: the site's audit_page_limit (default below), never above the hard max.
        'default_page_limit' => 500,
        'max_page_limit' => 5000,
        // Redirect hops followed (and recorded) per URL before calling it a loop.
        'max_redirects' => 10,
        // HTML bodies above this are not parsed (and declared-larger downloads are cut short).
        'max_body_bytes' => 5 * 1024 * 1024,
        // pages_crawled is written every this many pages, for the UI's progress bar.
        'progress_every' => 10,
        // Sitemaps: files read (indexes followed) and URLs taken from them.
        'max_sitemaps' => 50,
        'max_sitemap_urls' => 10000,
        // Status checks after the crawl (HEAD, GET when HEAD fails): distinct external links and internal images.
        'external_check_limit' => (int) env('AUDIT_EXTERNAL_CHECK_LIMIT', 200),
        'image_check_limit' => 300,
        // Audits kept with their pages, links and issues per site (older ones keep only their summary).
        'keep_audits' => 10,
        // Scheduled audits: due this many days after the site's last finished audit.
        'schedule_days' => ['weekly' => 7, 'monthly' => 30],

        'thresholds' => [
            'title_max' => 60,
            'title_min' => 30,
            'meta_description_max' => 160,
            'slow_response_ms' => 2000,
            'thin_content_words' => 200,
            // redirect_chain: this many redirects or more before the final page.
            'redirect_chain_hops' => 2,
        ],

        // Health score = 100 − page deductions − site-wide deductions, clamped 0–100 (docs/API.md).
        'health' => [
            // Share of crawled pages with at least one issue of the severity, times this weight.
            'page_weights' => ['critical' => 50, 'warning' => 30, 'notice' => 10],
            // Fixed points per site-wide issue (AuditIssues marks which are site-wide).
            'site_points' => ['critical' => 25, 'warning' => 5, 'notice' => 2],
        ],

        // Core Web Vitals through PageSpeed Insights (key optional: config/services.php pagespeed.key).
        'vitals' => [
            // The home page plus this many of the most-linked indexable pages.
            'pages' => 4,
            'strategies' => ['mobile', 'desktop'],
            'timeout' => 90,
            'concurrency' => 4,
            // Lighthouse performance score (0–100): below poor = poor, below good = needs improvement.
            'poor_score' => 50,
            'good_score' => 90,
        ],
    ],

    /*
    | Rank tracking through DataForSEO's SERP API (credentials in
    | config/services.php `dataforseo`). Prices are USD per check at depth
    | 100 (one check = one keyword x location x device x day); they drive the
    | usage page's estimates, the real cost comes back from DataForSEO.
    */
    'rank_tracking' => [
        // Standard queue (overnight checks, priority 1) and Live mode ("check now").
        'price_standard' => (float) env('RANK_PRICE_STANDARD', 0.0006),
        'price_live' => (float) env('RANK_PRICE_LIVE', 0.002),
        // Results asked for per SERP: positions beyond it count as "not in the top 100".
        'depth' => 100,
        // Tasks per task_post call (DataForSEO's maximum is 100).
        'batch_size' => 100,
        // Beta limits (per workspace; pricing plans come later). Keywords = active
        // keyword x location x device combinations across the workspace's sites;
        // workspaces.rank_keyword_limit overrides it for one workspace.
        'workspace_keyword_limit' => (int) env('RANK_WORKSPACE_KEYWORD_LIMIT', 500),
        // "Check now" requests a workspace may make a calendar day (SAST); failed ones count too (they're billed).
        'check_now_daily_limit' => (int) env('RANK_CHECK_NOW_DAILY_LIMIT', 20),
        // Platform-wide DataForSEO spend a calendar month (USD, every workspace): posting stops
        // and "check now" pauses at it. 0 or empty = no cap. Never shown to workspaces.
        'monthly_spend_cap_usd' => (float) env('RANK_MONTHLY_SPEND_CAP_USD', 50),
        // The month's spend is cached this many seconds for the usage endpoint (posting reads it fresh).
        'spend_cache_seconds' => 60,
        // Platform › Usage: the provider's account balance is cached this many seconds.
        'balance_cache_seconds' => 600,
        // Keyword ids in one bulk request.
        'max_bulk_ids' => 1000,
        // Keywords added in one request (before the location/device combinations).
        'max_keywords_per_request' => 200,
        'max_locations_per_request' => 5,
        // A suburb is a coordinate "lat,lng,radius": DataForSEO wants the radius in mm (199-199999).
        'coordinate_radius' => (int) env('RANK_COORDINATE_RADIUS', 20000),
        // Standard checks not collected after this many hours are marked failed.
        'pending_timeout_hours' => 24,
        // DataForSEO's location list (~270k places, ~400 MB to load, a few minutes) is downloaded
        // into rank_locations by a queued job on the long connection, never during a request,
        // and refreshed after this many days.
        'locations_refresh_days' => 30,
        'locations_memory_limit' => env('RANK_LOCATIONS_MEMORY_LIMIT', '1024M'),
        'locations_queue_connection' => env('RANK_LOCATIONS_QUEUE_CONNECTION', 'redis-long'),
        // Location search ranks this country's places first.
        'home_country' => 'ZA',
        // Seconds a live check may take (DataForSEO's own limit is 120 s); below the job's timeout.
        'live_timeout' => 75,
        // Collection and "check now" jobs are small: the default redis connection (Horizon's supervisor-1).
        'queue_connection' => env('RANK_QUEUE_CONNECTION', 'redis'),
        // Competitor tracking (no extra DataForSEO cost: it reads the SERPs the checks fetch).
        // Active competitors per site.
        'max_competitors_per_site' => (int) env('RANK_MAX_COMPETITORS_PER_SITE', 5),
        // A new (or restored, resumed) competitor is filled in from the site's checks of this many days.
        'competitor_backfill_days' => 90,
        // Share-of-voice trend: ISO weeks, the current one included.
        'competitor_trend_weeks' => 12,
        // The daily alert email sends alerts not emailed yet, at most this many days old.
        'competitor_alert_days' => 7,
        // Alert email recipients per site, and alert rows listed in one email.
        'max_alert_recipients' => 10,
        'competitor_alert_email_rows' => 50,
        // Domains suggested as competitors.
        'competitor_suggestions' => 10,
        // Big generic sites never suggested as competitors (subdomains too, e.g. en.wikipedia.org).
        // SA directories (Yellow Pages, Snupit…) stay: they do compete for local searches.
        'suggestion_blocklist' => [
            'wikipedia.org', 'youtube.com', 'facebook.com', 'instagram.com', 'linkedin.com', 'twitter.com', 'x.com',
            'tiktok.com', 'pinterest.com', 'reddit.com', 'quora.com', 'google.com', 'google.co.za', 'amazon.com',
            'medium.com', 'tripadvisor.com', 'tripadvisor.co.za', 'yelp.com', 'apple.com', 'microsoft.com',
        ],
    ],

    /*
    | Local map pack grid: Google Maps rankings from a grid of points around a
    | centre (DataForSEO Maps SERP, Live mode). Company-paid like rank
    | tracking: its spend counts toward rank_tracking.monthly_spend_cap_usd.
    */
    'local_grid' => [
        // Live Maps SERP, per point and per business search (DataForSEO's returned cost is stored).
        'price_live' => (float) env('LOCAL_GRID_PRICE_LIVE', 0.002),
        // Map results asked for per point: a business beyond it is "not in the top 20".
        'depth' => 20,
        // Google Maps zoom of each point's search ("lat,lng,15z").
        'zoom' => 15,
        // Beta limits (per workspace): active grids, manual "scan now" and business searches a
        // calendar day (SAST); failed scans and searches count too (they're billed).
        'max_grids_per_workspace' => (int) env('LOCAL_GRID_MAX_GRIDS', 10),
        'scan_now_daily_limit' => (int) env('LOCAL_GRID_SCAN_NOW_DAILY_LIMIT', 10),
        'business_search_daily_limit' => (int) env('LOCAL_GRID_SEARCH_DAILY_LIMIT', 30),
        // Candidates a business search returns; it searches South Africa unless given a point.
        'search_results' => 10,
        'search_location_code' => 2710,
        'language_code' => 'en',
        // Points asked for at once (one job runs one round; each live call may take up to live_timeout s).
        'concurrency' => 7,
        'live_timeout' => 75,
        // Scans still queued or running after this many hours are failed (their points stop counting as pending spend).
        'stale_hours' => 3,
        // Scans listed on a grid's page, newest first.
        'scans_listed' => 20,
        // Points are small jobs: the default redis connection (Horizon's supervisor-1).
        'queue_connection' => env('LOCAL_GRID_QUEUE_CONNECTION', 'redis'),
    ],

    /*
    | White-label reports: PDF (dompdf), share links and the monthly email.
    */
    'reports' => [
        // Generation renders a PDF: the long-job connection (Horizon's supervisor-long).
        'queue_connection' => env('REPORTS_QUEUE_CONNECTION', 'redis-long'),
        // Share link made when a report is emailed (the email's button), in days.
        'email_share_days' => 90,
        // Choices for POST /reports/{report}/share (null = no expiry).
        'share_days' => [7, 30, 90],
        // Recipients per send and per schedule.
        'max_recipients' => 10,
        // Reports per bulk action (the list shows at most 100 a page).
        'max_bulk_ids' => 100,
        // A change this big (%, or keywords) is "significant" and leads the summary.
        'summary_pct_threshold' => 5,
        'summary_keyword_threshold' => 3,
        'summary_health_threshold' => 5,
    ],

];
