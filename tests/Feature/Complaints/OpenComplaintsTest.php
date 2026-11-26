<?php

use App\Enums\ComplaintStatus;
use App\Enums\Role;
use App\Models\Complaint;

require_once __DIR__.'/helpers.php';

it('shows a Resident the open Complaints at a Common Area in their own Block, as privacy-safe summaries', function () {
    ['lift' => $lift, 'liftComplaint' => $complaint] = openComplaintsSetup();

    $summary = openComplaintsAt($lift->id)->assertOk()->json('data.0');

    expect($summary)->toBe([
        'id' => $complaint['id'],
        'reference_no' => $complaint['reference_no'],
        'title' => 'Lift stuck on floor 3',
        'category' => null,
        'status' => 'reported',
        'created_at' => $complaint['created_at'],
        'following' => false,
        'mine' => false,
    ]);
});

it('also covers Common Areas on a Floor of the Resident\'s Block and the Shared Areas', function () {
    ['corridor' => $corridor, 'pool' => $pool] = openComplaintsSetup();

    openComplaintsAt($corridor->id)->assertOk()->assertJsonPath('data.*.title', ['Corridor light out']);
    openComplaintsAt($pool->id)->assertOk()->assertJsonPath('data.*.title', ['Pool filter noisy']);
});

it('returns an empty list for another Block\'s Common Areas and for any Unit', function () {
    ['liftB' => $liftB, 'neighbour' => $samsUnit, 'unit' => $rinasUnit] = openComplaintsSetup();
    submitComplaint(complaintBody(['my_unit' => true, 'title' => 'Rina\'s own window']));

    openComplaintsAt($liftB->id)->assertOk()->assertJsonCount(0, 'data');
    openComplaintsAt($samsUnit->id)->assertOk()->assertJsonCount(0, 'data');
    openComplaintsAt($rinasUnit->id)->assertOk()->assertJsonCount(0, 'data');
});

it('leaves out Resolved and Closed Complaints and lists the rest newest first', function () {
    ['lift' => $lift, 'sam' => $sam, 'rina' => $rina] = openComplaintsSetup();
    $this->actingAs($sam);
    $later = function (string $title) use ($lift) {
        $this->travel(1)->minutes();

        return submitComplaint(complaintBody(['location_id' => $lift->id, 'title' => $title]))->json('data.id');
    };
    $resolved = $later('Lift button sticky');
    $closed = $later('Lift mirror cracked');
    $underReview = $later('Lift fan loud');
    // Setup only: the review workflow arrives in Phase 3.
    Complaint::whereKey($resolved)->update(['status' => ComplaintStatus::Resolved]);
    Complaint::whereKey($closed)->update(['status' => ComplaintStatus::Closed]);
    Complaint::whereKey($underReview)->update(['status' => ComplaintStatus::UnderReview]);

    $this->actingAs($rina);
    openComplaintsAt($lift->id)->assertOk()
        ->assertJsonPath('data.*.title', ['Lift fan loud', 'Lift stuck on floor 3'])
        ->assertJsonPath('data.*.status', ['under_review', 'reported']);
});

it('marks the viewer\'s own Complaints as mine', function () {
    ['lift' => $lift] = openComplaintsSetup();
    submitComplaint(complaintBody(['location_id' => $lift->id, 'title' => 'Lift light flickers']));

    openComplaintsAt($lift->id)->assertOk()
        ->assertJsonPath('data.*.title', ['Lift light flickers', 'Lift stuck on floor 3'])
        ->assertJsonPath('data.*.mine', [true, false]);
});

it('lets a Manager see the open Complaints at any Common Area, but never at a Unit', function () {
    ['liftB' => $liftB, 'neighbour' => $samsUnit] = openComplaintsSetup();
    $this->actingAs(account(Role::Manager));

    openComplaintsAt($liftB->id)->assertOk()->assertJsonPath('data.*.title', ['Block B lift door jammed']);
    openComplaintsAt($samsUnit->id)->assertOk()->assertJsonCount(0, 'data');
});

it('answers not found for a Location that does not exist', function () {
    openComplaintsSetup();

    openComplaintsAt(999999)->assertNotFound();
});
