<?php

use App\Enums\NotificationEvent;
use App\Enums\Role;
use App\Notifications\ComplaintEmail;
use Illuminate\Notifications\SendQueuedNotifications;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;

require_once __DIR__.'/helpers.php';

beforeEach(fn () => Storage::fake('evidence'));

/** What $user has been told, oldest first, as [event, message] pairs read through the API. */
function toldTo($user): array
{
    test()->actingAs($user);
    $entries = test()->getJson('/api/v1/notifications')->assertOk()->json('data');

    return array_reverse(array_map(fn (array $entry) => [$entry['event'], $entry['message']], $entries));
}

function eventsTo($user): array
{
    return array_column(toldTo($user), 0);
}

function referenceOf(int $complaintId): string
{
    return test()->getJson("/api/v1/complaints/{$complaintId}")->json('data.reference_no');
}

it('tells the Technician a Complaint was reassigned away from them', function () {
    ['complaint' => $complaint, 'manager' => $manager, 'tariq' => $tariq, 'una' => $una] = technicianWorkSetup();
    $reference = referenceOf($complaint);
    $this->actingAs($manager);
    assign($complaint, ['technician_id' => $una['id'], 'reason' => 'Tariq is off sick.'])->assertOk();

    expect(toldTo($tariq['user']))->toBe([
        ['assigned_to_you', "You have been assigned {$reference}: Lift not operating at A-LIFT-1."],
        ['reassigned_away', "{$reference}: Lift not operating has been reassigned to another Technician."],
    ]);
});

it('tells the reporter when work starts, but not the Technician who started it', function () {
    ['complaint' => $complaint, 'tariq' => $tariq, 'rina' => $rina] = inProgressSetup();
    $reference = referenceOf($complaint);

    expect(toldTo($rina))->toContain(['work_started', "Work has started on your Complaint {$reference}."])
        ->and(eventsTo($tariq['user']))->toBe(['assigned_to_you']);
});

it('tells the Managers when work is ready to verify', function () {
    ['complaint' => $complaint, 'manager' => $manager, 'tariq' => $tariq] = pendingVerificationSetup();

    expect(toldTo($manager))->toContain(['work_pending_verification', 'Work on '.referenceOf($complaint).': Lift not operating is ready for you to verify.'])
        ->and(eventsTo($tariq['user']))->not->toContain('work_pending_verification');
});

it('tells the Technician their work was returned by the Manager, without the reason', function () {
    ['complaint' => $complaint, 'tariq' => $tariq] = pendingVerificationSetup();
    verifyWork($complaint, ['decision' => 'return', 'reason' => 'The door still sticks.'])->assertOk();

    expect(toldTo($tariq['user']))->toContain(['work_returned', 'Your work on '.referenceOf($complaint).' was returned by the Manager. Open it to see why.']);
});

it('tells the reporter the repair is done and asks them to confirm', function () {
    ['complaint' => $complaint, 'rina' => $rina, 'tariq' => $tariq] = resolvedSetup();
    $reference = referenceOf($complaint);

    expect(toldTo($rina))->toContain(['your_complaint_resolved', "Your Complaint {$reference} has been repaired. Please confirm the repair, or tell us if the problem is still there."])
        ->and(eventsTo($tariq['user']))->not->toContain('your_complaint_resolved');
});

it('tells the Managers and the Technician about a Resident Return', function () {
    ['complaint' => $complaint, 'manager' => $manager, 'tariq' => $tariq, 'rina' => $rina] = resolvedSetup();
    $reference = referenceOf($complaint);
    confirmResolution($complaint, ['decision' => 'return', 'reason' => 'The door sticks again.'])->assertOk();

    expect(toldTo($manager))->toContain(['resident_returned', "The Resident returned {$reference}: Lift not operating. The problem is still there."])
        ->and(toldTo($tariq['user']))->toContain(['work_returned', "{$reference} was returned by the Resident: the problem is still there. Open it to see why."])
        ->and(eventsTo($rina))->not->toContain('resident_returned');
});

it('does not tell the reporter about a Closure they made', function () {
    ['complaint' => $complaint, 'rina' => $rina] = resolvedSetup();
    confirmResolution($complaint, ['decision' => 'confirm'])->assertOk();

    expect(eventsTo($rina))->not->toContain('your_complaint_closed');
});

it('tells the reporter about Automatic Closure', function () {
    ['complaint' => $complaint, 'rina' => $rina] = resolvedSetup();
    $reference = referenceOf($complaint);
    $this->travel(8)->days();
    $this->artisan('complaints:close-resolved')->assertSuccessful();

    expect(toldTo($rina))->toContain(['your_complaint_closed', "Your Complaint {$reference} was closed automatically after 7 days without an answer."]);
});

it('tells Followers about each status change with the summary only', function () {
    ['liftComplaint' => $lift, 'rina' => $rina] = openComplaintsSetup();
    $this->postJson("/api/v1/complaints/{$lift['id']}/follow")->assertSuccessful();
    $this->actingAs($manager = account(Role::Manager));
    $this->postJson("/api/v1/complaints/{$lift['id']}/review", ['version' => loadedVersion($lift['id'])])->assertOk();

    $told = toldTo($rina);
    expect($told)->toBe([['followed_complaint_changed', "{$lift['reference_no']}: Lift stuck on floor 3, which you follow, is now Under Review."]])
        ->and(json_encode($told))->not->toContain('since morning');   // the description stays private
});

it('tells every Manager about each SLA Breach once, however often the check runs', function () {
    $setup = complaintsSetup();
    $complaint = submitComplaint(complaintBody(['location_id' => $setup['lift']->id]))->assertCreated()->json('data');
    $manager = account(Role::Manager);
    $this->travelTo(now()->addDays(3)->addMinutes(5));

    $this->artisan('complaints:check-sla')->assertSuccessful();
    $this->artisan('complaints:check-sla')->assertSuccessful();

    $breaches = array_values(array_filter(toldTo($manager), fn (array $told) => $told[0] === 'sla_breached'));
    expect($breaches)->toHaveCount(1)
        ->and($breaches[0][1])->toStartWith("SLA breached: {$complaint['reference_no']}: Lift not operating missed its Response target (due ");
});

it('emails only the four events, queued, without the description', function () {
    Queue::fake();
    ['complaint' => $complaint, 'manager' => $manager, 'tariq' => $tariq, 'rina' => $rina] = technicianWorkSetup();
    startWork($complaint)->assertOk();
    completeWork($complaint, completionBody())->assertOk();
    $this->actingAs($manager);
    verifyWork($complaint, ['decision' => 'approve'])->assertOk();
    $this->travel(30)->days();
    $this->artisan('complaints:check-sla')->assertSuccessful();   // the approval came in time: no breach

    $emails = collect(Queue::pushed(SendQueuedNotifications::class))
        ->filter(fn (SendQueuedNotifications $job) => $job->notification instanceof ComplaintEmail)
        ->flatMap(fn (SendQueuedNotifications $job) => $job->notifiables->map(fn ($user) => [$user->id, $job->notification->event->value]))
        ->all();

    expect($emails)->toEqualCanonicalizing([
        [$manager->id, 'complaint_submitted'],
        [$tariq['user']->id, 'assigned_to_you'],
        [$rina->id, 'your_complaint_assigned'],
        [$rina->id, 'your_complaint_resolved'],
    ]);
    $mail = (new ComplaintEmail(NotificationEvent::YourComplaintResolved, $complaint, 'CMP-X', 'Your Complaint CMP-X has been repaired.'))->toMail($rina);
    expect($mail->subject)->toBe('Please confirm the repair of CMP-X')
        ->and($mail->actionUrl)->toEndWith("/resident/complaints/{$complaint}")
        ->and(json_encode($mail->toArray()))->not->toContain('since morning');
});

it('emails the Managers about an SLA Breach', function () {
    Queue::fake();
    $setup = complaintsSetup();
    submitComplaint(complaintBody(['location_id' => $setup['lift']->id]))->assertCreated();
    $manager = account(Role::Manager);
    $this->travel(4)->days();

    $this->artisan('complaints:check-sla')->assertSuccessful();

    Queue::assertPushed(SendQueuedNotifications::class, fn (SendQueuedNotifications $job) => $job->notification instanceof ComplaintEmail
        && $job->notification->event->value === 'sla_breached' && $job->notifiables->pluck('id')->all() === [$manager->id]);
});

it('never emails a Follower', function () {
    Queue::fake();
    ['liftComplaint' => $lift, 'rina' => $rina] = openComplaintsSetup();
    $this->postJson("/api/v1/complaints/{$lift['id']}/follow")->assertSuccessful();
    $this->actingAs(account(Role::Manager));
    $this->postJson("/api/v1/complaints/{$lift['id']}/review", ['version' => loadedVersion($lift['id'])])->assertOk();

    Queue::assertNotPushed(SendQueuedNotifications::class, fn (SendQueuedNotifications $job) => $job->notifiables->contains('id', $rina->id));
});
