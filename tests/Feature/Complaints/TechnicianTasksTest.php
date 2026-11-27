<?php

use App\Enums\ComplaintStatus;
use App\Enums\Role;

require_once __DIR__.'/helpers.php';

function tasks()
{
    return test()->getJson('/api/v1/technician/tasks');
}

it('groups the Technician’s Complaints as New, In progress, Returned and Completed', function () {
    ['complaint' => $complaint, 'manager' => $manager, 'tariq' => $tariq] = $setup = technicianWorkSetup();
    $this->actingAs($manager);
    $ids = ['new' => $complaint];
    foreach (['in_progress' => ComplaintStatus::InProgress, 'returned' => ComplaintStatus::Returned, 'pending' => ComplaintStatus::PendingVerification, 'resolved' => ComplaintStatus::Resolved, 'closed' => ComplaintStatus::Closed] as $key => $status) {
        $ids[$key] = classifiedComplaint($setup);
        assign($ids[$key], ['technician_id' => $tariq['id']])->assertOk();
        // No endpoint reaches these statuses until later Phase 3 tickets, so the status is set directly.
        forceStatus($ids[$key], $status);
    }
    classifiedComplaint($setup);   // not assigned to anyone

    $this->actingAs($tariq['user']);
    $groups = tasks()->assertOk()->json('data');

    expect(array_keys($groups))->toBe(['new', 'in_progress', 'returned', 'completed'])
        ->and(array_column($groups['new'], 'id'))->toBe([$ids['new']])
        ->and(array_column($groups['in_progress'], 'id'))->toBe([$ids['in_progress']])
        ->and(array_column($groups['returned'], 'id'))->toBe([$ids['returned']])
        ->and(array_column($groups['completed'], 'id'))->toEqualCanonicalizing([$ids['pending'], $ids['resolved'], $ids['closed']])
        ->and($groups['new'][0])->toHaveKey('reference_no')->toMatchArray(['priority' => 'low', 'status' => 'assigned'])
        ->and($groups['new'][0]['location'])->toMatchArray(['code' => 'A-LIFT-1'])
        ->and($groups['new'][0]['assigned_at'])->not->toBeNull();
});

it('lists the most urgent first, then the longest waiting', function () {
    ['complaint' => $first, 'manager' => $manager, 'tariq' => $tariq] = $setup = technicianWorkSetup();
    $this->actingAs($manager);
    $second = classifiedComplaint($setup);
    $urgent = classifiedComplaint($setup);
    $this->patchJson("/api/v1/complaints/{$urgent}/classification", [
        'category_id' => $setup['liftCategory']->id, 'priority' => 'critical', 'priority_reason' => 'Trapped resident.', 'version' => loadedVersion($urgent),
    ])->assertOk();
    $this->travel(1)->minutes();
    assign($second, ['technician_id' => $tariq['id']])->assertOk();
    assign($urgent, ['technician_id' => $tariq['id']])->assertOk();

    $this->actingAs($tariq['user']);
    expect(array_column(tasks()->json('data.new'), 'id'))->toBe([$urgent, $first, $second]);
});

it('drops a Complaint from the list once it is reassigned to someone else', function () {
    ['complaint' => $complaint, 'manager' => $manager, 'tariq' => $tariq, 'una' => $una] = technicianWorkSetup();
    $this->actingAs($manager);
    assign($complaint, ['technician_id' => $una['id'], 'reason' => 'Tariq is off sick.'])->assertOk();

    $this->actingAs($tariq['user']);
    expect(tasks()->json('data.new'))->toBe([]);
    $this->actingAs($una['user']);
    expect(array_column(tasks()->json('data.new'), 'id'))->toBe([$complaint]);
});

it('gives a Technician without a profile an empty list', function () {
    technicianWorkSetup();
    $this->actingAs(account(Role::Technician));

    tasks()->assertOk()->assertExactJson(['data' => ['new' => [], 'in_progress' => [], 'returned' => [], 'completed' => []]]);
});

it('keeps the task list to Technicians', function (Role $role) {
    $this->actingAs(account($role));

    tasks()->assertForbidden();
})->with([Role::Resident, Role::Manager, Role::Administrator]);
