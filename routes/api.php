<?php

use App\Http\Controllers\Api\Auth\SessionController;
use App\Http\Controllers\Api\MeController;
use Illuminate\Support\Facades\Route;

/*
| /api/v1 — deny by default: every route behind `auth:sanctum` must also
| declare, through the `role` middleware, which roles may call it
| (enforced by tests/Feature/Authorization/RoleGuardTest.php).
*/

Route::post('/auth/login', [SessionController::class, 'store']);

Route::middleware(['auth:sanctum', 'active'])->group(function () {
    Route::middleware('role:resident,manager,technician,administrator')->group(function () {
        Route::post('/auth/logout', [SessionController::class, 'destroy']);
        Route::get('/me', MeController::class);
    });
});
