<?php

namespace App\Services\SearchConsole;

use App\Models\GoogleConnection;

/**
 * The Search Console calls Indlela makes. GoogleSearchConsoleApi talks to
 * Google over HTTP (tests fake it with Http::fake); bind another
 * implementation for a different source.
 */
interface SearchConsoleApi
{
    /**
     * The properties the connected account can read.
     *
     * @return list<array{site_url: string, permission_level: string}>
     */
    public function properties(GoogleConnection $connection): array;

    /**
     * One searchAnalytics.query page.
     *
     * @param  array<string, mixed>  $body  startDate, endDate, dimensions, rowLimit, startRow, …
     * @return list<array{keys?: list<string>, clicks: int|float, impressions: int|float, ctr: float, position: float}>
     */
    public function searchAnalytics(GoogleConnection $connection, string $property, array $body): array;
}
