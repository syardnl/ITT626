<?php

use App\Enums\ComplaintStatus;
use App\Enums\Role;

require_once __DIR__.'/helpers.php';

/** assignmentSetup() with the Complaint assigned to Tariq, and Una, another Lift Technician. */
function reassignmentSetup(): array
{
    $setup = assignmentSetup();
    $tariq = technicianProfile('Tariq Technician', $setup['block']->id);
    $una = technicianProfile('Una Other', $setup['block']->id);
    assign($setup['complaint'], ['technician_id' => $tariq['id']])->assertOk();

    return [...$setup, 'tariq' => $tariq, 'una' => $una];
}

it('reassigns with a reason: the current Assignment ends and a new one starts', function (ComplaintStatus $from) {
    ['complaint' => $complaint, 'tariq' => $tariq, 'una' => $una, 'manager' => $manager] = reassignmentSetup();
    $from === ComplaintStatus::Assigned || forceStatus($complaint, $from);

    assign($complaint, ['technician_id' => $una['id'], 'reason' => 'Tariq is off sick.'])->assertOk()
        ->assertJsonPath('data.status', 'assigned')
        ->assertJsonPath('data.assignment.technician', ['id' => $una['id'], 'name' => 'Una Other']);

    $assignments = timelineOf($complaint, 'assignment');
    expect($assignments)->toHaveCount(2)
        ->and($assignments[1])->toMatchArray([
            'technician' => ['id' => $una['id'], 'name' => 'Una Other'],
            'previous_technician' => ['id' => $tariq['id'], 'name' => 'Tariq Technician'],
            'reason' => 'Tariq is off sick.',
            'actor' => ['id' => $manager->id, 'name' => 'Maya Manager'],
        ]);
})->with([ComplaintStatus::Assigned, ComplaintStatus::InProgress, ComplaintStatus::Returned]);

it('returns an In Progress or Returned Complaint to Assigned, keeping the staff-only reason from the reporter', function (ComplaintStatus $from) {
    ['complaint' => $complaint, 'una' => $una, 'rina' => $rina] = reassignmentSetup();
    forceStatus($complaint, $from);

    assign($complaint, ['technician_id' => $una['id'], 'reason' => 'Tariq is off sick.'])->assertOk();

    $this->actingAs($rina);
    $response = $this->getJson("/api/v1/complaints/{$complaint}")->assertOk();
    expect(collect($response->json('data.timeline'))->where('type', 'status')->last())->toMatchArray([
        'from_status' => $from->value, 'to_status' => 'assigned', 'reason' => null,
    ])->and($response->getContent())->not->toContain('Tariq is off sick.');
})->with([ComplaintStatus::InProgress, ComplaintStatus::Returned]);

it('adds no status change when reassigning an Assigned Complaint', function () {
    ['complaint' => $complaint, 'una' => $una] = reassignmentSetup();
    $before = count(timelineOf($complaint, 'status'));

    assign($complaint, ['technician_id' => $una['id'], 'reason' => 'Closer to Block A.'])->assertOk();

    expect(timelineOf($complaint, 'status'))->toHaveCount($before);
});

it('moves the Active Task from the old Technician to the new one', function () {
    ['complaint' => $complaint, 'una' => $una] = reassignmentSetup();

    assign($complaint, ['technician_id' => $una['id'], 'reason' => 'Tariq is off sick.'])->assertOk();

    $tasks = collect(recommendationsFor($complaint)->json('data'))->mapWithKeys(fn (array $c) => [$c['technician']['name'] => $c['active_tasks']]);
    expect($tasks->all())->toEqual(['Tariq Technician' => 0, 'Una Other' => 1]);
});

it('requires a reason to reassign', function () {
    ['complaint' => $complaint, 'una' => $una] = reassignmentSetup();

    assign($complaint, ['technician_id' => $una['id']])->assertUnprocessable()
        ->assertJsonValidationErrors(['reason' => 'Give a reason for reassigning this Complaint.']);
    assign($complaint, ['technician_id' => $una['id'], 'reason' => '   '])->assertUnprocessable()->assertJsonValidationErrors('reason');
});

it('refuses to reassign to the Technician who already has it', function () {
    ['complaint' => $complaint, 'tariq' => $tariq] = reassignmentSetup();

    assign($complaint, ['technician_id' => $tariq['id'], 'reason' => 'x'])->assertUnprocessable()
        ->assertJsonValidationErrors(['technician_id' => 'This Complaint is already assigned to Tariq Technician.']);
});

it('applies the capacity override and the On leave refusal to reassignment too', function () {
    ['complaint' => $complaint, 'block' => $block] = $setup = reassignmentSetup();
    $fred = technicianProfile('Fred Full', $block->id, ['max_tasks' => 1]);
    $olga = technicianProfile('Olga On leave', $block->id, ['availability' => 'on_leave']);
    assign(classifiedComplaint($setup), ['technician_id' => $fred['id']])->assertOk();

    assign($complaint, ['technician_id' => $olga['id'], 'reason' => 'x'])->assertUnprocessable()->assertJsonValidationErrors('technician_id');
    assign($complaint, ['technician_id' => $fred['id'], 'reason' => 'x'])->assertUnprocessable()->assertJsonValidationErrors('override_capacity');
    assign($complaint, ['technician_id' => $fred['id'], 'reason' => 'x', 'override_capacity' => true, 'override_reason' => 'Urgent.'])->assertOk();
});

it('records the reassignment in the audit log', function () {
    ['complaint' => $complaint, 'tariq' => $tariq, 'una' => $una] = reassignmentSetup();
    assign($complaint, ['technician_id' => $una['id'], 'reason' => 'Tariq is off sick.']);

    $this->actingAs(account(Role::Administrator));
    expect($this->getJson('/api/v1/admin/audit-logs?action=complaint.reassigned')->assertOk()->json('data.0.metadata'))->toBe([
        'technician_id' => $una['id'], 'previous_technician_id' => $tariq['id'], 'reason' => 'Tariq is off sick.',
    ]);
});

it('refuses reassignment at Pending Verification, Resolved and Closed', function (ComplaintStatus $status) {
    ['complaint' => $complaint, 'una' => $una] = reassignmentSetup();
    forceStatus($complaint, $status);

    assign($complaint, ['technician_id' => $una['id'], 'reason' => 'x'])->assertConflict()
        ->assertJsonPath('message', "This Complaint is already {$status->label()}.");
})->with([ComplaintStatus::PendingVerification, ComplaintStatus::Resolved, ComplaintStatus::Closed]);
