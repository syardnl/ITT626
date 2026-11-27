<?php

use App\Enums\Role;
use Illuminate\Support\Facades\Storage;

require_once __DIR__.'/helpers.php';

beforeEach(fn () => Storage::fake('evidence'));

/** resolvedSetup(), with a neighbour following the lift Complaint since before it was Resolved (a Resolved one cannot be followed). */
function resolvedWithFollowerSetup(): array
{
    $setup = pendingVerificationSetup();
    test()->actingAs($follower = account(Role::Resident, ['unit_id' => $setup['neighbour']->id]));
    test()->postJson("/api/v1/complaints/{$setup['complaint']}/follow")->assertNoContent();
    test()->actingAs($setup['manager']);
    verifyWork($setup['complaint'], ['decision' => 'approve'])->assertOk();
    test()->actingAs($setup['rina']);

    return [...$setup, 'follower' => $follower];
}

it('closes the Complaint when the reporter confirms, keeping the Rating and ending the Assignment', function () {
    ['complaint' => $complaint, 'rina' => $rina, 'manager' => $manager] = resolvedSetup();

    confirmResolution($complaint, ['decision' => 'confirm', 'rating' => 4, 'feedback' => 'Quick and tidy.'])->assertOk()
        ->assertJsonPath('data.status', 'closed')
        ->assertJsonPath('data.closed_at', fn (?string $at) => $at !== null)
        ->assertJsonPath('data.rating', fn (array $rating) => $rating['score'] === 4 && $rating['feedback'] === 'Quick and tidy.')
        ->assertJsonPath('data.can_confirm_resolution', false);

    expect(collect(timelineOf($complaint, 'status'))->last())->toMatchArray([
        'from_status' => 'resolved', 'to_status' => 'closed', 'actor' => ['id' => $rina->id, 'name' => 'Rina Resident'],
    ]);

    $this->actingAs($manager);
    $data = $this->getJson("/api/v1/complaints/{$complaint}")->assertOk()->json('data');
    expect($data['assignment'])->toBeNull()
        ->and($data['rating'])->toMatchArray(['score' => 4, 'feedback' => 'Quick and tidy.']);
});

it('confirms without a Rating', function () {
    ['complaint' => $complaint] = resolvedSetup();

    confirmResolution($complaint, ['decision' => 'confirm'])->assertOk()
        ->assertJsonPath('data.status', 'closed')
        ->assertJsonPath('data.rating', null);
});

it('needs a whole-number Rating from 1 to 5 and feedback of at most 1000 characters', function (array $body, string $field) {
    ['complaint' => $complaint] = resolvedSetup();

    confirmResolution($complaint, ['decision' => 'confirm', ...$body])->assertUnprocessable()->assertJsonValidationErrors($field);
})->with([
    'zero' => [['rating' => 0], 'rating'],
    'six' => [['rating' => 6], 'rating'],
    'a fraction' => [['rating' => 3.5], 'rating'],
    'long feedback' => [['rating' => 5, 'feedback' => str_repeat('a', 1001)], 'feedback'],
    'feedback without a Rating' => [['feedback' => 'Left a mess in the hallway.'], 'rating'],
    'no decision' => [['decision' => null], 'decision'],
    'unknown decision' => [['decision' => 'maybe'], 'decision'],
]);

it('accepts Ratings of 1 and 5', function (int $score) {
    ['complaint' => $complaint] = resolvedSetup();

    confirmResolution($complaint, ['decision' => 'confirm', 'rating' => $score])->assertOk()->assertJsonPath('data.rating.score', $score);
})->with([1, 5]);

it('returns the repair with a reason: a Resident Return starts a new Repair Cycle with the same Technician', function () {
    ['complaint' => $complaint, 'rina' => $rina, 'tariq' => $tariq, 'manager' => $manager] = resolvedSetup();

    confirmResolution($complaint, ['decision' => 'return', 'reason' => 'The door sticks again.', 'rating' => 1])->assertOk()
        ->assertJsonPath('data.status', 'returned')
        ->assertJsonPath('data.rating', null)
        ->assertJsonPath('data.can_confirm_resolution', false);
    expect(collect(timelineOf($complaint, 'status'))->last())->toMatchArray([
        'from_status' => 'resolved', 'to_status' => 'returned', 'reason' => 'The door sticks again.',
        'actor' => ['id' => $rina->id, 'name' => 'Rina Resident'],
    ]);

    $this->actingAs($manager);
    $data = $this->getJson("/api/v1/complaints/{$complaint}")->json('data');
    expect(array_column($data['repair_cycles'], 'number'))->toBe([1, 2])
        ->and($data['assignment']['technician']['id'])->toBe($tariq['id']);

    // Back with Tariq, who sees why and starts again; the next record falls in cycle 2.
    $this->actingAs($tariq['user']);
    expect(collect(timelineOf($complaint, 'status'))->last()['reason'])->toBe('The door sticks again.');
    startWork($complaint)->assertOk();
    completeWork($complaint, completionBody())->assertOk()
        ->assertJsonPath('data.maintenance_records.1.repair_cycle', 2)
        ->assertJsonPath('data.maintenance_records.1.attempt', 2);
});

it('needs a reason to return the repair', function (array $body) {
    ['complaint' => $complaint] = resolvedSetup();

    confirmResolution($complaint, ['decision' => 'return', ...$body])->assertUnprocessable()
        ->assertJsonValidationErrors(['reason' => 'Tell us what is still wrong, so the Technician can look again.']);
})->with([
    'missing' => [[]],
    'blank' => [['reason' => '   ']],
]);

it('accepts "0" as a reason', function () {
    ['complaint' => $complaint] = resolvedSetup();

    confirmResolution($complaint, ['decision' => 'return', 'reason' => '0'])->assertOk();
    expect(collect(timelineOf($complaint, 'status'))->last()['reason'])->toBe('0');
});

it('refuses a Follower, and conceals the Complaint from other Residents', function () {
    ['complaint' => $complaint, 'follower' => $follower, 'neighbour' => $neighbour] = resolvedWithFollowerSetup();

    $this->actingAs(account(Role::Resident, ['unit_id' => $neighbour->id]));
    confirmResolution($complaint, ['decision' => 'confirm'])->assertNotFound();

    $this->actingAs($follower);
    confirmResolution($complaint, ['decision' => 'confirm'])->assertForbidden();
    confirmResolution($complaint, ['decision' => 'return', 'reason' => 'Still broken'])->assertForbidden();
});

it('refuses an answer unless the Complaint is Resolved', function () {
    ['complaint' => $complaint, 'rina' => $rina] = pendingVerificationSetup();
    $this->actingAs($rina);

    confirmResolution($complaint, ['decision' => 'confirm'])->assertConflict()
        ->assertJsonPath('message', 'This Complaint is Pending Verification, not Resolved.');
    confirmResolution($complaint, ['decision' => 'return'])->assertConflict();   // the status is decided before the reason
});

it('takes one answer only: a second confirmation gets 409 and keeps the first Rating', function () {
    ['complaint' => $complaint] = resolvedSetup();
    confirmResolution($complaint, ['decision' => 'confirm', 'rating' => 5])->assertOk();

    confirmResolution($complaint, ['decision' => 'confirm', 'rating' => 1])->assertConflict()
        ->assertJsonPath('message', 'This Complaint is Closed, not Resolved.');
    $this->getJson("/api/v1/complaints/{$complaint}")->assertJsonPath('data.rating.score', 5);
});

it('refuses an answer sent with a stale version', function () {
    ['complaint' => $complaint] = resolvedSetup();
    $stale = loadedVersion($complaint) - 1;

    confirmResolution($complaint, ['decision' => 'confirm', 'version' => $stale])->assertConflict();
    $this->getJson("/api/v1/complaints/{$complaint}")->assertJsonPath('data.status', 'resolved');
});

it('shows the reporter the repair result and photos, not parts, notes or expenses', function () {
    ['complaint' => $complaint] = resolvedSetup();

    $data = $this->getJson("/api/v1/complaints/{$complaint}")->assertOk()
        ->assertJsonPath('data.can_confirm_resolution', true)
        ->json('data');
    expect(array_keys($data['maintenance_records'][0]))->toBe(['id', 'attempt', 'repair_result', 'photo_ids', 'submitted_at'])
        ->and(array_column($data['evidence'], 'type'))->toBe(['before_photo', 'after_photo']);
});

it('shows the Rating to the office and the reporter, never to the Technician or a Follower', function () {
    ['complaint' => $complaint, 'tariq' => $tariq, 'follower' => $follower] = resolvedWithFollowerSetup();
    confirmResolution($complaint, ['decision' => 'confirm', 'rating' => 2, 'feedback' => 'Slow.'])->assertOk();

    $this->getJson("/api/v1/complaints/{$complaint}")->assertJsonPath('data.rating.score', 2);
    $this->actingAs(account(Role::Administrator));
    $this->getJson("/api/v1/complaints/{$complaint}")->assertJsonPath('data.rating.feedback', 'Slow.');

    $this->actingAs($tariq['user']);
    $data = $this->getJson("/api/v1/complaints/{$complaint}")->assertOk()->json('data');
    expect($data)->not->toHaveKey('rating')
        ->and($data['maintenance_records'][0])->toHaveKey('expenses');   // still their own record in full

    $this->actingAs($follower);
    $summary = $this->getJson("/api/v1/complaints/{$complaint}")->assertOk()->json('data');
    expect($summary)->not->toHaveKey('rating')->not->toHaveKey('maintenance_records')->not->toHaveKey('evidence');
});

it('audits the confirmation and the Resident Return', function () {
    ['complaint' => $complaint, 'rina' => $rina] = resolvedSetup();
    confirmResolution($complaint, ['decision' => 'return', 'reason' => 'Still noisy.'])->assertOk();

    $this->actingAs(account(Role::Administrator));
    $returned = $this->getJson('/api/v1/admin/audit-logs?action=complaint.resident_returned')->assertOk()->json('data.0');
    expect($returned['actor']['id'])->toBe($rina->id)
        ->and($returned['changes']['status'])->toBe(['from' => 'resolved', 'to' => 'returned'])
        ->and($returned['metadata'])->toBe(['reason' => 'Still noisy.', 'repair_cycle' => 2]);
});
