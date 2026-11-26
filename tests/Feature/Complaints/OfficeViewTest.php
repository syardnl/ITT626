<?php

use App\Enums\ComplaintStatus;
use App\Enums\LocationImportance;
use App\Enums\LocationType;
use App\Enums\Role;
use App\Models\Category;
use App\Models\Complaint;

require_once __DIR__.'/helpers.php';

/** Rina's Unit Complaint (yesterday) and a second Resident's lift Complaint (today). */
function twoResidentsComplaints(): array
{
    $setup = complaintsSetup();
    test()->travelTo(now()->subDay());
    $unitComplaint = submitComplaint(complaintBody(['my_unit' => true, 'title' => 'Leaking kitchen pipe']))->json('data');
    test()->travelBack();

    test()->actingAs($sam = account(Role::Resident, ['name' => 'Sam Resident', 'unit_id' => $setup['neighbour']->id]));
    $liftComplaint = submitComplaint(complaintBody(['location_id' => $setup['lift']->id]))->json('data');

    return [...$setup, 'sam' => $sam, 'unitComplaint' => $unitComplaint, 'liftComplaint' => $liftComplaint];
}

it('lists every Complaint for Managers and Administrators, newest first, paginated', function (Role $role) {
    twoResidentsComplaints();

    $this->actingAs(account($role))->getJson('/api/v1/complaints?per_page=1')->assertOk()
        ->assertJsonPath('data.*.title', ['Lift not operating'])
        ->assertJsonPath('meta.total', 2);
})->with([Role::Manager, Role::Administrator]);

it('opens any Complaint for Managers and Administrators, Unit Complaints included, with the reporter', function (Role $role) {
    ['unitComplaint' => $complaint, 'rina' => $rina, 'unit' => $unit] = twoResidentsComplaints();

    $this->actingAs(account($role))->getJson("/api/v1/complaints/{$complaint['id']}")->assertOk()
        ->assertJsonPath('data.title', 'Leaking kitchen pipe')
        ->assertJsonPath('data.reporter.id', $rina->id)
        ->assertJsonPath('data.reporter.name', 'Rina Resident')
        ->assertJsonPath('data.reporter.unit.code', $unit->code)
        ->assertJsonPath('data.priority_breakdown.urgency', 0);
})->with([Role::Manager, Role::Administrator]);

it('keeps the all-Complaints view from Residents and Technicians', function () {
    ['rina' => $rina] = twoResidentsComplaints();

    $this->actingAs($rina)->getJson('/api/v1/complaints')->assertJsonPath('data.*.title', ['Leaking kitchen pipe']);
    $this->actingAs(account(Role::Technician))->getJson('/api/v1/complaints')->assertJsonCount(0, 'data');
});

it('shows the reporter block only to the office, not to the reporter themselves', function () {
    ['lift' => $lift] = complaintsSetup();
    $id = submitComplaint(complaintBody(['location_id' => $lift->id]))->json('data.id');

    $this->getJson("/api/v1/complaints/{$id}")->assertOk()->assertJsonMissingPath('data.reporter');
});

/**
 * Four Complaints, one minute apart, read by a Manager:
 * kitchen pipe (Rina's Unit in Block A, unclassified, Low), lift (Block A, Lift, safety risk, Critical),
 * pool (Shared Area, Common Facilities, Low, Closed) and lobby (Block B, unclassified, Medium).
 */
function officeFixture(): array
{
    $setup = complaintsSetup();
    $blockB = place(LocationType::Block, null, 'B');
    $lobbyB = place(LocationType::CommonArea, $blockB, 'B-LOBBY', LocationImportance::Important);
    $at = now()->subHour();
    $submit = function (array $body) use (&$at) {
        test()->travelTo($at = $at->addMinute());
        $complaint = submitComplaint(complaintBody($body))->assertCreated()->json('data');
        test()->travelBack();

        return $complaint;
    };

    $pipe = $submit(['my_unit' => true, 'title' => 'Leaking kitchen pipe']);
    $lift = $submit(['location_id' => $setup['lift']->id, 'title' => 'Lift stuck with people inside', 'urgency' => 'critical', 'safety_risk' => true,
        'category_id' => Category::where('key', 'lift')->value('id')]);
    $pool = $submit(['location_id' => $setup['pool']->id, 'title' => 'Pool filter broken', 'category_id' => Category::where('key', 'facilities')->value('id')]);
    $lobby = $submit(['location_id' => $lobbyB->id, 'title' => 'Lobby door broken', 'urgency' => 'medium']);   // 15 + important 10 = 25, Medium

    // Setup only: no endpoint can close a Complaint until Phase 3.
    Complaint::whereKey($pool['id'])->update(['status' => ComplaintStatus::Closed]);

    test()->actingAs(account(Role::Manager));

    return [...$setup, 'blockB' => $blockB, 'pipe' => $pipe, 'liftComplaint' => $lift, 'poolComplaint' => $pool, 'lobby' => $lobby];
}

function officeTitles(string $query): array
{
    return test()->getJson("/api/v1/complaints?{$query}")->assertOk()->json('data.*.title');
}

it('filters the office list', function (Closure $query, array $titles) {
    $fixture = officeFixture();

    expect(officeTitles($query($fixture)))->toBe($titles);
})->with([
    'status' => [fn () => 'status=closed', ['Pool filter broken']],
    'Priority' => [fn () => 'priority=critical', ['Lift stuck with people inside']],
    'Category' => [fn () => 'category='.Category::where('key', 'lift')->value('id'), ['Lift stuck with people inside']],
    'unclassified' => [fn () => 'category=unclassified', ['Lobby door broken', 'Leaking kitchen pipe']],
    'Block, through Floors to Units and Common Areas' => [fn ($f) => "block={$f['block']->id}", ['Lift stuck with people inside', 'Leaking kitchen pipe']],
    'another Block' => [fn ($f) => "block={$f['blockB']->id}", ['Lobby door broken']],
    'exact Location' => [fn ($f) => "location={$f['pool']->id}", ['Pool filter broken']],
    'safety risk' => [fn () => 'safety_risk=1', ['Lift stuck with people inside']],
    'no safety risk' => [fn () => 'safety_risk=0', ['Lobby door broken', 'Pool filter broken', 'Leaking kitchen pipe']],
    'search by title' => [fn () => 'q=pool', ['Pool filter broken']],
    'search by reference' => [fn ($f) => "q={$f['liftComplaint']['reference_no']}", ['Lift stuck with people inside']],
    'filters combine' => [fn ($f) => "block={$f['block']->id}&safety_risk=0", ['Leaking kitchen pipe']],
]);

it('rejects unknown filter values', function (Closure $query, string $field) {
    $fixture = officeFixture();

    $this->getJson('/api/v1/complaints?'.$query($fixture))->assertStatus(422)->assertJsonValidationErrors($field);
})->with([
    'status' => [fn () => 'status=open', 'status'],
    'Priority' => [fn () => 'priority=urgent', 'priority'],
    'Category' => [fn () => 'category=plumbing', 'category'],
    'a Unit as Block' => [fn ($f) => "block={$f['unit']->id}", 'block'],
    'Location' => [fn () => 'location=999999', 'location'],
    'safety risk' => [fn () => 'safety_risk=maybe', 'safety_risk'],
]);

it('sorts the office list by an allowlisted field, descending with a leading minus', function (string $sort, array $titles) {
    officeFixture();

    expect(officeTitles("sort={$sort}"))->toBe($titles);
})->with([
    'most urgent Priority first' => ['-priority', ['Lift stuck with people inside', 'Lobby door broken', 'Pool filter broken', 'Leaking kitchen pipe']],
    'least urgent Priority first' => ['priority', ['Pool filter broken', 'Leaking kitchen pipe', 'Lobby door broken', 'Lift stuck with people inside']],
    'oldest first' => ['created_at', ['Leaking kitchen pipe', 'Lift stuck with people inside', 'Pool filter broken', 'Lobby door broken']],
    'newest first' => ['-created_at', ['Lobby door broken', 'Pool filter broken', 'Lift stuck with people inside', 'Leaking kitchen pipe']],
    'reference' => ['reference_no', ['Leaking kitchen pipe', 'Lift stuck with people inside', 'Pool filter broken', 'Lobby door broken']],
    'reference, descending' => ['-reference_no', ['Lobby door broken', 'Pool filter broken', 'Lift stuck with people inside', 'Leaking kitchen pipe']],
]);

it('rejects sorts that are not allowlisted', function () {
    officeFixture();

    $this->getJson('/api/v1/complaints?sort=title')->assertStatus(422)->assertJsonValidationErrors('sort');
});

it('keeps a Resident inside their own Complaints whatever filters they send', function () {
    ['block' => $block, 'neighbour' => $neighbour] = officeFixture();   // all four were reported by Rina

    $this->actingAs(account(Role::Resident, ['unit_id' => $neighbour->id]));
    expect(officeTitles("block={$block->id}"))->toBe([])
        ->and(officeTitles('q=lift&sort=-priority'))->toBe([]);
});
