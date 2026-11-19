<?php

namespace App\Http\Controllers\Users;

use App\Enums\UserStatus;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\Auth\AccountService;
use App\Services\Auth\PasswordResetService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/**
 * Owner actions from the Users list: Unlock and Reset password (members of
 * the current workspace only, through the `{user}` binding).
 */
class UserAccessController extends Controller
{
    public function unlock(Request $request, User $user, AccountService $accounts): JsonResponse
    {
        Gate::authorize('update_settings_users');

        $accounts->unlock($user, $request->user());

        return response()->json(['message' => "{$user->displayName()} is unlocked."]);
    }

    public function sendPasswordReset(Request $request, User $user, PasswordResetService $resets): JsonResponse
    {
        Gate::authorize('update_settings_users');

        if (! in_array($user->status, [UserStatus::Active, UserStatus::Locked], true)) {
            throw ValidationException::withMessages(['status' => ['Only active or locked accounts can reset a password.']]);
        }

        $resets->sendLinkTo($user, 'Sent by '.$request->user()->displayName());

        return response()->json(['message' => "A reset link was emailed to {$user->email}."]);
    }
}
