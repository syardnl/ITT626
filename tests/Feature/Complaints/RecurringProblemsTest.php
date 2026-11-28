<?php

use App\Enums\LocationType;
use App\Enums\Role;
use App\Models\Category;
use App\Models\Complaint;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Artisan;

require_once __DIR__.'/helpers.php';

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-12-01 08:00:00', 'UTC'));
    $this->setup = complaintsSetup();
    $this->manager = account(Role::Manager, ['name' => 'Maya Manager']);
});

/** Rina reports a Complaint at the Location (the Block A lift unless given), in the Category (Lift unless given; null = unclassified); its id. */
function reportAt(?string $category = 'lift', $location = null): int
{
    test()->actingAs(test()->setup['rina']);
    $id = submitComplaint(complaintBody([
        'location_id' => ($location ?? test()->setup['lift'])->id,
        'category_id' => $category ? Category::where('key', $category)->value('id') : null,
    ]))->assertCreated()->json('data.id');
    test()->travel(1)->days();

    return $id;
}

function detectRecurring(): string
{
    Artisan::call('complaints:detect-recurring');

    return Artisan::output();
}

function flags(?User $as = null): array
{
    test()->actingAs($as ?? test()->manager);

    return test()->getJson('/api/v1/recurring-flags')->assertOk()->json();
}

function decide(int $flagId, string $decision, ?string $reason = 'Checked on site.')
{
    test()->actingAs(test()->manager);

    return test()->postJson("/api/v1/recurring-flags/{$flagId}/{$decision}", ['reason' => $reason]);
}

it('flags three Complaints of one Category at one exact Location within 90 days, with the evidence', function () {
    $ids = [reportAt(), reportAt(), reportAt()];

    expect(detectRecurring())->toContain('Raised 1 new');
    $flag = flags()['data'][0];
    expect(flags()['data'])->toHaveCount(1)
        ->and($flag)->toMatchArray([
            'status' => 'open', 'active' => true, 'complaint_count' => 3,
            'location' => ['id' => $this->setup['lift']->id, 'code' => 'A-LIFT-1', 'name' => 'A-LIFT-1'],
            'category' => ['id' => Category::where('key', 'lift')->value('id'), 'name' => 'Lift'],
            'first_reported_at' => '2026-12-01T08:00:00Z', 'last_reported_at' => '2026-12-03T08:00:00Z',
            'decided_by' => null, 'reason' => null, 'superseded_at' => null,
        ])
        ->and(array_column($flag['complaints'], 'id'))->toBe($ids)
        ->and($flag['complaints'][0])->toHaveKeys(['reference_no', 'title', 'status', 'created_at']);
});

it('does not flag two, another Location, another Category, or unclassified Complaints', function () {
    reportAt();
    reportAt();
    reportAt('lift', $this->setup['pool']);
    reportAt('plumbing');
    reportAt(null);

    expect(detectRecurring())->toContain('Raised 0 new')
        ->and(flags()['data'])->toBe([]);
});

it('counts an exact Location only, never rolling up to the Block', function () {
    $liftTwo = place(LocationType::CommonArea, $this->setup['block'], 'A-LIFT-2');
    reportAt();
    reportAt();
    reportAt('lift', $liftTwo);

    detectRecurring();

    expect(flags()['data'])->toBe([]);
});

it('counts a Complaint once however many Repair Cycles it has, and Resolved and Closed ones too', function () {
    $closed = reportAt();
    reportAt();
    Complaint::whereKey($closed)->update(['status' => 'closed']);   // no endpoint needed: only the count matters here
    $returned = reportAt();
    Complaint::findOrFail($returned)->repairCycles()->create(['number' => 2, 'started_at' => now()]);

    detectRecurring();

    expect(flags()['data'][0]['complaint_count'])->toBe(3);
});

it('counts a classification made after submission', function () {
    reportAt();
    reportAt();
    $late = reportAt(null);
    detectRecurring();
    expect(flags()['data'])->toBe([]);

    $this->actingAs($this->manager);
    $this->postJson("/api/v1/complaints/{$late}/review", ['version' => loadedVersion($late)])->assertOk();
    $this->patchJson("/api/v1/complaints/{$late}/classification", ['category_id' => Category::where('key', 'lift')->value('id'), 'priority' => 'low', 'version' => loadedVersion($late)])->assertOk();
    detectRecurring();

    expect(flags()['data'][0]['complaint_count'])->toBe(3);
});

it('uses a rolling 90 days: the oldest ages out and the flag turns inactive, but is kept', function () {
    reportAt();
    reportAt();
    reportAt();
    detectRecurring();

    $this->travelTo(CarbonImmutable::parse('2026-12-01 08:00:00', 'UTC')->addDays(90));   // the first is exactly 90 days old
    detectRecurring();
    expect(flags()['data'][0]['active'])->toBeTrue();

    $this->travel(1)->minutes();
    expect(detectRecurring())->toContain('marked 1 inactive');
    expect(flags()['data'][0])->toMatchArray(['active' => false, 'status' => 'open', 'complaint_count' => 3]);
});

it('updates the current flag as more Complaints arrive, without raising another', function () {
    reportAt();
    reportAt();
    reportAt();
    detectRecurring();
    $id = flags()['data'][0]['id'];
    $fourth = reportAt();

    expect(detectRecurring())->toContain('Raised 0 new Recurring Problem flags, updated 1,');
    expect(flags()['data'])->toHaveCount(1)
        ->and(flags()['data'][0])->toMatchArray(['id' => $id, 'complaint_count' => 4])
        ->and(array_column(flags()['data'][0]['complaints'], 'id'))->toContain($fourth);
});

it('acknowledges a flag with a reason, audited, and keeps it current and updated', function () {
    reportAt();
    reportAt();
    reportAt();
    detectRecurring();
    $id = flags()['data'][0]['id'];

    decide($id, 'acknowledge', 'Lift motor is failing; replacement ordered.')->assertOk()
        ->assertJsonPath('data.status', 'acknowledged')
        ->assertJsonPath('data.decided_by', ['id' => $this->manager->id, 'name' => 'Maya Manager'])
        ->assertJsonPath('data.reason', 'Lift motor is failing; replacement ordered.');
    reportAt();
    detectRecurring();

    expect(flags()['data'][0])->toMatchArray(['id' => $id, 'status' => 'acknowledged', 'complaint_count' => 4]);
    $this->actingAs(account(Role::Administrator));
    $this->getJson('/api/v1/admin/audit-logs?action=recurring.acknowledged')->assertOk()
        ->assertJsonPath('data.0.target_type', 'recurring_flag')
        ->assertJsonPath('data.0.metadata', ['reason' => 'Lift motor is failing; replacement ordered.']);
});

it('keeps a dismissed flag dismissed while its evidence is unchanged', function () {
    reportAt();
    reportAt();
    reportAt();
    detectRecurring();
    $id = flags()['data'][0]['id'];
    decide($id, 'dismiss', 'Three different faults, not one problem.')->assertOk()->assertJsonPath('data.status', 'dismissed');

    detectRecurring();
    detectRecurring();

    expect(flags()['data'])->toBe([])
        ->and(flags()['history'])->toHaveCount(1)
        ->and(flags()['history'][0])->toMatchArray(['id' => $id, 'status' => 'dismissed', 'reason' => 'Three different faults, not one problem.', 'superseded_at' => null]);
});

it('raises a new open flag when a new Complaint joins a dismissed group, keeping the old one as history', function () {
    reportAt();
    reportAt();
    reportAt();
    detectRecurring();
    $old = flags()['data'][0]['id'];
    decide($old, 'dismiss')->assertOk();
    reportAt();

    expect(detectRecurring())->toContain('Raised 1 new');
    $now = flags();
    expect($now['data'])->toHaveCount(1)
        ->and($now['data'][0])->toMatchArray(['status' => 'open', 'complaint_count' => 4])
        ->and($now['data'][0]['id'])->not->toBe($old)
        ->and($now['history'][0])->toMatchArray(['id' => $old, 'status' => 'dismissed'])
        ->and($now['history'][0]['superseded_at'])->not->toBeNull();
    decide($old, 'acknowledge')->assertConflict()->assertJsonPath('message', 'This flag has been replaced by a newer one for the same Location and Category.');
});

it('refuses a second decision and a decision without a reason', function () {
    reportAt();
    reportAt();
    reportAt();
    detectRecurring();
    $id = flags()['data'][0]['id'];

    decide($id, 'acknowledge', null)->assertUnprocessable()->assertJsonValidationErrors(['reason' => 'Give a reason for this decision.']);
    decide($id, 'acknowledge', '   ')->assertUnprocessable();
    decide($id, 'acknowledge')->assertOk();
    decide($id, 'dismiss')->assertConflict()->assertJsonPath('message', 'This flag has already been acknowledged.');
    decide(999999, 'dismiss')->assertNotFound();
});

it('lists open flags before acknowledged ones', function () {
    reportAt();
    reportAt();
    reportAt();
    reportAt('lift', $this->setup['pool']);
    reportAt('lift', $this->setup['pool']);
    reportAt('lift', $this->setup['pool']);
    detectRecurring();
    $lift = collect(flags()['data'])->firstWhere('location.code', 'A-LIFT-1')['id'];
    decide($lift, 'acknowledge')->assertOk();

    expect(array_column(flags()['data'], 'status'))->toBe(['open', 'acknowledged'])
        ->and(flags()['data'][1]['id'])->toBe($lift);
});

it('runs nightly at 01:00', function () {
    Artisan::call('schedule:list');

    expect(Artisan::output())->toMatch('/^\s*0\s+1\s+\*\s+\*\s+\*\s+php artisan complaints:detect-recurring/m');
});
