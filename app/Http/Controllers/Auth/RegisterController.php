<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\RegisterRequest;
use App\Services\Auth\LoginService;
use App\Services\Auth\RegistrationService;
use Illuminate\Http\JsonResponse;

class RegisterController extends Controller
{
    /**
     * Workspace sign-up: the account, its workspace (as Owner) and a session,
     * in the login response's shape. No approval step.
     */
    public function register(RegisterRequest $request, RegistrationService $registrations, LoginService $login): JsonResponse
    {
        $user = $registrations->register($request->account());

        return response()->json($login->startSession($user, 'Signed up'), 201);
    }
}
