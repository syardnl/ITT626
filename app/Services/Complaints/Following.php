<?php

namespace App\Services\Complaints;

use App\Models\Complaint;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Following: a Resident tracks someone else's open Common Area Complaint, in their own Block
 * or a Shared Area, instead of submitting a duplicate. Anything they could not find in an
 * list of open Complaints at a Location is concealed as not found.
 */
class Following
{
    /** Following twice has no extra effect. */
    public function follow(User $resident, int $complaintId): void
    {
        $complaint = Complaint::query()
            ->where(fn ($findable) => $findable
                ->where('reporter_id', $resident->id)
                ->orWhere(fn ($shared) => $shared->atCommonAreasSharedWith($resident)))
            ->findOrFail($complaintId);

        if ($complaint->isReportedBy($resident)) {
            throw new FollowRefused('You reported this Complaint, so you are already tracking it.');
        }
        if (! $complaint->status->isOpen()) {
            throw new FollowRefused('This Complaint is no longer open, so it cannot be followed.');
        }

        // insertOrIgnore, not a check then insert: two quick taps must not hit the unique index.
        DB::table('complaint_followers')->insertOrIgnore([
            'complaint_id' => $complaint->id,
            'user_id' => $resident->id,
            'created_at' => now(),
        ]);
    }

    /** Unfollowing something not followed has no effect; a Complaint the Resident cannot see is not found. */
    public function unfollow(User $resident, int $complaintId): void
    {
        Complaint::query()->visibleTo($resident)->findOrFail($complaintId)->followers()->detach($resident->id);
    }
}
