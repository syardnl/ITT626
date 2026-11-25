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

/** Flatten the /locations tree into code => node. */
function treeByCode(array $nodes, array $into = []): array
{
    foreach ($nodes as $node) {
        $into[$node['code']] = $node;
        $into = treeByCode($node['children'], $into);
    }

    return $into;
}

it('seeds a sample Location tree with the agreed importance defaults', function () {
    $this->seed(DemoSeeder::class);
    $this->postJson('/api/v1/auth/login', ['email' => 'resident@demo.test', 'password' => 'demo-password']);

    $tree = $this->getJson('/api/v1/locations')->assertOk()->json('data');
    $byCode = treeByCode($tree);
    $count = fn (string $type) => collect($byCode)->where('type', $type)->count();

    expect($count('block'))->toBeGreaterThanOrEqual(2)
        ->and($count('floor'))->toBeGreaterThanOrEqual(4)
        ->and($count('unit'))->toBeGreaterThanOrEqual(8)
        ->and($count('common_area'))->toBeGreaterThanOrEqual(8);

    $importanceOf = fn (string $pattern) => collect($byCode)
        ->filter(fn ($node) => $node['type'] === 'common_area' && preg_match($pattern, $node['name']))
        ->pluck('importance')->unique()->values()->all();

    expect($importanceOf('/lift/i'))->toBe(['essential'])
        ->and($importanceOf('/water tank|pump/i'))->toBe(['essential'])
        ->and($importanceOf('/main switch room/i'))->toBe(['essential'])
        ->and($importanceOf('/guardhouse/i'))->toBe(['essential'])
        ->and($importanceOf('/lobby/i'))->toBe(['important'])
        ->and($importanceOf('/car park/i'))->toBe(['important'])
        ->and($importanceOf('/stairwell/i'))->toBe(['important'])
        ->and(collect($byCode)->where('type', 'unit')->pluck('importance')->unique()->all())->toBe(['normal'])
        ->and(collect($byCode)->where('is_shared_area', true)->count())->toBeGreaterThanOrEqual(3);
});
