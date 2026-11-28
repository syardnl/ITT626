<?php

use App\Enums\ComplaintStatus;
use Illuminate\Support\Facades\Storage;

require_once __DIR__.'/helpers.php';

beforeEach(fn () => Storage::fake('evidence'));

/** technicianWorkSetup() reassigned from Tariq to Una, with Tariq signed in again. */
function reassignedAwaySetup(): array
{
    $setup = technicianWorkSetup();
    test()->actingAs($setup['manager']);
    assign($setup['complaint'], ['technician_id' => $setup['una']['id'], 'reason' => 'Tariq is off sick.'])->assertOk();
    test()->actingAs($setup['tariq']['user']);

    return $setup;
}

it('shows a previous assignee the same full Complaint, read-only', function () {
    ['complaint' => $complaint, 'rina' => $rina, 'unit' => $unit] = reassignedAwaySetup();

    $this->getJson("/api/v1/complaints/{$complaint}")->assertOk()
        ->assertJsonPath('data.description', 'The lift has been stuck on floor 3 since morning.')
        ->assertJsonPath('data.reporter', ['id' => $rina->id, 'name' => 'Rina Resident', 'unit' => ['id' => $unit->id, 'code' => 'A-01-01']])
        ->assertJsonPath('data.assigned_to_you', false)
        ->assertJsonPath('data.reassigned_from_you', true)
        ->assertJsonPath('data.can_start_work', false)
        ->assertJsonPath('data.can_complete_work', false)
        ->assertJsonMissingPath('data.reporter.email')
        ->assertJsonMissingPath('data.assignment');
    expect(array_column(timelineOf($complaint, 'comment'), 'body'))->toBe(['Key is at the guard house.']);
});

it('lists the Complaint for a previous assignee but not for a Technician never assigned', function () {
    ['complaint' => $complaint, 'manager' => $manager, 'tariq' => $tariq] = $setup = reassignedAwaySetup();
    $this->actingAs($manager);
    $other = technicianProfile('Omar Outsider', $setup['block']->id);

    $this->actingAs($tariq['user']);
    expect(array_column($this->getJson('/api/v1/complaints')->assertOk()->json('data'), 'id'))->toBe([$complaint]);
    $this->actingAs($other['user']);
    $this->getJson("/api/v1/complaints/{$complaint}")->assertNotFound();
});

it('refuses a previous assignee every action', function () {
    ['complaint' => $complaint] = reassignedAwaySetup();

    startWork($complaint)->assertForbidden();
    completeWork($complaint, completionBody())->assertForbidden();
    $this->postJson("/api/v1/complaints/{$complaint}/comments", ['body' => 'Still here?'])->assertForbidden();
    $this->postJson("/api/v1/complaints/{$complaint}/comments", ['body' => 'Note.', 'visibility' => 'internal'])->assertForbidden();
});

it('refuses a previous assignee the action even once the new one has started', function () {
    ['complaint' => $complaint, 'una' => $una, 'tariq' => $tariq] = reassignedAwaySetup();
    $this->actingAs($una['user']);
    startWork($complaint)->assertOk();

    $this->actingAs($tariq['user']);
    completeWork($complaint, completionBody())->assertForbidden();
    $this->actingAs($una['user']);
    $this->getJson("/api/v1/complaints/{$complaint}")->assertJsonPath('data.status', ComplaintStatus::InProgress->value)
        ->assertJsonPath('data.maintenance_records', []);
});

it('lets a previous assignee keep seeing their own Maintenance Records and photos, but not the new assignee’s', function () {
    ['complaint' => $complaint, 'tariq' => $tariq, 'una' => $una, 'record' => $record] = pendingVerificationSetup();
    verifyWork($complaint, ['decision' => 'return', 'reason' => 'The door still sticks.'])->assertOk();
    assign($complaint, ['technician_id' => $una['id'], 'reason' => 'Una knows this lift.'])->assertOk();
    $this->actingAs($una['user']);
    startWork($complaint)->assertOk();
    completeWork($complaint, completionBody(['repair_result' => 'Realigned the door track.']))->assertOk();

    $this->actingAs($tariq['user']);
    $data = $this->getJson("/api/v1/complaints/{$complaint}")->assertOk()->json('data');
    expect($data['maintenance_records'])->toHaveCount(1)
        ->and($data['maintenance_records'][0])->toMatchArray([
            'id' => $record['id'], 'attempt' => 1, 'repair_result' => 'Replaced the door sensor; the lift runs normally.',
            'expense_total' => '185.50', 'verification_outcome' => 'returned',
        ])
        ->and(collect($data['evidence'])->pluck('maintenance_record_id')->filter()->unique()->values()->all())->toBe([$record['id']]);
    foreach ($data['evidence'] as $photo) {
        $this->get($photo['url'])->assertOk();
    }

    $this->actingAs($una['user']);
    $unasPhoto = collect($this->getJson("/api/v1/complaints/{$complaint}")->json('data.evidence'))->firstWhere('maintenance_record_id', '!=', $record['id']);
    $this->actingAs($tariq['user']);
    $this->get($unasPhoto['url'])->assertNotFound();
});

it('keeps the history after the Complaint is Closed', function () {
    ['complaint' => $complaint, 'tariq' => $tariq, 'una' => $una, 'rina' => $rina, 'manager' => $manager] = reassignedAwaySetup();
    $this->actingAs($una['user']);
    startWork($complaint)->assertOk();
    completeWork($complaint, completionBody())->assertOk();
    $this->actingAs($manager);
    verifyWork($complaint, ['decision' => 'approve'])->assertOk();
    $this->actingAs($rina);
    confirmResolution($complaint, ['decision' => 'confirm', 'rating' => 5])->assertOk();

    $this->actingAs($tariq['user']);
    $this->getJson("/api/v1/complaints/{$complaint}")->assertOk()
        ->assertJsonPath('data.status', 'closed')
        ->assertJsonPath('data.reassigned_from_you', true)
        ->assertJsonMissingPath('data.rating');
    $this->actingAs($una['user']);
    $this->getJson("/api/v1/complaints/{$complaint}")->assertJsonPath('data.reassigned_from_you', false);
    $this->actingAs($tariq['user']);
    expect(array_column($this->getJson('/api/v1/technician/tasks')->json('data.history'), 'id'))->toBe([$complaint]);
    $this->postJson("/api/v1/complaints/{$complaint}/comments", ['body' => 'Glad it is fixed.'])->assertForbidden();
});
