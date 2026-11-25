<?php

require_once __DIR__.'/helpers.php';

it('renames a Location and changes its code, keeping codes unique', function () {
    [$block, $floor] = sampleBranch();

    $this->patchJson("/api/v1/admin/locations/{$block['id']}", ['name' => 'Block Anggerik', 'code' => 'ang'])
        ->assertOk()->assertJsonPath('data.name', 'Block Anggerik')->assertJsonPath('data.code', 'ANG');

    $this->patchJson("/api/v1/admin/locations/{$floor['id']}", ['code' => 'ANG'])
        ->assertStatus(422)->assertJsonValidationErrors('code');
    $this->patchJson("/api/v1/admin/locations/{$floor['id']}", ['code' => 'A-01'])->assertOk();
});

it('moves a Location to another valid parent', function () {
    [$blockA, $floor] = sampleBranch();
    $blockB = createLocation('block', null, 'B');

    $this->patchJson("/api/v1/admin/locations/{$floor['id']}", ['parent_id' => $blockB['id']])
        ->assertOk()->assertJsonPath('data.parent_id', $blockB['id']);
});

it('rejects moves that break placement rules or would make a cycle', function () {
    [$block, $floor, $unit] = sampleBranch();
    $corridor = createLocation('common_area', $floor['id'], 'A-01-CORRIDOR');

    $this->patchJson("/api/v1/admin/locations/{$floor['id']}", ['parent_id' => $floor['id']])
        ->assertStatus(422)->assertJsonValidationErrors(['parent_id' => 'itself or one of its own descendants']);
    $this->patchJson("/api/v1/admin/locations/{$block['id']}", ['parent_id' => $unit['id']])
        ->assertStatus(422)->assertJsonValidationErrors(['parent_id' => 'itself or one of its own descendants']);
    $this->patchJson("/api/v1/admin/locations/{$unit['id']}", ['parent_id' => $block['id']])
        ->assertStatus(422)->assertJsonValidationErrors(['parent_id' => 'cannot be placed under a Block']);
    $this->patchJson("/api/v1/admin/locations/{$corridor['id']}", ['parent_id' => $unit['id']])
        ->assertStatus(422)->assertJsonValidationErrors('parent_id');
});

it('does not let a Location change type', function () {
    [$block] = sampleBranch();

    $this->patchJson("/api/v1/admin/locations/{$block['id']}", ['type' => 'common_area'])
        ->assertStatus(422)->assertJsonValidationErrors('type');
});

it('sets Importance on Common Areas only; other Locations always read as normal', function () {
    [$block, $floor, $unit] = sampleBranch();

    $lift = createLocation('common_area', $block['id'], 'A-LIFT', 'Lift A', ['importance' => 'essential']);
    expect($lift['importance'])->toBe('essential');
    $this->patchJson("/api/v1/admin/locations/{$lift['id']}", ['importance' => 'important'])
        ->assertOk()->assertJsonPath('data.importance', 'important');

    foreach ([$block, $floor, $unit] as $location) {
        $this->patchJson("/api/v1/admin/locations/{$location['id']}", ['importance' => 'essential'])
            ->assertStatus(422)->assertJsonPath('errors.importance.0', 'Importance can only be set on Common Areas.');
    }
    $this->postJson('/api/v1/admin/locations', ['type' => 'unit', 'parent_id' => $floor['id'], 'code' => 'A-01-02', 'name' => 'U', 'importance' => 'important'])
        ->assertStatus(422)->assertJsonValidationErrors('importance');

    expect($unit['importance'])->toBe('normal');
});

it('reports Common Areas directly under the Property as Shared Areas', function () {
    [$block] = sampleBranch();

    expect(createLocation('common_area', null, 'POOL', 'Swimming pool')['is_shared_area'])->toBeTrue()
        ->and(createLocation('common_area', $block['id'], 'A-LOBBY', 'Lobby A')['is_shared_area'])->toBeFalse()
        ->and($block['is_shared_area'])->toBeFalse();
});

it('answers 404 for a Location that does not exist', function () {
    sampleBranch();
    $this->patchJson('/api/v1/admin/locations/999999', ['name' => 'Ghost'])->assertNotFound();
});

it('renames every kind of Location', function (string $which) {
    [$block, $floor, $unit] = sampleBranch();
    $corridor = createLocation('common_area', $floor['id'], 'A-01-CORRIDOR', 'Corridor');
    $location = compact('block', 'floor', 'unit', 'corridor')[$which];

    $this->patchJson("/api/v1/admin/locations/{$location['id']}", ['name' => 'Renamed'])
        ->assertOk()->assertJsonPath('data.name', 'Renamed');
})->with(['block', 'floor', 'unit', 'corridor']);

it('rejects Importance when creating anything but a Common Area', function (string $type, string $under) {
    [$block, $floor] = sampleBranch();
    $parents = ['property' => null, 'block' => $block['id'], 'floor' => $floor['id']];

    $this->postJson('/api/v1/admin/locations', ['type' => $type, 'parent_id' => $parents[$under], 'code' => 'NEW', 'name' => 'New', 'importance' => 'essential'])
        ->assertStatus(422)->assertJsonPath('errors.importance.0', 'Importance can only be set on Common Areas.');
})->with([
    'Block' => ['block', 'property'],
    'Floor' => ['floor', 'block'],
    'Unit' => ['unit', 'floor'],
]);
