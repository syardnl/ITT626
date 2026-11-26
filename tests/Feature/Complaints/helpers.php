<?php

use App\Enums\LocationImportance;
use App\Enums\LocationType;
use App\Enums\ResidentType;
use App\Enums\Role;
use App\Models\Location;
use App\Models\Property;

/** Adds one Location straight to the tree (setup only; Location editing is tested in tests/Feature/Locations). */
function place(LocationType $type, ?Location $parent, string $code, LocationImportance $importance = LocationImportance::Normal, bool $active = true): Location
{
    return Location::create([
        'property_id' => Property::current()->id,
        'parent_id' => $parent?->id,
        'type' => $type,
        'code' => $code,
        'name' => $code,
        'importance' => $importance,
        'active' => $active,
    ]);
}

/**
 * Block A (lift: essential) > Floor A-01 > Units A-01-01, A-01-02, plus a Shared Area pool,
 * and Rina, a Resident of A-01-01, signed in.
 */
function complaintsSetup(): array
{
    $block = place(LocationType::Block, null, 'A');
    $lift = place(LocationType::CommonArea, $block, 'A-LIFT-1', LocationImportance::Essential);
    $floor = place(LocationType::Floor, $block, 'A-01');
    $unit = place(LocationType::Unit, $floor, 'A-01-01');
    $neighbour = place(LocationType::Unit, $floor, 'A-01-02');
    $pool = place(LocationType::CommonArea, null, 'POOL');

    $rina = account(Role::Resident, ['name' => 'Rina Resident', 'unit_id' => $unit->id, 'resident_type' => ResidentType::Tenant]);
    test()->actingAs($rina);

    return compact('block', 'lift', 'floor', 'unit', 'neighbour', 'pool', 'rina');
}

/** A valid submission body; low-impact answers unless overridden. */
function complaintBody(array $overrides = []): array
{
    return [
        'title' => 'Lift not operating',
        'description' => 'The lift has been stuck on floor 3 since morning.',
        'urgency' => 'low',
        'safety_risk' => false,
        'affected_units' => 'one',
        'essential_service_down' => false,
        ...$overrides,
    ];
}

function submitComplaint(array $body)
{
    return test()->postJson('/api/v1/complaints', $body);
}
