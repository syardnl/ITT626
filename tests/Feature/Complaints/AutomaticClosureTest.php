<?php

use App\Enums\Role;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Storage;

require_once __DIR__.'/helpers.php';

beforeEach(fn () => Storage::fake('evidence'));

function closeResolvedComplaints(): void
{
    test()->artisan('complaints:close-resolved')->assertSuccessful();
}

it('leaves a Complaint Resolved for just under 7 days', function () {
    ['complaint' => $complaint] = resolvedSetup();
    $this->travel(7)->days();
    $this->travel(-1)->minutes();

    closeResolvedComplaints();

    $this->getJson("/api/v1/complaints/{$complaint}")->assertJsonPath('data.status', 'resolved');
});

it('closes a Complaint Resolved for 7 days, with the system as actor, ending the Assignment', function () {
    ['complaint' => $complaint, 'manager' => $manager] = resolvedSetup();
    $this->travel(7)->days();
    $this->travel(1)->minutes();

    closeResolvedComplaints();

    $this->getJson("/api/v1/complaints/{$complaint}")
        ->assertJsonPath('data.status', 'closed')
        ->assertJsonPath('data.rating', null);
    expect(collect(timelineOf($complaint, 'status'))->last())->toMatchArray(['from_status' => 'resolved', 'to_status' => 'closed', 'actor' => null]);

    $this->actingAs($manager);
    $this->getJson("/api/v1/complaints/{$complaint}")->assertJsonPath('data.assignment', null);

    $this->actingAs(account(Role::Administrator));
    $entry = $this->getJson('/api/v1/admin/audit-logs?action=complaint.closed')->assertOk()->json('data.0');
    expect($entry['actor'])->toBeNull()
        ->and($entry['metadata'])->toBe(['automatic' => true])
        ->and($entry['changes']['status'])->toBe(['from' => 'resolved', 'to' => 'closed']);
});

it('refuses a confirmation that arrives after Automatic Closure with 409', function () {
    ['complaint' => $complaint] = resolvedSetup();
    $version = loadedVersion($complaint);   // the reporter opened it before the closure ran
    $this->travel(8)->days();

    closeResolvedComplaints();

    confirmResolution($complaint, ['decision' => 'confirm', 'rating' => 5, 'version' => $version])->assertConflict()
        ->assertJsonPath('message', 'This Complaint is Closed, not Resolved.');
});

it('leaves alone Complaints that were answered or are not Resolved', function () {
    ['complaint' => $complaint] = resolvedSetup();
    confirmResolution($complaint, ['decision' => 'return', 'reason' => 'Still broken.'])->assertOk();
    $this->travel(30)->days();

    closeResolvedComplaints();

    $this->getJson("/api/v1/complaints/{$complaint}")->assertJsonPath('data.status', 'returned');
});

it('runs every hour', function () {
    Artisan::call('schedule:list');

    // Column widths depend on the other scheduled commands, so any run of spaces is accepted.
    expect(Artisan::output())->toMatch('/^\s*0\s+\*\s+\*\s+\*\s+\*\s+php artisan complaints:close-resolved/m');
});
