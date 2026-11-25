<?php

use App\Enums\Role;

require_once __DIR__.'/helpers.php';

beforeEach(fn () => $this->actingAs(account(Role::Administrator)));

it('creates a Location with a unique code and returns it', function () {
    $this->postJson('/api/v1/admin/locations', ['type' => 'block', 'parent_id' => null, 'code' => 'a', 'name' => 'Block A'])
        ->assertCreated()
        ->assertJsonPath('data.type', 'block')
        ->assertJsonPath('data.code', 'A')
        ->assertJsonPath('data.name', 'Block A')
        ->assertJsonPath('data.parent_id', null)
        ->assertJsonPath('data.importance', 'normal')
        ->assertJsonPath('data.is_shared_area', false)
        ->assertJsonPath('data.active', true);

    $this->postJson('/api/v1/admin/locations', ['type' => 'block', 'parent_id' => null, 'code' => 'A', 'name' => 'Another'])
        ->assertStatus(422)->assertJsonValidationErrors('code');
});

it('accepts every valid placement', function (string $type, ?string $under) {
    [$block, $floor] = [createLocation('block', null, 'B'), null];
    $floor = createLocation('floor', $block['id'], 'B-01');
    $parents = ['property' => null, 'block' => $block['id'], 'floor' => $floor['id']];

    createLocation($type, $parents[$under], 'NEW');
})->with([
    'Block under the Property' => ['block', 'property'],
    'Floor under a Block' => ['floor', 'block'],
    'Unit under a Floor' => ['unit', 'floor'],
    'Common Area under the Property' => ['common_area', 'property'],
    'Common Area under a Block' => ['common_area', 'block'],
    'Common Area under a Floor' => ['common_area', 'floor'],
]);

it('rejects every invalid placement with 422', function (string $type, string $under) {
    $block = createLocation('block', null, 'B');
    $floor = createLocation('floor', $block['id'], 'B-01');
    $unit = createLocation('unit', $floor['id'], 'B-01-01');
    $area = createLocation('common_area', $floor['id'], 'B-01-CORRIDOR');
    $parents = ['property' => null, 'block' => $block['id'], 'floor' => $floor['id'], 'unit' => $unit['id'], 'common_area' => $area['id']];

    $this->postJson('/api/v1/admin/locations', ['type' => $type, 'parent_id' => $parents[$under], 'code' => 'NEW', 'name' => 'New'])
        ->assertStatus(422)
        ->assertJsonValidationErrors('parent_id');
})->with([
    'Block under a Block' => ['block', 'block'],
    'Block under a Floor' => ['block', 'floor'],
    'Floor under the Property' => ['floor', 'property'],
    'Floor under a Floor' => ['floor', 'floor'],
    'Unit under the Property' => ['unit', 'property'],
    'Unit under a Block' => ['unit', 'block'],
    'Unit under a Unit' => ['unit', 'unit'],
    'Common Area under a Unit' => ['common_area', 'unit'],
    'Floor under a Common Area' => ['floor', 'common_area'],
    'Unit under a Common Area' => ['unit', 'common_area'],
]);

it('rejects an unknown type or a parent that does not exist', function () {
    $this->postJson('/api/v1/admin/locations', ['type' => 'tower', 'parent_id' => null, 'code' => 'T', 'name' => 'T'])
        ->assertStatus(422)->assertJsonValidationErrors('type');
    $this->postJson('/api/v1/admin/locations', ['type' => 'floor', 'parent_id' => 999999, 'code' => 'F', 'name' => 'F'])
        ->assertStatus(422)->assertJsonValidationErrors('parent_id');
});
