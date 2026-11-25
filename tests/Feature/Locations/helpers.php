<?php

use App\Enums\Role;

/** Create a Location through the Administrator API and return its JSON. */
function createLocation(string $type, ?int $parentId, string $code, ?string $name = null, array $extra = []): array
{
    return test()->postJson('/api/v1/admin/locations', [
        'type' => $type, 'parent_id' => $parentId, 'code' => $code, 'name' => $name ?? $code, ...$extra,
    ])->assertCreated()->json('data');
}

/** Block A > Floor A-01 > Unit A-01-01, created through the API as the Administrator. */
function sampleBranch(): array
{
    test()->actingAs(account(Role::Administrator));
    $block = createLocation('block', null, 'A', 'Block A');
    $floor = createLocation('floor', $block['id'], 'A-01', 'Block A, Floor 1');
    $unit = createLocation('unit', $floor['id'], 'A-01-01', 'Unit A-01-01');

    return [$block, $floor, $unit];
}
