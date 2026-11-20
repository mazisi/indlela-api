<?php

use App\Http\Controllers\LocalGrid\LocalGridController;
use App\Http\Controllers\LocalGrid\MapGridController;
use App\Http\Controllers\LocalGrid\MapGridScanController;
use Illuminate\Support\Facades\Route;

// Local map pack grid (*_local_grid), in the current workspace. Site, grid
// and scan routes also need access to the site's client (SitePolicy /
// MapGridPolicy).
Route::middleware(['auth:sanctum', 'active', 'workspace'])->group(function () {
    Route::get('/local-grid/sites', [LocalGridController::class, 'sites']);
    Route::get('/local-grid/usage', [LocalGridController::class, 'usage']);

    Route::prefix('/sites/{site}')->whereNumber('site')->group(function () {
        Route::get('/local-grids', [MapGridController::class, 'index']);
        Route::post('/local-grids', [MapGridController::class, 'store']);
        Route::post('/local-grids/business-search', [MapGridController::class, 'search'])->middleware('throttle:20,1');
    });

    Route::prefix('/local-grids/{grid}')->whereNumber('grid')->group(function () {
        Route::get('/', [MapGridController::class, 'show']);
        Route::put('/', [MapGridController::class, 'update']);
        Route::delete('/', [MapGridController::class, 'destroy']);
        Route::post('/scan', [MapGridController::class, 'scan']);
    });

    Route::get('/local-grid-scans/{scan}', [MapGridScanController::class, 'show'])->whereNumber('scan');
});
