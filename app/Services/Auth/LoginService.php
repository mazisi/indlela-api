<?php

namespace App\Services\Auth;

use App\Enums\LoginEventType;
use App\Enums\MfaChannel;
use App\Enums\UserStatus;
use App\Exceptions\AuthFlowException;
use App\Models\User;
use App\Services\Workspaces\WorkspaceContext;
use App\Support\AccountState;
use Illuminate\Support\Facades\Hash;

/**
 * Password step of sign-in, ported from workforce-api:
 *
 *  - 5 wrong passwords in a row lock the account (status = locked).
 *  - A locked account is refused before the password is even checked.
 *  - A disabled account is only told so once the password is right — a
 *    stranger guessing emails learns nothing from the status.
 *  - Then either the one-time PIN challenge, or (PIN disabled globally or for
 *    the user) straight in.
 *  - The session starts in the user's current workspace (or the first they
 *    joined); an account in no workspace is refused (`no_workspace`).
 */
class LoginService
{
    private static ?string $dummyHash = null;

    public function __construct(
        private readonly LoginChallengeService $challenges,
        private readonly LoginEventLogger $events,
        private readonly WorkspaceContext $workspaces,
    ) {}

    /**
     * @return array<string, mixed> the JSON body for POST /login
     */
    public function attempt(string $email, string $password): array
    {
        $user = User::whereEmail($email)->first();

        if (! $user) {
            // Burn comparable time so response timing doesn't reveal the miss.
            Hash::check($password, self::$dummyHash ??= Hash::make('indlela-timing-guard'));
            $this->events->record(LoginEventType::UnknownUser, $email);

            throw new AuthFlowException('The email or password is not correct.', 'invalid_credentials');
        }

        if ($user->status === UserStatus::Locked) {
            $this->events->record(LoginEventType::BlockedStatus, $user, 'Account is locked');

            throw new AuthFlowException(UserStatus::Locked->loginMessage(), 'account_locked');
        }

        if (! Hash::check($password, $user->password)) {
            $this->registerFailure($user);
        }

        if ($user->failed_login_attempts > 0) {
            $user->forceFill(['failed_login_attempts' => 0])->save();
        }

        if (! $user->isActive()) {
            $this->events->record(LoginEventType::BlockedStatus, $user, $user->status->label());

            throw new AuthFlowException($user->status->loginMessage(), 'account_'.$user->status->value);
        }

        // PIN switched off for everyone (AUTH_PIN_ENABLED=false) or for this user.
        if (! config('indlela.auth.pin_enabled') || $user->mfa_channel === MfaChannel::Off) {
            return $this->startSession($user, 'Password only');
        }

        ['challenge' => $challenge, 'token' => $token] = $this->challenges->issue($user);
        $this->events->record(LoginEventType::PinSent, $user, 'One-time PIN sent by '.$user->mfa_channel->label());

        return [
            'message' => 'Enter the one-time PIN we sent you.',
            'requires_mfa' => true,
            'challenge_token' => $token,
            'channel' => $challenge->channel->value,
            'destination' => $this->challenges->maskedDestination($user),
            'expires_at' => $challenge->expires_at->toIso8601String(),
            'resend_available_in' => (int) config('indlela.auth.pin_resend_cooldown_seconds'),
        ];
    }

    /**
     * Second step: trade the challenge token + PIN for a session.
     *
     * @return array<string, mixed>
     */
    public function verifyPin(string $challengeToken, string $code): array
    {
        $user = $this->challenges->verify($challengeToken, $code);

        return $this->startSession($user, '2FA by '.$user->mfa_channel->label());
    }

    /**
     * Issue the Sanctum token: workforce's response contract (`message, user,
     * token, token_type, roles, permissions`) so the ported FE login code reads
     * it unchanged, plus `workspace` and `workspaces` (AccountState).
     *
     * @return array<string, mixed>
     */
    public function startSession(User $user, string $method): array
    {
        if (! $this->workspaces->enter($user)) {
            $this->events->record(LoginEventType::BlockedStatus, $user, 'In no workspace');

            throw new AuthFlowException('This account is not in any workspace any more. Ask a workspace Owner to add you again.', 'no_workspace');
        }

        $user->forceFill(['last_login_at' => now()])->save();
        $token = $user->createToken('auth_token')->plainTextToken;

        $this->events->record(LoginEventType::SignedIn, $user, $method);

        $state = AccountState::for($user);

        return [
            'message' => 'Login successful',
            'requires_mfa' => false,
            'user' => $state['user'],
            'token' => $token,
            'token_type' => 'Bearer',
            'roles' => $state['roles'],
            'permissions' => $state['permissions'],
            'workspace' => $state['workspace'],
            'workspaces' => $state['workspaces'],
        ];
    }

    private function registerFailure(User $user): never
    {
        $max = (int) config('indlela.auth.max_failed_attempts');

        // Atomic so two parallel guesses can't both land on "4 of 5".
        $user->increment('failed_login_attempts');
        $user->refresh();

        if ($user->failed_login_attempts >= $max) {
            $user->forceFill(['status' => UserStatus::Locked, 'locked_at' => now()])->save();
            $this->challenges->closeAllFor($user);
            $this->events->record(LoginEventType::AccountLocked, $user, "{$max} wrong passwords");

            throw new AuthFlowException(
                'Too many wrong passwords: the account is now locked. Reset your password or ask the Owner of your workspace to unlock it.',
                'account_locked',
                'password',
            );
        }

        $left = $max - $user->failed_login_attempts;
        $this->events->record(LoginEventType::WrongPassword, $user, "Attempt {$user->failed_login_attempts} of {$max}");

        throw new AuthFlowException(
            "The email or password is not correct. {$left} attempt".($left === 1 ? '' : 's').' left before the account locks.',
            'invalid_credentials',
            'password',
            extra: ['attempts_left' => $left],
        );
    }
}
