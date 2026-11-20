<?php

namespace App\Services\SearchConsole;

use App\Exceptions\GoogleApiException;
use App\Models\GoogleConnection;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * The Search Console REST API (webmasters v3) with the connection's access
 * token, refreshed as needed.
 */
class GoogleSearchConsoleApi implements SearchConsoleApi
{
    public const BASE_URL = 'https://www.googleapis.com/webmasters/v3';

    public function __construct(private readonly GoogleConnectionService $connections) {}

    public function properties(GoogleConnection $connection): array
    {
        $response = $this->http($connection)->get(self::BASE_URL.'/sites');
        if (! $response->successful()) {
            throw GoogleApiException::fromResponse($response, 'the list of Search Console properties');
        }

        return collect($response->json('siteEntry', []))
            // Unverified entries can't be queried.
            ->reject(fn (array $entry) => ($entry['permissionLevel'] ?? '') === 'siteUnverifiedUser')
            ->map(fn (array $entry) => ['site_url' => (string) $entry['siteUrl'], 'permission_level' => (string) ($entry['permissionLevel'] ?? '')])
            ->sortBy('site_url')
            ->values()
            ->all();
    }

    public function searchAnalytics(GoogleConnection $connection, string $property, array $body): array
    {
        $url = self::BASE_URL.'/sites/'.rawurlencode($property).'/searchAnalytics/query';
        $response = $this->http($connection)->timeout(120)->post($url, $body);

        if (! $response->successful()) {
            throw GoogleApiException::fromResponse($response, "Search Console data for {$property}");
        }

        return $response->json('rows', []);
    }

    private function http(GoogleConnection $connection): PendingRequest
    {
        return Http::withToken($this->connections->accessToken($connection))->acceptJson()->timeout(30)
            // Google's rate limit and transient errors: a short retry before giving up.
            ->retry(2, 1000, fn (Throwable $e) => $e instanceof RequestException && in_array($e->response->status(), [429, 500, 502, 503], true), throw: false);
    }
}
