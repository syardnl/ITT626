<?php

use App\Http\Controllers\Api\Admin\AuditLogController;
use App\Http\Controllers\Api\Admin\CategoryController as AdminCategoryController;
use App\Http\Controllers\Api\Admin\LocationController as AdminLocationController;
use App\Http\Controllers\Api\Admin\SkillController;
use App\Http\Controllers\Api\Admin\SlaRuleController;
use App\Http\Controllers\Api\Auth\PasswordController;
use App\Http\Controllers\Api\Auth\SessionController;
use App\Http\Controllers\Api\CategoryController;
use App\Http\Controllers\Api\LocationTreeController;
use App\Http\Controllers\Api\MeController;
use Illuminate\Support\Facades\Route;

/*
| /api/v1 — deny by default: every route behind `auth:sanctum` must also
| declare, through the `role` middleware, which roles may call it
| (enforced by tests/Feature/Authorization/RoleGuardTest.php).
*/

Route::post('/auth/login', [SessionController::class, 'store']);
Route::post('/auth/forgot-password', [PasswordController::class, 'forgot']);
Route::post('/auth/reset-password', [PasswordController::class, 'reset']);

Route::middleware(['auth:sanctum', 'active'])->group(function () {
    Route::middleware('role:resident,manager,technician,administrator')->group(function () {
        Route::post('/auth/logout', [SessionController::class, 'destroy']);
        Route::get('/me', MeController::class);
        Route::get('/locations', LocationTreeController::class);
        Route::get('/categories', CategoryController::class);
    });

    Route::middleware('role:administrator')->prefix('admin')->group(function () {
        Route::get('/audit-logs', [AuditLogController::class, 'index']);
        Route::get('/locations', [AdminLocationController::class, 'index']);
        Route::post('/locations', [AdminLocationController::class, 'store']);
        Route::patch('/locations/{location}', [AdminLocationController::class, 'update']);
        Route::get('/categories', [AdminCategoryController::class, 'index']);
        Route::patch('/categories/{category}', [AdminCategoryController::class, 'update']);
        Route::get('/skills', [SkillController::class, 'index']);
        Route::get('/sla-rules', [SlaRuleController::class, 'index']);
        Route::patch('/sla-rules/{slaRule}', [SlaRuleController::class, 'update']);
    });
});
