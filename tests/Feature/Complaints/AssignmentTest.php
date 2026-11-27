<?php

use App\Enums\ComplaintStatus;
use App\Enums\Role;
use App\Models\Category;

require_once __DIR__.'/helpers.php';

it('assigns a Technician to a Complaint Under Review, which becomes Assigned', function () {
    ['complaint' => $complaint, 'block' => $block, 'manager' => $manager] = assignmentSetup();
    $tariq = technicianProfile('Tariq Technician', $block->id);

    assign($complaint, ['technician_id' => $tariq['id']])->assertOk()
        ->assertJsonPath('data.status', 'assigned')
        ->assertJsonPath('data.assignment.technician', ['id' => $tariq['id'], 'name' => 'Tariq Technician'])
        ->assertJsonPath('data.assignment.assigned_by', ['id' => $manager->id, 'name' => 'Maya Manager'])
        ->assertJsonPath('data.assignment.override_reason', null);

    expect(timelineOf($complaint, 'status'))->toHaveCount(3)
        ->and(collect(timelineOf($complaint, 'status'))->last())->toMatchArray(['from_status' => 'under_review', 'to_status' => 'assigned'])
        ->and(timelineOf($complaint, 'assignment')[0])->toMatchArray([
            'technician' => ['id' => $tariq['id'], 'name' => 'Tariq Technician'],
            'previous_technician' => null, 'reason' => null, 'override_reason' => null,
            'actor' => ['id' => $manager->id, 'name' => 'Maya Manager'],
        ]);
});

it('saves the optional assignment note as an Internal Comment', function () {
    ['complaint' => $complaint, 'block' => $block, 'rina' => $rina, 'manager' => $manager] = assignmentSetup();
    $tariq = technicianProfile('Tariq Technician', $block->id);

    assign($complaint, ['technician_id' => $tariq['id'], 'note' => 'Key is with the guard house.'])->assertOk();

    expect(timelineOf($complaint, 'comment'))->toHaveCount(1)
        ->and(timelineOf($complaint, 'comment')[0])->toMatchArray([
            'visibility' => 'internal', 'body' => 'Key is with the guard house.',
            'author' => ['id' => $manager->id, 'name' => 'Maya Manager', 'role' => 'manager'],
        ]);
    $this->actingAs($rina);
    expect(timelineOf($complaint, 'comment'))->toBe([]);
});

it('shows the reporter the status change but not the Assignment details', function () {
    ['complaint' => $complaint, 'block' => $block, 'rina' => $rina] = assignmentSetup();
    assign($complaint, ['technician_id' => technicianProfile('Tariq Technician', $block->id)['id']])->assertOk();

    $this->actingAs($rina);
    $this->getJson("/api/v1/complaints/{$complaint}")->assertOk()
        ->assertJsonPath('data.status', 'assigned')
        ->assertJsonMissingPath('data.assignment');
    expect(timelineOf($complaint, 'assignment'))->toBe([]);
});

it('records the Assignment in the audit log', function () {
    ['complaint' => $complaint, 'block' => $block, 'manager' => $manager] = assignmentSetup();
    $tariq = technicianProfile('Tariq Technician', $block->id);
    assign($complaint, ['technician_id' => $tariq['id']]);

    $this->actingAs(account(Role::Administrator));
    $entry = $this->getJson('/api/v1/admin/audit-logs?action=complaint.assigned')->assertOk()->json('data.0');
    expect($entry)->toMatchArray(['target_type' => 'complaint', 'target_id' => $complaint])
        ->and($entry['actor']['id'])->toBe($manager->id)
        ->and($entry['changes']['status'])->toBe(['from' => 'under_review', 'to' => 'assigned'])
        ->and($entry['metadata'])->toBe(['technician_id' => $tariq['id']]);
});

it('assigns a Busy Technician', function () {
    ['complaint' => $complaint, 'block' => $block] = assignmentSetup();

    assign($complaint, ['technician_id' => technicianProfile('Bella Busy', $block->id, ['availability' => 'busy'])['id']])
        ->assertOk()->assertJsonPath('data.status', 'assigned');
});

it('assigns a Technician without the matching Skill', function () {
    ['complaint' => $complaint, 'block' => $block] = assignmentSetup();

    assign($complaint, ['technician_id' => technicianProfile('Cara Electrician', $block->id, ['skills' => ['electrical']])['id']])
        ->assertOk();
});

it('refuses a Technician who is On leave or whose account is deactivated', function () {
    ['complaint' => $complaint, 'block' => $block, 'manager' => $manager] = assignmentSetup();
    $olga = technicianProfile('Olga On leave', $block->id, ['availability' => 'on_leave']);
    $ivan = technicianProfile('Ivan Inactive', $block->id);
    $this->actingAs(account(Role::Administrator))->patchJson("/api/v1/admin/users/{$ivan['user']->id}", ['active' => false])->assertOk();
    $this->actingAs($manager);

    assign($complaint, ['technician_id' => $olga['id']])->assertUnprocessable()
        ->assertJsonValidationErrors(['technician_id' => 'Olga On leave is on leave and cannot take new work.']);
    assign($complaint, ['technician_id' => $ivan['id']])->assertUnprocessable()
        ->assertJsonValidationErrors(['technician_id' => 'Ivan Inactive’s account is deactivated.']);
    assign($complaint, ['technician_id' => 999999])->assertUnprocessable()->assertJsonValidationErrors('technician_id');
});

it('needs an explicit override with a reason to assign a Technician at maximum tasks', function () {
    ['complaint' => $complaint, 'block' => $block] = $setup = assignmentSetup();
    $tariq = technicianProfile('Tariq Technician', $block->id, ['max_tasks' => 1]);
    assign(classifiedComplaint($setup), ['technician_id' => $tariq['id']])->assertOk();

    assign($complaint, ['technician_id' => $tariq['id']])->assertUnprocessable()
        ->assertJsonValidationErrors(['override_capacity' => 'Tariq Technician is at maximum tasks (1 of 1). Confirm the override to assign anyway.']);
    assign($complaint, ['technician_id' => $tariq['id'], 'override_capacity' => true])->assertUnprocessable()
        ->assertJsonValidationErrors(['override_reason' => 'Give a reason for assigning above maximum tasks.']);

    assign($complaint, ['technician_id' => $tariq['id'], 'override_capacity' => true, 'override_reason' => 'Only lift specialist on site.'])
        ->assertOk()->assertJsonPath('data.assignment.override_reason', 'Only lift specialist on site.');
    expect(timelineOf($complaint, 'assignment')[0]['override_reason'])->toBe('Only lift specialist on site.');

    $this->actingAs(account(Role::Administrator));
    expect($this->getJson('/api/v1/admin/audit-logs?action=complaint.assigned')->json('data.0.metadata'))
        ->toBe(['technician_id' => $tariq['id'], 'override_reason' => 'Only lift specialist on site.']);
});

it('ignores an override for a Technician below maximum tasks', function () {
    ['complaint' => $complaint, 'block' => $block] = assignmentSetup();

    assign($complaint, ['technician_id' => technicianProfile('Tariq Technician', $block->id)['id'], 'override_capacity' => true, 'override_reason' => 'Just in case.'])
        ->assertOk()->assertJsonPath('data.assignment.override_reason', null);
});

it('asks for a Category before the first Assignment', function () {
    $setup = complaintsSetup();
    $id = submitComplaint(complaintBody(['location_id' => $setup['lift']->id]))->json('data.id');
    $this->actingAs(account(Role::Manager));
    $this->postJson("/api/v1/complaints/{$id}/review", ['version' => loadedVersion($id)])->assertOk();

    assign($id, ['technician_id' => 1])->assertConflict()
        ->assertJsonPath('message', 'Classify this Complaint before assigning a Technician.');
});

it('refuses to assign before review has begun', function () {
    $setup = complaintsSetup();
    $id = submitComplaint(complaintBody(['location_id' => $setup['lift']->id]))->json('data.id');
    $this->actingAs(account(Role::Manager));

    assign($id, [])->assertConflict()->assertJsonPath('message', 'Begin review before assigning a Technician.');
});

it('refuses to assign once the work is with the office or done', function (ComplaintStatus $status, string $label) {
    ['complaint' => $complaint, 'block' => $block] = assignmentSetup();
    $tariq = technicianProfile('Tariq Technician', $block->id);
    assign($complaint, ['technician_id' => $tariq['id']])->assertOk();
    forceStatus($complaint, $status);

    assign($complaint, ['technician_id' => technicianProfile('Una Other', $block->id)['id'], 'reason' => 'x'])->assertConflict()
        ->assertJsonPath('message', "This Complaint is already {$label}.");
})->with([
    [ComplaintStatus::PendingVerification, 'Pending Verification'],
    [ComplaintStatus::Resolved, 'Resolved'],
    [ComplaintStatus::Closed, 'Closed'],
]);

it('refuses an Assignment sent from a stale copy', function () {
    ['complaint' => $complaint, 'block' => $block] = assignmentSetup();
    $tariq = technicianProfile('Tariq Technician', $block->id);
    $version = loadedVersion($complaint);

    assign($complaint, ['technician_id' => $tariq['id'], 'version' => $version + 1])->assertConflict()
        ->assertJsonPath('message', 'Someone else changed this Complaint after you opened it (it is now Under Review). Reload it to see the latest.');
    assign($complaint, [])->assertUnprocessable()->assertJsonValidationErrors('technician_id');
    $this->postJson("/api/v1/complaints/{$complaint}/assignments", ['technician_id' => $tariq['id']])
        ->assertUnprocessable()->assertJsonValidationErrors('version');
});

it('conceals a Complaint that does not exist before validating', function () {
    assignmentSetup();

    assign(999999, [])->assertNotFound();
});

it('lets only a Manager assign', function (Role $role) {
    ['complaint' => $complaint, 'block' => $block] = assignmentSetup();
    $tariq = technicianProfile('Tariq Technician', $block->id);
    $this->actingAs(account($role));

    assign($complaint, ['technician_id' => $tariq['id']])->assertForbidden();
})->with([Role::Resident, Role::Technician, Role::Administrator]);

it('warns, without refusing, when a new Category is one the assigned Technician has no Skill for', function () {
    ['complaint' => $complaint, 'block' => $block] = $setup = assignmentSetup();
    assign($complaint, ['technician_id' => technicianProfile('Tariq Technician', $block->id)['id']])->assertOk();
    $electrical = Category::where('key', 'electrical')->firstOrFail();

    $this->patchJson("/api/v1/complaints/{$complaint}/classification", ['category_id' => $electrical->id, 'priority' => 'low', 'version' => loadedVersion($complaint)])
        ->assertOk()
        ->assertJsonPath('data.category.id', $electrical->id)
        ->assertJsonPath('warnings', ['Tariq Technician has no '.$electrical->name.' Skill. Reassign the Complaint if they cannot do this work.']);
    $this->patchJson("/api/v1/complaints/{$complaint}/classification", ['category_id' => $setup['liftCategory']->id, 'priority' => 'low', 'version' => loadedVersion($complaint)])
        ->assertOk()->assertJsonPath('warnings', []);
});
