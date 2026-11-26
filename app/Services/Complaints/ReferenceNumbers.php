<?php

namespace App\Services\Complaints;

use App\Models\Property;
use Illuminate\Support\Facades\DB;
use LogicException;

/**
 * Hands out Complaint references: CMP-<year>-<sequence>, the year taken in the
 * Property's timezone, the sequence restarting each year and padded to four digits.
 * Call inside a transaction: the year's counter row stays locked until it commits.
 * A deadlock between the year's first submissions is left to the caller's transaction retry.
 */
class ReferenceNumbers
{
    public function next(): string
    {
        if (DB::transactionLevel() === 0) {
            throw new LogicException('Reference numbers must be taken inside a transaction.');
        }

        $year = now(Property::current()->timezone)->year;

        $counter = DB::table('complaint_reference_counters')->where('year', $year);

        // Lock first, so concurrent submissions queue on the row instead of deadlocking
        // on a shared-to-exclusive upgrade. Only the year's first reference inserts.
        $last = $counter->clone()->lockForUpdate()->value('last_number');
        if ($last === null) {
            if (DB::table('complaint_reference_counters')->insertOrIgnore(['year' => $year, 'last_number' => 1]) === 1) {
                return sprintf('CMP-%d-%04d', $year, 1);
            }
            // Another submission created the year's row first: queue on it like any other.
            $last = $counter->clone()->lockForUpdate()->value('last_number');
        }

        $counter->update(['last_number' => $last + 1]);

        return sprintf('CMP-%d-%04d', $year, $last + 1);
    }
}
