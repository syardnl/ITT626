<?php

use App\Enums\ComplaintStatus;
use App\Enums\Role;
use App\Models\Complaint;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

require_once __DIR__.'/helpers.php';

beforeEach(fn () => Storage::fake('evidence'));

it('completes In Progress work as a Maintenance Record, moving it to Pending Verification', function () {
    ['complaint' => $complaint, 'tariq' => $tariq] = inProgressSetup();

    $record = completeWork($complaint, completionBody([
        'parts_used' => 'Door sensor DS-200',
        'notes' => 'Sensor was corroded.',
        'expenses' => [
            ['description' => 'Door sensor', 'quantity' => 1, 'amount' => '185.50'],
            ['description' => 'Cable ties', 'quantity' => 10, 'amount' => '4.00'],
        ],
    ]))->assertOk()
        ->assertJsonPath('data.status', 'pending_verification')
        ->json('data.maintenance_records.0');

    expect($record)->toMatchArray([
        'attempt' => 1,
        'repair_cycle' => 1,
        'technician' => ['id' => $tariq['id'], 'name' => 'Tariq Technician'],
        'repair_result' => 'Replaced the door sensor; the lift runs normally.',
        'parts_used' => 'Door sensor DS-200',
        'notes' => 'Sensor was corroded.',
        'verification_outcome' => null,
        'expense_total' => '189.50',
    ])->and($record['expenses'])->toBe([
        ['description' => 'Door sensor', 'quantity' => '1.00', 'amount' => '185.50'],
        ['description' => 'Cable ties', 'quantity' => '10.00', 'amount' => '4.00'],
    ])->and($record['submitted_at'])->not->toBeNull();
    expect(collect(timelineOf($complaint, 'status'))->last())->toMatchArray(['from_status' => 'in_progress', 'to_status' => 'pending_verification']);
});

it('stores the photos re-encoded and tagged Before or After on the record', function () {
    ['complaint' => $complaint] = inProgressSetup();

    $data = completeWork($complaint, completionBody([
        'photos' => [UploadedFile::fake()->image('before.png', 900, 700), UploadedFile::fake()->image('after.webp', 900, 700)],
        'photo_tags' => ['before', 'after'],
    ]))->assertOk()->json('data');

    $photos = collect($data['evidence'])->where('maintenance_record_id', $data['maintenance_records'][0]['id'])->values();
    expect($photos->pluck('type')->all())->toBe(['before_photo', 'after_photo'])
        ->and($photos->pluck('mime')->unique()->all())->toBe(['image/jpeg'])
        ->and($data['maintenance_records'][0]['photo_ids'])->toBe($photos->pluck('id')->all());
    $this->get($photos[1]['url'])->assertOk()->assertHeader('Content-Type', 'image/jpeg');
});

it('requires a repair result and at least one After photo', function () {
    ['complaint' => $complaint] = inProgressSetup();

    completeWork($complaint, ['repair_result' => '', 'photos' => [], 'photo_tags' => []])->assertUnprocessable()
        ->assertJsonValidationErrors(['repair_result', 'photos']);
    completeWork($complaint, completionBody(['photo_tags' => ['before']]))->assertUnprocessable()
        ->assertJsonValidationErrors(['photos' => 'Add at least one After photo of the finished repair.']);
});

it('applies the report photo rules: at most five, images only, one tag each', function () {
    ['complaint' => $complaint] = inProgressSetup();
    $six = array_map(fn ($n) => UploadedFile::fake()->image("p{$n}.jpg"), range(1, 6));

    completeWork($complaint, completionBody(['photos' => $six, 'photo_tags' => array_fill(0, 6, 'after')]))
        ->assertUnprocessable()->assertJsonValidationErrors('photos');
    completeWork($complaint, completionBody(['photos' => [UploadedFile::fake()->create('notes.pdf', 10, 'application/pdf')]]))
        ->assertUnprocessable()->assertJsonValidationErrors('photos.0');
    completeWork($complaint, completionBody(['photo_tags' => ['during']]))->assertUnprocessable()->assertJsonValidationErrors('photo_tags.0');
    completeWork($complaint, completionBody(['photo_tags' => ['after', 'before']]))->assertUnprocessable()->assertJsonValidationErrors('photo_tags');
});

it('validates expense lines', function () {
    ['complaint' => $complaint] = inProgressSetup();

    completeWork($complaint, completionBody(['expenses' => [['description' => '', 'quantity' => 0, 'amount' => '12.345']]]))
        ->assertUnprocessable()->assertJsonValidationErrors(['expenses.0.description', 'expenses.0.quantity', 'expenses.0.amount']);
    completeWork($complaint, completionBody(['expenses' => [['description' => 'Pipe', 'quantity' => '1.555', 'amount' => '3']]]))
        ->assertUnprocessable()->assertJsonValidationErrors('expenses.0.quantity');
    completeWork($complaint, completionBody(['expenses' => [['description' => '', 'quantity' => 0, 'amount' => '12.345']]]))
        ->assertUnprocessable()->assertJsonValidationErrors(['expenses.0.description', 'expenses.0.quantity', 'expenses.0.amount']);
});

it('numbers each completion as a new attempt and keeps the earlier records', function () {
    ['complaint' => $complaint] = inProgressSetup();
    completeWork($complaint, completionBody())->assertOk();
    // No endpoint returns the work until the verification ticket, so the status is set directly.
    forceStatus($complaint, ComplaintStatus::Returned);
    startWork($complaint)->assertOk();

    $records = completeWork($complaint, completionBody(['repair_result' => 'Replaced the controller board.']))->assertOk()
        ->json('data.maintenance_records');

    expect(array_column($records, 'attempt'))->toBe([1, 2])
        ->and($records[1]['repair_result'])->toBe('Replaced the controller board.');
});

it('answers a retry with the same Idempotency-Key without a second record', function () {
    ['complaint' => $complaint] = inProgressSetup();
    $key = ['Idempotency-Key' => 'complete-0123456789abcdef'];
    $version = loadedVersion($complaint);

    completeWork($complaint, completionBody(['version' => $version]), $key)->assertOk();
    completeWork($complaint, completionBody(['version' => $version]), $key)->assertOk()
        ->assertJsonPath('data.status', 'pending_verification')
        ->assertJsonCount(1, 'data.maintenance_records');
    expect(Storage::disk('evidence')->allFiles())->toHaveCount(1);
});

it('records the completion in the audit log', function () {
    ['complaint' => $complaint, 'tariq' => $tariq] = inProgressSetup();
    $recordId = completeWork($complaint, completionBody())->json('data.maintenance_records.0.id');

    $this->actingAs(account(Role::Administrator));
    $entry = $this->getJson('/api/v1/admin/audit-logs?action=complaint.work_completed')->assertOk()->json('data.0');
    expect($entry)->toMatchArray(['target_type' => 'complaint', 'target_id' => $complaint])
        ->and($entry['actor']['id'])->toBe($tariq['user']->id)
        ->and($entry['changes']['status'])->toBe(['from' => 'in_progress', 'to' => 'pending_verification'])
        ->and($entry['metadata'])->toBe(['maintenance_record_id' => $recordId, 'attempt' => 1]);
});

it('asks to start work first, and refuses once the work is with the office', function () {
    ['complaint' => $complaint] = technicianWorkSetup();

    completeWork($complaint, completionBody())->assertConflict()->assertJsonPath('message', 'Start work before completing it.');
    startWork($complaint)->assertOk();
    completeWork($complaint, completionBody())->assertOk();
    completeWork($complaint, completionBody())->assertConflict()->assertJsonPath('message', 'This Complaint is already Pending Verification.');
});

it('refuses a completion sent from a stale copy', function () {
    ['complaint' => $complaint] = inProgressSetup();

    completeWork($complaint, completionBody(['version' => loadedVersion($complaint) + 1]))->assertConflict();
    completeWork($complaint, completionBody(['version' => null]))->assertUnprocessable()->assertJsonValidationErrors('version');
});

it('conceals the Complaint from other Technicians and refuses other roles', function () {
    ['complaint' => $complaint, 'una' => $una] = inProgressSetup();

    $this->actingAs($una['user']);
    completeWork($complaint, completionBody())->assertNotFound();
    foreach ([Role::Resident, Role::Manager, Role::Administrator] as $role) {
        $this->actingAs(account($role));
        completeWork($complaint, completionBody())->assertForbidden();
    }
});

it('shows the office every record with expenses, and the reporter only the result once Resolved', function () {
    ['complaint' => $complaint, 'manager' => $manager, 'rina' => $rina] = inProgressSetup();
    $data = completeWork($complaint, completionBody(['parts_used' => 'Sensor', 'notes' => 'Corroded.', 'expenses' => [['description' => 'Sensor', 'quantity' => 1, 'amount' => '185.50']]]))
        ->assertOk()->json('data');
    $afterPhoto = collect($data['evidence'])->firstWhere('type', 'after_photo');

    $this->actingAs($manager);
    expect($this->getJson("/api/v1/complaints/{$complaint}")->json('data.maintenance_records.0'))
        ->toHaveKeys(['expenses', 'expense_total', 'parts_used', 'notes', 'technician']);

    $this->actingAs($rina);
    expect($this->getJson("/api/v1/complaints/{$complaint}")->json('data.maintenance_records'))->toBe([]);
    $this->get($afterPhoto['url'])->assertNotFound();

    // No endpoint approves work until the verification ticket, so Resolved is set directly.
    Complaint::findOrFail($complaint)->forceFill(['status' => ComplaintStatus::Resolved, 'resolved_at' => now()])->save();
    $record = $this->getJson("/api/v1/complaints/{$complaint}")->assertOk()->json('data.maintenance_records.0');
    expect(array_keys($record))->toEqualCanonicalizing(['id', 'attempt', 'repair_result', 'submitted_at', 'photo_ids']);
    $this->get($afterPhoto['url'])->assertOk();
});

it('keeps another Technician from opening Before and After photos', function () {
    ['complaint' => $complaint, 'manager' => $manager, 'tariq' => $tariq, 'una' => $una] = inProgressSetup();
    $url = collect(completeWork($complaint, completionBody())->json('data.evidence'))->firstWhere('type', 'after_photo')['url'];
    forceStatus($complaint, ComplaintStatus::Returned);   // no Manager Rejection endpoint until the verification ticket
    $this->actingAs($manager);
    assign($complaint, ['technician_id' => $una['id'], 'reason' => 'Tariq is off sick.'])->assertOk();

    $this->actingAs($una['user']);
    $this->get($url)->assertNotFound();
    expect(collect($this->getJson("/api/v1/complaints/{$complaint}")->json('data.evidence'))->where('type', 'after_photo'))->toBeEmpty();
});

it('refuses an Idempotency-Key already used on another Complaint', function () {
    ['complaint' => $complaint, 'tariq' => $tariq, 'manager' => $manager] = $setup = inProgressSetup();
    $key = ['Idempotency-Key' => 'complete-0123456789abcdef'];
    completeWork($complaint, completionBody(), $key)->assertOk();
    $this->actingAs($manager);
    $other = classifiedComplaint($setup);
    assign($other, ['technician_id' => $tariq['id']])->assertOk();
    $this->actingAs($tariq['user']);
    startWork($other)->assertOk();

    completeWork($other, completionBody(), $key)->assertUnprocessable()->assertJsonValidationErrors('idempotency_key');
});

it('shows a Technician only their own records after a reassignment', function () {
    ['complaint' => $complaint, 'manager' => $manager, 'una' => $una] = inProgressSetup();
    completeWork($complaint, completionBody())->assertOk();
    forceStatus($complaint, ComplaintStatus::Returned);   // no Manager Rejection endpoint until the verification ticket
    $this->actingAs($manager);
    assign($complaint, ['technician_id' => $una['id'], 'reason' => 'Tariq is off sick.'])->assertOk();

    $this->actingAs($una['user']);
    expect($this->getJson("/api/v1/complaints/{$complaint}")->assertOk()->json('data.maintenance_records'))->toBe([]);
    startWork($complaint)->assertOk();
    $records = completeWork($complaint, completionBody())->assertOk()->json('data.maintenance_records');
    expect(array_column($records, 'attempt'))->toBe([2]);
});
