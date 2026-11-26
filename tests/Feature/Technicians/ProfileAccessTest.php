<?php

use App\Enums\Role;
use App\Models\Technician;

require_once __DIR__.'/helpers.php';

it('lets a Technician see their own profile and nobody else\'s', function () {
    ['technician' => $tariq, 'blockA' => $a, 'plumbing' => $plumbing] = techniciansSetup();
    $mine = createProfile(profileBody($tariq, $a['id'], [$plumbing]))->json('data.id');
    $chen = account(Role::Technician, ['name' => 'Chen Contractor']);
    $theirs = createProfile(profileBody($chen, $a['id'], [$plumbing]))->json('data.id');

    $this->actingAs($tariq);
    $this->getJson('/api/v1/technician/profile')->assertOk()
        ->assertJsonPath('data.id', $mine)
        ->assertJsonPath('data.skills.*.name', ['Plumbing & Water Supply']);
    $this->getJson("/api/v1/technicians/{$mine}")->assertOk()->assertJsonPath('data.user.name', 'Tariq Technician');
    $this->getJson("/api/v1/technicians/{$theirs}")->assertNotFound();
});

it('answers 404 to a Technician who has no profile yet', function () {
    techniciansSetup();

    $this->actingAs(account(Role::Technician))->getJson('/api/v1/technician/profile')->assertNotFound();
});

it('lets Managers and Administrators read any profile by id', function (Role $role) {
    ['technician' => $tariq, 'blockA' => $a, 'plumbing' => $plumbing] = techniciansSetup();
    $id = createProfile(profileBody($tariq, $a['id'], [$plumbing]))->json('data.id');

    $this->actingAs(account($role))->getJson("/api/v1/technicians/{$id}")->assertOk()
        ->assertJsonPath('data.user.name', 'Tariq Technician');
})->with([Role::Manager, Role::Administrator]);

it('keeps Technicians to reading and Residents out entirely', function () {
    ['technician' => $tariq, 'blockA' => $a, 'plumbing' => $plumbing] = techniciansSetup();
    $id = createProfile(profileBody($tariq, $a['id'], [$plumbing]))->json('data.id');
    $resident = account(Role::Resident);

    $this->actingAs($tariq);
    $this->getJson('/api/v1/technicians')->assertForbidden();
    createProfile(profileBody(account(Role::Technician), $a['id'], [$plumbing]))->assertForbidden();
    $this->patchJson("/api/v1/technicians/{$id}", ['max_tasks' => 99])->assertForbidden();

    $this->actingAs($resident);
    $this->getJson('/api/v1/technicians')->assertForbidden();
    $this->getJson("/api/v1/technicians/{$id}")->assertForbidden();
    $this->getJson('/api/v1/technician/profile')->assertForbidden();
    createProfile(profileBody(account(Role::Technician), $a['id'], [$plumbing]))->assertForbidden();
    $this->patchJson("/api/v1/technicians/{$id}", ['max_tasks' => 99])->assertForbidden();

    expect(Technician::find($id)->max_tasks)->toBe(5);
});
