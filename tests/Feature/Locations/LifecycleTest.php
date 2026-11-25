<?php

use App\Enums\Role;
use App\Models\Location;
use App\Models\User;

require_once __DIR__.'/helpers.php';

function setActive(array $location, bool $active)
{
    return test()->patchJson("/api/v1/admin/locations/{$location['id']}", ['active' => $active]);
}

it('deactivates and reactivates a Location', function () {
    [, , $unit] = sampleBranch();

    setActive($unit, false)->assertOk()->assertJsonPath('data.active', false);
    setActive($unit, true)->assertOk()->assertJsonPath('data.active', true);
});

it('has no way to delete a Location', function () {
    [, , $unit] = sampleBranch();

    $this->deleteJson("/api/v1/admin/locations/{$unit['id']}")->assertStatus(405);
    setActive($unit, false)->assertOk();
});

it('refuses to deactivate a Unit that still has active Residents', function () {
    [, , $unit] = sampleBranch();
    account(Role::Resident, ['unit_id' => $unit['id']]);

    setActive($unit, false)
        ->assertStatus(409)
        ->assertExactJson(['message' => 'This Unit still has active Residents. Move or deactivate them before deactivating the Unit.']);
});

it('allows deactivating a Unit whose Residents are all inactive', function () {
    [, , $unit] = sampleBranch();
    User::factory()->inactive()->withRole(Role::Resident)->create(['unit_id' => $unit['id']]);

    setActive($unit, false)->assertOk();
});

it('refuses to deactivate a Location that still contains active Locations', function () {
    [$block, $floor, $unit] = sampleBranch();

    setActive($block, false)->assertStatus(409)->assertJsonPath('message', 'This Location still contains active Locations. Deactivate those first.');

    setActive($unit, false)->assertOk();
    setActive($floor, false)->assertOk();
    setActive($block, false)->assertOk();
});

it('refuses to reactivate a Location under a deactivated parent, or to add one there', function () {
    [, $floor, $unit] = sampleBranch();
    setActive($unit, false);
    setActive($floor, false);

    setActive($unit, true)->assertStatus(409);
    $this->postJson('/api/v1/admin/locations', ['type' => 'unit', 'parent_id' => $floor['id'], 'code' => 'A-01-09', 'name' => 'U'])
        ->assertStatus(422)->assertJsonPath('errors.parent_id.0', 'The chosen parent Location is deactivated.');
});

it('writes an audit entry for every create, update, deactivate and reactivate', function () {
    [, , $unit] = sampleBranch();
    $this->patchJson("/api/v1/admin/locations/{$unit['id']}", ['name' => 'Unit A-1-1']);
    setActive($unit, false);
    setActive($unit, true);

    $entries = $this->getJson('/api/v1/admin/audit-logs?target_type=location&per_page=100')->json('data');

    expect(collect($entries)->pluck('action')->all())->toBe(['reactivated', 'deactivated', 'updated', 'created', 'created', 'created'])
        ->and($entries[2]['changes'])->toBe(['name' => ['from' => 'Unit A-01-01', 'to' => 'Unit A-1-1']])
        ->and($entries[1]['changes'])->toBe(['active' => ['from' => true, 'to' => false]])
        ->and($entries[0]['target_id'])->toBe($unit['id']);
});

it('writes no audit entry when nothing changed', function () {
    [, , $unit] = sampleBranch();
    $this->patchJson("/api/v1/admin/locations/{$unit['id']}", ['name' => 'Unit A-01-01'])->assertOk();

    $this->getJson('/api/v1/admin/audit-logs?target_type=location&action=updated')->assertJsonCount(0, 'data');
});

it('keeps Location management to the Administrator', function (Role $role) {
    [$block] = sampleBranch();
    $this->actingAs(account($role));

    $this->getJson('/api/v1/admin/locations')->assertForbidden();
    $this->postJson('/api/v1/admin/locations', ['type' => 'block', 'parent_id' => null, 'code' => 'Z', 'name' => 'Z'])->assertForbidden();
    $this->patchJson("/api/v1/admin/locations/{$block['id']}", ['name' => 'Hacked'])->assertForbidden();
})->with([Role::Resident, Role::Manager, Role::Technician]);

it('lists Locations for the Administrator, including inactive ones, one level at a time', function () {
    [$block, $floor, $unit] = sampleBranch();
    createLocation('block', null, 'B');
    setActive($unit, false);

    $this->getJson('/api/v1/admin/locations?parent=root')
        ->assertOk()
        ->assertJsonPath('data.*.code', ['A', 'B'])
        ->assertJsonPath('data.0.children_count', 1);
    $this->getJson("/api/v1/admin/locations?parent={$floor['id']}")
        ->assertJsonPath('data.*.code', ['A-01-01'])
        ->assertJsonPath('data.0.active', false);
    $this->getJson('/api/v1/admin/locations?q=a-01&active=0')->assertJsonPath('data.*.code', ['A-01-01']);
    $this->getJson('/api/v1/admin/locations?type=floor')->assertJsonPath('data.*.code', ['A-01'])->assertJsonPath('meta.total', 1);
    $this->getJson('/api/v1/admin/locations?type=tower')->assertStatus(422);
});

it('records a rename and a deactivation sent together as two audit entries', function () {
    [, , $unit] = sampleBranch();

    $this->patchJson("/api/v1/admin/locations/{$unit['id']}", ['name' => 'Unit A-1-1', 'active' => false])->assertOk()
        ->assertJsonPath('data.name', 'Unit A-1-1')->assertJsonPath('data.active', false);

    $entries = $this->getJson('/api/v1/admin/audit-logs?target_type=location&per_page=2')->json('data');
    expect(array_column($entries, 'action'))->toBe(['deactivated', 'updated'])
        ->and($entries[0]['changes'])->toBe(['active' => ['from' => true, 'to' => false]])
        ->and($entries[1]['changes'])->toBe(['name' => ['from' => 'Unit A-01-01', 'to' => 'Unit A-1-1']]);
});

it('reads Importance as normal for anything but a Common Area, whatever is stored', function () {
    [$block] = sampleBranch();
    Location::whereKey($block['id'])->update(['importance' => 'essential']); // e.g. a bad import

    $this->getJson('/api/v1/admin/locations?parent=root')->assertJsonPath('data.0.importance', 'normal');
    $this->getJson('/api/v1/locations')->assertJsonPath('data.0.importance', 'normal');
});

it('rolls back the whole edit when a deactivation sent with it is refused', function () {
    [, , $unit] = sampleBranch();
    account(Role::Resident, ['unit_id' => $unit['id']]);

    $this->patchJson("/api/v1/admin/locations/{$unit['id']}", ['name' => 'Renamed', 'active' => false])->assertStatus(409);

    $this->getJson('/api/v1/admin/locations?type=unit')->assertJsonPath('data.0.name', 'Unit A-01-01');
    $this->getJson('/api/v1/admin/audit-logs?target_type=location&action=updated')->assertJsonCount(0, 'data');
});
