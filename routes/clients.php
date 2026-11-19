<?php

use App\Http\Controllers\Clients\ClientController;
use App\Http\Controllers\Clients\SiteController;
use Illuminate\Support\Facades\Route;

// Clients (*_clients_clients) and their sites (*_clients_sites), in the current workspace.
Route::middleware(['auth:sanctum', 'active', 'workspace'])->group(function () {
    Route::get('/clients', [ClientController::class, 'index']);
    Route::get('/clients/history', [ClientController::class, 'history']);
    Route::post('/clients', [ClientController::class, 'store']);
    Route::post('/clients/bulk', [ClientController::class, 'bulk']);
    Route::get('/clients/{client}', [ClientController::class, 'show'])->whereNumber('client');
    Route::put('/clients/{client}', [ClientController::class, 'update'])->whereNumber('client');
    Route::delete('/clients/{client}', [ClientController::class, 'destroy'])->whereNumber('client');

    Route::get('/sites', [SiteController::class, 'index']);
    Route::get('/sites/history', [SiteController::class, 'history']);
    Route::post('/sites', [SiteController::class, 'store']);
    Route::post('/sites/bulk', [SiteController::class, 'bulk']);
    Route::get('/sites/{site}', [SiteController::class, 'show'])->whereNumber('site');
    Route::put('/sites/{site}', [SiteController::class, 'update'])->whereNumber('site');
    Route::delete('/sites/{site}', [SiteController::class, 'destroy'])->whereNumber('site');
});
