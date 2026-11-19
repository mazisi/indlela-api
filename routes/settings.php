<?php

use App\Http\Controllers\Settings\EmailTemplateController;
use App\Http\Controllers\Settings\RoleController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth:sanctum', 'active', 'workspace'])->group(function () {
    // Roles and permissions (*_settings_roles), the current workspace's.
    Route::prefix('settings')->group(function () {
        Route::get('/roles', [RoleController::class, 'index']);
        Route::get('/roles/history', [RoleController::class, 'history']);
        Route::post('/roles', [RoleController::class, 'store']);
        Route::put('/roles/{role}', [RoleController::class, 'update'])->whereNumber('role');
        Route::put('/roles/{role}/permissions', [RoleController::class, 'updatePermissions'])->whereNumber('role');
        Route::delete('/roles/{role}', [RoleController::class, 'destroy'])->whereNumber('role');
    });

    // Email templates (*_settings_email_templates): the code-owned email types' wording, per workspace.
    Route::prefix('email-templates')->group(function () {
        Route::get('/', [EmailTemplateController::class, 'index']);
        Route::get('/{type}', [EmailTemplateController::class, 'show']);
        Route::put('/{type}', [EmailTemplateController::class, 'update']);
        Route::post('/{type}/preview', [EmailTemplateController::class, 'preview']);
        Route::post('/{type}/test', [EmailTemplateController::class, 'test']);
        Route::post('/{type}/reset', [EmailTemplateController::class, 'reset']);
    });
});
