<?php

use App\Enums\Role;

require_once __DIR__.'/helpers.php';

/** Rina's Complaint at the Block A lift, and a Manager signed in. */
function reviewSetup(): array
{
    $setup = complaintsSetup();
    $complaint = submitComplaint(complaintBody(['location_id' => $setup['lift']->id]))->json('data');
    test()->actingAs($manager = account(Role::Manager, ['name' => 'Maya Manager']));

    return [...$setup, 'complaint' => $complaint, 'manager' => $manager];
}

function beginReview(int $complaintId)
{
    return test()->postJson("/api/v1/complaints/{$complaintId}/review", ['version' => loadedVersion($complaintId)]);
}

it('lets a Manager begin review of a Reported Complaint', function () {
    ['complaint' => $complaint, 'manager' => $manager] = reviewSetup();

    beginReview($complaint['id'])->assertOk()
        ->assertJsonPath('data.status', 'under_review')
        ->assertJsonPath('data.reviewed_at', fn (?string $at) => $at !== null);

    $timeline = $this->getJson("/api/v1/complaints/{$complaint['id']}")->json('data.timeline');
    expect(end($timeline))->toMatchArray([
        'type' => 'status', 'from_status' => 'reported', 'to_status' => 'under_review',
        'actor' => ['id' => $manager->id, 'name' => 'Maya Manager'],
    ]);
});

it('does not count opening a Complaint as a review', function () {
    ['complaint' => $complaint] = reviewSetup();

    $this->getJson("/api/v1/complaints/{$complaint['id']}")->assertOk()
        ->assertJsonPath('data.status', 'reported')
        ->assertJsonPath('data.reviewed_at', null);
});

it('answers 409 naming the current status when review has already begun', function () {
    ['complaint' => $complaint] = reviewSetup();
    beginReview($complaint['id'])->assertOk();

    beginReview($complaint['id'])->assertConflict()
        ->assertJsonPath('message', 'This Complaint is already Under Review.');
});

it('records the review in the audit log', function () {
    ['complaint' => $complaint, 'manager' => $manager] = reviewSetup();
    beginReview($complaint['id']);

    $this->actingAs(account(Role::Administrator));
    $entry = $this->getJson('/api/v1/admin/audit-logs?action=complaint.reviewed')->assertOk()->json('data.0');
    expect($entry)->toMatchArray(['target_type' => 'complaint', 'target_id' => $complaint['id']])
        ->and($entry['actor']['id'])->toBe($manager->id)
        ->and($entry['changes']['status'])->toBe(['from' => 'reported', 'to' => 'under_review']);
});

it('conceals a Complaint that does not exist', function () {
    reviewSetup();

    beginReview(999999)->assertNotFound();
});

it('lets only a Manager begin review', function (Role $role) {
    ['complaint' => $complaint] = reviewSetup();
    $this->actingAs(account($role));

    beginReview($complaint['id'])->assertForbidden();
})->with([Role::Resident, Role::Technician, Role::Administrator]);

it('refuses a review sent from a stale copy of the Complaint', function () {
    ['complaint' => $complaint] = reviewSetup();
    $version = $this->getJson("/api/v1/complaints/{$complaint['id']}")->json('data.version');

    $this->postJson("/api/v1/complaints/{$complaint['id']}/review", ['version' => $version + 1])->assertConflict()
        ->assertJsonPath('message', 'Someone else changed this Complaint after you opened it (it is now Reported). Reload it to see the latest.');
    $this->postJson("/api/v1/complaints/{$complaint['id']}/review", ['version' => $version])->assertOk()
        ->assertJsonPath('data.version', $version + 1);
});

it('requires the version the Manager loaded', function () {
    ['complaint' => $complaint] = reviewSetup();

    $this->postJson("/api/v1/complaints/{$complaint['id']}/review")->assertUnprocessable()->assertJsonValidationErrors('version');
});

it('answers 409, not a validation error, when review has already begun', function () {
    ['complaint' => $complaint] = reviewSetup();
    beginReview($complaint['id'])->assertOk();

    $this->postJson("/api/v1/complaints/{$complaint['id']}/review")->assertConflict()
        ->assertJsonPath('message', 'This Complaint is already Under Review.');
});
