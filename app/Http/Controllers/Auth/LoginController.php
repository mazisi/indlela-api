<?php

namespace App\Http\Controllers\Auth;

use App\Enums\LoginEventType;
use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\LoginRequest;
use App\Http\Requests\Auth\ResendPinRequest;
use App\Http\Requests\Auth\VerifyPinRequest;
use App\Services\Auth\LoginChallengeService;
use App\Services\Auth\LoginEventLogger;
use App\Services\Auth\LoginService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Sign-in: password → (one-time PIN) → Sanctum token. Business rules live in
 * LoginService / LoginChallengeService; refusals render via AuthFlowException.
 */
class LoginController extends Controller
{
    public function __construct(private readonly LoginService $login) {}

    public function login(LoginRequest $request): JsonResponse
    {
        return response()->json($this->login->attempt(
            $request->validated('email'),
            $request->validated('password'),
        ));
    }

    public function verifyPin(VerifyPinRequest $request): JsonResponse
    {
        return response()->json($this->login->verifyPin(
            $request->validated('challenge_token'),
            $request->validated('code'),
        ));
    }

    public function resendPin(ResendPinRequest $request, LoginChallengeService $challenges): JsonResponse
    {
        $challenge = $challenges->resend($request->validated('challenge_token'));

        return response()->json([
            'message' => 'A new code is on its way.',
            'destination' => $challenges->maskedDestination($challenge->user),
            'expires_at' => $challenge->expires_at->toIso8601String(),
            'resend_available_in' => (int) config('indlela.auth.pin_resend_cooldown_seconds'),
        ]);
    }

    public function logout(Request $request, LoginEventLogger $events): JsonResponse
    {
        $user = $request->user();
        $user->currentAccessToken()?->delete();
        $events->record(LoginEventType::SignedOut, $user);

        return response()->json(['message' => 'You have been signed out.']);
    }
}
