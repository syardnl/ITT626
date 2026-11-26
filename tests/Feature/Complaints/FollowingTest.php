<?php

use App\Enums\ComplaintStatus;
use App\Enums\Role;
use App\Models\Complaint;

require_once __DIR__.'/helpers.php';

function follow(int $complaintId)
{
    return test()->postJson("/api/v1/complaints/{$complaintId}/follow");
}

function unfollow(int $complaintId)
{
    return test()->deleteJson("/api/v1/complaints/{$complaintId}/follow");
}

function following()
{
    return test()->getJson('/api/v1/complaints?scope=following');
}

it('lets a Resident follow an open Common Area Complaint in their Block and list it under following', function () {
    ['liftComplaint' => $lift] = openComplaintsSetup();

    follow($lift['id'])->assertNoContent();

    following()->assertOk()->assertJsonPath('data.*.reference_no', [$lift['reference_no']]);
});

it('lets a Resident follow Complaints on a Floor of their Block and at Shared Areas', function () {
    ['corridorComplaint' => $corridor, 'poolComplaint' => $pool] = openComplaintsSetup();

    follow($corridor['id'])->assertNoContent();
    follow($pool['id'])->assertNoContent();

    following()->assertJsonCount(2, 'data');
});

it('has no extra effect when a Resident follows the same Complaint twice', function () {
    ['liftComplaint' => $lift, 'lift' => $liftArea] = openComplaintsSetup();

    follow($lift['id'])->assertNoContent();
    follow($lift['id'])->assertNoContent();

    following()->assertJsonCount(1, 'data');
    openComplaintsAt($liftArea->id)->assertJsonPath('data.0.following', true);
});

it('refuses to let a Resident follow their own Complaint', function () {
    ['lift' => $lift] = openComplaintsSetup();
    $mine = submitComplaint(complaintBody(['location_id' => $lift->id]))->json('data.id');

    follow($mine)->assertConflict()->assertJsonPath('message', 'You reported this Complaint, so you are already tracking it.');
    following()->assertJsonCount(0, 'data');
});

it('conceals Unit Complaints and other Blocks\' Complaints as not found when followed', function () {
    ['samUnitComplaint' => $unit, 'liftBComplaint' => $otherBlock] = openComplaintsSetup();

    follow($unit['id'])->assertNotFound();
    follow($otherBlock['id'])->assertNotFound();
    follow(999999)->assertNotFound();
    following()->assertJsonCount(0, 'data');
});

it('refuses to follow a Resolved or Closed Complaint', function (ComplaintStatus $finished) {
    ['liftComplaint' => $lift] = openComplaintsSetup();
    Complaint::whereKey($lift['id'])->update(['status' => $finished]); // setup only: no workflow yet

    follow($lift['id'])->assertConflict()->assertJsonPath('message', 'This Complaint is no longer open, so it cannot be followed.');
    following()->assertJsonCount(0, 'data');
})->with([ComplaintStatus::Resolved, ComplaintStatus::Closed]);

it('lets a Follower unfollow, after which the Complaint is concealed again', function () {
    ['liftComplaint' => $lift] = openComplaintsSetup();
    follow($lift['id']);

    unfollow($lift['id'])->assertNoContent();

    following()->assertJsonCount(0, 'data');
    $this->getJson("/api/v1/complaints/{$lift['id']}")->assertNotFound();
    unfollow($lift['id'])->assertNotFound();
});

it('keeps a followed Complaint in the following list until it is Closed', function () {
    ['liftComplaint' => $lift, 'poolComplaint' => $pool] = openComplaintsSetup();
    follow($lift['id']);
    follow($pool['id']);
    Complaint::whereKey($lift['id'])->update(['status' => ComplaintStatus::Resolved]); // setup only
    Complaint::whereKey($pool['id'])->update(['status' => ComplaintStatus::Closed]);

    following()->assertJsonPath('data.*.title', ['Lift stuck on floor 3']);
});

it('keeps the default list to the Resident\'s own Complaints, not the ones they follow', function () {
    ['liftComplaint' => $lift] = openComplaintsSetup();
    follow($lift['id']);
    submitComplaint(complaintBody(['my_unit' => true, 'title' => 'Window latch broken']));

    $this->getJson('/api/v1/complaints')->assertJsonPath('data.*.title', ['Window latch broken']);
    $this->getJson('/api/v1/complaints?scope=mine')->assertJsonPath('data.*.title', ['Window latch broken']);
});

it('shows a Follower only the reference, title, Category, status and date of a followed Complaint', function () {
    ['liftComplaint' => $lift] = openComplaintsSetup();
    follow($lift['id']);

    $seen = $this->getJson("/api/v1/complaints/{$lift['id']}")->assertOk()->json('data');
    $listed = following()->json('data.0');

    $summary = [
        'id' => $lift['id'],
        'reference_no' => $lift['reference_no'],
        'title' => 'Lift stuck on floor 3',
        'category' => null,
        'status' => 'reported',
        'created_at' => $lift['created_at'],
        'following' => true,
        'mine' => false,
    ];
    expect($seen)->toBe($summary)->and($listed)->toBe($summary);
});

it('never lets a similar open Complaint stop a Resident submitting a new one', function () {
    ['lift' => $lift, 'liftComplaint' => $existing] = openComplaintsSetup();
    follow($existing['id']);

    submitComplaint(complaintBody(['location_id' => $lift->id, 'title' => 'Lift stuck on floor 3']))->assertCreated();

    openComplaintsAt($lift->id)->assertJsonCount(2, 'data');
});

it('rejects an unknown list scope', function () {
    openComplaintsSetup();

    $this->getJson('/api/v1/complaints?scope=everyone')->assertUnprocessable()->assertJsonValidationErrors('scope');
});

it('rejects a list scope from Managers and Administrators, who see every Complaint', function () {
    openComplaintsSetup();
    $this->actingAs(account(Role::Manager));

    $this->getJson('/api/v1/complaints?scope=following')->assertUnprocessable()->assertJsonValidationErrors('scope');
});
