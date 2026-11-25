<?php

use App\Enums\Role;
use App\Models\Property;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Database\Seeders\DemoSeeder;
use Database\Seeders\ReferenceSeeder;
use Illuminate\Support\Facades\DB;

it('seeds the single Property and the four roles, and no accounts', function () {
    $this->seed(DatabaseSeeder::class);

    expect(Property::count())->toBe(1)
        ->and(DB::table('roles')->orderBy('name')->pluck('name')->all())
        ->toBe(['administrator', 'manager', 'resident', 'technician'])
        ->and(User::count())->toBe(0);
});

it('can be re-run without duplicating reference data', function () {
    $this->seed(ReferenceSeeder::class);
    $this->seed(ReferenceSeeder::class);

    expect(Property::count())->toBe(1)->and(DB::table('roles')->count())->toBe(4);
});

it('creates one active demo account per role that can sign in', function (Role $role, string $email) {
    $this->seed(DemoSeeder::class);

    $this->postJson('/api/v1/auth/login', ['email' => $email, 'password' => 'demo-password'])
        ->assertOk()
        ->assertJsonPath('data.role', $role->value);
})->with([
    'resident' => [Role::Resident, 'resident@demo.test'],
    'manager' => [Role::Manager, 'manager@demo.test'],
    'technician' => [Role::Technician, 'technician@demo.test'],
    'administrator' => [Role::Administrator, 'administrator@demo.test'],
]);

it('links the demo Resident to a Unit', function () {
    $this->seed(DemoSeeder::class);
    $this->postJson('/api/v1/auth/login', ['email' => 'resident@demo.test', 'password' => 'demo-password']);

    $this->getJson('/api/v1/me')->assertOk()->assertJsonPath('data.unit.code', 'A-01-01');
});

it('can be re-run without duplicating demo accounts', function () {
    $this->seed(DemoSeeder::class);
    $this->seed(DemoSeeder::class);

    expect(User::count())->toBe(4);
});

it('refuses to create demo accounts in production', function () {
    app()->detectEnvironment(fn () => 'production');

    expect(fn () => app(DemoSeeder::class)->run())->toThrow(RuntimeException::class);
    expect(User::count())->toBe(0);
});
