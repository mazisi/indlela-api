<?php

namespace Tests\Feature\Auth;

use App\Enums\UserStatus;
use App\Models\Role;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\Support\AuthTestHelpers;
use Tests\TestCase;

class AccountTest extends TestCase
{
    use AuthTestHelpers, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpAuth();
    }

    public function test_me_returns_the_login_body_without_the_token(): void
    {
        $user = $this->makeUser(Role::CLIENT);

        foreach (['/api/me', '/api/user/permissions', '/api/account'] as $path) {
            $response = $this->actingAs($user, 'sanctum')->getJson($path)->assertOk()
                ->assertJsonMissingPath('token')
                ->assertJsonPath('workspace.id', $this->workspace()->id)
                ->assertJsonPath('workspaces.0.role', 'Client')
                ->assertJsonPath('pin_enabled', true);

            $this->assertSame(['Client'], $response->json('roles'));
            $this->assertContains('view_reports', $response->json('permissions'));
            $this->assertNotContains('update_reports', $response->json('permissions'));
            $this->assertNotContains('view_settings_users', $response->json('permissions'));
        }
    }

    public function test_owners_and_managers_see_every_client_others_their_own(): void
    {
        $this->makeClient();
        $this->makeClient(state: ['is_active' => false]);
        $owner = $this->ownerOf($this->workspace());

        $this->actingAs($owner, 'sanctum')->getJson('/api/me')
            ->assertJsonPath('user.sees_all_clients', true)
            ->assertJsonCount(1, 'user.clients');

        $analyst = $this->makeUser();
        $this->actingAs($analyst, 'sanctum')->getJson('/api/me')
            ->assertJsonPath('user.sees_all_clients', false)
            ->assertJsonCount(1, 'user.clients');

        $this->actingAs($owner, 'sanctum')->getJson('/api/me')->assertJsonCount(2, 'user.clients');
    }

    public function test_change_password_needs_the_current_one_and_signs_out_other_devices(): void
    {
        $user = $this->makeUser(state: ['mfa_channel' => 'off']);
        $user->createToken('other-device');
        $token = $this->login($user)->json('token');

        $this->withToken($token)->putJson('/api/account/password', [
            'current_password' => 'Nope@2026', 'password' => 'Fresh#2027', 'password_confirmation' => 'Fresh#2027',
        ])->assertJsonPath('errors.current_password.0', 'Current password is not correct.');

        $this->withToken($token)->putJson('/api/account/password', [
            'current_password' => 'Demo@2026', 'password' => 'Fresh#2027', 'password_confirmation' => 'Fresh#2027',
        ])->assertOk();

        $this->assertTrue(Hash::check('Fresh#2027', $user->fresh()->password));
        $this->assertSame(1, $user->tokens()->count());
    }

    public function test_only_an_owner_may_switch_the_pin_off(): void
    {
        $analyst = $this->makeUser();
        $this->actingAs($analyst, 'sanctum')->putJson('/api/account', ['mfa_channel' => 'off'])
            ->assertJsonValidationErrors('mfa_channel');

        $owner = $this->makeOwner();
        $this->actingAs($owner, 'sanctum')->putJson('/api/account', ['mfa_channel' => 'off'])->assertOk();
    }

    public function test_sms_needs_a_cell_number(): void
    {
        $user = $this->makeUser(state: ['mobile' => null]);

        $this->actingAs($user, 'sanctum')->putJson('/api/account', ['mfa_channel' => 'sms'])
            ->assertJsonValidationErrors('mobile');

        $this->actingAs($user, 'sanctum')->putJson('/api/account', ['mfa_channel' => 'sms', 'mobile' => '082 555 0199'])
            ->assertOk()
            ->assertJsonPath('user.mobile', '0825550199');
    }

    public function test_an_owner_can_unlock_and_send_a_reset(): void
    {
        $owner = $this->makeOwner();
        $locked = $this->makeUser(state: ['status' => UserStatus::Locked]);

        $this->actingAs($owner, 'sanctum')->postJson("/api/users/{$locked->id}/unlock")->assertOk();
        $this->assertSame(UserStatus::Active, $locked->fresh()->status);

        $this->actingAs($owner, 'sanctum')->postJson("/api/users/{$locked->id}/password-reset")->assertOk();
        $this->assertNotNull($this->lastResetUrl($locked->email));

        $this->actingAs($this->makeUser(Role::MANAGER), 'sanctum')->postJson("/api/users/{$locked->id}/unlock")->assertForbidden();
    }

    public function test_login_history_is_for_owners(): void
    {
        $user = $this->makeUser();
        $this->login($user, 'Wrong@2026');

        $this->actingAs($this->makeOwner(), 'sanctum')->getJson('/api/login-history')
            ->assertOk()
            ->assertJsonPath('data.0.event_label', 'Wrong password')
            ->assertJsonPath('data.0.note', 'Attempt 1 of 5');

        $this->actingAs($this->makeUser(Role::MANAGER), 'sanctum')->getJson('/api/login-history')->assertForbidden();
    }

    public function test_login_history_searches_name_or_email_and_filters_by_event(): void
    {
        $owner = $this->makeOwner();
        $kevin = $this->makeUser(state: ['full_name' => 'Kevin', 'surname' => 'Adams', 'email' => 'k.adams@example.co.za']);
        $other = $this->makeUser();
        $this->login($kevin, 'Wrong@2026');
        $this->login($other, 'Wrong@2026');
        $this->login($kevin);

        $this->actingAs($owner, 'sanctum')->getJson('/api/login-history?search=kevin ad')
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.user', 'Kevin Adams');

        $this->actingAs($owner, 'sanctum')->getJson('/api/login-history?search=K.ADAMS&event=wrong_password')
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.event', 'wrong_password')
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('meta.per_page', 15);

        // A literal % matches nothing rather than everything.
        $this->actingAs($owner, 'sanctum')->getJson('/api/login-history?search=%25')->assertJsonCount(0, 'data');
    }
}
