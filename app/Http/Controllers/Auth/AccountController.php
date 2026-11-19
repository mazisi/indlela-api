<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\ChangePasswordRequest;
use App\Http\Requests\Auth\UpdateAccountRequest;
use App\Http\Resources\UserLoginResource;
use App\Services\Auth\AccountService;
use App\Support\AccountState;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * "My account": the signed-in user's own profile, 2FA channel and password.
 */
class AccountController extends Controller
{
    public function __construct(private readonly AccountService $accounts) {}

    /**
     * Current user + roles + permissions in the current workspace, and the
     * workspaces to switch to: the login response without the token
     * (GET /me, /user/permissions, /account).
     */
    public function show(Request $request): JsonResponse
    {
        return response()->json(AccountState::for($request->user()) + [
            // Lets "My account" explain that the PIN channel is not in use.
            'pin_enabled' => (bool) config('indlela.auth.pin_enabled'),
        ]);
    }

    public function update(UpdateAccountRequest $request): JsonResponse
    {
        $user = $this->accounts->updateProfile($request->user(), $request->validated());

        return response()->json([
            'message' => 'Account saved.',
            'user' => new UserLoginResource($user->load('roles')),
        ]);
    }

    public function changePassword(ChangePasswordRequest $request): JsonResponse
    {
        $user = $request->user();
        $this->accounts->changePassword(
            $user,
            $request->validated('password'),
            $user->currentAccessToken()?->id,
        );

        return response()->json(['message' => 'Password changed. Other devices have been signed out.']);
    }
}
