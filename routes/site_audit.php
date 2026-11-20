<?php

use App\Http\Controllers\SiteAudit\SiteAuditController;
use App\Http\Controllers\SiteAudit\SiteAuditReportController;
use App\Http\Controllers\SiteAudit\SiteAuditSiteController;
use Illuminate\Support\Facades\Route;

// Site audit (*_site_audit), in the current workspace. Site and audit routes
// also need access to the site's client (SitePolicy / SiteAuditPolicy).
Route::middleware(['auth:sanctum', 'active', 'workspace'])->group(function () {
    Route::get('/site-audit/sites', [SiteAuditSiteController::class, 'index']);

    Route::prefix('/sites/{site}')->whereNumber('site')->group(function () {
        Route::put('/audit-settings', [SiteAuditSiteController::class, 'updateSettings']);
        Route::get('/audits', [SiteAuditController::class, 'index']);
        Route::post('/audits', [SiteAuditController::class, 'store']);
    });

    Route::prefix('/audits/{audit}')->whereNumber('audit')->group(function () {
        Route::get('/', [SiteAuditController::class, 'show']);
        Route::post('/cancel', [SiteAuditController::class, 'cancel']);
        Route::get('/issues', [SiteAuditReportController::class, 'issues']);
        Route::get('/issues/{type}', [SiteAuditReportController::class, 'issueUrls'])->where('type', '[a-z0-9_]+');
        Route::get('/pages', [SiteAuditReportController::class, 'pages']);
        Route::get('/pages/{page}', [SiteAuditReportController::class, 'page'])->whereNumber('page')->scopeBindings();
        Route::get('/vitals', [SiteAuditReportController::class, 'vitals']);
        Route::get('/comparison', [SiteAuditReportController::class, 'comparison']);
    });
});
