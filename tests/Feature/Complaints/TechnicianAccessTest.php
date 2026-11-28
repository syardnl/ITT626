<?php

use App\Enums\ComplaintStatus;
use App\Enums\Role;
use App\Models\Category;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

require_once __DIR__.'/helpers.php';

it('shows the assigned Technician the full Complaint with the reporter’s name and Unit but not their email', function () {
    ['complaint' => $complaint, 'rina' => $rina, 'unit' => $unit] = technicianWorkSetup();

    $this->getJson("/api/v1/complaints/{$complaint}")->assertOk()
        ->assertJsonPath('data.description', 'The lift has been stuck on floor 3 since morning.')
        ->assertJsonPath('data.reporter', ['id' => $rina->id, 'name' => 'Rina Resident', 'unit' => ['id' => $unit->id, 'code' => 'A-01-01']])
        ->assertJsonPath('data.assigned_to_you', true)
        ->assertJsonPath('data.reassigned_from_you', false)
        ->assertJsonMissingPath('data.reporter.email')
        ->assertJsonMissingPath('data.assignment');
});

it('shows the assigned Technician Public and Internal Comments and the status timeline, not the office’s notes', function () {
    ['complaint' => $complaint] = technicianWorkSetup();

    expect(timelineOf($complaint, 'comment'))->toHaveCount(1)
        ->and(timelineOf($complaint, 'comment')[0])->toMatchArray(['visibility' => 'internal', 'body' => 'Key is at the guard house.'])
        ->and(collect(timelineOf($complaint, 'status'))->pluck('to_status')->all())->toBe(['reported', 'under_review', 'assigned'])
        ->and(timelineOf($complaint, 'classification'))->toBe([])
        ->and(timelineOf($complaint, 'assignment'))->toBe([]);
});

it('lets the assigned Technician open the Complaint’s photos', function () {
    Storage::fake('evidence');
    $setup = complaintsSetup();
    $photo = submitComplaint([...complaintBody(['location_id' => $setup['lift']->id]), 'photos' => [UploadedFile::fake()->image('lift.jpg', 800, 600)]])
        ->assertCreated()->json('data.id');
    $this->actingAs($manager = account(Role::Manager));
    $this->postJson("/api/v1/complaints/{$photo}/review", ['version' => loadedVersion($photo)])->assertOk();
    $this->patchJson("/api/v1/complaints/{$photo}/classification", ['category_id' => Category::where('key', 'lift')->value('id'), 'priority' => 'low', 'version' => loadedVersion($photo)])->assertOk();
    $tariq = technicianProfile('Tariq Technician', $setup['block']->id);
    $una = technicianProfile('Una Other', $setup['block']->id);
    assign($photo, ['technician_id' => $tariq['id']])->assertOk();

    $this->actingAs($tariq['user']);
    $url = $this->getJson("/api/v1/complaints/{$photo}")->json('data.evidence.0.url');
    $this->get($url)->assertOk()->assertHeader('Content-Type', 'image/jpeg');

    $this->actingAs($una['user']);
    $this->get($url)->assertNotFound();
});

it('conceals every Complaint never assigned to the Technician', function () {
    ['complaint' => $complaint, 'una' => $una] = $setup = technicianWorkSetup();
    $unassigned = classifiedComplaint($setup);

    $this->actingAs($una['user']);
    $this->getJson("/api/v1/complaints/{$complaint}")->assertNotFound();
    $this->getJson("/api/v1/complaints/{$unassigned}")->assertNotFound();
    $this->getJson('/api/v1/complaints')->assertOk()->assertJsonPath('meta.total', 0);
});

it('conceals a Complaint from a Technician without a profile', function () {
    ['complaint' => $complaint] = technicianWorkSetup();

    $this->actingAs(account(Role::Technician));
    $this->getJson("/api/v1/complaints/{$complaint}")->assertNotFound();
});

it('lets the assigned Technician post Public and Internal Comments', function () {
    ['complaint' => $complaint, 'tariq' => $tariq, 'rina' => $rina] = technicianWorkSetup();

    $this->postJson("/api/v1/complaints/{$complaint}/comments", ['body' => 'Can I come at 3pm?'])->assertCreated()
        ->assertJsonPath('data.visibility', 'public')
        ->assertJsonPath('data.author', ['id' => $tariq['user']->id, 'name' => 'Tariq Technician', 'role' => 'technician']);
    $this->postJson("/api/v1/complaints/{$complaint}/comments", ['body' => 'Needs a new door sensor.', 'visibility' => 'internal'])->assertCreated();

    $this->actingAs($rina);
    expect(array_column(timelineOf($complaint, 'comment'), 'body'))->toBe(['Can I come at 3pm?']);
});

it('refuses comments from a Technician once the Complaint is Closed', function () {
    ['complaint' => $complaint] = technicianWorkSetup();
    // No endpoint closes a Complaint until a later Phase 3 ticket, so the status is set directly.
    forceStatus($complaint, ComplaintStatus::Closed);

    $this->postJson("/api/v1/complaints/{$complaint}/comments", ['body' => 'Late note.'])->assertConflict();
});

it('shows the reporter no start time', function () {
    ['complaint' => $complaint, 'rina' => $rina] = technicianWorkSetup();
    startWork($complaint)->assertOk();

    $this->actingAs($rina);
    $this->getJson("/api/v1/complaints/{$complaint}")->assertOk()->assertJsonMissingPath('data.started_at');
});
