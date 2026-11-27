<?php

use App\Enums\Role;
use App\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;

beforeEach(function () {
    // Stand-in endpoints behind exactly the middleware stack real /api/v1 routes use.
    Route::middleware(['api', 'auth:sanctum', 'active', 'role:administrator'])
        ->get('/api/v1/__probe/administrator-only', fn () => ['ok' => true]);
    Route::middleware(['api', 'auth:sanctum', 'active', 'role:manager,administrator'])
        ->get('/api/v1/__probe/staff-office', fn () => ['ok' => true]);
});

it('lets through only the roles an endpoint names', function (string $uri, array $allowed) {
    foreach (Role::cases() as $role) {
        $response = $this->actingAs(account($role))->getJson($uri);

        in_array($role, $allowed, true)
            ? $response->assertOk()
            : $response->assertForbidden()->assertExactJson(['message' => 'This action is unauthorized.']);
    }
})->with([
    'administrator only' => ['/api/v1/__probe/administrator-only', [Role::Administrator]],
    'manager or administrator' => ['/api/v1/__probe/staff-office', [Role::Manager, Role::Administrator]],
]);

it('answers 401, not 403, to anyone not signed in', function () {
    $this->getJson('/api/v1/__probe/administrator-only')->assertUnauthorized();
});

it('denies an account that somehow holds no role', function () {
    $user = User::factory()->create();

    $this->actingAs($user)->getJson('/api/v1/__probe/staff-office')->assertForbidden();
    $this->actingAs($user)->getJson('/api/v1/me')->assertForbidden();
});

it('makes every signed-in API route declare which roles may call it', function () {
    $undeclared = collect(Route::getRoutes()->getRoutes())
        ->filter(fn ($route) => str_starts_with($route->uri(), 'api/v1/'))
        ->filter(fn ($route) => in_array('auth:sanctum', $route->gatherMiddleware(), true))
        ->reject(fn ($route) => collect($route->gatherMiddleware())->contains(fn ($m) => is_string($m) && str_starts_with($m, 'role:')))
        ->map(fn ($route) => implode('|', $route->methods()).' '.$route->uri())
        ->values()
        ->all();

    expect($undeclared)->toBe([]);
});

it('holds exactly one role per account: a new role replaces the old one', function () {
    $user = account(Role::Manager);
    $user->assignRole(Role::Administrator);

    $this->actingAs($user)->getJson('/api/v1/me')->assertJsonPath('data.role', 'administrator');
    $this->getJson('/api/v1/__probe/administrator-only')->assertOk();
    expect(DB::table('role_user')->where('user_id', $user->id)->count())->toBe(1);
});

it('refuses, at the database, to store a second role for an account', function () {
    $user = account(Role::Manager);
    $administrator = DB::table('roles')->where('name', 'administrator')->value('id');

    expect(fn () => DB::table('role_user')->insert(['user_id' => $user->id, 'role_id' => $administrator]))
        ->toThrow(UniqueConstraintViolationException::class);
});

it('admits each role to the Complaint endpoints it may call', function (string $method, string $uri, array $allowed) {
    foreach (Role::cases() as $role) {
        $status = $this->actingAs(account($role))->json($method, $uri)->status();

        // Allowed roles get through the role check (to validation, a list, or a concealed 404); others get 403.
        in_array($role, $allowed, true)
            ? expect($status)->not->toBe(403, "{$role->value} should reach {$method} {$uri}")
            : expect($status)->toBe(403, "{$role->value} should be refused {$method} {$uri}");
    }
})->with([
    'submit' => ['POST', '/api/v1/complaints', [Role::Resident]],
    'list' => ['GET', '/api/v1/complaints', Role::cases()],
    'detail' => ['GET', '/api/v1/complaints/999999', Role::cases()],
    'open Complaints at a Location' => ['GET', '/api/v1/locations/999999/open-complaints', [Role::Resident, Role::Manager]],
    'follow' => ['POST', '/api/v1/complaints/999999/follow', [Role::Resident]],
    'unfollow' => ['DELETE', '/api/v1/complaints/999999/follow', [Role::Resident]],
    'photo' => ['GET', '/api/v1/attachments/999999', Role::cases()],
    'comment' => ['POST', '/api/v1/complaints/999999/comments', [Role::Resident, Role::Manager, Role::Technician]],
    'begin review' => ['POST', '/api/v1/complaints/999999/review', [Role::Manager]],
    'classify' => ['PATCH', '/api/v1/complaints/999999/classification', [Role::Manager]],
    'technician recommendations' => ['GET', '/api/v1/complaints/999999/technician-recommendations', [Role::Manager]],
    'assign or reassign' => ['POST', '/api/v1/complaints/999999/assignments', [Role::Manager]],
    'technician tasks' => ['GET', '/api/v1/technician/tasks', [Role::Technician]],
    'start work' => ['POST', '/api/v1/complaints/999999/start-work', [Role::Technician]],
]);
