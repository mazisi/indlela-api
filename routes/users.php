<?php

use App\Http\Controllers\Users\LoginHistoryController;
use App\Http\Controllers\Users\UserAccessController;
use App\Http\Controllers\Users\UserController;
use Illuminate\Support\Facades\Route;

// Settings › Users and access (Owners by default): members of the current workspace.
Route::middleware(['auth:sanctum', 'active', 'workspace'])->group(function () {
    Route::get('/users', [UserController::class, 'index']);
    Route::get('/users/history', [UserController::class, 'history']);
    Route::get('/users/options', [UserController::class, 'options']);
    Route::post('/users', [UserController::class, 'store']);
    Route::post('/users/bulk', [UserController::class, 'bulk']);
    Route::put('/users/{user}', [UserController::class, 'update'])->whereNumber('user');
    Route::delete('/users/{user}', [UserController::class, 'destroy'])->whereNumber('user');

    Route::post('/users/{user}/unlock', [UserAccessController::class, 'unlock'])->whereNumber('user');
    Route::post('/users/{user}/password-reset', [UserAccessController::class, 'sendPasswordReset'])->whereNumber('user');

    Route::get('/login-history', [LoginHistoryController::class, 'index']);
});
