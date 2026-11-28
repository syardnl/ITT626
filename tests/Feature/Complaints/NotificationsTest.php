<?php

use App\Enums\Role;

require_once __DIR__.'/helpers.php';

function myNotifications(array $query = [])
{
    return test()->getJson('/api/v1/notifications'.($query ? '?'.http_build_query($query) : ''))->assertOk();
}

it('tells every active Manager about a new Complaint, and nobody else', function () {
    $this->freezeSecond();
    $setup = complaintsSetup();
    $maya = account(Role::Manager, ['name' => 'Maya Manager']);
    $mo = account(Role::Manager, ['name' => 'Mo Manager']);
    $retired = account(Role::Manager, ['active' => false]);
    $admin = account(Role::Administrator);

    $this->actingAs($setup['rina']);
    $complaint = submitComplaint(complaintBody(['location_id' => $setup['lift']->id, 'title' => 'Lift stuck']))->assertCreated()->json('data');

    foreach ([$maya, $mo] as $manager) {
        $this->actingAs($manager);
        myNotifications()->assertJsonPath('meta.unread_count', 1)->assertJsonPath('data.0', fn (array $entry) => $entry === [
            'id' => $entry['id'],
            'event' => 'complaint_submitted',
            'message' => "New Complaint {$complaint['reference_no']}: Lift stuck at {$complaint['location']['name']}.",
            'complaint' => ['id' => $complaint['id'], 'reference_no' => $complaint['reference_no']],
            'link' => "/manager/complaints/{$complaint['id']}",
            'read_at' => null,
            'created_at' => $complaint['created_at'],
        ]);
    }
    foreach ([$setup['rina'], $admin] as $other) {
        $this->actingAs($other);
        myNotifications()->assertJsonPath('meta.total', 0)->assertJsonPath('meta.unread_count', 0);
    }
    expect($retired->notifications()->count())->toBe(0);
});

it('tells the assigned Technician and the reporter about the first Assignment, but not the Manager who made it', function () {
    ['complaint' => $complaint, 'manager' => $manager, 'tariq' => $tariq, 'rina' => $rina] = technicianWorkSetup();
    $reference = $this->getJson("/api/v1/complaints/{$complaint}")->json('data.reference_no');

    myNotifications()->assertJsonPath('meta.total', 1)
        ->assertJsonPath('data.0.event', 'assigned_to_you')
        ->assertJsonPath('data.0.message', "You have been assigned {$reference}: Lift not operating at A-LIFT-1.")
        ->assertJsonPath('data.0.link', "/technician/complaints/{$complaint}");
    $this->actingAs($rina);
    myNotifications()->assertJsonPath('meta.total', 1)
        ->assertJsonPath('data.0.event', 'your_complaint_assigned')
        ->assertJsonPath('data.0.message', "Your Complaint {$reference} has been assigned to a Technician.")
        ->assertJsonPath('data.0.link', "/resident/complaints/{$complaint}");
    $this->actingAs($manager);
    expect(collect(myNotifications()->json('data'))->pluck('event')->all())->toBe(['complaint_submitted']);
});

it('tells the new Technician about a reassignment, without telling the reporter again', function () {
    ['complaint' => $complaint, 'manager' => $manager, 'una' => $una, 'rina' => $rina] = technicianWorkSetup();
    $this->actingAs($manager);
    assign($complaint, ['technician_id' => $una['id'], 'reason' => 'Tariq is off sick.'])->assertOk();

    $this->actingAs($una['user']);
    myNotifications()->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.event', 'assigned_to_you');
    $this->actingAs($rina);
    myNotifications()->assertJsonPath('meta.total', 1);
});

it('sends nothing when the action is refused', function () {
    ['complaint' => $complaint, 'manager' => $manager, 'una' => $una, 'tariq' => $tariq] = technicianWorkSetup();
    $this->actingAs($manager);
    assign($complaint, ['technician_id' => $una['id']])->assertUnprocessable();   // a reassignment needs a reason

    $this->actingAs($una['user']);
    myNotifications()->assertJsonPath('meta.total', 0);
});

it('marks one notification read, then all of them', function () {
    $setup = complaintsSetup();
    $manager = account(Role::Manager);
    $this->actingAs($setup['rina']);
    foreach (['Lift stuck', 'Lift noisy', 'Lift dark'] as $title) {
        submitComplaint(complaintBody(['location_id' => $setup['lift']->id, 'title' => $title]))->assertCreated();
        $this->travel(1)->minutes();
    }
    $this->actingAs($manager);
    $entries = myNotifications()->json('data');
    expect(array_map(fn ($entry) => str_contains($entry['message'], 'Lift dark'), $entries))->toBe([true, false, false]);

    $this->postJson("/api/v1/notifications/{$entries[0]['id']}/read")->assertOk()
        ->assertJsonPath('data.id', $entries[0]['id'])
        ->assertJsonPath('data.read_at', fn (?string $at) => $at !== null);
    myNotifications()->assertJsonPath('meta.unread_count', 2);

    $this->postJson('/api/v1/notifications/read-all')->assertOk()->assertJsonPath('meta.unread_count', 0);
    myNotifications()->assertJsonPath('meta.unread_count', 0)->assertJsonPath('meta.total', 3);
});

it('conceals another account’s notification', function () {
    $setup = complaintsSetup();
    $maya = account(Role::Manager);
    submitComplaint(complaintBody(['location_id' => $setup['lift']->id]))->assertCreated();
    $this->actingAs($maya);
    $id = myNotifications()->json('data.0.id');

    $this->actingAs(account(Role::Manager));
    $this->postJson("/api/v1/notifications/{$id}/read")->assertNotFound();
    $this->postJson('/api/v1/notifications/not-a-uuid/read')->assertNotFound();
    $this->actingAs($maya);
    myNotifications()->assertJsonPath('meta.unread_count', 1);
});

it('pages the list twenty at a time, newest first', function () {
    $setup = complaintsSetup();
    $manager = account(Role::Manager);
    foreach (range(1, 21) as $n) {
        submitComplaint(complaintBody(['location_id' => $setup['lift']->id, 'title' => "Lift problem {$n}"]))->assertCreated();
        $this->travel(1)->seconds();
    }

    $this->actingAs($manager);
    $first = myNotifications()->assertJsonCount(20, 'data')->assertJsonPath('meta.total', 21)->assertJsonPath('meta.last_page', 2)->json('data');
    expect($first[0]['message'])->toContain('Lift problem 21');
    expect(myNotifications(['page' => 2])->assertJsonCount(1, 'data')->json('data.0.message'))->toContain('Lift problem 1 ');
});
