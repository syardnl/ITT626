<?php

use App\Enums\Role;
use App\Models\Technician;

require_once __DIR__.'/helpers.php';

it('lets a Manager create a profile for a Technician account', function () {
    ['technician' => $tariq, 'blockA' => $blockA, 'plumbing' => $plumbing, 'electrical' => $electrical] = techniciansSetup();

    createProfile(profileBody($tariq, $blockA['id'], [$plumbing, $electrical]))
        ->assertCreated()
        ->assertJsonPath('data.user.id', $tariq->id)
        ->assertJsonPath('data.user.name', 'Tariq Technician')
        ->assertJsonPath('data.type', 'in_house')
        ->assertJsonPath('data.company_name', null)
        ->assertJsonPath('data.availability', 'available')
        ->assertJsonPath('data.max_tasks', 5)
        ->assertJsonPath('data.home_block.code', 'A')
        ->assertJsonPath('data.skills.*.name', ['Electrical', 'Plumbing & Water Supply']);

    $this->getJson('/api/v1/technicians')->assertOk()
        ->assertJsonPath('data.0.user.name', 'Tariq Technician');
});

it('refuses a profile for an account that is not a Technician, or a second profile', function () {
    ['technician' => $tariq, 'blockA' => $blockA, 'plumbing' => $plumbing, 'actor' => $manager] = techniciansSetup();
    $resident = account(Role::Resident);

    createProfile(profileBody($resident, $blockA['id'], [$plumbing]))
        ->assertStatus(422)->assertJsonValidationErrors(['user_id' => 'Choose an active Technician account.']);
    createProfile(profileBody($manager, $blockA['id'], [$plumbing]))
        ->assertStatus(422)->assertJsonValidationErrors('user_id');
    createProfile(profileBody($tariq, $blockA['id'], [$plumbing]))->assertCreated();
    createProfile(profileBody($tariq, $blockA['id'], [$plumbing]))
        ->assertStatus(422)->assertJsonValidationErrors(['user_id' => 'This Technician already has a profile.']);

    expect(Technician::count())->toBe(1);
});

it('refuses a profile for a deactivated Technician account', function () {
    ['blockA' => $blockA, 'plumbing' => $plumbing] = techniciansSetup();
    $gone = account(Role::Technician, ['active' => false]);

    createProfile(profileBody($gone, $blockA['id'], [$plumbing]))
        ->assertStatus(422)->assertJsonValidationErrors(['user_id' => 'Choose an active Technician account.']);
});

it('keeps a profiled account a Technician: a change to another role is refused', function () {
    ['technician' => $tariq, 'blockA' => $blockA, 'plumbing' => $plumbing] = techniciansSetup();
    createProfile(profileBody($tariq, $blockA['id'], [$plumbing]))->assertCreated();
    $this->actingAs(account(Role::Administrator));

    $this->patchJson("/api/v1/admin/users/{$tariq->id}", ['role' => 'manager'])->assertStatus(409)
        ->assertJsonPath('message', 'This account has a Technician profile, so it must stay a Technician. Give the person a separate account for the new role.');
    $this->patchJson("/api/v1/admin/users/{$tariq->id}", ['name' => 'Tariq bin Ali', 'role' => 'technician'])->assertOk();

    expect($tariq->refresh())->name->toBe('Tariq bin Ali')
        ->and($tariq->role())->toBe(Role::Technician);
});
