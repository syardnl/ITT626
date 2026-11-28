<?php

use App\Enums\ComplaintStatus;
use App\Enums\Role;

require_once __DIR__.'/helpers.php';

it('lets the assigned Technician start work on an Assigned Complaint', function () {
    ['complaint' => $complaint, 'tariq' => $tariq] = technicianWorkSetup();

    startWork($complaint)->assertOk()
        ->assertJsonPath('data.status', 'in_progress')
        ->assertJsonPath('data.started_at', fn (?string $at) => $at !== null);

    expect(collect(timelineOf($complaint, 'status'))->last())->toMatchArray([
        'from_status' => 'assigned', 'to_status' => 'in_progress',
        'actor' => ['id' => $tariq['user']->id, 'name' => 'Tariq Technician'],
    ]);
});

it('starts work again on a Returned Complaint, keeping the first start time', function () {
    ['complaint' => $complaint] = technicianWorkSetup();
    $first = startWork($complaint)->assertOk()->json('data.started_at');
    // No endpoint returns work until later Phase 3 tickets, so the status is set directly.
    forceStatus($complaint, ComplaintStatus::Returned);
    $this->travel(5)->minutes();

    startWork($complaint)->assertOk()
        ->assertJsonPath('data.status', 'in_progress')
        ->assertJsonPath('data.started_at', $first);
});

it('records the start of work in the audit log', function () {
    ['complaint' => $complaint, 'tariq' => $tariq] = technicianWorkSetup();
    startWork($complaint)->assertOk();

    $this->actingAs(account(Role::Administrator));
    $entry = $this->getJson('/api/v1/admin/audit-logs?action=complaint.work_started')->assertOk()->json('data.0');
    expect($entry)->toMatchArray(['target_type' => 'complaint', 'target_id' => $complaint])
        ->and($entry['actor']['id'])->toBe($tariq['user']->id)
        ->and($entry['changes']['status'])->toBe(['from' => 'assigned', 'to' => 'in_progress'])
        ->and($entry['metadata'])->toHaveKeys(['assignment_id', 'started_at']);
});

it('refuses to start work that is already In Progress or with the office', function (ComplaintStatus $status) {
    ['complaint' => $complaint] = technicianWorkSetup();
    // No endpoint reaches these statuses until later Phase 3 tickets, so the status is set directly.
    forceStatus($complaint, $status);

    startWork($complaint)->assertConflict()->assertJsonPath('message', "This Complaint is already {$status->label()}.");
})->with([ComplaintStatus::InProgress, ComplaintStatus::PendingVerification, ComplaintStatus::Resolved, ComplaintStatus::Closed]);

it('refuses a start sent from a stale copy', function () {
    ['complaint' => $complaint] = technicianWorkSetup();

    startWork($complaint, loadedVersion($complaint) + 1)->assertConflict();
    $this->postJson("/api/v1/complaints/{$complaint}/start-work")->assertUnprocessable()->assertJsonValidationErrors('version');
});

it('conceals the Complaint from a Technician it is not assigned to', function () {
    ['complaint' => $complaint, 'una' => $una] = technicianWorkSetup();
    $this->actingAs($una['user']);

    startWork($complaint, 1)->assertNotFound();
});

it('refuses a Technician who was reassigned away', function () {
    ['complaint' => $complaint, 'manager' => $manager, 'tariq' => $tariq, 'una' => $una] = technicianWorkSetup();
    $this->actingAs($manager);
    assign($complaint, ['technician_id' => $una['id'], 'reason' => 'Tariq is off sick.'])->assertOk();

    $this->actingAs($tariq['user']);
    startWork($complaint, loadedVersion($complaint))->assertForbidden();
});

it('lets only Technicians start work', function (Role $role) {
    ['complaint' => $complaint] = technicianWorkSetup();
    $this->actingAs(account($role));

    startWork($complaint, 1)->assertForbidden();
})->with([Role::Resident, Role::Manager, Role::Administrator]);
