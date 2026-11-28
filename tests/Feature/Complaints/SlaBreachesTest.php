<?php

use App\Enums\Role;
use App\Models\Category;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Storage;

require_once __DIR__.'/helpers.php';

// Every Complaint here is reported at 08:00 on 1 December 2026 at Low Priority:
// Response due 3 days later (4320 minutes), Resolution due 7 days later (10080 minutes).
beforeEach(function () {
    Storage::fake('evidence');
    $this->travelTo(CarbonImmutable::parse('2026-12-01 08:00:00', 'UTC'));
});

function atMinute(int $minutes): string
{
    return CarbonImmutable::parse('2026-12-01 08:00:00', 'UTC')->addMinutes($minutes)->format('Y-m-d\TH:i:s\Z');
}

function travelToMinute(int $minutes): void
{
    test()->travelTo(CarbonImmutable::parse('2026-12-01 08:00:00', 'UTC')->addMinutes($minutes));
}

function checkSla(): void
{
    test()->artisan('complaints:check-sla')->assertSuccessful();
}

/** Every recorded breach of the Complaint, per cycle, as the office sees it. */
function breachesOf(int $complaintId, $manager): array
{
    test()->actingAs($manager);

    return array_column(test()->getJson("/api/v1/complaints/{$complaintId}")->assertOk()->json('data.repair_cycles'), 'breaches', 'number');
}

function slaStatusOf(int $complaintId)
{
    return test()->getJson("/api/v1/complaints/{$complaintId}")->assertOk()->json('data.sla_status');
}

/** Rina's new Low Complaint at the Block A lift, not yet reviewed, and Maya the Manager signed in. */
function reportedSetup(): array
{
    $setup = complaintsSetup();
    $complaint = submitComplaint(complaintBody(['location_id' => $setup['lift']->id]))->assertCreated()->json('data.id');
    test()->actingAs($manager = account(Role::Manager, ['name' => 'Maya Manager']));

    return [...$setup, 'complaint' => $complaint, 'manager' => $manager];
}

it('records a Response breach just after the deadline passes unreviewed, not just before', function () {
    ['complaint' => $complaint, 'manager' => $manager] = reportedSetup();

    travelToMinute(4320);
    checkSla();
    expect(breachesOf($complaint, $manager)[1])->toBe([]);

    travelToMinute(4321);
    checkSla();
    expect(breachesOf($complaint, $manager)[1])->toBe([['type' => 'response', 'due_at' => atMinute(4320), 'detected_at' => atMinute(4321)]]);
});

it('records a breach for a review that came late, even when the check runs afterwards', function () {
    ['complaint' => $complaint, 'manager' => $manager] = reportedSetup();
    travelToMinute(4330);
    $this->postJson("/api/v1/complaints/{$complaint}/review", ['version' => loadedVersion($complaint)])->assertOk();

    travelToMinute(5000);
    checkSla();

    expect(breachesOf($complaint, $manager)[1])->toBe([['type' => 'response', 'due_at' => atMinute(4320), 'detected_at' => atMinute(5000)]]);
});

it('records nothing for a review in time', function () {
    ['complaint' => $complaint, 'manager' => $manager] = reportedSetup();
    travelToMinute(4320);
    $this->postJson("/api/v1/complaints/{$complaint}/review", ['version' => loadedVersion($complaint)])->assertOk();

    travelToMinute(5000);
    checkSla();

    expect(breachesOf($complaint, $manager)[1])->toBe([]);
});

it('records a Resolution breach when the repair is not approved in time, once however often the check runs', function () {
    ['complaint' => $complaint, 'manager' => $manager] = assignmentSetup();

    travelToMinute(10081);
    checkSla();
    travelToMinute(10090);
    checkSla();

    expect(breachesOf($complaint, $manager)[1])->toBe([['type' => 'resolution', 'due_at' => atMinute(10080), 'detected_at' => atMinute(10081)]]);
    Artisan::call('complaints:check-sla');
    expect(Artisan::output())->toContain('Recorded 0 new SLA Breaches.');
});

it('records the breach a late Priority upgrade causes at the next check, and never clears it', function () {
    ['complaint' => $complaint, 'manager' => $manager] = assignmentSetup();
    travelToMinute(600);
    $reclassify = fn (string $priority, ?string $reason) => $this->patchJson("/api/v1/complaints/{$complaint}/classification", [
        'category_id' => Category::where('key', 'lift')->value('id'), 'priority' => $priority, 'priority_reason' => $reason, 'version' => loadedVersion($complaint),
    ])->assertOk();

    $reclassify('critical', 'Residents are trapped.');   // Resolution due at minute 480: already past
    expect(slaStatusOf($complaint))->toBe(['state' => 'breached', 'due_at' => atMinute(480)]);
    travelToMinute(605);
    checkSla();
    $reclassify('low', null);   // the deadline moves back to minute 10080

    expect(breachesOf($complaint, $manager)[1])->toBe([['type' => 'resolution', 'due_at' => atMinute(480), 'detected_at' => atMinute(605)]])
        ->and(slaStatusOf($complaint))->toBe(['state' => 'breached', 'due_at' => atMinute(10080)]);
});

it('shows On track, then Due soon in the last 20% of the target time, then Breached', function () {
    ['complaint' => $complaint] = reportedSetup();

    // 20% of the 4320-minute Response target is 864 minutes: Due soon from minute 3457.
    foreach ([0 => 'on_track', 3456 => 'on_track', 3457 => 'due_soon', 4320 => 'due_soon', 4321 => 'breached'] as $minute => $state) {
        travelToMinute($minute);
        expect(slaStatusOf($complaint))->toBe(['state' => $state, 'due_at' => atMinute(4320)]);
    }
});

it('moves the indicator to the Resolution deadline once Response has happened', function () {
    ['complaint' => $complaint] = assignmentSetup();

    expect(slaStatusOf($complaint))->toBe(['state' => 'on_track', 'due_at' => atMinute(10080)]);
    travelToMinute(10080 - 2015);   // 20% of 10080 is 2016 minutes
    expect(slaStatusOf($complaint)['state'])->toBe('due_soon');
});

it('stays Breached for the rest of the cycle after a late Response', function () {
    ['complaint' => $complaint] = reportedSetup();
    travelToMinute(4400);
    checkSla();
    $this->postJson("/api/v1/complaints/{$complaint}/review", ['version' => loadedVersion($complaint)])->assertOk();

    expect(slaStatusOf($complaint))->toBe(['state' => 'breached', 'due_at' => atMinute(10080)]);
});

it('shows no indicator once Resolved or Closed, keeping the breaches on the detail page', function () {
    ['complaint' => $complaint, 'manager' => $manager, 'rina' => $rina] = pendingVerificationSetup();
    travelToMinute(10100);
    checkSla();
    verifyWork($complaint, ['decision' => 'approve'])->assertOk();

    expect(slaStatusOf($complaint))->toBeNull()
        ->and(collect(breachesOf($complaint, $manager)[1])->pluck('type')->all())->toBe(['resolution']);
    $this->actingAs($rina);
    confirmResolution($complaint, ['decision' => 'confirm'])->assertOk();
    expect(slaStatusOf($complaint))->toBeNull();
});

it('judges a Resident Return by its new cycle, keeping the old cycle’s breach', function () {
    ['complaint' => $complaint, 'manager' => $manager, 'rina' => $rina] = pendingVerificationSetup();
    travelToMinute(10100);
    checkSla();
    verifyWork($complaint, ['decision' => 'approve'])->assertOk();
    $this->actingAs($rina);
    confirmResolution($complaint, ['decision' => 'return', 'reason' => 'The door sticks again.'])->assertOk();

    expect(slaStatusOf($complaint))->toBe(['state' => 'on_track', 'due_at' => atMinute(10100 + 10080)])
        ->and(breachesOf($complaint, $manager))->toMatchArray([1 => [['type' => 'resolution', 'due_at' => atMinute(10080), 'detected_at' => atMinute(10100)]], 2 => []]);
    $this->getJson('/api/v1/complaints?sla=breached')->assertOk()->assertJsonPath('meta.total', 0);
});

it('lists only open Complaints whose current cycle is Breached', function () {
    ['complaint' => $late, 'manager' => $manager, 'rina' => $rina, 'lift' => $lift] = reportedSetup();
    travelToMinute(4000);
    $this->actingAs($rina);
    $fresh = submitComplaint(complaintBody(['location_id' => $lift->id]))->assertCreated()->json('data.id');

    travelToMinute(4321);   // the first is overdue for Response; the check has not run yet
    $this->actingAs($manager);
    $listed = $this->getJson('/api/v1/complaints?sla=breached')->assertOk()->json('data');

    expect(array_column($listed, 'id'))->toBe([$late])
        ->and($listed[0]['sla_status'])->toBe(['state' => 'breached', 'due_at' => atMinute(4320)])
        ->and(collect($this->getJson('/api/v1/complaints')->json('data'))->firstWhere('id', $fresh)['sla_status']['state'])->toBe('on_track');
    $this->getJson('/api/v1/complaints?sla=late')->assertUnprocessable()->assertJsonValidationErrors('sla');
});

it('shows the indicator on the Technician’s task list and the reporter’s own list', function () {
    ['complaint' => $complaint, 'tariq' => $tariq, 'rina' => $rina] = technicianWorkSetup();

    $this->actingAs($tariq['user']);
    expect($this->getJson('/api/v1/technician/tasks')->json('data.new.0.sla_status'))->toBe(['state' => 'on_track', 'due_at' => atMinute(10080)]);
    $this->actingAs($rina);
    expect($this->getJson('/api/v1/complaints')->json('data.0.sla_status.state'))->toBe('on_track');
});

it('runs the check every five minutes', function () {
    Artisan::call('schedule:list');

    expect(Artisan::output())->toMatch('/\*\/5\s+\*\s+\*\s+\*\s+\*\s+php artisan complaints:check-sla/');
});
