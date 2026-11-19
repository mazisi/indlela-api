<?php

namespace Tests\Feature\Auth;

use App\Enums\UserStatus;
use App\Mail\TemplatedMail;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Tests\Support\AuthTestHelpers;
use Tests\TestCase;

class PasswordResetTest extends TestCase
{
    use AuthTestHelpers, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpAuth();
    }

    /** @return array{token: string, email: string} */
    private function requestLink(string $email): array
    {
        $this->postJson('/api/forgot-password', ['email' => $email])->assertOk();
        parse_str((string) parse_url((string) $this->lastResetUrl($email), PHP_URL_QUERY), $query);

        return ['token' => $query['token'], 'email' => $query['email']];
    }

    public function test_the_answer_is_the_same_whether_or_not_the_account_exists(): void
    {
        $user = $this->makeUser();
        $message = 'If an account exists for that email, a reset link is on its way.';

        $this->postJson('/api/forgot-password', ['email' => $user->email])->assertOk()->assertJsonPath('message', $message);
        $this->postJson('/api/forgot-password', ['email' => 'ghost@example.co.za'])->assertOk()->assertJsonPath('message', $message);

        Mail::assertSent(TemplatedMail::class, 1);
    }

    public function test_disabled_accounts_get_no_link(): void
    {
        $user = $this->makeUser(state: ['status' => UserStatus::Disabled]);

        $this->postJson('/api/forgot-password', ['email' => $user->email])->assertOk();

        Mail::assertNothingSent();
    }

    public function test_the_link_points_at_the_spa_and_the_token_is_stored_hashed(): void
    {
        $user = $this->makeUser();
        ['token' => $token] = $this->requestLink($user->email);

        $this->assertStringStartsWith(config('app.frontend_url').'/reset-password?token=', $this->lastResetUrl($user->email));
        $stored = \DB::table('password_reset_tokens')->where('email', $user->email)->value('token');
        $this->assertNotSame($token, $stored);
        $this->assertTrue(Hash::check($token, $stored));
    }

    public function test_reset_sets_the_password_signs_out_everywhere_and_the_link_works_once(): void
    {
        $user = $this->makeUser(state: ['mfa_channel' => 'off']);
        $user->createToken('old-device');
        $link = $this->requestLink($user->email);

        $this->postJson('/api/reset-password', $link + ['password' => 'Fresh#2027', 'password_confirmation' => 'Fresh#2027'])
            ->assertOk()
            ->assertJsonPath('message', 'Your password was changed. Sign in with the new password.');

        $this->assertTrue(Hash::check('Fresh#2027', $user->fresh()->password));
        $this->assertSame(0, $user->tokens()->count());
        $this->login($user, 'Fresh#2027')->assertOk();

        $this->postJson('/api/reset-password', $link + ['password' => 'Other#2027', 'password_confirmation' => 'Other#2027'])
            ->assertStatus(400)
            ->assertJsonPath('error_type', 'invalid_reset_token');
    }

    public function test_reset_unlocks_a_locked_account(): void
    {
        $user = $this->makeUser(state: ['status' => UserStatus::Locked, 'mfa_channel' => 'off']);
        $user->forceFill(['failed_login_attempts' => 5])->save();
        $link = $this->requestLink($user->email);

        $this->postJson('/api/reset-password', $link + ['password' => 'Fresh#2027', 'password_confirmation' => 'Fresh#2027'])->assertOk();

        $this->assertSame(UserStatus::Active, $user->fresh()->status);
        $this->assertSame(0, $user->fresh()->failed_login_attempts);
        $this->assertDatabaseHas('login_events', ['user_id' => $user->id, 'event' => 'unlocked', 'note' => 'By password reset']);
    }

    public function test_the_link_expires_after_an_hour_and_says_so(): void
    {
        config(['indlela.auth.reset_ttl_minutes' => 60]);
        $user = $this->makeUser();
        $link = $this->requestLink($user->email);

        $this->travel(61)->minutes();
        $this->postJson('/api/reset-password', $link + ['password' => 'Fresh#2027', 'password_confirmation' => 'Fresh#2027'])
            ->assertStatus(400)
            ->assertJsonPath('error_type', 'invalid_reset_token')
            ->assertJsonPath('message', 'This reset link has expired: links last 60 minutes. Ask for a new one.');
    }

    public function test_only_the_newest_link_works_and_the_old_one_says_why(): void
    {
        $user = $this->makeUser(state: ['mfa_channel' => 'off']);
        $first = $this->requestLink($user->email);
        $this->travel(2)->minutes();
        $second = $this->requestLink($user->email);

        $this->postJson('/api/reset-password', $first + ['password' => 'Fresh#2027', 'password_confirmation' => 'Fresh#2027'])
            ->assertStatus(400)
            ->assertJsonPath('error_type', 'invalid_reset_token')
            ->assertJsonPath('message', fn (string $m) => str_contains($m, 'only the link in the latest email works'));

        $this->postJson('/api/reset-password', $second + ['password' => 'Fresh#2027', 'password_confirmation' => 'Fresh#2027'])->assertOk();
    }

    public function test_an_unknown_email_reads_like_a_used_link(): void
    {
        $user = $this->makeUser();
        $link = $this->requestLink($user->email);
        $this->travel(2)->hours();

        $unknown = $this->postJson('/api/reset-password', ['email' => 'nobody@example.com', 'token' => $link['token'], 'password' => 'Fresh#2027', 'password_confirmation' => 'Fresh#2027'])->assertStatus(400);
        $wrong = $this->postJson('/api/reset-password', ['token' => str_repeat('x', 64)] + $link + ['password' => 'Fresh#2027', 'password_confirmation' => 'Fresh#2027'])->assertStatus(400);

        $this->assertSame($unknown->json('message'), $wrong->json('message'));
    }

    public function test_the_current_password_cannot_be_reused(): void
    {
        $user = $this->makeUser();
        $link = $this->requestLink($user->email);

        $this->postJson('/api/reset-password', $link + ['password' => 'Demo@2026', 'password_confirmation' => 'Demo@2026'])
            ->assertStatus(422)
            ->assertJsonPath('errors.password.0', 'Choose a password you have not used before.');
    }

    public function test_password_rules_are_enforced(): void
    {
        $user = $this->makeUser(state: ['email' => 'thandi@example.co.za']);
        $link = $this->requestLink($user->email);

        foreach (['short1!', 'NoDigits!!', 'NoSymbol123', 'thandi'] as $weak) {
            $this->postJson('/api/reset-password', $link + ['password' => $weak, 'password_confirmation' => $weak])
                ->assertStatus(422)
                ->assertJsonValidationErrors('password');
        }

        $this->postJson('/api/reset-password', $link + ['password' => 'Fresh#2027', 'password_confirmation' => 'Nope#2027'])
            ->assertJsonPath('errors.password.0', 'The passwords do not match.');
    }
}
