<?php

use App\Enums\Role;

require_once __DIR__.'/helpers.php';

function buildSampleTree(): array
{
    [$block, $floor, $unit] = sampleBranch();
    $retired = createLocation('unit', $floor['id'], 'A-01-02', 'Unit A-01-02');
    test()->patchJson("/api/v1/admin/locations/{$retired['id']}", ['active' => false])->assertOk();
    $lift = createLocation('common_area', $block['id'], 'A-LIFT', 'Lift A', ['importance' => 'essential']);
    $pool = createLocation('common_area', null, 'POOL', 'Swimming pool', ['importance' => 'important']);

    return compact('block', 'floor', 'unit', 'lift', 'pool');
}

it('shows every signed-in role the active tree, nested from the Property down', function (Role $role) {
    $t = buildSampleTree();
    $node = fn (array $location, string $type, string $importance, bool $shared, array $children = []) => [
        'id' => $location['id'], 'type' => $type, 'code' => $location['code'], 'name' => $location['name'],
        'importance' => $importance, 'is_shared_area' => $shared, 'children' => $children,
    ];

    $this->actingAs(account($role))->getJson('/api/v1/locations')
        ->assertOk()
        ->assertExactJson(['data' => [
            $node($t['block'], 'block', 'normal', false, [
                $node($t['floor'], 'floor', 'normal', false, [
                    $node($t['unit'], 'unit', 'normal', false),
                ]),
                $node($t['lift'], 'common_area', 'essential', false),
            ]),
            $node($t['pool'], 'common_area', 'important', true),
        ]]);
})->with([Role::Resident, Role::Manager, Role::Technician, Role::Administrator]);

it('hides deactivated Locations and everything beneath them', function () {
    $tree = buildSampleTree();
    $this->patchJson("/api/v1/admin/locations/{$tree['unit']['id']}", ['active' => false])->assertOk();
    $this->patchJson("/api/v1/admin/locations/{$tree['floor']['id']}", ['active' => false])->assertOk();

    $codes = collect($this->getJson('/api/v1/locations')->json('data'))
        ->flatMap(fn ($node) => [$node['code'], ...collect($node['children'])->pluck('code')]);

    expect($codes->all())->toBe(['A', 'A-LIFT', 'POOL']);
});

it('searches by name or code, keeping the path to each match', function () {
    buildSampleTree();

    $this->getJson('/api/v1/locations?q=lift')
        ->assertJsonPath('data.*.code', ['A'])
        ->assertJsonPath('data.0.children.*.code', ['A-LIFT']);

    $this->getJson('/api/v1/locations?q=A-01-01')
        ->assertJsonPath('data.0.children.0.code', 'A-01')
        ->assertJsonPath('data.0.children.0.children.*.code', ['A-01-01']);

    $this->getJson('/api/v1/locations?q=a-01-02')->assertExactJson(['data' => []]);
    $this->getJson('/api/v1/locations?q=pool')->assertJsonPath('data.*.code', ['POOL']);
});

it('answers 401 to anyone not signed in', function () {
    $this->getJson('/api/v1/locations')->assertUnauthorized();
});
