<?php

use App\Http\Controllers\RankTracking\RankKeywordController;
use App\Http\Controllers\RankTracking\RankTrackingController;
use App\Http\Controllers\RankTracking\RankTrackingReportController;
use Illuminate\Support\Facades\Route;

// Rank tracking (view_rank_tracking / update_rank_tracking), in the current
// workspace. Site and keyword routes also need access to the site's client
// (SitePolicy / RankKeywordPolicy).
Route::middleware(['auth:sanctum', 'active', 'workspace'])->group(function () {
    Route::get('/rank-tracking/sites', [RankTrackingController::class, 'sites']);
    Route::get('/rank-tracking/locations', [RankTrackingController::class, 'locations']);
    Route::get('/rank-tracking/usage', [RankTrackingController::class, 'usage']);

    Route::prefix('/sites/{site}')->whereNumber('site')->group(function () {
        Route::get('/rank-keywords', [RankKeywordController::class, 'index']);
        Route::post('/rank-keywords', [RankKeywordController::class, 'store']);
        Route::post('/rank-keywords/bulk', [RankKeywordController::class, 'bulk']);
        Route::get('/rank-tracking/summary', [RankTrackingReportController::class, 'summary']);
        Route::get('/rank-tracking/cannibalisation', [RankTrackingReportController::class, 'cannibalisation']);
    });

    Route::prefix('/rank-keywords/{rankKeyword}')->whereNumber('rankKeyword')->group(function () {
        Route::put('/', [RankKeywordController::class, 'update']);
        Route::delete('/', [RankKeywordController::class, 'destroy']);
        Route::post('/check', [RankKeywordController::class, 'check']);
        Route::get('/history', [RankKeywordController::class, 'history']);
    });
});
