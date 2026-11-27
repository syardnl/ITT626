<?php

use App\Enums\ComplaintStatus;
use App\Enums\Role;
use App\Models\Category;
use App\Models\Complaint;

require_once __DIR__.'/helpers.php';

/**
 * Rina's low-impact Complaint at the Block A lift (computed Priority: Low), with review begun
 * by a Manager, who stays signed in.
 */
function classificationSetup(): array
{
    $setup = complaintsSetup();
    $complaint = submitComplaint(complaintBody(['location_id' => $setup['lift']->id]))->json('data');
    test()->actingAs($manager = account(Role::Manager, ['name' => 'Maya Manager']));
    test()->postJson("/api/v1/complaints/{$complaint['id']}/review", ['version' => loadedVersion($complaint['id'])])->assertOk();

    return [...$setup, 'complaint' => $complaint, 'manager' => $manager, 'lift' => Category::where('key', 'lift')->firstOrFail()];
}

/** Sends the version the signed-in account loaded unless $body names one. */
function classify(int $complaintId, array $body)
{
    return test()->patchJson("/api/v1/complaints/{$complaintId}/classification", ['version' => loadedVersion($complaintId), ...$body]);
}

/** The classification entries on the timeline the signed-in account sees. */
function classificationsSeen(int $complaintId): array
{
    $timeline = test()->getJson("/api/v1/complaints/{$complaintId}")->assertOk()->json('data.timeline');

    return array_values(array_filter($timeline, fn (array $entry) => $entry['type'] === 'classification'));
}

/** No endpoint reaches these statuses until later Phase 3 tickets, so the status is set directly. */
function forceStatus(int $complaintId, ComplaintStatus $status): void
{
    Complaint::findOrFail($complaintId)->update(['status' => $status]);
}

it('lets a Manager confirm the Category and the suggested Priority without a reason', function () {
    ['complaint' => $complaint, 'manager' => $manager, 'lift' => $lift] = classificationSetup();

    classify($complaint['id'], ['category_id' => $lift->id, 'priority' => 'low'])->assertOk()
        ->assertJsonPath('data.category.id', $lift->id)
        ->assertJsonPath('data.priority', 'low')
        ->assertJsonPath('data.status', 'under_review');

    expect(classificationsSeen($complaint['id']))->toHaveCount(1)
        ->and(classificationsSeen($complaint['id'])[0])->toMatchArray([
            'from_category' => null,
            'to_category' => ['id' => $lift->id, 'name' => $lift->name],
            'from_priority' => 'low',
            'to_priority' => 'low',
            'reason' => null,
            'actor' => ['id' => $manager->id, 'name' => 'Maya Manager'],
        ]);
});

it('requires a reason when the Priority differs from the suggested one', function () {
    ['complaint' => $complaint, 'lift' => $lift] = classificationSetup();

    classify($complaint['id'], ['category_id' => $lift->id, 'priority' => 'high'])->assertUnprocessable()
        ->assertJsonValidationErrors(['priority_reason' => 'Give a reason for choosing a Priority other than the suggested Low.']);
});

it('keeps the suggested Priority and records the override reason', function () {
    ['complaint' => $complaint, 'lift' => $lift] = classificationSetup();

    classify($complaint['id'], ['category_id' => $lift->id, 'priority' => 'high', 'priority_reason' => 'Elderly residents on upper floors.'])->assertOk()
        ->assertJsonPath('data.priority', 'high')
        ->assertJsonPath('data.initial_priority', 'low');

    expect(classificationsSeen($complaint['id'])[0])->toMatchArray([
        'from_priority' => 'low', 'to_priority' => 'high', 'reason' => 'Elderly residents on upper floors.',
    ]);
});

it('allows going back to the suggested Priority without a reason', function () {
    ['complaint' => $complaint, 'lift' => $lift] = classificationSetup();
    classify($complaint['id'], ['category_id' => $lift->id, 'priority' => 'high', 'priority_reason' => 'Elderly residents.'])->assertOk();

    classify($complaint['id'], ['category_id' => $lift->id, 'priority' => 'low'])->assertOk()->assertJsonPath('data.priority', 'low');
});

it('records the classification in the audit log with the reason', function () {
    ['complaint' => $complaint, 'manager' => $manager, 'lift' => $lift] = classificationSetup();
    classify($complaint['id'], ['category_id' => $lift->id, 'priority' => 'medium', 'priority_reason' => 'Busy lift.']);

    $this->actingAs(account(Role::Administrator));
    $entry = $this->getJson('/api/v1/admin/audit-logs?action=complaint.classified')->assertOk()->json('data.0');
    expect($entry)->toMatchArray(['target_type' => 'complaint', 'target_id' => $complaint['id']])
        ->and($entry['actor']['id'])->toBe($manager->id)
        ->and($entry['changes']['category_id'])->toBe(['from' => null, 'to' => $lift->id])
        ->and($entry['changes']['priority'])->toBe(['from' => 'low', 'to' => 'medium'])
        ->and($entry['metadata'])->toBe(['reason' => 'Busy lift.']);
});

it('shows the office the Category default priority next to the Priority Score', function () {
    ['complaint' => $complaint, 'lift' => $lift] = classificationSetup();
    classify($complaint['id'], ['category_id' => $lift->id, 'priority' => 'low']);

    $this->getJson("/api/v1/complaints/{$complaint['id']}")->assertOk()
        ->assertJsonPath('data.category.default_priority', $lift->default_priority->value)
        ->assertJsonPath('data.priority_score', 15)
        ->assertJsonPath('data.priority_breakdown.location_importance', 15);
});

it('refuses classification before review has begun', function () {
    $setup = complaintsSetup();
    $complaint = submitComplaint(complaintBody(['location_id' => $setup['lift']->id]))->json('data');
    $this->actingAs(account(Role::Manager));

    classify($complaint['id'], ['category_id' => Category::first()->id, 'priority' => 'low'])->assertConflict()
        ->assertJsonPath('message', 'Begin review before classifying this Complaint.');
});

it('refuses classification once the Complaint is Resolved or Closed', function (ComplaintStatus $status, string $label) {
    ['complaint' => $complaint, 'lift' => $lift] = classificationSetup();
    forceStatus($complaint['id'], $status);

    classify($complaint['id'], ['category_id' => $lift->id, 'priority' => 'low'])->assertConflict()
        ->assertJsonPath('message', "This Complaint is already {$label}.");
})->with([[ComplaintStatus::Resolved, 'Resolved'], [ComplaintStatus::Closed, 'Closed']]);

it('allows classification in every open status after review', function (ComplaintStatus $status) {
    ['complaint' => $complaint, 'lift' => $lift] = classificationSetup();
    forceStatus($complaint['id'], $status);

    classify($complaint['id'], ['category_id' => $lift->id, 'priority' => 'low'])->assertOk()
        ->assertJsonPath('data.status', $status->value);
})->with([ComplaintStatus::Assigned, ComplaintStatus::InProgress, ComplaintStatus::PendingVerification, ComplaintStatus::Returned]);

it('validates the Category and Priority', function () {
    ['complaint' => $complaint, 'lift' => $lift] = classificationSetup();
    $lift->update(['active' => false]);

    classify($complaint['id'], ['category_id' => $lift->id, 'priority' => 'urgent'])->assertUnprocessable()
        ->assertJsonValidationErrors(['category_id', 'priority']);
    classify($complaint['id'], ['version' => null])->assertUnprocessable()->assertJsonValidationErrors(['category_id', 'priority', 'version']);
});

it('refuses a classification sent from a stale copy', function () {
    ['complaint' => $complaint, 'lift' => $lift] = classificationSetup();
    $version = $this->getJson("/api/v1/complaints/{$complaint['id']}")->json('data.version');
    classify($complaint['id'], ['category_id' => $lift->id, 'priority' => 'low', 'version' => $version])->assertOk();

    classify($complaint['id'], ['category_id' => $lift->id, 'priority' => 'medium', 'priority_reason' => 'x', 'version' => $version])
        ->assertConflict();
});

it('shows the reporter the new Category and Priority but not the office’s classification notes', function () {
    ['complaint' => $complaint, 'lift' => $lift, 'rina' => $rina] = classificationSetup();
    classify($complaint['id'], ['category_id' => $lift->id, 'priority' => 'high', 'priority_reason' => 'Staff note.']);

    $this->actingAs($rina);
    $this->getJson("/api/v1/complaints/{$complaint['id']}")->assertOk()
        ->assertJsonPath('data.category.id', $lift->id)
        ->assertJsonPath('data.priority', 'high')
        ->assertJsonMissingPath('data.category.default_priority');
    expect(classificationsSeen($complaint['id']))->toBe([]);
});

it('lets only a Manager classify', function (Role $role) {
    ['complaint' => $complaint, 'lift' => $lift] = classificationSetup();
    $this->actingAs(account($role));

    classify($complaint['id'], ['category_id' => $lift->id, 'priority' => 'low'])->assertForbidden();
})->with([Role::Resident, Role::Technician, Role::Administrator]);

it('answers 409, not a validation error, when the status does not allow classification', function () {
    $setup = complaintsSetup();
    $complaint = submitComplaint(complaintBody(['location_id' => $setup['lift']->id]))->json('data');
    $this->actingAs(account(Role::Manager));

    classify($complaint['id'], ['priority' => 'critical'])->assertConflict()
        ->assertJsonPath('message', 'Begin review before classifying this Complaint.');
});

it('conceals a Complaint that does not exist before validating', function () {
    classificationSetup();

    classify(999999, [])->assertNotFound();
});

it('lets a Manager confirm a Category that has since been retired', function () {
    ['complaint' => $complaint, 'lift' => $lift] = classificationSetup();
    classify($complaint['id'], ['category_id' => $lift->id, 'priority' => 'low'])->assertOk();
    $lift->update(['active' => false]);
    $other = Category::where('active', true)->where('id', '!=', $lift->id)->firstOrFail();

    classify($complaint['id'], ['category_id' => $lift->id, 'priority' => 'low'])->assertOk();
    $lift->update(['active' => true]);
    classify($complaint['id'], ['category_id' => $other->id, 'priority' => 'low'])->assertOk();
    $lift->update(['active' => false]);
    classify($complaint['id'], ['category_id' => $lift->id, 'priority' => 'low'])->assertUnprocessable()->assertJsonValidationErrors('category_id');
});
