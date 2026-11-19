<?php

namespace App\Services\Auth;

use App\Enums\LoginEventType;
use App\Enums\UserStatus;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * The signed-in user's own account ("My account") and the
 * Owner's unlock action.
 */
class AccountService
{
    public function __construct(
        private readonly LoginChallengeService $challenges,
        private readonly LoginEventLogger $events,
    ) {}

    /**
     * @param  array{mobile?: string, mfa_channel?: string}  $data
     */
    public function updateProfile(User $user, array $data): User
    {
        $user->fill(array_intersect_key($data, array_flip(['mobile', 'mfa_channel'])))->save();

        return $user;
    }

    /**
     * Change the password and sign out every other session.
     */
    public function changePassword(User $user, string $password, ?int $keepTokenId): void
    {
        DB::transaction(function () use ($user, $password, $keepTokenId) {
            $user->forceFill(['password' => $password])->save();
            $user->tokens()->when($keepTokenId, fn ($q) => $q->whereKeyNot($keepTokenId))->delete();

            $this->events->record(LoginEventType::PasswordChanged, $user, 'By the user');
        });
    }

    public function unlock(User $user, User $admin): User
    {
        if ($user->status !== UserStatus::Locked) {
            throw ValidationException::withMessages(['status' => ['This account is not locked.']]);
        }

        $user->forceFill([
            'status' => UserStatus::Active,
            'failed_login_attempts' => 0,
            'locked_at' => null,
        ])->save();
        $this->challenges->closeAllFor($user);

        $this->events->record(LoginEventType::Unlocked, $user, 'By '.$admin->displayName());

        return $user;
    }
}
