<?php

use App\Http\Controllers\WorkspaceController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth:sanctum', 'active', 'workspace'])->group(function () {
    // The switcher: any member.
    Route::get('/workspaces', [WorkspaceController::class, 'index']);
    Route::post('/workspaces', [WorkspaceController::class, 'store']);
    Route::post('/workspaces/{workspace}/switch', [WorkspaceController::class, 'switch'])->whereNumber('workspace');

    // Settings › Workspace (*_settings_workspace): the current workspace.
    Route::get('/workspace', [WorkspaceController::class, 'show']);
    Route::patch('/workspace', [WorkspaceController::class, 'update']);
    Route::get('/workspace/history', [WorkspaceController::class, 'history']);
});
