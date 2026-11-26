<?php

use App\Enums\Role;
use App\Models\Category;

require_once __DIR__.'/helpers.php';

it('offers the profile form its choices: Technicians without a profile, active Skills and active Blocks', function () {
    ['technician' => $tariq, 'blockA' => $a, 'blockB' => $b, 'plumbing' => $plumbing, 'actor' => $manager] = techniciansSetup();
    $chen = account(Role::Technician, ['name' => 'Chen Contractor']);
    account(Role::Technician, ['name' => 'Gone Technician', 'active' => false]);
    $lift = Category::where('key', 'lift')->firstOrFail();
    $this->actingAs(account(Role::Administrator));
    $this->patchJson("/api/v1/admin/categories/{$lift->id}", ['active' => false])->assertOk();
    $this->patchJson("/api/v1/admin/locations/{$b['id']}", ['active' => false])->assertOk();
    $this->actingAs($manager);
    createProfile(profileBody($chen, $a['id'], [$plumbing]))->assertCreated();

    $options = $this->getJson('/api/v1/technicians/form-options')->assertOk()->json('data');

    expect($options['accounts'])->toBe([['id' => $tariq->id, 'name' => 'Tariq Technician', 'email' => $tariq->email]])
        ->and($options['blocks'])->toBe([['id' => $a['id'], 'code' => 'A', 'name' => 'Block A']])
        ->and(collect($options['skills'])->pluck('name'))->toHaveCount(9)->not->toContain('Lift')
        ->and($options['skills'][0])->toHaveKeys(['id', 'name']);
});

it('keeps the form choices to Managers and Administrators', function (Role $role) {
    techniciansSetup();

    $this->actingAs(account($role))->getJson('/api/v1/technicians/form-options')->assertForbidden();
})->with([Role::Resident, Role::Technician]);
