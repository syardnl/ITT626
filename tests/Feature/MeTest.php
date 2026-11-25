<?php

use App\Enums\ResidentType;
use App\Enums\Role;
use App\Models\Location;
use App\Models\Property;

function unitA101(): Location
{
    $property = Property::current();
    $block = Location::create(['property_id' => $property->id, 'type' => 'block', 'code' => 'A', 'name' => 'Block A']);
    $floor = Location::create(['property_id' => $property->id, 'parent_id' => $block->id, 'type' => 'floor', 'code' => 'A-01', 'name' => 'Floor 1']);

    return Location::create(['property_id' => $property->id, 'parent_id' => $floor->id, 'type' => 'unit', 'code' => 'A-01-01', 'name' => 'Unit A-01-01']);
}

it('tells a Resident who they are, their Resident Type and the Unit they are linked to', function () {
    $unit = unitA101();
    $resident = account(Role::Resident, [
        'name' => 'Rina Resident', 'email' => 'rina@example.test',
        'unit_id' => $unit->id, 'resident_type' => ResidentType::Tenant,
    ]);

    $this->actingAs($resident)->getJson('/api/v1/me')
        ->assertOk()
        ->assertExactJson(['data' => [
            'id' => $resident->id,
            'name' => 'Rina Resident',
            'email' => 'rina@example.test',
            'role' => 'resident',
            'resident_type' => 'tenant',
            'unit' => ['id' => $unit->id, 'code' => 'A-01-01', 'name' => 'Unit A-01-01'],
        ]]);
});

it('tells staff who they are, with no Unit or Resident Type', function () {
    $technician = account(Role::Technician, ['name' => 'Tariq Technician', 'email' => 'tariq@example.test']);

    $this->actingAs($technician)->getJson('/api/v1/me')
        ->assertOk()
        ->assertJsonPath('data.role', 'technician')
        ->assertJsonPath('data.resident_type', null)
        ->assertJsonPath('data.unit', null);
});

it('never exposes the password hash', function () {
    $this->actingAs(account(Role::Manager))->getJson('/api/v1/me')
        ->assertOk()
        ->assertJsonMissingPath('data.password')
        ->assertJsonMissingPath('data.remember_token');
});

it('answers 401 to anyone not signed in', function () {
    $this->getJson('/api/v1/me')->assertUnauthorized()->assertExactJson(['message' => 'Unauthenticated.']);
});

it('ends the session of an account deactivated while signed in', function () {
    $manager = account(Role::Manager, ['email' => 'mariam@example.test', 'password' => 'correct-horse']);
    $this->postJson('/api/v1/auth/login', ['email' => 'mariam@example.test', 'password' => 'correct-horse'])->assertOk();

    $manager->update(['active' => false]);

    $this->getJson('/api/v1/me')->assertUnauthorized();
    $manager->update(['active' => true]);
    $this->getJson('/api/v1/me')->assertUnauthorized();
});
