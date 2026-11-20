<?php

namespace Tests\Feature\SearchConsole;

use App\Models\GoogleConnection;
use App\Models\Role;
use App\Models\User;
use App\Services\SearchConsole\GoogleConnectionService;
use App\Services\SearchConsole\GoogleOAuth;
use App\Support\CurrentWorkspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;
use Tests\Support\AuthTestHelpers;
use Tests\Support\SearchConsoleHelpers;
use Tests\TestCase;

class GoogleConnectionTest extends TestCase
{
    use AuthTestHelpers, RefreshDatabase, SearchConsoleHelpers;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpAuth();
        config(['services.google' => ['client_id' => 'client-id', 'client_secret' => 'secret', 'redirect_uri' => 'http://localhost:8000/api/google/callback'], 'app.frontend_url' => 'http://localhost:5173']);
        $this->owner = $this->ownerOf($this->workspace());
        Http::preventStrayRequests();
    }

    /** POST /google/connect and pull the state out of the consent URL. */
    private function startConnect(?User $user = null): string
    {
        $url = $this->actingAs($user ?? $this->owner, 'sanctum')->postJson('/api/google/connect')->assertOk()->json('url');
        parse_str(parse_url($url, PHP_URL_QUERY), $query);

        return $query['state'];
    }

    private function fakeTokenExchange(array $overrides = []): void
    {
        Http::fake(['oauth2.googleapis.com/token' => Http::response([
            'access_token' => 'access-new', 'refresh_token' => 'refresh-new', 'expires_in' => 3599, 'token_type' => 'Bearer',
            'scope' => 'openid https://www.googleapis.com/auth/userinfo.email https://www.googleapis.com/auth/webmasters.readonly',
            'id_token' => $this->idToken('seo@agency.co.za'), ...$overrides,
        ])]);
    }

    public function test_connect_returns_googles_consent_url_for_offline_read_only_access(): void
    {
        $url = $this->actingAs($this->owner, 'sanctum')->postJson('/api/google/connect')->assertOk()->json('url');
        parse_str(parse_url($url, PHP_URL_QUERY), $query);

        $this->assertStringStartsWith('https://accounts.google.com/o/oauth2/v2/auth?', $url);
        $this->assertSame('client-id', $query['client_id']);
        $this->assertSame('http://localhost:8000/api/google/callback', $query['redirect_uri']);
        $this->assertSame('offline', $query['access_type']);
        $this->assertSame('consent', $query['prompt']);
        $this->assertStringContainsString('https://www.googleapis.com/auth/webmasters.readonly', $query['scope']);
        $state = json_decode(Crypt::decryptString($query['state']), true);
        $this->assertSame([$this->workspace()->id, $this->owner->id], [$state['w'], $state['u']]);
        $this->assertEqualsWithDelta(now()->addMinutes(10)->getTimestamp(), $state['exp'], 2);
    }

    public function test_the_callback_stores_the_connection_and_returns_to_the_app(): void
    {
        $state = $this->startConnect();
        $old = $this->connectGoogle(state: ['google_email' => 'old@agency.co.za']);
        $this->fakeTokenExchange();

        $this->get('/api/google/callback?code=auth-code&state='.urlencode($state))
            ->assertRedirect('http://localhost:5173/dashboard/search-console?google=connected');

        Http::assertSent(fn (Request $r) => $r->url() === 'https://oauth2.googleapis.com/token' && $r['code'] === 'auth-code' && $r['grant_type'] === 'authorization_code');
        $this->assertModelMissing($old);
        $connection = GoogleConnection::withoutGlobalScopes()->sole();
        $this->assertSame($this->workspace()->id, $connection->workspace_id);
        $this->assertSame('seo@agency.co.za', $connection->google_email);
        $this->assertSame('refresh-new', $connection->refresh_token);
        $this->assertNotSame('refresh-new', $connection->getRawOriginal('refresh_token'), 'tokens are encrypted at rest');

        $this->actingAs($this->owner, 'sanctum')->getJson('/api/google/connection')
            ->assertOk()
            ->assertJsonPath('data.google_email', 'seo@agency.co.za')
            ->assertJsonPath('data.status', 'active')
            ->assertJsonPath('data.connected_by', ['id' => $this->owner->id, 'name' => $this->owner->displayName()])
            ->assertJsonMissingPath('data.access_token')
            ->assertJsonMissingPath('data.refresh_token');
    }

    public function test_a_bad_expired_or_replayed_state_is_refused(): void
    {
        $this->fakeTokenExchange();

        $this->get('/api/google/callback?code=c&state=forged')
            ->assertRedirect('http://localhost:5173/dashboard/search-console?google=error&message='.rawurlencode('That Google sign-in link is not valid. Start again from Search Console.'));

        $state = $this->startConnect();
        $this->travel(11)->minutes();
        $this->get('/api/google/callback?code=c&state='.urlencode($state))->assertRedirectContains('google=error')->assertRedirectContains(rawurlencode('longer than 10 minutes'));
        $this->travelBack();

        $state = $this->startConnect();
        $this->get('/api/google/callback?code=c&state='.urlencode($state))->assertRedirectContains('google=connected');
        $this->get('/api/google/callback?code=c&state='.urlencode($state))->assertRedirectContains(rawurlencode('already used'));

        Http::assertSentCount(1);
    }

    public function test_google_errors_and_missing_permissions_end_on_the_error_page(): void
    {
        $this->get('/api/google/callback?error=access_denied&state=x')->assertRedirectContains(rawurlencode('Google access was not granted.'));

        $tokens = ['access_token' => 'a', 'refresh_token' => 'r', 'expires_in' => 3600, 'scope' => 'openid email'];
        Http::fake(['oauth2.googleapis.com/token' => Http::sequence()
            ->push(['error' => 'invalid_grant'], 400)
            ->push($tokens)
            ->push([...$tokens, 'scope' => GoogleOAuth::SEARCH_CONSOLE_SCOPE, 'refresh_token' => null])]);

        $this->get('/api/google/callback?code=c&state='.urlencode($this->startConnect()))->assertRedirectContains(rawurlencode('Google did not accept the sign-in'));
        $this->get('/api/google/callback?code=c&state='.urlencode($this->startConnect()))->assertRedirectContains(rawurlencode('permission to view your Search Console data'));
        $this->get('/api/google/callback?code=c&state='.urlencode($this->startConnect()))->assertRedirectContains(rawurlencode('did not grant offline access'));

        // An Analyst may connect; a Client login may not even start.
        $this->actingAs($this->makeUser(Role::CLIENT), 'sanctum')->postJson('/api/google/connect')->assertForbidden();

        $this->assertSame(0, GoogleConnection::withoutGlobalScopes()->count());
    }

    public function test_a_user_who_lost_the_permission_meanwhile_cannot_finish_connecting(): void
    {
        $analyst = $this->makeUser(Role::ANALYST);
        $state = $this->startConnect($analyst);
        $this->workspace()->users()->detach($analyst->id);
        $this->fakeTokenExchange();

        $this->get('/api/google/callback?code=c&state='.urlencode($state))->assertRedirectContains(rawurlencode('no longer have permission'));
        $this->assertSame(0, GoogleConnection::withoutGlobalScopes()->count());
    }

    public function test_an_expired_access_token_is_refreshed_and_invalid_grant_marks_the_connection(): void
    {
        $connection = $this->connectGoogle(state: ['token_expires_at' => now()->subMinute()]);
        Http::fake([
            'oauth2.googleapis.com/token' => Http::sequence()
                ->push(['access_token' => 'access-2', 'expires_in' => 3600])
                ->push(['error' => 'invalid_grant', 'error_description' => 'Token has been expired or revoked.'], 400),
            'www.googleapis.com/webmasters/v3/sites' => Http::response(['siteEntry' => [
                ['siteUrl' => 'sc-domain:karoo.co.za', 'permissionLevel' => 'siteOwner'],
                ['siteUrl' => 'https://www.acme.co.za/', 'permissionLevel' => 'siteFullUser'],
                ['siteUrl' => 'https://unverified.co.za/', 'permissionLevel' => 'siteUnverifiedUser'],
            ]]),
        ]);

        $this->actingAs($this->owner, 'sanctum')->getJson('/api/google/properties')
            ->assertOk()
            ->assertExactJson(['data' => [
                ['site_url' => 'https://www.acme.co.za/', 'permission_level' => 'siteFullUser'],
                ['site_url' => 'sc-domain:karoo.co.za', 'permission_level' => 'siteOwner'],
            ]]);
        Http::assertSent(fn (Request $r) => str_ends_with($r->url(), '/webmasters/v3/sites') && $r->hasHeader('Authorization', 'Bearer access-2'));
        $this->assertSame('access-2', $connection->fresh()->access_token);
        $this->assertTrue($connection->fresh()->token_expires_at->isFuture());

        // Expired again: Google says the grant is gone.
        $connection->refresh()->forceFill(['token_expires_at' => now()->subMinute()])->save();
        $this->actingAs($this->owner, 'sanctum')->getJson('/api/google/properties')
            ->assertStatus(409)->assertJsonPath('error_type', 'google_not_connected');
        $this->assertSame(GoogleConnection::ERROR, $connection->fresh()->status);
        $this->assertStringContainsString('revoked or has expired', $connection->fresh()->last_error);

        $this->actingAs($this->owner, 'sanctum')->getJson('/api/google/properties')->assertStatus(409);
        $this->actingAs($this->owner, 'sanctum')->getJson('/api/google/connection')->assertJsonPath('data.status', 'error');
    }

    public function test_properties_without_a_connection_is_409_and_viewing_shows_null(): void
    {
        $this->actingAs($this->owner, 'sanctum')->getJson('/api/google/properties')
            ->assertStatus(409)->assertJsonPath('error_type', 'google_not_connected');
        $this->actingAs($this->owner, 'sanctum')->getJson('/api/google/connection')->assertOk()->assertExactJson(['data' => null]);

        // Client logins see the status but can't list properties.
        $this->actingAs($this->makeUser(Role::CLIENT), 'sanctum')->getJson('/api/google/connection')->assertOk();
        $this->actingAs($this->makeUser(Role::CLIENT), 'sanctum')->getJson('/api/google/properties')->assertForbidden();
    }

    public function test_disconnect_revokes_at_google_and_deletes_only_this_workspaces_connection(): void
    {
        $mine = $this->connectGoogle();
        $theirs = $this->connectGoogle($this->makeWorkspace('Rival'));
        $site = $this->linkedSite();
        Http::fake(['oauth2.googleapis.com/revoke' => Http::response([], 200)]);

        $this->actingAs($this->makeUser(Role::CLIENT), 'sanctum')->deleteJson('/api/google/connection')->assertForbidden();
        $this->actingAs($this->owner, 'sanctum')->deleteJson('/api/google/connection')->assertNoContent();

        Http::assertSent(fn (Request $r) => $r->url() === 'https://oauth2.googleapis.com/revoke' && $r['token'] === 'refresh-1');
        $this->assertModelMissing($mine);
        $this->assertModelExists($theirs);
        $this->assertSame('sc-domain:example.co.za', $site->fresh()->gsc_property, 'sites keep their property');
    }

    public function test_the_connection_service_only_sees_the_current_workspace(): void
    {
        $this->connectGoogle($this->makeWorkspace('Rival'));

        $found = app(CurrentWorkspace::class)->using($this->workspace(), fn () => app(GoogleConnectionService::class)->current());

        $this->assertNull($found);
    }
}
