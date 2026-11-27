<?php

use App\Enums\ComplaintStatus;
use App\Enums\Role;
use Illuminate\Support\Facades\Storage;

require_once __DIR__.'/helpers.php';

beforeEach(fn () => Storage::fake('evidence'));

it('approves the work: Pending Verification becomes Resolved and the record is verified', function () {
    ['complaint' => $complaint, 'manager' => $manager] = pendingVerificationSetup();

    $data = verifyWork($complaint, ['decision' => 'approve'])->assertOk()
        ->assertJsonPath('data.status', 'resolved')
        ->assertJsonPath('data.resolved_at', fn (?string $at) => $at !== null)
        ->json('data');

    expect($data['maintenance_records'][0])->toMatchArray([
        'verification_outcome' => 'approved',
        'verification_reason' => null,
        'verified_by' => ['id' => $manager->id, 'name' => 'Maya Manager'],
    ])->and($data['maintenance_records'][0]['verified_at'])->not->toBeNull()
        ->and(collect(timelineOf($complaint, 'status'))->last())->toMatchArray([
            'from_status' => 'pending_verification', 'to_status' => 'resolved',
            'actor' => ['id' => $manager->id, 'name' => 'Maya Manager'],
        ]);
});

it('shows the reporter the approved repair once Resolved', function () {
    ['complaint' => $complaint, 'rina' => $rina] = pendingVerificationSetup();
    verifyWork($complaint, ['decision' => 'approve'])->assertOk();

    $this->actingAs($rina);
    $record = $this->getJson("/api/v1/complaints/{$complaint}")->assertOk()->json('data.maintenance_records.0');
    expect($record['repair_result'])->toBe('Replaced the door sensor; the lift runs normally.')
        ->and($record['photo_ids'])->toHaveCount(2);
});

it('returns the work with a reason to the same Technician, in the same Repair Cycle', function () {
    ['complaint' => $complaint, 'tariq' => $tariq] = pendingVerificationSetup();

    $data = verifyWork($complaint, ['decision' => 'return', 'reason' => 'The door still sticks on floor 3.'])->assertOk()
        ->assertJsonPath('data.status', 'returned')
        ->assertJsonPath('data.resolved_at', null)
        ->assertJsonPath('data.assignment.technician.id', $tariq['id'])
        ->json('data');

    expect($data['repair_cycles'])->toHaveCount(1)
        ->and($data['maintenance_records'][0])->toMatchArray([
            'verification_outcome' => 'returned', 'verification_reason' => 'The door still sticks on floor 3.',
        ]);

    // The Technician sees why, starts again, and the next record is attempt 2 in cycle 1.
    $this->actingAs($tariq['user']);
    expect($this->getJson("/api/v1/complaints/{$complaint}")->json('data.maintenance_records.0.verification_reason'))
        ->toBe('The door still sticks on floor 3.');
    startWork($complaint)->assertOk();
    $records = completeWork($complaint, completionBody())->assertOk()->json('data.maintenance_records');
    expect(array_column($records, 'attempt'))->toBe([1, 2])
        ->and(array_column($records, 'repair_cycle'))->toBe([1, 1]);
});

it('keeps a Manager Rejection’s reason from the reporter', function () {
    ['complaint' => $complaint, 'rina' => $rina] = pendingVerificationSetup();
    verifyWork($complaint, ['decision' => 'return', 'reason' => 'Sloppy work by the Technician.'])->assertOk();

    $this->actingAs($rina);
    $response = $this->getJson("/api/v1/complaints/{$complaint}")->assertOk()->assertJsonPath('data.status', 'returned');
    expect($response->getContent())->not->toContain('Sloppy work')
        ->and($response->json('data.maintenance_records'))->toBe([]);
});

it('requires a reason to return the work, and a known decision', function () {
    ['complaint' => $complaint] = pendingVerificationSetup();

    verifyWork($complaint, ['decision' => 'return', 'reason' => '  '])->assertUnprocessable()
        ->assertJsonValidationErrors(['reason' => 'Give a reason for returning the work to the Technician.']);
    verifyWork($complaint, ['decision' => 'maybe'])->assertUnprocessable()->assertJsonValidationErrors('decision');
});

it('records the verification in the audit log', function () {
    ['complaint' => $complaint, 'manager' => $manager, 'record' => $record] = pendingVerificationSetup();
    verifyWork($complaint, ['decision' => 'return', 'reason' => 'Door still sticks.']);

    $this->actingAs(account(Role::Administrator));
    $entry = $this->getJson('/api/v1/admin/audit-logs?action=complaint.verified')->assertOk()->json('data.0');
    expect($entry)->toMatchArray(['target_type' => 'complaint', 'target_id' => $complaint])
        ->and($entry['actor']['id'])->toBe($manager->id)
        ->and($entry['changes']['status'])->toBe(['from' => 'pending_verification', 'to' => 'returned'])
        ->and($entry['metadata'])->toBe(['decision' => 'return', 'maintenance_record_id' => $record['id'], 'reason' => 'Door still sticks.']);
});

it('refuses verification outside Pending Verification', function (ComplaintStatus $status) {
    ['complaint' => $complaint] = pendingVerificationSetup();
    // No endpoint reaches some of these statuses until later Phase 3 tickets, so the status is set directly.
    forceStatus($complaint, $status);

    verifyWork($complaint, ['decision' => 'approve'])->assertConflict()
        ->assertJsonPath('message', "This Complaint is {$status->label()}, not Pending Verification.");
})->with([ComplaintStatus::InProgress, ComplaintStatus::Returned, ComplaintStatus::Resolved, ComplaintStatus::Closed]);

it('answers 409, not a validation error, when nothing waits for verification', function () {
    ['complaint' => $complaint] = technicianWorkSetup();
    $this->actingAs(account(Role::Manager));

    verifyWork($complaint, [])->assertConflict();
});

it('refuses a verification sent from a stale copy', function () {
    ['complaint' => $complaint] = pendingVerificationSetup();

    verifyWork($complaint, ['decision' => 'approve', 'version' => loadedVersion($complaint) + 1])->assertConflict();
    verifyWork($complaint, ['decision' => 'approve', 'version' => null])->assertUnprocessable()->assertJsonValidationErrors('version');
});

it('conceals a Complaint that does not exist', function () {
    pendingVerificationSetup();

    verifyWork(999999, ['decision' => 'approve'])->assertNotFound();
});

it('lets only a Manager verify', function (Role $role) {
    ['complaint' => $complaint] = pendingVerificationSetup();
    $this->actingAs(account($role));

    verifyWork($complaint, ['decision' => 'approve'])->assertForbidden();
})->with([Role::Resident, Role::Technician, Role::Administrator]);
