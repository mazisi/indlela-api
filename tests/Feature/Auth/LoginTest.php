<?php

namespace Tests\Feature\Auth;

use App\Enums\LoginEventType;
use App\Enums\UserStatus;
use App\Models\LoginEvent;
use App\Models\Role;
use App\Models\User;
use App\Services\Sms\TwilioSmsSender;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\Support\AuthTestHelpers;
use Tests\TestCase;

class LoginTest extends TestCase
{
    use AuthTestHelpers, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpAuth();
    }

    public function test_unknown_email_gets_the_generic_message(): void
    {
        $this->postJson('/api/login', ['email' => 'nobody@example.co.za', 'password' => 'Whatever1!'])
            ->assertStatus(422)
            ->assertJsonPath('error_type', 'invalid_credentials')
            ->assertJsonPath('message', 'The email or password is not correct.');

        $this->assertDatabaseHas('login_events', ['email' => 'nobody@example.co.za', 'event' => LoginEventType::UnknownUser->value]);
    }

    public function test_email_is_matched_case_insensitively(): void
    {
        $user = $this->makeUser(state: ['mfa_channel' => 'off']);

        $this->postJson('/api/login', ['email' => strtoupper($user->email), 'password' => 'Demo@2026'])
            ->assertOk()
            ->assertJsonStructure(['token']);
    }

    public function test_wrong_password_counts_down_and_the_fifth_locks_the_account(): void
    {
        $user = $this->makeUser();

        foreach ([4, 3, 2, 1] as $left) {
            $this->login($user, 'Wrong@2026')
                ->assertStatus(422)
                ->assertJsonPath('attempts_left', $left)
                ->assertJsonPath('errors.password.0', "The email or password is not correct. {$left} attempt".($left === 1 ? '' : 's').' left before the account locks.');
        }

        $this->login($user, 'Wrong@2026')
            ->assertStatus(422)
            ->assertJsonPath('error_type', 'account_locked');

        $this->assertSame(UserStatus::Locked, $user->fresh()->status);

        // Now even the right password is refused.
        $this->login($user)->assertStatus(422)->assertJsonPath('error_type', 'account_locked');
        $this->assertDatabaseHas('login_events', ['user_id' => $user->id, 'event' => 'account_locked', 'note' => '5 wrong passwords']);
    }

    public function test_a_correct_password_resets_the_counter(): void
    {
        $user = $this->makeUser(state: ['mfa_channel' => 'off']);
        $this->login($user, 'Wrong@2026');
        $this->login($user, 'Wrong@2026');

        $this->login($user)->assertOk();

        $this->assertSame(0, $user->fresh()->failed_login_attempts);
    }

    public function test_status_is_only_revealed_after_a_correct_password(): void
    {
        $user = $this->makeUser(state: ['status' => UserStatus::Disabled]);

        $this->login($user, 'Wrong@2026')->assertJsonPath('error_type', 'invalid_credentials');

        $this->login($user)
            ->assertStatus(422)
            ->assertJsonPath('error_type', 'account_disabled')
            ->assertJsonPath('message', 'This account is disabled. Contact the Owner of your workspace.');
    }

    public function test_2fa_off_signs_straight_in_with_the_workforce_contract_plus_workspaces(): void
    {
        $user = $this->makeUser(state: ['mfa_channel' => 'off']);
        $workspace = $this->workspace();

        $response = $this->login($user)
            ->assertOk()
            ->assertJsonPath('message', 'Login successful')
            ->assertJsonPath('requires_mfa', false)
            ->assertJsonPath('token_type', 'Bearer')
            ->assertJsonPath('user.email', $user->email)
            ->assertJsonPath('user.role', 'Analyst')
            ->assertJsonPath('user.sees_all_clients', false)
            ->assertJsonPath('roles.0', 'Analyst')
            ->assertJsonPath('workspace', ['id' => $workspace->id, 'name' => $workspace->name, 'slug' => $workspace->slug, 'logo_url' => null, 'brand_color' => null])
            ->assertJsonPath('workspaces', [['id' => $workspace->id, 'name' => $workspace->name, 'slug' => $workspace->slug, 'logo_url' => null, 'role' => 'Analyst']])
            ->assertJsonStructure(['token', 'permissions', 'user' => ['clients' => [['id', 'name']]]]);

        $this->assertContains('update_rank_tracking', $response->json('permissions'));
        $this->assertNotContains('view_settings_users', $response->json('permissions'));
        $this->assertDatabaseHas('login_events', ['user_id' => $user->id, 'event' => 'signed_in', 'note' => 'Password only']);
    }

    public function test_the_session_starts_in_the_current_workspace_with_that_workspaces_role(): void
    {
        $user = $this->makeUser(Role::CLIENT, ['mfa_channel' => 'off']);
        $other = $this->makeWorkspace('Second');
        $this->addToWorkspace($user, $other, Role::MANAGER);
        $user->forceFill(['current_workspace_id' => $other->id])->save();

        $response = $this->login($user)->assertOk()
            ->assertJsonPath('workspace.id', $other->id)
            ->assertJsonPath('roles', ['Manager'])
            ->assertJsonPath('user.sees_all_clients', true)
            ->assertJsonCount(2, 'workspaces');

        $this->assertContains('delete_clients_clients', $response->json('permissions'));
        $this->assertSame(['Client', 'Manager'], collect($response->json('workspaces'))->sortBy('role')->pluck('role')->all());
    }

    public function test_an_account_in_no_workspace_cannot_sign_in(): void
    {
        $user = User::factory()->withoutMfa()->create();

        $this->login($user)->assertStatus(422)->assertJsonPath('error_type', 'no_workspace');
    }

    public function test_the_response_reports_the_previous_sign_in(): void
    {
        $user = $this->makeUser(state: ['mfa_channel' => 'off']);

        $this->login($user)->assertJsonPath('user.previous_login_at', null);
        $this->travel(1)->days();

        $previous = $this->login($user)->json('user.previous_login_at');
        $this->assertNotNull($previous);
        $this->assertTrue(now()->subDay()->isSameMinute(Carbon::parse($previous)));
    }

    public function test_pin_can_be_switched_off_for_everyone(): void
    {
        config(['indlela.auth.pin_enabled' => false]);
        $user = $this->makeUser(state: ['mfa_channel' => 'sms']);

        $this->login($user)
            ->assertOk()
            ->assertJsonPath('requires_mfa', false)
            ->assertJsonStructure(['token']);

        $this->assertEmpty($this->sms->sent);
        $this->assertDatabaseHas('login_events', ['user_id' => $user->id, 'event' => 'signed_in', 'note' => 'Password only']);
    }

    public function test_email_pin_flow_issues_a_session_only_after_the_pin(): void
    {
        $user = $this->makeUser();

        $challenge = $this->login($user)
            ->assertOk()
            ->assertJsonPath('requires_mfa', true)
            ->assertJsonPath('channel', 'email')
            ->assertJsonMissingPath('token')
            ->json('challenge_token');

        $pin = $this->lastEmailedPin($user->email);
        $this->assertMatchesRegularExpression('/^\d{6}$/', $pin);

        $this->postJson('/api/login/verify-pin', ['challenge_token' => $challenge, 'code' => $pin])
            ->assertOk()
            ->assertJsonStructure(['token', 'user', 'permissions']);

        // Single use.
        $this->postJson('/api/login/verify-pin', ['challenge_token' => $challenge, 'code' => $pin])
            ->assertStatus(422)
            ->assertJsonPath('error_type', 'challenge_closed');
    }

    public function test_sms_pin_goes_to_the_cell_number_masked(): void
    {
        $user = $this->makeUser(state: ['mfa_channel' => 'sms', 'mobile' => '0825550101']);

        $this->login($user)->assertJsonPath('channel', 'sms')->assertJsonPath('destination', '082•••0101');

        $this->assertSame('+27825550101', TwilioSmsSender::toE164($this->sms->sent[0]['to']));
        $this->assertNotNull($this->sms->lastCode());
    }

    public function test_three_wrong_pins_close_the_challenge(): void
    {
        $user = $this->makeUser();
        $challenge = $this->login($user)->json('challenge_token');
        $pin = $this->lastEmailedPin($user->email);
        $wrong = $pin === '000000' ? '111111' : '000000';

        $this->postJson('/api/login/verify-pin', ['challenge_token' => $challenge, 'code' => $wrong])->assertJsonPath('attempts_left', 2);
        $this->postJson('/api/login/verify-pin', ['challenge_token' => $challenge, 'code' => $wrong])->assertJsonPath('attempts_left', 1);
        $this->postJson('/api/login/verify-pin', ['challenge_token' => $challenge, 'code' => $wrong])
            ->assertJsonPath('error_type', 'challenge_closed')
            ->assertJsonPath('message', 'Too many wrong codes. Sign in again.');

        // The right PIN no longer helps.
        $this->postJson('/api/login/verify-pin', ['challenge_token' => $challenge, 'code' => $pin])
            ->assertJsonPath('error_type', 'challenge_closed');
    }

    public function test_an_expired_pin_is_refused_and_a_new_code_revives_it(): void
    {
        $user = $this->makeUser();
        $challenge = $this->login($user)->json('challenge_token');
        $pin = $this->lastEmailedPin($user->email);

        $this->travel(6)->minutes();

        $this->postJson('/api/login/verify-pin', ['challenge_token' => $challenge, 'code' => $pin])
            ->assertJsonPath('error_type', 'pin_expired');

        $this->postJson('/api/login/resend-pin', ['challenge_token' => $challenge])->assertOk();
        $fresh = $this->lastEmailedPin($user->email);

        $this->postJson('/api/login/verify-pin', ['challenge_token' => $challenge, 'code' => $fresh])->assertOk();
    }

    public function test_resend_has_a_cooldown(): void
    {
        $user = $this->makeUser();
        $challenge = $this->login($user)->json('challenge_token');

        $this->postJson('/api/login/resend-pin', ['challenge_token' => $challenge])
            ->assertStatus(429)
            ->assertJsonPath('error_type', 'resend_too_soon');
    }

    public function test_a_newer_sign_in_invalidates_the_older_challenge(): void
    {
        $user = $this->makeUser();
        $old = $this->login($user)->json('challenge_token');
        $oldPin = $this->lastEmailedPin($user->email);
        $this->login($user);

        $this->postJson('/api/login/verify-pin', ['challenge_token' => $old, 'code' => $oldPin])
            ->assertJsonPath('error_type', 'challenge_closed');
    }

    public function test_logout_revokes_the_token(): void
    {
        $user = $this->makeUser(state: ['mfa_channel' => 'off']);
        $token = $this->login($user)->json('token');

        $this->withToken($token)->postJson('/api/logout')->assertOk();
        $this->app['auth']->forgetGuards();

        $this->withToken($token)->getJson('/api/user/permissions')->assertUnauthorized();
        $this->assertDatabaseHas('login_events', ['user_id' => $user->id, 'event' => 'signed_out']);
    }

    public function test_a_disabled_user_loses_open_sessions(): void
    {
        $user = $this->makeUser(state: ['mfa_channel' => 'off']);
        $token = $this->login($user)->json('token');
        $user->forceFill(['status' => UserStatus::Disabled])->save();

        $this->withToken($token)->getJson('/api/user/permissions')
            ->assertUnauthorized()
            ->assertJsonPath('error_type', 'account_disabled');

        $this->assertSame(0, $user->tokens()->count());
    }

    public function test_every_outcome_lands_in_login_history(): void
    {
        $user = $this->makeUser();
        $this->login($user, 'Wrong@2026');
        $this->login($user);

        $this->assertEqualsCanonicalizing(
            ['wrong_password', 'pin_sent'],
            LoginEvent::where('user_id', $user->id)->pluck('event')->map->value->all(),
        );
    }
}
