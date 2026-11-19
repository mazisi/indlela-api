<?php

use App\Http\Controllers\Auth\AccountController;
use App\Http\Controllers\Auth\LoginController;
use App\Http\Controllers\Auth\PasswordResetController;
use App\Http\Controllers\Auth\RegisterController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

/*
| Public auth endpoints. Throttles blunt credential stuffing, PIN guessing and
| email enumeration; the per-account lockout and per-challenge PIN limits sit
| behind them in the services.
*/
Route::middleware('throttle:auth')->group(function () {
    Route::post('/login', [LoginController::class, 'login']);
    Route::post('/login/verify-pin', [LoginController::class, 'verifyPin']);
    Route::post('/login/resend-pin', [LoginController::class, 'resendPin']);

    Route::post('/forgot-password', [PasswordResetController::class, 'forgotPassword']);
    Route::post('/reset-password', [PasswordResetController::class, 'resetPassword']);

    // Workspace sign-up: account + workspace (Owner) + session.
    Route::post('/register', [RegisterController::class, 'register']);
});

// Lightweight auth probe for the FE — returns 200 in both cases so the
// header can choose Sign In vs Dashboard without handling a 401.
Route::get('/auth/check', function (Request $request) {
    $user = $request->user('sanctum');

    return response()->json([
        'authenticated' => (bool) $user,
        'user' => $user ? [
            'id' => $user->id,
            'full_name' => $user->full_name,
            'surname' => $user->surname,
            'email' => $user->email,
        ] : null,
    ]);
});

Route::middleware(['auth:sanctum', 'active', 'workspace'])->group(function () {
    Route::post('/logout', [LoginController::class, 'logout']);

    // The login response without the token. `/user/permissions` is the path
    // the ported PermissionProvider calls; `/me` the new one.
    Route::get('/me', [AccountController::class, 'show']);
    Route::get('/user/permissions', [AccountController::class, 'show']);
    Route::get('/account', [AccountController::class, 'show']);
    Route::put('/account', [AccountController::class, 'update']);
    Route::put('/account/password', [AccountController::class, 'changePassword']);
});
