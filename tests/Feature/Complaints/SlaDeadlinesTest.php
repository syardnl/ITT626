<?php

use App\Enums\Role;
use App\Models\Category;
use App\Models\Complaint;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

require_once __DIR__.'/helpers.php';

beforeEach(function () {
    Storage::fake('evidence');
    $this->travelTo(CarbonImmutable::parse('2026-12-01 08:00:00', 'UTC'));
});

/** The time $minutes after 08:00 on 1 December 2026 (when every test's Complaint is reported), as the API writes it. */
function reportedPlus(int $minutes): string
{
    return CarbonImmutable::parse('2026-12-01 08:00:00', 'UTC')->addMinutes($minutes)->format('Y-m-d\TH:i:s\Z');
}

function nowAsApi(): string
{
    return now()->utc()->format('Y-m-d\TH:i:s\Z');
}

/** The Complaint's Repair Cycles as the office sees them, opened by $manager. */
function cyclesOf(int $complaintId, $manager): array
{
    test()->actingAs($manager);

    return test()->getJson("/api/v1/complaints/{$complaintId}")->assertOk()->json('data.repair_cycles');
}

function reclassify(int $complaintId, string $priority): void
{
    test()->patchJson("/api/v1/complaints/{$complaintId}/classification", [
        'category_id' => Category::where('key', 'lift')->value('id'), 'priority' => $priority,
        'priority_reason' => $priority === 'low' ? null : 'Residents are trapped.', 'version' => loadedVersion($complaintId),
    ])->assertOk();
}

it('gives Repair Cycle 1 Response and Resolution deadlines from when it was reported, with the Target it started with', function () {
    $setup = complaintsSetup();
    $complaint = submitComplaint(complaintBody(['location_id' => $setup['lift']->id]))->assertCreated()->json('data');

    expect($complaint['priority'])->toBe('low')
        ->and($complaint['sla'])->toBe(['repair_cycle' => 1, 'response_due_at' => reportedPlus(4320), 'resolution_due_at' => reportedPlus(10080)]);
    expect(cyclesOf($complaint['id'], account(Role::Manager)))->toBe([[
        'number' => 1, 'started_at' => reportedPlus(0),
        'sla_target' => ['response_minutes' => 4320, 'resolution_minutes' => 10080, 'version' => 1],
        'response_due_at' => reportedPlus(4320), 'responded_at' => null,
        'resolution_due_at' => reportedPlus(10080), 'resolved_at' => null,
        'breaches' => [],
    ]]);
});

it('records the Response when review begins, leaving the deadlines as they were', function () {
    ['complaint' => $complaint, 'manager' => $manager] = assignmentSetup();

    expect(cyclesOf($complaint, $manager)[0])->toMatchArray([
        'responded_at' => reportedPlus(0), 'response_due_at' => reportedPlus(4320), 'resolution_due_at' => reportedPlus(10080),
    ]);
});

it('recalculates the Resolution deadline from the report time when the Priority changes, keeping the Response deadline once Response has happened', function (string $priority, int $resolutionMinutes) {
    ['complaint' => $complaint, 'manager' => $manager] = assignmentSetup();
    $this->travel(2)->hours();

    reclassify($complaint, $priority);

    expect(cyclesOf($complaint, $manager)[0])->toMatchArray([
        'sla_target' => ['response_minutes' => ['critical' => 60, 'high' => 240, 'medium' => 1440][$priority], 'resolution_minutes' => $resolutionMinutes, 'version' => 1],
        'response_due_at' => reportedPlus(4320), 'resolution_due_at' => reportedPlus($resolutionMinutes),
    ]);
})->with([['critical', 480], ['high', 1440], ['medium', 4320]]);

it('puts the Resolution deadline in the past when an upgrade comes late', function () {
    ['complaint' => $complaint, 'manager' => $manager] = assignmentSetup();
    $this->travel(10)->hours();

    reclassify($complaint, 'critical');

    expect(cyclesOf($complaint, $manager)[0]['resolution_due_at'])->toBe(reportedPlus(480))
        ->and(reportedPlus(480) < nowAsApi())->toBeTrue();
});

it('leaves the deadlines alone when only the Category changes', function () {
    ['complaint' => $complaint, 'manager' => $manager] = assignmentSetup();
    $this->travel(1)->hours();

    $this->patchJson("/api/v1/complaints/{$complaint}/classification", [
        'category_id' => Category::where('key', 'electrical')->value('id'), 'priority' => 'low', 'version' => loadedVersion($complaint),
    ])->assertOk();

    expect(cyclesOf($complaint, $manager)[0]['resolution_due_at'])->toBe(reportedPlus(10080));
});

it('records the Resolution on the current cycle when the Manager approves the work', function () {
    ['complaint' => $complaint, 'manager' => $manager] = pendingVerificationSetup();
    $this->travel(3)->hours();

    verifyWork($complaint, ['decision' => 'approve'])->assertOk();

    expect(cyclesOf($complaint, $manager))->toHaveCount(1)
        ->and(cyclesOf($complaint, $manager)[0]['resolved_at'])->toBe(reportedPlus(180));
});

it('keeps the cycle and its deadline after a Manager Rejection', function () {
    ['complaint' => $complaint, 'manager' => $manager] = pendingVerificationSetup();
    $before = cyclesOf($complaint, $manager);
    $this->travel(1)->days();

    verifyWork($complaint, ['decision' => 'return', 'reason' => 'The door still sticks.'])->assertOk();

    expect(cyclesOf($complaint, $manager))->toBe($before);
});

it('starts a new cycle with a fresh Resolution deadline and no Response on a Resident Return', function () {
    ['complaint' => $complaint, 'manager' => $manager] = resolvedSetup();
    $this->travel(2)->days();

    confirmResolution($complaint, ['decision' => 'return', 'reason' => 'The door sticks again.'])->assertOk()
        ->assertJsonPath('data.sla', ['repair_cycle' => 2, 'response_due_at' => null, 'resolution_due_at' => reportedPlus(2 * 1440 + 10080)]);

    $cycles = cyclesOf($complaint, $manager);
    expect($cycles)->toHaveCount(2)
        ->and($cycles[0])->toMatchArray(['resolved_at' => reportedPlus(0), 'resolution_due_at' => reportedPlus(10080)])
        ->and($cycles[1])->toMatchArray([
            'number' => 2, 'started_at' => reportedPlus(2 * 1440),
            'response_due_at' => null, 'responded_at' => null,
            'resolution_due_at' => reportedPlus(2 * 1440 + 10080), 'resolved_at' => null,
        ]);
});

it('applies a change to the SLA Targets only to cycles started afterwards', function () {
    ['complaint' => $old, 'manager' => $manager, 'rina' => $rina, 'lift' => $lift] = assignmentSetup();
    $this->actingAs(account(Role::Administrator));
    $rules = collect($this->getJson('/api/v1/admin/sla-rules')->json('data'))->keyBy('priority');
    $this->patchJson("/api/v1/admin/sla-rules/{$rules['low']['id']}", ['resolution_minutes' => 5000])->assertOk();
    $this->patchJson("/api/v1/admin/sla-rules/{$rules['critical']['id']}", ['resolution_minutes' => 120])->assertOk();

    $this->actingAs($rina);
    $new = submitComplaint(complaintBody(['location_id' => $lift->id]))->assertCreated()->json('data');
    expect($new['sla']['resolution_due_at'])->toBe(reportedPlus(5000));

    // The older cycle keeps its snapshot, even when its Priority changes after the edit.
    $this->actingAs($manager);
    reclassify($old, 'critical');
    expect(cyclesOf($old, $manager)[0])->toMatchArray([
        'sla_target' => ['response_minutes' => 60, 'resolution_minutes' => 480, 'version' => 1],
        'resolution_due_at' => reportedPlus(480),
    ]);
});

it('shows the reporter and the Technician the current deadlines but not the Repair Cycles', function () {
    ['complaint' => $complaint, 'rina' => $rina, 'tariq' => $tariq] = technicianWorkSetup();
    $expected = ['repair_cycle' => 1, 'response_due_at' => reportedPlus(4320), 'resolution_due_at' => reportedPlus(10080)];

    foreach ([$rina, $tariq['user']] as $viewer) {
        $this->actingAs($viewer);
        $this->getJson("/api/v1/complaints/{$complaint}")->assertOk()
            ->assertJsonPath('data.sla', $expected)
            ->assertJsonMissingPath('data.repair_cycles');
    }
});

it('fills in the deadlines and milestones of cycles that existed before Phase 4', function () {
    // Migrations have no endpoint: the backfill step of the Phase 4 migration is run directly on cycles stripped of their SLA columns.
    ['complaint' => $complaint, 'manager' => $manager] = resolvedSetup();
    $this->travel(2)->days();
    confirmResolution($complaint, ['decision' => 'return', 'reason' => 'The door sticks again.'])->assertOk();
    $expected = cyclesOf($complaint, $manager);
    DB::table('repair_cycles')->update(['sla_targets' => null, 'response_due_at' => null, 'resolution_due_at' => null, 'responded_at' => null, 'resolved_at' => null]);

    $migration = require database_path('migrations/2026_12_04_000000_add_sla_to_repair_cycles.php');
    (fn () => $this->backfill())->call($migration);

    expect(cyclesOf($complaint, $manager))->toBe($expected)
        ->and(Complaint::findOrFail($complaint)->repairCycles)->toHaveCount(2);
});
