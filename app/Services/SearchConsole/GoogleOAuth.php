<?php

namespace App\Services\SearchConsole;

use App\Exceptions\GoogleApiException;
use App\Exceptions\SearchConsoleException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Google's OAuth 2.0 endpoints over Laravel's Http client (no google/apiclient):
 * the consent URL, code exchange, refresh and revoke. `openid email` is asked
 * for alongside Search Console so the id_token names the connected account.
 */
class GoogleOAuth
{
    public const AUTH_URL = 'https://accounts.google.com/o/oauth2/v2/auth';

    public const TOKEN_URL = 'https://oauth2.googleapis.com/token';

    public const REVOKE_URL = 'https://oauth2.googleapis.com/revoke';

    public const SEARCH_CONSOLE_SCOPE = 'https://www.googleapis.com/auth/webmasters.readonly';

    public const SCOPES = ['openid', 'email', self::SEARCH_CONSOLE_SCOPE];

    public function authUrl(string $state): string
    {
        return self::AUTH_URL.'?'.http_build_query([
            'client_id' => config('services.google.client_id'),
            'redirect_uri' => config('services.google.redirect_uri'),
            'response_type' => 'code',
            'scope' => implode(' ', self::SCOPES),
            'access_type' => 'offline',
            'prompt' => 'consent',
            'include_granted_scopes' => 'true',
            'state' => $state,
        ], '', '&', PHP_QUERY_RFC3986);
    }

    /**
     * @return array{access_token: string, refresh_token?: string, expires_in?: int, scope?: string, id_token?: string}
     */
    public function exchangeCode(string $code): array
    {
        $response = Http::asForm()->acceptJson()->timeout(20)->post(self::TOKEN_URL, [
            'code' => $code,
            'client_id' => config('services.google.client_id'),
            'client_secret' => config('services.google.client_secret'),
            'redirect_uri' => config('services.google.redirect_uri'),
            'grant_type' => 'authorization_code',
        ]);

        if (! $response->successful() || ! is_string($response->json('access_token'))) {
            throw GoogleApiException::fromResponse($response, 'the sign-in code');
        }

        return $response->json();
    }

    /**
     * A new access token. `invalid_grant` (revoked, expired after 6 months
     * unused, or a test-mode consent past 7 days) can't be retried: the
     * account must be connected again.
     *
     * @return array{access_token: string, expires_in?: int, scope?: string}
     */
    public function refresh(string $refreshToken): array
    {
        $response = Http::asForm()->acceptJson()->timeout(20)->post(self::TOKEN_URL, [
            'refresh_token' => $refreshToken,
            'client_id' => config('services.google.client_id'),
            'client_secret' => config('services.google.client_secret'),
            'grant_type' => 'refresh_token',
        ]);

        if ($response->json('error') === 'invalid_grant') {
            throw SearchConsoleException::googleNotConnected('Google access for this workspace was revoked or has expired. Connect the Google account again.');
        }

        if (! $response->successful() || ! is_string($response->json('access_token'))) {
            throw GoogleApiException::fromResponse($response, 'to refresh the access token');
        }

        return $response->json();
    }

    /** Best effort: a token Google already forgot is fine, and so is Google being down. */
    public function revoke(string $token): void
    {
        try {
            Http::asForm()->timeout(10)->post(self::REVOKE_URL, ['token' => $token]);
        } catch (Throwable $e) {
            Log::warning('Google token revoke failed: '.$e->getMessage());
        }
    }

    /**
     * The account's email from the id_token. The token comes straight from
     * Google's token endpoint over TLS, so its claims are read, not verified.
     */
    public function emailFromIdToken(?string $idToken): ?string
    {
        $parts = explode('.', (string) $idToken);
        if (count($parts) !== 3) {
            return null;
        }

        $claims = json_decode((string) base64_decode(strtr($parts[1], '-_', '+/'), true), true);

        return is_array($claims) && is_string($claims['email'] ?? null) ? $claims['email'] : null;
    }
}
