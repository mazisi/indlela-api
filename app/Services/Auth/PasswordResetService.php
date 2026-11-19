<?php

namespace App\Services\Auth;

use App\Enums\LoginEventType;
use App\Enums\UserStatus;
use App\Exceptions\AuthFlowException;
use App\Mail\EmailTemplateType;
use App\Mail\TemplatedMail;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Throwable;

/**
 * Forgot / reset password (ported from workforce-api). Rules: the link
 * works once and lasts an hour (AUTH_RESET_TTL_MINUTES), the new password may not be the current
 * one, and a successful reset unlocks a locked account.
 */
class PasswordResetService
{
    public function __construct(
        private readonly PasswordResetTokenService $tokens,
        private readonly LoginChallengeService $challenges,
        private readonly LoginEventLogger $events,
    ) {}

    /**
     * Email a reset link — only to accounts that could use it (active or
     * locked). The caller answers identically either way, so this can't be used
     * to discover which emails have accounts.
     */
    public function sendLink(string $email, string $note = 'Reset link emailed'): void
    {
        $user = User::whereEmail($email)
            ->whereIn('status', [UserStatus::Active, UserStatus::Locked])
            ->first();

        if (! $user) {
            return;
        }

        // A mail failure must not turn into a 500 only real accounts can
        // trigger — that would leak which emails exist. Report it instead.
        try {
            $this->sendLinkTo($user, $note);
        } catch (Throwable $e) {
            report($e);
        }
    }

    public function sendLinkTo(User $user, string $note): void
    {
        $ttl = (int) config('indlela.auth.reset_ttl_minutes');
        $url = $this->tokens->issueUrl($user->email, $ttl);

        Mail::to($user->email)->send(new TemplatedMail(EmailTemplateType::PasswordReset, [
            'name' => $user->full_name,
            'ttl' => "{$ttl} minutes",
        ], $url, $user->current_workspace_id));

        $this->events->record(LoginEventType::ResetRequested, $user, $note);
    }

    public function reset(string $email, string $token, string $password): User
    {
        $user = User::whereEmail($email)->first();

        // A wrong, used or replaced token and an unknown email all read the
        // same; only the real token can learn that it has expired.
        $state = $user ? $this->tokens->check($email, $token) : 'invalid';
        if ($state === 'expired') {
            $ttl = (int) config('indlela.auth.reset_ttl_minutes');
            throw new AuthFlowException("This reset link has expired: links last {$ttl} minutes. Ask for a new one.", 'invalid_reset_token', 'token', 400);
        }
        if ($state !== 'ok') {
            throw new AuthFlowException('This reset link no longer works. It may have been used already, or a newer link was sent: only the link in the latest email works. Otherwise ask for a new one.', 'invalid_reset_token', 'token', 400);
        }

        if (Hash::check($password, $user->password)) {
            throw new AuthFlowException('Choose a password you have not used before.', 'password_reused', 'password');
        }

        DB::transaction(function () use ($user, $password) {
            $wasLocked = $user->status === UserStatus::Locked;

            $user->forceFill([
                'password' => $password,
                'failed_login_attempts' => 0,
                'locked_at' => null,
                'status' => $wasLocked ? UserStatus::Active : $user->status,
            ])->save();

            $this->tokens->forget($user->email);
            // Anyone holding the old password is signed out everywhere.
            $user->tokens()->delete();
            $this->challenges->closeAllFor($user);

            if ($wasLocked) {
                $this->events->record(LoginEventType::Unlocked, $user, 'By password reset');
            }
            $this->events->record(LoginEventType::PasswordReset, $user, 'Reset by email link');
        });

        return $user;
    }
}
