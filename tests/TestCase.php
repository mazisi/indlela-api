<?php

namespace Tests;

use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use RuntimeException;

abstract class TestCase extends BaseTestCase
{
    /**
     * RefreshDatabase seeds the permission catalogue once, with its single
     * migrate:fresh per run; each test then runs in a transaction on top.
     * Workspaces (and their default roles) are made per test
     * (Tests\Support\AuthTestHelpers::makeWorkspace).
     */
    protected bool $seed = true;

    protected string $seeder = RolePermissionSeeder::class;

    /**
     * Hook the safety check before setUpTraits() runs. RefreshDatabase boots
     * inside setUpTraits() and immediately fires migrate:fresh — so we must
     * abort *before* parent::setUpTraits() returns, not in setUp(). We throw
     * (rather than $this->fail) so the entire process dies before any DDL
     * touches the connection.
     */
    protected function setUpTraits(): array
    {
        $this->assertSafeDatabaseTarget();

        return parent::setUpTraits();
    }

    /**
     * Refuse to run if the active DB connection doesn't smell like a local
     * test target. Catches misconfigured .env / phpunit.xml combinations
     * where Laravel's Dotenv loads .env (APP_ENV=local + prod-shaped host)
     * before phpunit.xml's <env> tags get a chance to override.
     */
    private function assertSafeDatabaseTarget(): void
    {
        $default = config('database.default');
        $dbName = (string) config("database.connections.{$default}.database");
        $dbHost = (string) config("database.connections.{$default}.host");

        $dangerousHostPatterns = [
            '.rds.amazonaws.com',
            '.database.azure.com',
            '.cloudsql.gcp',
            '.db.elephantsql.com',
            '.postgres.database.azure.com',
            '.mysql.database.azure.com',
            '.net',
            '.com',
        ];

        // `pgsql` is the Sail service.
        $safeHosts = ['127.0.0.1', 'localhost', '0.0.0.0', 'host.docker.internal', 'pgsql', 'postgres', 'db'];

        $dangerousDbPatterns = ['production', 'prod', 'live', 'main', 'billing'];
        $safeDbPatterns = ['test', 'testing', 'sqlite', ':memory:'];

        foreach ($dangerousHostPatterns as $pattern) {
            if (stripos($dbHost, $pattern) !== false) {
                throw new RuntimeException(
                    "SAFETY ABORT: refusing to run tests against remote DB host '{$dbHost}'. ".
                    'Tests must target a local DB. Check that phpunit.xml <env> entries are forced and .env.testing is loaded.'
                );
            }
        }

        foreach ($dangerousDbPatterns as $pattern) {
            if (stripos($dbName, $pattern) !== false) {
                throw new RuntimeException(
                    "SAFETY ABORT: refusing to run tests against database '{$dbName}' (matches pattern '{$pattern}')."
                );
            }
        }

        $isSafeHost = false;
        foreach ($safeHosts as $safeHost) {
            if (strcasecmp($dbHost, $safeHost) === 0) {
                $isSafeHost = true;
                break;
            }
        }

        $isSafeDb = false;
        foreach ($safeDbPatterns as $pattern) {
            if (stripos($dbName, $pattern) !== false) {
                $isSafeDb = true;
                break;
            }
        }

        // Host safety is unconditional: a remote numeric IP matches none of the
        // dangerous patterns above, and APP_ENV=testing must never excuse it.
        if (! $isSafeHost) {
            throw new RuntimeException(
                "SAFETY ABORT: DB host '{$dbHost}' is not on the local allowlist (".implode(', ', $safeHosts).'). '.
                'Tests must target a local DB regardless of APP_ENV. Current APP_ENV: '.app()->environment()
            );
        }

        if (! $isSafeDb && app()->environment() !== 'testing') {
            throw new RuntimeException(
                "SAFETY ABORT: database name '{$dbName}' does not look like a test database. ".
                "Database name must contain 'test', or APP_ENV must be 'testing'. ".
                'Current APP_ENV: '.app()->environment()
            );
        }
    }
}
