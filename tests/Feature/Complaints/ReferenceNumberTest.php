<?php

use Illuminate\Support\Facades\DB;

require_once __DIR__.'/helpers.php';

function referenceAt(string $utc, int $locationId): string
{
    test()->travelTo(now()->parse($utc));
    $reference = submitComplaint(complaintBody(['location_id' => $locationId]))->assertCreated()->json('data.reference_no');
    test()->travelBack();

    return $reference;
}

it('numbers Complaints in sequence within a year', function () {
    ['lift' => $lift] = complaintsSetup();

    expect(referenceAt('2026-06-01 02:00:00', $lift->id))->toBe('CMP-2026-0001')
        ->and(referenceAt('2026-06-01 03:00:00', $lift->id))->toBe('CMP-2026-0002')
        ->and(referenceAt('2026-06-02 03:00:00', $lift->id))->toBe('CMP-2026-0003');
});

it('takes the year in Kuala Lumpur time and restarts the sequence each year', function () {
    ['lift' => $lift] = complaintsSetup();

    expect(referenceAt('2026-12-31 15:59:00', $lift->id))->toBe('CMP-2026-0001')   // 23:59 in KL
        ->and(referenceAt('2026-12-31 16:01:00', $lift->id))->toBe('CMP-2027-0001')  // 00:01 on 1 January in KL
        ->and(referenceAt('2027-01-01 03:00:00', $lift->id))->toBe('CMP-2027-0002');
});

it('grows past four digits', function () {
    ['lift' => $lift] = complaintsSetup();
    // Setup only: no endpoint can hand out 9,999 references quickly.
    DB::table('complaint_reference_counters')->insert(['year' => 2026, 'last_number' => 9999]);

    expect(referenceAt('2026-06-01 02:00:00', $lift->id))->toBe('CMP-2026-10000');
});
