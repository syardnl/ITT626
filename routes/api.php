<?php

use App\Http\Controllers\Api\Admin\AuditLogController;
use App\Http\Controllers\Api\Admin\CategoryController as AdminCategoryController;
use App\Http\Controllers\Api\Admin\LocationController as AdminLocationController;
use App\Http\Controllers\Api\Admin\SkillController;
use App\Http\Controllers\Api\Admin\SlaRuleController;
use App\Http\Controllers\Api\Admin\UserController as AdminUserController;
use App\Http\Controllers\Api\Auth\PasswordController;
use App\Http\Controllers\Api\Auth\SessionController;
use App\Http\Controllers\Api\CategoryController;
use App\Http\Controllers\Api\ComplaintCommentController;
use App\Http\Controllers\Api\ComplaintController;
use App\Http\Controllers\Api\ComplaintFollowController;
use App\Http\Controllers\Api\ComplaintWorkflowController;
use App\Http\Controllers\Api\EvidenceController;
use App\Http\Controllers\Api\LocationTreeController;
use App\Http\Controllers\Api\MeController;
use App\Http\Controllers\Api\NotificationController;
use App\Http\Controllers\Api\OpenComplaintsController;
use App\Http\Controllers\Api\TechnicianController;
use App\Http\Controllers\Api\TechnicianTaskController;
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
        Route::get('/complaints', [ComplaintController::class, 'index']);
        Route::get('/complaints/{complaint}', [ComplaintController::class, 'show'])->whereNumber('complaint');
        Route::get('/attachments/{evidence}', [EvidenceController::class, 'show'])->whereNumber('evidence');
        Route::get('/notifications', [NotificationController::class, 'index']);
        Route::post('/notifications/read-all', [NotificationController::class, 'readAll']);
        Route::post('/notifications/{notification}/read', [NotificationController::class, 'read'])->whereUuid('notification');
    });

    Route::middleware('role:resident')->group(function () {
        Route::post('/complaints', [ComplaintController::class, 'store']);
        Route::post('/complaints/{complaint}/follow', [ComplaintFollowController::class, 'store'])->whereNumber('complaint');
        Route::delete('/complaints/{complaint}/follow', [ComplaintFollowController::class, 'destroy'])->whereNumber('complaint');
        Route::post('/complaints/{complaint}/confirm-resolution', [ComplaintWorkflowController::class, 'answerResolution'])->whereNumber('complaint');
    });
    Route::middleware('role:resident,manager,technician')
        ->post('/complaints/{complaint}/comments', [ComplaintCommentController::class, 'store'])->whereNumber('complaint');
    Route::middleware('role:resident,manager')
        ->get('/locations/{location}/open-complaints', OpenComplaintsController::class)->whereNumber('location');

    Route::middleware('role:manager')->group(function () {
        Route::post('/complaints/{complaint}/review', [ComplaintWorkflowController::class, 'review'])->whereNumber('complaint');
        Route::patch('/complaints/{complaint}/classification', [ComplaintWorkflowController::class, 'classify'])->whereNumber('complaint');
        Route::get('/complaints/{complaint}/technician-recommendations', [ComplaintWorkflowController::class, 'recommendations'])->whereNumber('complaint');
        Route::post('/complaints/{complaint}/assignments', [ComplaintWorkflowController::class, 'assign'])->whereNumber('complaint');
        Route::post('/complaints/{complaint}/verify', [ComplaintWorkflowController::class, 'verify'])->whereNumber('complaint');
    });

    Route::middleware('role:technician')->group(function () {
        Route::get('/technician/profile', [TechnicianController::class, 'mine']);
        Route::get('/technician/tasks', TechnicianTaskController::class);
        Route::post('/complaints/{complaint}/start-work', [ComplaintWorkflowController::class, 'startWork'])->whereNumber('complaint');
        Route::post('/complaints/{complaint}/complete-work', [ComplaintWorkflowController::class, 'completeWork'])->whereNumber('complaint');
    });
    Route::middleware('role:manager,technician,administrator')
        ->get('/technicians/{technician}', [TechnicianController::class, 'show'])->whereNumber('technician');

    Route::middleware('role:manager,administrator')->group(function () {
        Route::get('/technicians', [TechnicianController::class, 'index']);
        Route::get('/technicians/form-options', [TechnicianController::class, 'formOptions']);
        Route::post('/technicians', [TechnicianController::class, 'store']);
        Route::patch('/technicians/{technician}', [TechnicianController::class, 'update'])->whereNumber('technician');
    });

    Route::middleware('role:administrator')->prefix('admin')->group(function () {
        Route::get('/audit-logs', [AuditLogController::class, 'index']);
        Route::get('/users', [AdminUserController::class, 'index']);
        Route::post('/users', [AdminUserController::class, 'store']);
        Route::patch('/users/{user}', [AdminUserController::class, 'update']);
        Route::post('/users/{user}/resend-invite', [AdminUserController::class, 'resendInvite']);
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
