<?php

use App\Http\Controllers\Platform\PlatformUsageController;
use Illuminate\Support\Facades\Route;

// Platform › Usage (Indlela staff): only in the platform workspace
// (INDLELA_PLATFORM_WORKSPACE) with view_platform_usage; 403 elsewhere.
// The only routes that read across workspaces.
Route::middleware(['auth:sanctum', 'active', 'workspace'])->prefix('/platform')->group(function () {
    Route::get('/usage', [PlatformUsageController::class, 'overview']);
    Route::get('/usage/workspaces', [PlatformUsageController::class, 'workspaces']);
    Route::patch('/workspaces/{workspace}/limits', [PlatformUsageController::class, 'updateLimits'])->whereNumber('workspace');
});
