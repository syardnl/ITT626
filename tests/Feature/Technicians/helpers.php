<?php

use App\Enums\Role;
use App\Models\Category;
use App\Models\User;

/**
 * Builds Blocks A and B (as the Administrator), a Technician account and the Skill ids
 * for the seeded plumbing and electrical Categories, then signs in as $as.
 */
function techniciansSetup(Role $as = Role::Manager): array
{
    test()->actingAs(account(Role::Administrator));
    $post = fn (array $body) => test()->postJson('/api/v1/admin/locations', $body)->assertCreated()->json('data');

    $blockA = $post(['type' => 'block', 'parent_id' => null, 'code' => 'A', 'name' => 'Block A']);
    $blockB = $post(['type' => 'block', 'parent_id' => null, 'code' => 'B', 'name' => 'Block B']);
    $floor = $post(['type' => 'floor', 'parent_id' => $blockA['id'], 'code' => 'A-01', 'name' => 'Block A, Floor 1']);

    $skill = fn (string $key) => Category::where('key', $key)->firstOrFail()->skill->id;
    $plumbing = $skill('plumbing');
    $electrical = $skill('electrical');

    $technician = account(Role::Technician, ['name' => 'Tariq Technician']);

    test()->actingAs($actor = account($as, ['name' => 'Office '.$as->value]));

    return compact('actor', 'blockA', 'blockB', 'floor', 'plumbing', 'electrical', 'technician');
}

function profileBody(User $technician, int $blockId, array $skillIds, array $overrides = []): array
{
    return [
        'user_id' => $technician->id,
        'type' => 'in_house',
        'skill_ids' => $skillIds,
        'availability' => 'available',
        'max_tasks' => 5,
        'home_block_id' => $blockId,
        ...$overrides,
    ];
}

function createProfile(array $body)
{
    return test()->postJson('/api/v1/technicians', $body);
}
