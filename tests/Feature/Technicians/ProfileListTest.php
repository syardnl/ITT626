<?php

use App\Enums\Role;

require_once __DIR__.'/helpers.php';

/** Tariq (in-house, plumbing, A, available), Chen (contractor, electrical, B, busy), Aina (in-house, both, B, on leave). */
function threeTechnicians(): array
{
    $setup = techniciansSetup();
    ['technician' => $tariq, 'blockA' => $a, 'blockB' => $b, 'plumbing' => $plumbing, 'electrical' => $electrical] = $setup;

    createProfile(profileBody($tariq, $a['id'], [$plumbing]))->assertCreated();
    createProfile(profileBody(account(Role::Technician, ['name' => 'Chen Contractor']), $b['id'], [$electrical], [
        'type' => 'contractor', 'company_name' => 'Volt Sdn Bhd', 'availability' => 'busy',
    ]))->assertCreated();
    createProfile(profileBody(account(Role::Technician, ['name' => 'Aina Technician']), $b['id'], [$plumbing, $electrical], [
        'availability' => 'on_leave',
    ]))->assertCreated();

    return $setup;
}

it('lists profiles by Technician name, paginated', function () {
    threeTechnicians();

    $this->getJson('/api/v1/technicians?per_page=2')->assertOk()
        ->assertJsonPath('data.*.user.name', ['Aina Technician', 'Chen Contractor'])
        ->assertJsonPath('data.1.company_name', 'Volt Sdn Bhd')
        ->assertJsonPath('meta.total', 3)
        ->assertJsonPath('meta.last_page', 2);
});

it('filters by Skill, Availability, type and home Block', function () {
    ['blockA' => $a, 'blockB' => $b, 'plumbing' => $plumbing, 'electrical' => $electrical] = threeTechnicians();
    $names = fn (string $query) => $this->getJson("/api/v1/technicians?{$query}")->assertOk()->json('data.*.user.name');

    expect($names("skill={$plumbing}"))->toBe(['Aina Technician', 'Tariq Technician'])
        ->and($names("skill={$electrical}"))->toBe(['Aina Technician', 'Chen Contractor'])
        ->and($names('availability=busy'))->toBe(['Chen Contractor'])
        ->and($names('type=contractor'))->toBe(['Chen Contractor'])
        ->and($names('type=in_house'))->toBe(['Aina Technician', 'Tariq Technician'])
        ->and($names("block={$a['id']}"))->toBe(['Tariq Technician'])
        ->and($names("block={$b['id']}&skill={$plumbing}"))->toBe(['Aina Technician']);
});

it('rejects unknown filter values', function () {
    ['floor' => $floor] = techniciansSetup();

    $this->getJson('/api/v1/technicians?availability=sick')->assertStatus(422)->assertJsonValidationErrors('availability');
    $this->getJson('/api/v1/technicians?type=freelancer')->assertStatus(422)->assertJsonValidationErrors('type');
    $this->getJson("/api/v1/technicians?block={$floor['id']}")->assertStatus(422)->assertJsonValidationErrors('block');
    $this->getJson('/api/v1/technicians?skill=999999')->assertStatus(422)->assertJsonValidationErrors('skill');
    $this->getJson('/api/v1/technicians?per_page=1000')->assertStatus(422);
});

it('lets an Administrator list and edit profiles too', function () {
    ['technician' => $tariq, 'blockA' => $a, 'plumbing' => $plumbing] = techniciansSetup(Role::Administrator);

    $id = createProfile(profileBody($tariq, $a['id'], [$plumbing]))->assertCreated()->json('data.id');
    $this->patchJson("/api/v1/technicians/{$id}", ['availability' => 'busy'])->assertOk();
    $this->getJson('/api/v1/technicians')->assertOk()->assertJsonPath('data.0.availability', 'busy');
});

it('sorts by an allowlisted field, descending with a leading minus', function () {
    threeTechnicians();
    $names = fn (string $sort) => $this->getJson("/api/v1/technicians?sort={$sort}")->assertOk()->json('data.*.user.name');

    expect($names('-name'))->toBe(['Tariq Technician', 'Chen Contractor', 'Aina Technician'])
        ->and($names('availability'))->toBe(['Tariq Technician', 'Chen Contractor', 'Aina Technician'])
        ->and($names('-availability'))->toBe(['Aina Technician', 'Chen Contractor', 'Tariq Technician']);

    $this->getJson('/api/v1/technicians?sort=user_id')->assertStatus(422)->assertJsonValidationErrors('sort');
});
