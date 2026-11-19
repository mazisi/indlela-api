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

## Tests

```bash
./vendor/bin/sail artisan test
```

They run against the Postgres database `indlela_testing`, created automatically the first time the pgsql container starts (`docker/pgsql/create-testing-database.sql`). If your pgsql volume predates that file: `./vendor/bin/sail exec pgsql createdb -U sail indlela_testing`. `tests/TestCase.php` refuses to run against any database that doesn't look like a local test database.

Code style: `./vendor/bin/sail bin pint`.

## Configuration

Everything environment-specific is in `.env` (see `.env.example`, which is commented). Business rules for sign-in (lockout after 5 wrong passwords, PIN lifetime and tries, reset and invitation link lifetimes) are in `config/indlela.php`, overridable from `.env`. SMS PINs: `SMS_DRIVER=log` locally, `twilio` with `TWILIO_*` in production.
