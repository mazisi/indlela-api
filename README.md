# indlela-api

The Laravel 13 API behind **Indlela**, an SEO tool for agencies and SMEs: Search Console dashboard and quick wins, site audit, rank tracking and white-label reports. Multi-tenant: every agency or business is a **workspace** with its own clients, sites, users and roles. The web app is the separate repo [`indlela`](../indlela).

Routes and permissions: [`docs/API.md`](docs/API.md). Architecture rules for contributors (and Claude Code): [`CLAUDE.md`](CLAUDE.md).

## What you need

- Docker Desktop (on Windows: with WSL2, and work inside the WSL filesystem).
- PHP 8.3+ and Composer on the host, only for the first `composer install` (or use the one-off container below).

Everything else (PHP 8.4, Postgres 18, Redis, Mailpit, S3 storage) runs in Laravel Sail.

## Set up (about 15 minutes, most of it the first image build)

```bash
git clone <repo-url> indlela-api && cd indlela-api
cp .env.example .env

# 1. Dependencies (host PHP; no PHP on the host? see "Without host PHP" below)
composer install

# 2. Ports: if another project already uses one of these on your machine, change it in .env
#    APP_PORT=8000, FORWARD_DB_PORT=5432, FORWARD_REDIS_PORT=6379, FORWARD_MAILPIT_PORT=1025,
#    FORWARD_MAILPIT_DASHBOARD_PORT=8025, FORWARD_MINIO_PORT=9000, FORWARD_MINIO_CONSOLE_PORT=9001
#    (check with `docker ps`; e.g. a local Postgres on 5432 → FORWARD_DB_PORT=5433)

# 3. Start the containers (the first run builds the PHP image)
./vendor/bin/sail up -d

# 4. App key, database, first user, storage bucket
./vendor/bin/sail artisan key:generate
./vendor/bin/sail artisan migrate --seed
./vendor/bin/sail artisan storage:bucket
```

`migrate --seed` creates the permission catalogue and a first account that owns the workspace "Indlela Demo", from `SEED_ADMIN_*` / `SEED_WORKSPACE_NAME` in `.env`. Leave `SEED_ADMIN_PASSWORD` empty and the seeder prints a generated password once. Sign-in sends a one-time PIN by email: open Mailpit to read it (or set `AUTH_PIN_ENABLED=false` locally).

Tip: `alias sail='./vendor/bin/sail'`.

### Without host PHP

```bash
docker run --rm -u "$(id -u):$(id -g)" -v "$(pwd):/var/www/html" -w /var/www/html \
    laravelsail/php84-composer:latest composer install --ignore-platform-reqs
```

## Running it

| What | Where |
|---|---|
| API | http://localhost:8000/api (e.g. `GET /api/auth/check`) |
| Mailpit (all outgoing email) | http://localhost:8025 |
| S3 storage console (`minio` service, user `sail` / `password`) | http://localhost:9001 |
| Horizon (queues) | http://localhost:8000/horizon, while `sail artisan horizon` runs |
| Postgres from the host (TablePlus, psql) | `localhost:FORWARD_DB_PORT`, db `indlela`, user `sail` / `password` |

```bash
./vendor/bin/sail artisan horizon      # queue worker (QUEUE_CONNECTION=redis); Ctrl+C to stop
./vendor/bin/sail artisan pail         # tail the logs (SMS PINs land here with SMS_DRIVER=log)
./vendor/bin/sail down                 # stop everything (data is kept in Docker volumes)
```

The SPA runs on http://localhost:5173 (`FRONTEND_URL`), which CORS allows; it signs in with Bearer tokens. Point it at `http://localhost:8000/api`.

About the `minio` service: MinIO no longer publishes Docker images, so it runs RustFS, an S3-compatible server (the one Sail itself now ships). The app only sees an S3 endpoint (`AWS_ENDPOINT=http://minio:9000`, path-style), so swapping in real S3 later is a `.env` change.

## Search Console

### Google Cloud set-up (once per environment)

1. In the [Google Cloud console](https://console.cloud.google.com/) create a project (e.g. "Indlela local").
2. APIs & Services › Library: enable **Google Search Console API**.
3. APIs & Services › OAuth consent screen: user type **External**; add the scopes `.../auth/webmasters.readonly`, `openid` and `email`; while the app is in *Testing*, add every Google account that will connect under **Test users** (test-mode consents expire after 7 days: reconnect then).
4. APIs & Services › Credentials › Create credentials › **OAuth client ID**, type **Web application**, Authorised redirect URI `http://localhost:8000/api/google/callback` (exactly; add the production URL there later).
5. Put the client ID and secret in `.env`: `GOOGLE_CLIENT_ID`, `GOOGLE_CLIENT_SECRET` (and `GOOGLE_REDIRECT_URI` if it differs).

In the app: Search Console › Connect Google, pick the agency's Google account (the one with access to the clients' properties), then link each site to its property. One Google account per workspace.

### Syncing

Linking a property queues a 16-month backfill; `search-console:sync` (scheduled daily at 05:30, or run it by hand, `--site=ID` for one) re-fetches the last 5 days of every linked site. Syncs are queued jobs on the `redis-long` connection, so **Horizon must be running** (its `supervisor-long` processes them); run the scheduler with `php artisan schedule:work` locally. Thresholds for the reports (quick-win band, minimum impressions, the expected-CTR curve, decay rules) are in `config/indlela.php` under `search_console`.

### Demo data (no Google account needed)

```bash
php artisan db:seed --class=GscDemoSeeder
```

Adds the client "Demo Client" and the site `demo.indlela.local` to the first workspace (if missing) and fills it with ~16 months of generated Search Console data (~150 queries on 30 pages, ~90k rows) so every report has rows. Re-running replaces only that site's data. It is not part of `db:seed`.

## Site audit

Crawls a site (raw HTML, no JavaScript; robots.txt honoured as `IndlelaBot`), lists issues by severity with a plain-English fix for each, scores the site 0–100, compares with the previous audit and measures Core Web Vitals with Google PageSpeed Insights. Rules, thresholds and the issue catalogue: `docs/API.md` › Site audit; numbers in `config/indlela.php` under `audit`.

- **Horizon must be running**: audits are queued jobs on the `redis-long` connection (`supervisor-long`). "Run audit" in the app or `POST /api/sites/{id}/audits`.
- **Schedule**: set a site to weekly or monthly in the app; `site-audit:run-scheduled` (daily 02:00; run the scheduler locally with `php artisan schedule:work`) queues the due ones and prunes old audits (the latest 10 per site keep their details).
- **Politeness**: `AUDIT_CONCURRENCY` (4), `AUDIT_TIMEOUT` (10 s), `AUDIT_DELAY_MS` (250) in `.env`. Page limit per site (default 500, max 5000).

### PageSpeed Insights key (optional, recommended)

Without a key Google's shared quota applies and vitals often come back "unavailable" (the audit itself still finishes).

1. In the same Google Cloud project as Search Console: APIs & Services › Library › enable **PageSpeed Insights API**.
2. APIs & Services › Credentials › Create credentials › **API key**; restrict it to the PageSpeed Insights API.
3. Put it in `.env` as `PAGESPEED_API_KEY`.

### Demo audits (no crawling)

```bash
php artisan db:seed --class=AuditDemoSeeder
```

Builds two finished audits (two weeks ago and yesterday) for `demo.indlela.local` in the first workspace (creating the site if `GscDemoSeeder` hasn't), with issues of every severity, a comparison and vitals. Re-running replaces only that site's audits. Not part of `db:seed`.

## Rank tracking

Tracks Google positions (top 100) of a site's keywords by country, city or suburb (a coordinate), on desktop and mobile, daily or weekly, through the **DataForSEO SERP API**. Records the ranking URL (two of the site's pages in the top 100 = cannibalisation) and SERP features (featured snippet, map pack, AI overview, …). Endpoints, parsing rules and the report maths: `docs/API.md` › Rank tracking; numbers in `config/indlela.php` under `rank_tracking`.

### DataForSEO account (platform-level)

DataForSEO is **one platform account** that the company pays for. Workspaces (agencies, SMEs) never configure it and never see its cost, balance or name: they see only their limits and, when checks are off, "Rank checks are paused for now".

1. Sign up at dataforseo.com; the dashboard › **API Access** shows the API login and password (not your dashboard password).
2. Put them in `.env` as `DATAFORSEO_LOGIN` and `DATAFORSEO_PASSWORD`.
3. `DATAFORSEO_SANDBOX=true` (the default) calls `sandbox.dataforseo.com`: free, but answers with sample SERPs (positions are not real). Set it to `false` for real, billed checks, and keep the balance topped up in the DataForSEO dashboard.

Without credentials the app still works: keywords can be added, "check now" and the location search answer 409 `rank_checks_paused`, and the scheduled jobs skip.

### Costs (platform-only)

| Mode | When | Price (USD) | Per check |
|---|---|---|---|
| Standard queue | Overnight checks (`rank-tracking:post-checks`, 01:00) | $0.60 per 1,000 | $0.0006 |
| Live | "Check now" in the app | $2.00 per 1,000 | $0.002 |

One check = one keyword × location × device × day, top 100 results. A keyword checked daily costs about 30 × $0.0006 = $0.018 a month; weekly (the default) about $0.0026. No hourly tracking. `rank_checks.cost` records what DataForSEO charged; it is never shown to workspaces.

### Beta limits

Pricing plans come later; until then usage is capped so one workspace can't run up the bill. All in `.env`:

| Variable | Default | What |
|---|---|---|
| `RANK_WORKSPACE_KEYWORD_LIMIT` | 500 | Active keywords (keyword × location × device) per workspace. `workspaces.rank_keyword_limit` overrides it for one workspace (null = the default). |
| `RANK_CHECK_NOW_DAILY_LIMIT` | 20 | "Check now" requests per workspace per day (SAST); failed ones count. Over it: 429. |
| `RANK_MONTHLY_SPEND_CAP_USD` | 50 | Platform-wide DataForSEO spend per calendar month, all workspaces together. At the cap overnight posting stops (what fits is posted, the rest skipped with a log warning) and "check now" pauses. 0 or empty = no cap. |

Workspaces see their counts and limits, and whether checks are paused, in `GET /api/rank-tracking/usage`.

### Running it

- **Horizon and the scheduler must run**: `php artisan horizon` and, locally, `php artisan schedule:work` (production: the usual `schedule:run` cron). `rank-tracking:post-checks` (daily 01:00) posts the day's checks to the Standard queue; `rank-tracking:collect` (every 5 minutes) collects finished ones; both run on the default `redis` queue.
- Run them by hand: `php artisan rank-tracking:post-checks`, `php artisan rank-tracking:collect`. `php artisan rank-tracking:locations` downloads DataForSEO's location list (~270k places, a few minutes; run it once after adding credentials). It always comes from DataForSEO's live host, which is free, because the sandbox only has a one-country sample. Afterwards a weekly schedule refreshes it when it is over 30 days old; the location search never downloads it, it queues the download and says "Locations are still loading".

### Demo data (no DataForSEO needed)

```bash
php artisan db:seed --class=RankDemoSeeder
```

Adds 60 keywords (10 phrases × South Africa, Johannesburg and a Sandton coordinate × desktop and mobile) with 90 days of results to `demo.indlela.local` in the first workspace: trends, this week's gains and drops, keywords outside the top 100, URL switches (cannibalisation) and SERP features. Re-running replaces only that site's keywords. Not part of `db:seed`. The demo keywords are active, so once DataForSEO is configured the nightly job checks them too.

## White-label reports

A report is one site's results for a period (Search Console, rank tracking, site audit; any of the three) as a **PDF**, a **share link** (`{FRONTEND_URL}/r/{token}`, no sign-in, optional expiry) and an **email** to the client, with a short plain-language summary at the top ("Search traffic up 14% (5,190 clicks)", "6 keywords entered the top 10", "2 critical issues need attention"). Routes, snapshot shape and summary rules: `docs/API.md` › White-label reports.

- **White-label.** The client sees the agency, never Indlela: the workspace's logo and name (Settings › Workspace) and its **brand colour** (`brand_color`, `#RRGGBB`, default navy `#1f4e79`) in the PDF header and accents, the share page and the email. The email's sender name is the workspace (the address stays `MAIL_FROM_ADDRESS`, so SPF/DKIM keep working), replies go to the agency person who sent or generated it (else the workspace's first Owner), and it uses its own layout with no app name or footer. Its wording is the workspace's `client_report` template in Settings › Email templates.
- **Frozen.** Numbers are read once, when the report is generated, and stored with it (`reports.data`): the PDF, the in-app view, the share page and the email always agree, even after a re-sync. Generate a new report for new numbers.
- **PDF** is rendered by [dompdf](https://github.com/barryvdh/laravel-dompdf) from `resources/views/reports/pdf.blade.php` (A4, DejaVu Sans subset so files stay ~35 KB; charts are small server-built SVGs; page numbers drawn by dompdf) and stored at `reports/{workspace_id}/{report_id}.pdf` on the default disk. Generation is the `GenerateReport` job on the `redis-long` queue (Horizon's `supervisor-long`): Horizon must run.
- **Monthly email.** A site can have one schedule (day 1–28, time on the hour 00:00–23:00 SAST (default 08:00), recipients, sections). `reports:run-scheduled` runs every hour and sends the previous calendar month's report to the schedule's recipients; it runs once per site per month, however often the command runs. The scheduler must run (`php artisan schedule:work` locally). Run it by hand: `php artisan reports:run-scheduled` (only schedules whose day is today and whose hour has come).

### Demo report

```bash
php artisan db:seed --class=ReportDemoSeeder
```

After the three demo seeders above: generates last month's report of `demo.indlela.local` in the first workspace (synchronously: a real PDF on the S3 store), shares it for 90 days and prints the link; gives the workspace a demo brand colour if it has none. Each run adds a report. The demo audits are dated this month, so last month's report says no audit had finished yet.

## Platform usage (Indlela staff)

**Platform › Usage** shows the company's DataForSEO spend (this month, pending, against the cap, the account balance) and every workspace's rank-tracking usage, so staff can set prices and raise a beta tester's keyword limit. Customers never see it: it exists only in one **platform workspace** (endpoints and rules: `docs/API.md` › Platform).

To make someone a superuser:

1. Set `INDLELA_PLATFORM_WORKSPACE` in `.env` to the slug of the staff workspace (e.g. `indlela-demo`; `php artisan tinker --execute='echo App\Models\Workspace::pluck("slug", "id")'` lists them). Empty = the page is off everywhere.
2. Run `php artisan db:seed --class=RolePermissionSeeder` (idempotent). It gives that workspace's Owner `view_platform_usage`, adds a **Superuser** role there (everything a non-Owner role may hold, plus Platform › Usage, all clients) and takes the permission off any role in other workspaces.
3. In that workspace, open **Settings › Users** and give the person the **Superuser** role (or tick Platform › Usage on another role in Settings › Roles).

They see the page while acting in the platform workspace; switching to another workspace hides it.

## Tests

```bash
./vendor/bin/sail artisan test
```

They run against the Postgres database `indlela_testing`, created automatically the first time the pgsql container starts (`docker/pgsql/create-testing-database.sql`). If your pgsql volume predates that file: `./vendor/bin/sail exec pgsql createdb -U sail indlela_testing`. `tests/TestCase.php` refuses to run against any database that doesn't look like a local test database.

Code style: `./vendor/bin/sail bin pint`.

## Configuration

Everything environment-specific is in `.env` (see `.env.example`, which is commented). Business rules for sign-in (lockout after 5 wrong passwords, PIN lifetime and tries, reset and invitation link lifetimes) are in `config/indlela.php`, overridable from `.env`. SMS PINs: `SMS_DRIVER=log` locally, `twilio` with `TWILIO_*` in production.
