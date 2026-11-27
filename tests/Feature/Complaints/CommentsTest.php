<?php

use App\Enums\ComplaintStatus;
use App\Enums\Role;
use App\Models\Complaint;
use App\Models\StatusChange;

require_once __DIR__.'/helpers.php';

/** Rina's Complaint at the Block A lift, and a Manager; Rina signed in. */
function commentsSetup(): array
{
    $setup = complaintsSetup();
    $complaint = submitComplaint(complaintBody(['location_id' => $setup['lift']->id]))->json('data');

    return [...$setup, 'complaint' => $complaint, 'manager' => account(Role::Manager, ['name' => 'Maya Manager'])];
}

function postComment(int $complaintId, array $body)
{
    return test()->postJson("/api/v1/complaints/{$complaintId}/comments", $body);
}

/** The comments in the timeline the signed-in account sees. */
function commentsSeen(int $complaintId): array
{
    $timeline = test()->getJson("/api/v1/complaints/{$complaintId}")->assertOk()->json('data.timeline');

    return array_values(array_map(fn (array $entry) => $entry['body'], array_filter($timeline, fn (array $entry) => $entry['type'] === 'comment')));
}

it('lets the reporter post a Public Comment', function () {
    ['complaint' => $complaint, 'rina' => $rina] = commentsSetup();

    postComment($complaint['id'], ['body' => 'It is making a grinding noise now.'])->assertCreated()
        ->assertJsonPath('data.type', 'comment')
        ->assertJsonPath('data.visibility', 'public')
        ->assertJsonPath('data.body', 'It is making a grinding noise now.')
        ->assertJsonPath('data.author', ['id' => $rina->id, 'name' => 'Rina Resident', 'role' => 'resident']);

    expect(commentsSeen($complaint['id']))->toBe(['It is making a grinding noise now.']);
});

it('lets a Manager post Public and Internal Comments', function () {
    ['complaint' => $complaint, 'manager' => $manager] = commentsSetup();
    $this->actingAs($manager);

    postComment($complaint['id'], ['body' => 'A technician will visit tomorrow.', 'visibility' => 'public'])->assertCreated();
    postComment($complaint['id'], ['body' => 'Second lift complaint this month.', 'visibility' => 'internal'])->assertCreated()
        ->assertJsonPath('data.visibility', 'internal');

    expect(commentsSeen($complaint['id']))->toBe(['A technician will visit tomorrow.', 'Second lift complaint this month.']);
});

it('refuses an Internal Comment from the reporter', function () {
    ['complaint' => $complaint] = commentsSetup();

    postComment($complaint['id'], ['body' => 'Just for staff', 'visibility' => 'internal'])
        ->assertUnprocessable()->assertJsonValidationErrors(['visibility' => 'Only the Manager or the assigned Technician can post Internal Comments.']);
});

it('rejects an unknown visibility', function () {
    ['complaint' => $complaint, 'manager' => $manager] = commentsSetup();
    $this->actingAs($manager);

    postComment($complaint['id'], ['body' => 'Hello', 'visibility' => 'secret'])
        ->assertUnprocessable()->assertJsonValidationErrors('visibility');
});

it('keeps Internal Comments from Residents and shows the office every comment', function () {
    ['complaint' => $complaint, 'manager' => $manager, 'rina' => $rina] = commentsSetup();
    $this->actingAs($manager);
    postComment($complaint['id'], ['body' => 'Visible to Rina', 'visibility' => 'public']);
    postComment($complaint['id'], ['body' => 'Staff only', 'visibility' => 'internal']);

    expect(commentsSeen($complaint['id']))->toBe(['Visible to Rina', 'Staff only']);
    $this->actingAs(account(Role::Administrator));
    expect(commentsSeen($complaint['id']))->toBe(['Visible to Rina', 'Staff only']);
    $this->actingAs($rina);
    expect(commentsSeen($complaint['id']))->toBe(['Visible to Rina']);
});

it('shows a Follower no comments and lets them post none', function () {
    ['complaint' => $complaint, 'neighbour' => $neighbour] = commentsSetup();
    postComment($complaint['id'], ['body' => 'Reporter detail']);

    $this->actingAs(account(Role::Resident, ['unit_id' => $neighbour->id]));
    $this->postJson("/api/v1/complaints/{$complaint['id']}/follow")->assertNoContent();

    $this->getJson("/api/v1/complaints/{$complaint['id']}")->assertOk()->assertJsonMissingPath('data.timeline');
    postComment($complaint['id'], ['body' => 'Me too'])->assertForbidden();
});

it('conceals the Complaint from other Residents trying to comment', function () {
    ['complaint' => $complaint, 'neighbour' => $neighbour] = commentsSetup();
    $this->actingAs(account(Role::Resident, ['unit_id' => $neighbour->id]));

    postComment($complaint['id'], ['body' => 'Hello'])->assertNotFound();
    postComment(999999, ['body' => 'Hello'])->assertNotFound();
});

it('lets Administrators post no comments', function () {
    ['complaint' => $complaint] = commentsSetup();
    $this->actingAs(account(Role::Administrator));

    postComment($complaint['id'], ['body' => 'Hello'])->assertForbidden();
});

it('conceals the Complaint from a Technician it is not assigned to', function () {
    ['complaint' => $complaint] = commentsSetup();
    $this->actingAs(account(Role::Technician));

    postComment($complaint['id'], ['body' => 'Hello'])->assertNotFound();
});

it('needs a comment of 1 to 2000 characters', function (array $body) {
    ['complaint' => $complaint] = commentsSetup();

    postComment($complaint['id'], $body)->assertUnprocessable()->assertJsonValidationErrors('body');
})->with([
    'missing' => [[]],
    'blank' => [['body' => '   ']],
    'too long' => [['body' => str_repeat('a', 2001)]],
]);

it('accepts comments of exactly 1 and 2000 characters', function () {
    ['complaint' => $complaint] = commentsSetup();

    postComment($complaint['id'], ['body' => 'k'])->assertCreated();
    postComment($complaint['id'], ['body' => str_repeat('a', 2000)])->assertCreated();
});

it('refuses comments once the Complaint is Closed', function () {
    ['complaint' => $complaint, 'manager' => $manager] = commentsSetup();
    Complaint::whereKey($complaint['id'])->update(['status' => ComplaintStatus::Closed]); // setup only: no workflow yet

    postComment($complaint['id'], ['body' => 'Still broken'])->assertConflict();
    postComment($complaint['id'], ['body' => ''])->assertConflict();   // Closed is decided before the body
    $this->actingAs($manager);
    postComment($complaint['id'], ['body' => 'Note', 'visibility' => 'internal'])->assertConflict();
    expect(commentsSeen($complaint['id']))->toBe([]);
});

it('offers no way to edit or delete a comment', function () {
    ['complaint' => $complaint] = commentsSetup();
    $id = postComment($complaint['id'], ['body' => 'Original words'])->json('data.id');

    expect($this->patchJson("/api/v1/complaints/{$complaint['id']}/comments/{$id}", ['body' => 'Changed'])->status())->toBeIn([404, 405]);
    expect($this->deleteJson("/api/v1/complaints/{$complaint['id']}/comments/{$id}")->status())->toBeIn([404, 405]);
    expect(commentsSeen($complaint['id']))->toBe(['Original words']);
});

it('lists status changes and comments together in the order they happened', function () {
    ['complaint' => $complaint, 'manager' => $manager] = commentsSetup();
    $this->travel(1)->minutes();
    postComment($complaint['id'], ['body' => 'Added detail']);
    $this->travel(1)->minutes();
    // Setup only: the review workflow arrives in Phase 3.
    StatusChange::create(['complaint_id' => $complaint['id'], 'changed_by' => $manager->id, 'from_status' => 'reported', 'to_status' => 'under_review']);
    $this->travel(1)->minutes();
    $this->actingAs($manager);
    postComment($complaint['id'], ['body' => 'Looking into it', 'visibility' => 'public']);

    $timeline = $this->getJson("/api/v1/complaints/{$complaint['id']}")->json('data.timeline');

    expect(array_map(fn (array $entry) => $entry['type'] === 'status' ? $entry['to_status'] : $entry['body'], $timeline))
        ->toBe(['reported', 'Added detail', 'under_review', 'Looking into it']);
});

it('puts a status change before a comment made in the same second', function () {
    $this->freezeSecond();
    ['complaint' => $complaint] = commentsSetup();
    postComment($complaint['id'], ['body' => 'Straight after submitting']);

    $types = array_column($this->getJson("/api/v1/complaints/{$complaint['id']}")->json('data.timeline'), 'type');

    expect($types)->toBe(['status', 'comment']);
});
