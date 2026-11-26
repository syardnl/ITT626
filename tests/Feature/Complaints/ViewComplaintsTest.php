<?php

use App\Enums\ResidentType;
use App\Enums\Role;

require_once __DIR__.'/helpers.php';

it('lists a Resident\'s own Complaints, newest first, paginated', function () {
    ['lift' => $lift, 'pool' => $pool, 'rina' => $rina] = complaintsSetup();
    $this->travelTo(now()->subDay());
    submitComplaint(complaintBody(['location_id' => $lift->id, 'title' => 'Older lift problem']))->assertCreated();
    $this->travelBack();
    submitComplaint(complaintBody(['location_id' => $pool->id, 'title' => 'Pool filter broken']))->assertCreated();

    $this->actingAs(account(Role::Resident))->postJson('/api/v1/complaints', complaintBody(['location_id' => $lift->id, 'title' => 'Someone else\'s report']))->assertCreated();

    $this->actingAs($rina)->getJson('/api/v1/complaints?per_page=1')->assertOk()
        ->assertJsonPath('data.*.title', ['Pool filter broken'])
        ->assertJsonPath('meta.total', 2)
        ->assertJsonPath('meta.last_page', 2);
});

it('shows the reporter their Complaint with its answers, Priority breakdown and first timeline entry', function () {
    ['lift' => $lift, 'rina' => $rina] = complaintsSetup();
    $id = submitComplaint(complaintBody(['location_id' => $lift->id, 'urgency' => 'high']))->json('data.id');

    $this->getJson("/api/v1/complaints/{$id}")->assertOk()
        ->assertJsonPath('data.description', 'The lift has been stuck on floor 3 since morning.')
        ->assertJsonPath('data.urgency', 'high')
        ->assertJsonPath('data.safety_risk', false)
        ->assertJsonPath('data.affected_units', 'one')
        ->assertJsonPath('data.essential_service_down', false)
        ->assertJsonPath('data.priority_breakdown.urgency', 30)
        ->assertJsonPath('data.timeline.0.type', 'status')
        ->assertJsonPath('data.timeline.0.from_status', null)
        ->assertJsonPath('data.timeline.0.to_status', 'reported')
        ->assertJsonPath('data.timeline.0.actor.id', $rina->id);
});

it('conceals another Resident\'s Complaint as not found', function () {
    ['lift' => $lift] = complaintsSetup();
    $id = submitComplaint(complaintBody(['location_id' => $lift->id]))->json('data.id');

    $this->actingAs(account(Role::Resident))->getJson("/api/v1/complaints/{$id}")->assertNotFound();
});

it('conceals a Unit Complaint from another Resident of the same Unit', function () {
    ['unit' => $unit] = complaintsSetup();
    $id = submitComplaint(complaintBody(['my_unit' => true]))->json('data.id');
    $partner = account(Role::Resident, ['unit_id' => $unit->id, 'resident_type' => ResidentType::Owner]);

    $this->actingAs($partner)->getJson("/api/v1/complaints/{$id}")->assertNotFound();
    $this->getJson('/api/v1/complaints')->assertOk()->assertJsonCount(0, 'data');
});

it('gives a Technician an empty list and no Complaint until assignment exists', function () {
    ['lift' => $lift] = complaintsSetup();
    $id = submitComplaint(complaintBody(['location_id' => $lift->id]))->json('data.id');

    $this->actingAs(account(Role::Technician));
    $this->getJson('/api/v1/complaints')->assertOk()->assertJsonCount(0, 'data');
    $this->getJson("/api/v1/complaints/{$id}")->assertNotFound();
});

it('offers no way to edit, withdraw or delete a Complaint', function (string $method) {
    ['lift' => $lift] = complaintsSetup();
    $id = submitComplaint(complaintBody(['location_id' => $lift->id]))->json('data.id');

    $this->json($method, "/api/v1/complaints/{$id}", ['title' => 'Changed title'])->assertStatus(405);
    $this->getJson("/api/v1/complaints/{$id}")->assertJsonPath('data.title', 'Lift not operating');
})->with(['PUT', 'PATCH', 'DELETE']);

it('answers 401 to anyone not signed in', function () {
    $this->getJson('/api/v1/complaints')->assertUnauthorized();
    $this->postJson('/api/v1/complaints', [])->assertUnauthorized();
});
