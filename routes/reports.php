<?php

use App\Http\Controllers\Reports\PublicReportController;
use App\Http\Controllers\Reports\ReportController;
use App\Http\Controllers\Reports\ReportSiteController;
use App\Http\Middleware\NoIndex;
use Illuminate\Support\Facades\Route;

// White-label reports (view_reports / create_reports / delete_reports), in the
// current workspace. Site and report routes also need access to the site's
// client (SitePolicy / ReportPolicy).
Route::middleware(['auth:sanctum', 'active', 'workspace'])->group(function () {
    Route::get('/reports/sites', [ReportSiteController::class, 'index']);

    Route::prefix('/sites/{site}')->whereNumber('site')->group(function () {
        Route::get('/reports', [ReportController::class, 'index']);
        Route::post('/reports', [ReportController::class, 'store']);
        Route::post('/reports/bulk', [ReportController::class, 'bulk']);
        Route::get('/report-schedule', [ReportSiteController::class, 'schedule']);
        Route::put('/report-schedule', [ReportSiteController::class, 'updateSchedule']);
        // Emails clients: at most 5 a minute per user.
        Route::post('/report-schedule/send-now', [ReportSiteController::class, 'sendNow'])->middleware('throttle:5,1');
    });

    Route::prefix('/reports/{report}')->whereNumber('report')->group(function () {
        Route::get('/', [ReportController::class, 'show']);
        Route::delete('/', [ReportController::class, 'destroy']);
        Route::get('/pdf', [ReportController::class, 'pdf']);
        Route::post('/send', [ReportController::class, 'send']);
        Route::post('/share', [ReportController::class, 'share']);
        Route::delete('/share', [ReportController::class, 'unshare']);
    });
});

// Public (no sign-in): the share page's data, its PDF and logo; the app's
// signed logo URL. Never indexed; 60 requests a minute per IP.
Route::middleware(['throttle:60,1', NoIndex::class])->group(function () {
    Route::get('/public/reports/{token}', [PublicReportController::class, 'show'])->where('token', '[A-Za-z0-9]+');
    Route::get('/public/reports/{token}/pdf', [PublicReportController::class, 'pdf'])->where('token', '[A-Za-z0-9]+');
    Route::get('/public/reports/{token}/logo', [PublicReportController::class, 'logo'])->where('token', '[A-Za-z0-9]+')->name('public.reports.logo');
    Route::get('/public/report-logos/{report}', [PublicReportController::class, 'signedLogo'])->whereNumber('report')->middleware('signed')->name('reports.logo');
});
