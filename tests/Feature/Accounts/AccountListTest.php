<?php

use App\Enums\Role;
use Illuminate\Support\Facades\Notification;

require_once __DIR__.'/helpers.php';

beforeEach(fn () => Notification::fake());

it('lists accounts by name, paginated, with role, Unit and password status', function () {
    ['unit' => $unit] = accountsSetup();
    createAccount(residentBody($unit['id']))->assertCreated();
    account(Role::Technician, ['name' => 'Zul Technician']);

    $this->getJson('/api/v1/admin/users?per_page=2')->assertOk()
        ->assertJsonPath('data.*.name', ['Adam Admin', 'Rina Resident'])
        ->assertJsonPath('data.1.unit.code', 'A-01-01')
        ->assertJsonPath('data.1.password_set', false)
        ->assertJsonPath('data.0.password_set', true)
        ->assertJsonPath('meta.total', 3)
        ->assertJsonPath('meta.last_page', 2);
});

it('filters by name or email search, role, Block and active state', function () {
    ['unit' => $unit] = accountsSetup();
    $blockB = $this->postJson('/api/v1/admin/locations', ['type' => 'block', 'parent_id' => null, 'code' => 'B', 'name' => 'Block B'])->json('data');
    $floorB = $this->postJson('/api/v1/admin/locations', ['type' => 'floor', 'parent_id' => $blockB['id'], 'code' => 'B-01', 'name' => 'B1'])->json('data');
    $unitB = $this->postJson('/api/v1/admin/locations', ['type' => 'unit', 'parent_id' => $floorB['id'], 'code' => 'B-01-01', 'name' => 'B-01-01'])->json('data');

    createAccount(residentBody($unit['id']))->assertCreated();
    $bob = createAccount(residentBody($unitB['id'], ['name' => 'Bob Resident', 'email' => 'bob@mail.test']))->json('data');
    account(Role::Manager, ['name' => 'Mariam Manager', 'email' => 'mariam@office.test']);
    $this->patchJson("/api/v1/admin/users/{$bob['id']}", ['active' => false])->assertOk();

    $names = fn (string $query) => $this->getJson("/api/v1/admin/users?{$query}")->assertOk()->json('data.*.name');

    expect($names('q=rina'))->toBe(['Rina Resident'])
        ->and($names('q=office.test'))->toBe(['Mariam Manager'])
        ->and($names('role=resident'))->toBe(['Bob Resident', 'Rina Resident'])
        ->and($names("block={$blockB['id']}"))->toBe(['Bob Resident'])
        ->and($names('active=0'))->toBe(['Bob Resident'])
        ->and($names('role=resident&active=1'))->toBe(['Rina Resident']);
});

it('rejects unknown filter values', function () {
    ['unit' => $unit] = accountsSetup();

    $this->getJson('/api/v1/admin/users?role=janitor')->assertStatus(422)->assertJsonValidationErrors('role');
    $this->getJson("/api/v1/admin/users?block={$unit['id']}")->assertStatus(422)->assertJsonValidationErrors('block');
    $this->getJson('/api/v1/admin/users?per_page=1000')->assertStatus(422);
});

it('sorts by an allowlisted field, descending with a leading minus', function () {
    accountsSetup();
    account(Role::Manager, ['name' => 'Mariam Manager', 'email' => 'zz@office.test']);
    account(Role::Technician, ['name' => 'Zul Technician', 'email' => 'aa@office.test']);

    $names = fn (string $sort) => $this->getJson("/api/v1/admin/users?sort={$sort}")->assertOk()->json('data.*.name');

    expect($names('-name'))->toBe(['Zul Technician', 'Mariam Manager', 'Adam Admin'])
        ->and($names('email'))->toBe(['Zul Technician', 'Adam Admin', 'Mariam Manager']);

    $this->getJson('/api/v1/admin/users?sort=password')->assertStatus(422)->assertJsonValidationErrors('sort');
});
