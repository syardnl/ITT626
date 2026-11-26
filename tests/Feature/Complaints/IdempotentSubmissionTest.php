<?php

use App\Enums\Role;
use App\Models\Complaint;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

require_once __DIR__.'/helpers.php';

function retryKey(): string
{
    return '3f2b9c4e-8a1d-4c7e-9b6a-2d5e8f1a0c3b';
}

function submitWithKey(array $body, ?string $key = 'use-the-retry-key')
{
    $key = $key === 'use-the-retry-key' ? retryKey() : $key;

    return test()->postJson('/api/v1/complaints', $body, $key === null ? [] : ['Idempotency-Key' => $key]);
}

it('returns the original Complaint when a Resident repeats a submission with the same key', function () {
    ['lift' => $lift] = complaintsSetup();
    $body = complaintBody(['location_id' => $lift->id]);

    $first = submitWithKey($body)->assertCreated()->json();
    $again = submitWithKey($body)->assertCreated()->json();

    expect($again)->toBe($first)
        ->and(Complaint::count())->toBe(1);
});

it('never shows another Resident the Complaint behind a key they happen to reuse', function () {
    ['lift' => $lift, 'neighbour' => $neighbour] = complaintsSetup();
    $rinas = submitWithKey(complaintBody(['location_id' => $lift->id, 'title' => 'Lift stuck (Rina)']))->json('data');

    $this->actingAs(account(Role::Resident, ['unit_id' => $neighbour->id]));
    $sams = submitWithKey(complaintBody(['location_id' => $lift->id, 'title' => 'Lift stuck (Sam)']))->assertCreated()->json('data');

    expect($sams['id'])->not->toBe($rinas['id'])
        ->and($sams['title'])->toBe('Lift stuck (Sam)')
        ->and(Complaint::count())->toBe(2);
});

it('creates a new Complaint for a different key or no key', function () {
    ['lift' => $lift] = complaintsSetup();
    $body = complaintBody(['location_id' => $lift->id]);

    submitWithKey($body)->assertCreated();
    submitWithKey($body, 'a-different-key-0123456789')->assertCreated();
    submitWithKey($body, null)->assertCreated();
    submitWithKey($body, null)->assertCreated();

    expect(Complaint::count())->toBe(4);
});

it('does not use up a key on a submission that failed validation', function () {
    ['lift' => $lift] = complaintsSetup();

    submitWithKey(complaintBody(['location_id' => $lift->id, 'title' => 'x']))->assertUnprocessable();
    $corrected = submitWithKey(complaintBody(['location_id' => $lift->id, 'title' => 'Lift stuck again']))->assertCreated();

    expect($corrected->json('data.title'))->toBe('Lift stuck again')
        ->and(Complaint::count())->toBe(1);
});

it('rejects a malformed key', function (string $key) {
    ['lift' => $lift] = complaintsSetup();

    submitWithKey(complaintBody(['location_id' => $lift->id]), $key)
        ->assertUnprocessable()->assertJsonValidationErrors('idempotency_key');
    expect(Complaint::count())->toBe(0);
})->with([
    'empty' => '',
    'too short' => 'abc123',
    'too long' => str_repeat('a', 101),
    'spaces' => 'has spaces in the key 12345',
    'other characters' => 'key/with;odd<chars>0123',
]);

it('stores the photos of a repeated submission only once', function () {
    Storage::fake('evidence');
    ['lift' => $lift] = complaintsSetup();
    $body = complaintBody(['location_id' => $lift->id, 'photos' => [UploadedFile::fake()->image('p.jpg')]]);
    $send = fn () => $this->post('/api/v1/complaints', $body, ['Accept' => 'application/json', 'Idempotency-Key' => retryKey()]);

    $first = $send()->assertCreated()->json('data.evidence');
    $again = $send()->assertCreated()->json('data.evidence');

    expect($again)->toBe($first)
        ->and(Storage::disk('evidence')->allFiles())->toHaveCount(1);
});
