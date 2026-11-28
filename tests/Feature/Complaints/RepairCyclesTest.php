<?php

use App\Enums\Role;

require_once __DIR__.'/helpers.php';

it('starts Repair Cycle 1 when a Complaint is submitted, shown to the office', function () {
    $setup = complaintsSetup();
    $complaint = submitComplaint(complaintBody(['location_id' => $setup['lift']->id]))->assertCreated()->json('data');

    $this->actingAs(account(Role::Manager));
    $this->getJson("/api/v1/complaints/{$complaint['id']}")->assertOk()
        ->assertJsonCount(1, 'data.repair_cycles')
        ->assertJsonPath('data.repair_cycles.0.number', 1)
        ->assertJsonPath('data.repair_cycles.0.started_at', $complaint['created_at']);
});

it('keeps the same Repair Cycle through review and classification', function () {
    $setup = complaintsSetup();
    $complaint = submitComplaint(complaintBody(['location_id' => $setup['lift']->id]))->json('data');
    $this->actingAs(account(Role::Manager));
    $this->postJson("/api/v1/complaints/{$complaint['id']}/review", ['version' => loadedVersion($complaint['id'])])->assertOk();

    $this->getJson("/api/v1/complaints/{$complaint['id']}")->assertJsonCount(1, 'data.repair_cycles');
});

it('does not show Repair Cycles to the reporter', function () {
    $setup = complaintsSetup();
    $complaint = submitComplaint(complaintBody(['location_id' => $setup['lift']->id]))->json('data');

    $this->getJson("/api/v1/complaints/{$complaint['id']}")->assertOk()->assertJsonMissingPath('data.repair_cycles');
});
