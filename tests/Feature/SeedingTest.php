<?php

use App\Enums\Role;
use App\Models\Property;
use App\Models\Technician;
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

    expect(Property::count())->toBe(1)->and(DB::table('roles')->count())->toBe(4)
        ->and(DB::table('complaint_categories')->count())->toBe(10)
        ->and(DB::table('skills')->count())->toBe(10)
        ->and(DB::table('sla_rules')->count())->toBe(4);
});

it('seeds the ten Categories with their default priorities, each with one Skill of the same name', function () {
    $this->seed(DatabaseSeeder::class);
    $this->actingAs(account(Role::Administrator));

    $categories = collect($this->getJson('/api/v1/admin/categories')->json('data'))->keyBy('name');

    expect($categories->map(fn ($c) => $c['default_priority'])->sortKeys()->all())->toBe([
        'Common Facilities' => 'low',
        'Electrical' => 'high',
        'Fire & Safety' => 'critical',
        'Landscaping & Cleaning' => 'low',
        'Lift' => 'high',
        'Lighting' => 'medium',
        'Plumbing & Water Supply' => 'high',
        'Sanitation & Waste' => 'medium',
        'Security & Access' => 'high',
        'Structural & Building' => 'medium',
    ])->and($categories->every(fn ($c) => $c['active'] && $c['skill']['name'] === $c['name'] && $c['skill']['active']))->toBeTrue()
        ->and($this->getJson('/api/v1/admin/skills')->json('data'))->toHaveCount(10);
});

it('seeds the four SLA Targets with the agreed minutes', function () {
    $this->seed(DatabaseSeeder::class);
    $this->actingAs(account(Role::Administrator));

    $rules = collect($this->getJson('/api/v1/admin/sla-rules')->json('data'))
        ->map(fn ($r) => [$r['priority'], $r['response_minutes'], $r['resolution_minutes'], $r['version']])->all();

    expect($rules)->toBe([
        ['critical', 60, 480, 1],
        ['high', 240, 1440, 1],
        ['medium', 1440, 4320, 1],
        ['low', 4320, 10080, 1],
    ]);
});

it('keeps the Administrator\'s edits when the reference seed is re-run', function () {
    $this->actingAs(account(Role::Administrator));
    $lift = collect($this->getJson('/api/v1/admin/categories')->json('data'))->firstWhere('name', 'Lift');
    $high = collect($this->getJson('/api/v1/admin/sla-rules')->json('data'))->firstWhere('priority', 'high');
    $this->patchJson("/api/v1/admin/categories/{$lift['id']}", ['name' => 'Lifts', 'active' => false])->assertOk();
    $this->patchJson("/api/v1/admin/sla-rules/{$high['id']}", ['response_minutes' => 120])->assertOk();

    $this->seed(ReferenceSeeder::class);

    $categories = collect($this->getJson('/api/v1/admin/categories')->json('data'));
    expect($categories)->toHaveCount(10)
        ->and($categories->pluck('name'))->toContain('Lifts')->not->toContain('Lift')
        ->and($categories->firstWhere('name', 'Lifts')['active'])->toBeFalse()
        ->and($this->getJson('/api/v1/admin/skills')->json('data'))->toHaveCount(10)
        ->and(collect($this->getJson('/api/v1/admin/sla-rules')->json('data'))->firstWhere('priority', 'high'))
        ->toMatchArray(['response_minutes' => 120, 'version' => 2]);
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

it('gives the demo Technician a profile they can see', function () {
    $this->seed(DemoSeeder::class);
    $this->postJson('/api/v1/auth/login', ['email' => 'technician@demo.test', 'password' => 'demo-password']);

    $this->getJson('/api/v1/technician/profile')->assertOk()
        ->assertJsonPath('data.type', 'in_house')
        ->assertJsonPath('data.availability', 'available')
        ->assertJsonPath('data.home_block.code', 'A')
        ->assertJsonPath('data.skills.*.name', ['Electrical', 'Plumbing & Water Supply']);
});

it('can be re-run without duplicating demo accounts or profiles', function () {
    $this->seed(DemoSeeder::class);
    $this->seed(DemoSeeder::class);

    expect(User::count())->toBe(4)
        ->and(Technician::count())->toBe(1);
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
