<?php

use App\Http\Controllers\SearchConsole\GoogleConnectionController;
use App\Http\Controllers\SearchConsole\SearchConsoleReportController;
use App\Http\Controllers\SearchConsole\SearchConsoleSiteController;
use Illuminate\Support\Facades\Route;

// Public: Google redirects the browser here after consent (no Bearer token);
// the encrypted `state` says which workspace and user started it.
Route::get('/google/callback', [GoogleConnectionController::class, 'callback']);

// Search Console (*_search_console), in the current workspace. Site routes
// also need access to the site's client (SitePolicy).
Route::middleware(['auth:sanctum', 'active', 'workspace'])->group(function () {
    Route::get('/google/connection', [GoogleConnectionController::class, 'show']);
    Route::post('/google/connect', [GoogleConnectionController::class, 'connect']);
    Route::delete('/google/connection', [GoogleConnectionController::class, 'destroy']);
    Route::get('/google/properties', [GoogleConnectionController::class, 'properties']);

    Route::get('/search-console/sites', [SearchConsoleSiteController::class, 'index']);

    Route::prefix('/sites/{site}/search-console')->whereNumber('site')->group(function () {
        Route::put('/', [SearchConsoleSiteController::class, 'update']);
        Route::delete('/', [SearchConsoleSiteController::class, 'destroy']);
        Route::post('/sync', [SearchConsoleSiteController::class, 'sync']);

        Route::get('/overview', [SearchConsoleReportController::class, 'overview']);
        Route::get('/queries', [SearchConsoleReportController::class, 'queries']);
        Route::get('/pages', [SearchConsoleReportController::class, 'pages']);
        Route::get('/quick-wins', [SearchConsoleReportController::class, 'quickWins']);
        Route::get('/low-ctr', [SearchConsoleReportController::class, 'lowCtr']);
        Route::get('/content-decay', [SearchConsoleReportController::class, 'contentDecay']);
    });
});
