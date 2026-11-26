<?php

use App\Enums\Role;

require_once __DIR__.'/helpers.php';

it('lets a Manager change only the fields sent', function () {
    ['technician' => $tariq, 'blockA' => $blockA, 'blockB' => $blockB, 'plumbing' => $plumbing, 'electrical' => $electrical] = techniciansSetup();
    $id = createProfile(profileBody($tariq, $blockA['id'], [$plumbing]))->json('data.id');

    $this->patchJson("/api/v1/technicians/{$id}", [
        'availability' => 'busy',
        'max_tasks' => 3,
        'skill_ids' => [$electrical],
        'home_block_id' => $blockB['id'],
    ])->assertOk()
        ->assertJsonPath('data.availability', 'busy')
        ->assertJsonPath('data.max_tasks', 3)
        ->assertJsonPath('data.skills.*.name', ['Electrical'])
        ->assertJsonPath('data.home_block.code', 'B')
        ->assertJsonPath('data.type', 'in_house');

    $this->patchJson("/api/v1/technicians/{$id}", ['availability' => 'available'])->assertOk()
        ->assertJsonPath('data.skills.*.name', ['Electrical'])
        ->assertJsonPath('data.max_tasks', 3);
});

it('applies the same rules on edit as on create', function () {
    ['technician' => $tariq, 'blockA' => $blockA, 'floor' => $floor, 'plumbing' => $plumbing] = techniciansSetup();
    $id = createProfile(profileBody($tariq, $blockA['id'], [$plumbing]))->json('data.id');
    $patch = fn (array $body) => $this->patchJson("/api/v1/technicians/{$id}", $body);

    $patch(['skill_ids' => []])->assertStatus(422)->assertJsonValidationErrors('skill_ids');
    $patch(['max_tasks' => 0])->assertStatus(422)->assertJsonValidationErrors('max_tasks');
    $patch(['home_block_id' => $floor['id']])->assertStatus(422)->assertJsonValidationErrors('home_block_id');
    $patch(['availability' => 'away'])->assertStatus(422)->assertJsonValidationErrors('availability');
    $patch(['user_id' => account(Role::Technician)->id])->assertStatus(422)->assertJsonValidationErrors('user_id');
});

it('switches between In-house and Contractor, keeping the company name rule', function () {
    ['technician' => $tariq, 'blockA' => $blockA, 'plumbing' => $plumbing] = techniciansSetup();
    $id = createProfile(profileBody($tariq, $blockA['id'], [$plumbing]))->json('data.id');
    $patch = fn (array $body) => $this->patchJson("/api/v1/technicians/{$id}", $body);

    $patch(['type' => 'contractor'])->assertStatus(422)->assertJsonValidationErrors('company_name');
    $patch(['company_name' => 'Lift Co'])->assertStatus(422)->assertJsonValidationErrors('company_name');
    $patch(['type' => 'contractor', 'company_name' => 'Lift Co'])->assertOk()->assertJsonPath('data.company_name', 'Lift Co');

    $patch(['company_name' => 'Lift Co Sdn Bhd'])->assertOk()->assertJsonPath('data.company_name', 'Lift Co Sdn Bhd');
    $patch(['company_name' => null])->assertStatus(422)->assertJsonValidationErrors('company_name');

    $patch(['type' => 'in_house'])->assertOk()
        ->assertJsonPath('data.type', 'in_house')
        ->assertJsonPath('data.company_name', null);
});

it('answers 404 for a profile that does not exist', function () {
    techniciansSetup();

    $this->patchJson('/api/v1/technicians/999999', ['max_tasks' => 2])->assertNotFound();
});
