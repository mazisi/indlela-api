<?php

namespace App\Services\SearchConsole;

use App\Exceptions\GoogleApiException;
use App\Exceptions\SearchConsoleException;
use App\Models\GoogleConnection;
use App\Models\User;
use App\Models\Workspace;
use App\Support\CurrentWorkspace;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * The workspace's Google account: starting and finishing the OAuth
 * round-trip, keeping the access token fresh, disconnecting.
 *
 * The callback is a public route (Google redirects the browser there without
 * our Bearer token), so who and where come from `state`: an encrypted payload
 * (APP_KEY, so it can't be forged or read) of workspace, user, a single-use
 * nonce and a 10-minute expiry.
 */
class GoogleConnectionService
{
    private const STATE_TTL_MINUTES = 10;

    public function __construct(
        private readonly GoogleOAuth $oauth,
        private readonly CurrentWorkspace $current,
    ) {}

    /** The current workspace's connection, whatever its status. */
    public function current(): ?GoogleConnection
    {
        return GoogleConnection::query()->with('connectedBy')->first();
    }

    /** The current workspace's working connection, or 409 google_not_connected. */
    public function requireActive(): GoogleConnection
    {
        $connection = GoogleConnection::query()->first();

        if (! $connection) {
            throw SearchConsoleException::googleNotConnected();
        }
        if (! $connection->isActive()) {
            throw SearchConsoleException::googleNotConnected($connection->last_error ?: 'The Google connection needs to be connected again.');
        }

        return $connection;
    }

    public function connectUrl(User $user, Workspace $workspace): string
    {
        $state = Crypt::encryptString(json_encode([
            'w' => $workspace->id,
            'u' => $user->id,
            'n' => Str::random(32),
            'exp' => now()->addMinutes(self::STATE_TTL_MINUTES)->getTimestamp(),
        ]));

        return $this->oauth->authUrl($state);
    }

    /**
     * Google sent the browser back: check state, swap the code for tokens and
     * store the connection, replacing the workspace's previous one.
     *
     * @throws SearchConsoleException with a message for the person (error_type oauth_failed)
     */
    public function completeCallback(?string $code, ?string $state, ?string $googleError): GoogleConnection
    {
        if ($googleError !== null) {
            throw $this->failed($googleError === 'access_denied'
                ? 'Google access was not granted.'
                : 'Google could not complete the connection ('.Str::limit($googleError, 60).').');
        }

        [$workspace, $user] = $this->readState($state);

        if (! is_string($code) || $code === '') {
            throw $this->failed('Google did not send a sign-in code. Try connecting again.');
        }

        try {
            $tokens = $this->oauth->exchangeCode($code);
        } catch (GoogleApiException) {
            throw $this->failed('Google did not accept the sign-in. Try connecting again.');
        }

        $scopes = (string) ($tokens['scope'] ?? '');
        if (! str_contains($scopes, GoogleOAuth::SEARCH_CONSOLE_SCOPE)) {
            throw $this->failed('Indlela needs permission to view your Search Console data. Connect again and leave that box ticked.');
        }
        if (empty($tokens['refresh_token'])) {
            throw $this->failed('Google did not grant offline access. Remove Indlela under your Google account\'s third-party access, then connect again.');
        }

        return $this->current->using($workspace, fn () => DB::transaction(function () use ($tokens, $scopes, $user) {
            // Not revoked: the same Google account reconnecting shares the grant, so revoking would kill the new tokens.
            GoogleConnection::query()->delete();

            return GoogleConnection::create([
                'connected_by' => $user->id,
                'google_email' => $this->oauth->emailFromIdToken($tokens['id_token'] ?? null),
                'access_token' => $tokens['access_token'],
                'refresh_token' => $tokens['refresh_token'],
                'token_expires_at' => now()->addSeconds((int) ($tokens['expires_in'] ?? 3600)),
                'scopes' => $scopes,
                'status' => GoogleConnection::ACTIVE,
            ]);
        }));
    }

    /**
     * A usable access token, refreshed when it expires within a minute. On
     * invalid_grant the connection is marked `error` (syncs stop, the page
     * asks to reconnect) and 409 google_not_connected is thrown.
     */
    public function accessToken(GoogleConnection $connection): string
    {
        if ($connection->token_expires_at?->isAfter(now()->addMinute())) {
            return $connection->access_token;
        }

        try {
            $tokens = $this->oauth->refresh($connection->refresh_token);
        } catch (SearchConsoleException $e) {
            $connection->forceFill(['status' => GoogleConnection::ERROR, 'last_error' => $e->getMessage()])->save();

            throw $e;
        }

        $connection->forceFill([
            'access_token' => $tokens['access_token'],
            'token_expires_at' => now()->addSeconds((int) ($tokens['expires_in'] ?? 3600)),
            'status' => GoogleConnection::ACTIVE,
            'last_error' => null,
        ])->save();

        return $connection->access_token;
    }

    /** Revoke at Google (best effort) and forget it. Sites keep their property; syncing stops. */
    public function disconnect(GoogleConnection $connection): void
    {
        $this->oauth->revoke($connection->refresh_token);
        $connection->delete();
    }

    /** @return array{0: Workspace, 1: User} */
    private function readState(?string $state): array
    {
        try {
            $payload = json_decode(Crypt::decryptString((string) $state), true, flags: JSON_THROW_ON_ERROR);
        } catch (DecryptException|\JsonException) {
            throw $this->failed('That Google sign-in link is not valid. Start again from Search Console.');
        }

        if (! is_array($payload) || ! isset($payload['w'], $payload['u'], $payload['n'], $payload['exp'])) {
            throw $this->failed('That Google sign-in link is not valid. Start again from Search Console.');
        }
        if ((int) $payload['exp'] < now()->getTimestamp()) {
            throw $this->failed('The Google sign-in took longer than 10 minutes. Start again from Search Console.');
        }
        // Single use: a replayed state (back button, a leaked URL) is refused.
        if (! Cache::add('google-oauth-state:'.$payload['n'], true, now()->addMinutes(self::STATE_TTL_MINUTES + 1))) {
            throw $this->failed('That Google sign-in link was already used. Start again from Search Console.');
        }

        $workspace = Workspace::find($payload['w']);
        $user = User::find($payload['u']);
        $allowed = $workspace && $user && $user->isActive()
            && $user->belongsToWorkspace($workspace)
            && $this->current->using($workspace, fn () => $user->unsetRelation('roles')->unsetRelation('permissions')->can('update_search_console'));

        if (! $allowed) {
            throw $this->failed('You no longer have permission to connect Google for this workspace.');
        }

        return [$workspace, $user];
    }

    private function failed(string $message): SearchConsoleException
    {
        return new SearchConsoleException($message, 'oauth_failed', 400);
    }
}
