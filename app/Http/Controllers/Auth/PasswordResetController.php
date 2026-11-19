<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\ForgotPasswordRequest;
use App\Http\Requests\Auth\ResetPasswordRequest;
use App\Services\Auth\PasswordResetService;
use Illuminate\Http\JsonResponse;

class PasswordResetController extends Controller
{
    public function __construct(private readonly PasswordResetService $resets) {}

    /**
     * Same answer whether or not the account exists (no enumeration).
     */
    public function forgotPassword(ForgotPasswordRequest $request): JsonResponse
    {
        $this->resets->sendLink($request->validated('email'));

        return response()->json([
            'message' => 'If an account exists for that email, a reset link is on its way.',
        ]);
    }

    public function resetPassword(ResetPasswordRequest $request): JsonResponse
    {
        $this->resets->reset(
            $request->validated('email'),
            $request->validated('token'),
            $request->validated('password'),
        );

        return response()->json([
            'message' => 'Your password was changed. Sign in with the new password.',
        ]);
    }
}
