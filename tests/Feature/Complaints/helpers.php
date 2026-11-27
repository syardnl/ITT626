<?php

use App\Enums\ComplaintStatus;
use App\Enums\LocationImportance;
use App\Enums\LocationType;
use App\Enums\ResidentType;
use App\Enums\Role;
use App\Models\Category;
use App\Models\Complaint;
use App\Models\Location;
use App\Models\Property;
use Illuminate\Http\UploadedFile;

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

/**
 * Rina (Unit A-01-01) signed in, with Complaints by her neighbour Sam (A-01-02) at the Block A
 * lift, on a Floor A-01 corridor, at the shared pool, inside his own Unit, and at a Block B lift.
 */
function openComplaintsSetup(): array
{
    $setup = complaintsSetup();
    $corridor = place(LocationType::CommonArea, $setup['floor'], 'A-01-CORRIDOR');
    $blockB = place(LocationType::Block, null, 'B');
    $liftB = place(LocationType::CommonArea, $blockB, 'B-LIFT-1');

    test()->actingAs($sam = account(Role::Resident, ['name' => 'Sam Resident', 'unit_id' => $setup['neighbour']->id]));
    $submit = fn (array $body) => submitComplaint(complaintBody($body))->json('data');
    $complaints = [
        'liftComplaint' => $submit(['location_id' => $setup['lift']->id, 'title' => 'Lift stuck on floor 3']),
        'corridorComplaint' => $submit(['location_id' => $corridor->id, 'title' => 'Corridor light out']),
        'poolComplaint' => $submit(['location_id' => $setup['pool']->id, 'title' => 'Pool filter noisy']),
        'samUnitComplaint' => $submit(['my_unit' => true, 'title' => 'Bathroom tap dripping']),
        'liftBComplaint' => $submit(['location_id' => $liftB->id, 'title' => 'Block B lift door jammed']),
    ];
    test()->actingAs($setup['rina']);

    return [...$setup, ...$complaints, 'corridor' => $corridor, 'blockB' => $blockB, 'liftB' => $liftB, 'sam' => $sam];
}

function openComplaintsAt(int $locationId)
{
    return test()->getJson("/api/v1/locations/{$locationId}/open-complaints");
}

/** The version the signed-in account last loaded, as the SPA sends it back with a workflow action; 1 when it cannot open the Complaint. */
function loadedVersion(int $complaintId): int
{
    return test()->getJson("/api/v1/complaints/{$complaintId}")->json('data.version') ?? 1;
}

/**
 * complaintsSetup() plus Block B, and Rina's Complaint at the Block A lift reviewed and classified
 * as Lift (suggested Priority Low) by Maya, a Manager, who stays signed in.
 */
function assignmentSetup(): array
{
    $setup = complaintsSetup();
    $blockB = place(LocationType::Block, null, 'B');
    test()->actingAs($manager = account(Role::Manager, ['name' => 'Maya Manager']));
    $setup = [...$setup, 'blockB' => $blockB, 'manager' => $manager, 'liftCategory' => Category::where('key', 'lift')->firstOrFail()];

    return [...$setup, 'complaint' => classifiedComplaint($setup)];
}

/** Another Complaint by Rina at the Block A lift, reviewed and classified by the Manager, who stays signed in; its id. */
function classifiedComplaint(array $setup, string $category = 'lift'): int
{
    test()->actingAs($setup['rina']);
    $id = submitComplaint(complaintBody(['location_id' => $setup['lift']->id]))->assertCreated()->json('data.id');
    test()->actingAs($setup['manager']);
    test()->postJson("/api/v1/complaints/{$id}/review", ['version' => loadedVersion($id)])->assertOk();
    test()->patchJson("/api/v1/complaints/{$id}/classification", [
        'category_id' => Category::where('key', $category)->firstOrFail()->id, 'priority' => 'low', 'version' => loadedVersion($id),
    ])->assertOk();

    return $id;
}

/**
 * A Technician account with a profile made through the API by the signed-in Manager: Available,
 * maximum 5 tasks and the Lift Skill unless $profile says otherwise ('skills' lists Category keys).
 * Returns the profile id and the account.
 */
function technicianProfile(string $name, int $homeBlockId, array $profile = []): array
{
    $user = account(Role::Technician, ['name' => $name]);
    $skillIds = array_map(fn (string $key) => Category::where('key', $key)->firstOrFail()->skill->id, $profile['skills'] ?? ['lift']);
    unset($profile['skills']);
    $id = test()->postJson('/api/v1/technicians', [
        'user_id' => $user->id, 'type' => 'in_house', 'skill_ids' => $skillIds,
        'availability' => 'available', 'max_tasks' => 5, 'home_block_id' => $homeBlockId, ...$profile,
    ])->assertCreated()->json('data.id');

    return ['id' => $id, 'user' => $user];
}

function recommendationsFor(int $complaintId)
{
    return test()->getJson("/api/v1/complaints/{$complaintId}/technician-recommendations");
}

/** Assigns or reassigns, sending the version the signed-in account loaded unless $body names one. */
function assign(int $complaintId, array $body)
{
    return test()->postJson("/api/v1/complaints/{$complaintId}/assignments", ['version' => loadedVersion($complaintId), ...$body]);
}

/** No endpoint reaches these statuses until later Phase 3 tickets, so the status is set directly. */
function forceStatus(int $complaintId, ComplaintStatus $status): void
{
    Complaint::findOrFail($complaintId)->update(['status' => $status]);
}

/** The timeline entries of one type the signed-in account sees. */
function timelineOf(int $complaintId, string $type): array
{
    $timeline = test()->getJson("/api/v1/complaints/{$complaintId}")->assertOk()->json('data.timeline');

    return array_values(array_filter($timeline, fn (array $entry) => $entry['type'] === $type));
}

/**
 * assignmentSetup() with the Complaint assigned to Tariq (Lift Skill, home Block A), who is then
 * signed in. Una is another Lift Technician with no work.
 */
function technicianWorkSetup(): array
{
    $setup = assignmentSetup();
    $tariq = technicianProfile('Tariq Technician', $setup['block']->id);
    $una = technicianProfile('Una Other', $setup['block']->id);
    assign($setup['complaint'], ['technician_id' => $tariq['id'], 'note' => 'Key is at the guard house.'])->assertOk();
    test()->actingAs($tariq['user']);

    return [...$setup, 'tariq' => $tariq, 'una' => $una];
}

function startWork(int $complaintId, ?int $version = null)
{
    return test()->postJson("/api/v1/complaints/{$complaintId}/start-work", ['version' => $version ?? loadedVersion($complaintId)]);
}

/** technicianWorkSetup() with Tariq, still signed in, having started work: the Complaint is In Progress. */
function inProgressSetup(): array
{
    $setup = technicianWorkSetup();
    startWork($setup['complaint'])->assertOk();

    return $setup;
}

/** A Complete work body: a repair result and one After photo unless $overrides say otherwise. */
function completionBody(array $overrides = []): array
{
    return [
        'repair_result' => 'Replaced the door sensor; the lift runs normally.',
        'photos' => [UploadedFile::fake()->image('after.jpg', 800, 600)],
        'photo_tags' => ['after'],
        ...$overrides,
    ];
}

/** Sends the version the signed-in account loaded unless $body names one. */
function completeWork(int $complaintId, array $body, array $headers = [])
{
    return test()->withHeaders($headers)->postJson("/api/v1/complaints/{$complaintId}/complete-work", ['version' => loadedVersion($complaintId), ...$body]);
}

/** inProgressSetup() with the work completed (Pending Verification) and Maya, the Manager, signed in. */
function pendingVerificationSetup(): array
{
    $setup = inProgressSetup();
    $record = completeWork($setup['complaint'], completionBody([
        'photos' => [UploadedFile::fake()->image('before.jpg'), UploadedFile::fake()->image('after.jpg')],
        'photo_tags' => ['before', 'after'],
        'expenses' => [['description' => 'Door sensor', 'quantity' => 1, 'amount' => '185.50']],
    ]))->assertOk()->json('data.maintenance_records.0');
    test()->actingAs($setup['manager']);

    return [...$setup, 'record' => $record];
}

/** Sends the version the signed-in account loaded unless $body names one. */
function verifyWork(int $complaintId, array $body)
{
    return test()->postJson("/api/v1/complaints/{$complaintId}/verify", ['version' => loadedVersion($complaintId), ...$body]);
}

/** pendingVerificationSetup() with the work approved (Resolved) and Rina, the reporter, signed in. */
function resolvedSetup(): array
{
    $setup = pendingVerificationSetup();
    verifyWork($setup['complaint'], ['decision' => 'approve'])->assertOk();
    test()->actingAs($setup['rina']);

    return $setup;
}

/** Sends the version the signed-in account loaded unless $body names one. */
function confirmResolution(int $complaintId, array $body)
{
    return test()->postJson("/api/v1/complaints/{$complaintId}/confirm-resolution", ['version' => loadedVersion($complaintId), ...$body]);
}
