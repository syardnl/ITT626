<?php

namespace App\Http\Controllers\Api;

use App\Enums\ComplaintStatus;
use App\Http\Controllers\Controller;
use App\Http\Resources\ComplaintResource;
use App\Models\Complaint;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** A Technician's task list: the Complaints whose latest Assignment is theirs, grouped for their home page. */
class TechnicianTaskController extends Controller
{
    private const GROUPS = [
        'new' => [ComplaintStatus::Assigned],
        'in_progress' => [ComplaintStatus::InProgress],
        'returned' => [ComplaintStatus::Returned],
        'completed' => [ComplaintStatus::PendingVerification, ComplaintStatus::Resolved, ComplaintStatus::Closed],
    ];

    private const COMPLETED_SHOWN = 20;

    /** Each group runs most urgent first, then longest assigned; Completed runs most recently changed first. */
    public function __invoke(Request $request): JsonResponse
    {
        $technician = $request->user()->technician;
        $query = fn () => Complaint::query()->latestAssignmentHeldBy($technician->id)->with(['location', 'category', 'assignments']);
        // Open work in full; finished work only the most recent, so the list does not grow forever.
        $tasks = $technician === null ? collect() : $query()->whereNotIn('status', self::GROUPS['completed'])->get()
            ->concat($query()->whereIn('status', self::GROUPS['completed'])->latest('updated_at')->latest('id')->limit(self::COMPLETED_SHOWN)->get());

        $groups = [];
        foreach (self::GROUPS as $group => $statuses) {
            $inGroup = $tasks->filter(fn (Complaint $complaint) => in_array($complaint->status, $statuses, true));
            $sorted = $group === 'completed'
                ? $inGroup->sortByDesc(fn (Complaint $complaint) => [$complaint->updated_at, $complaint->id])
                : $inGroup->sortBy([
                    fn (Complaint $a, Complaint $b) => $a->priority->rank() <=> $b->priority->rank(),
                    fn (Complaint $a, Complaint $b) => [$a->latestAssignment()->assigned_at, $a->id] <=> [$b->latestAssignment()->assigned_at, $b->id],
                ]);
            $groups[$group] = $sorted->values()->map(fn (Complaint $complaint) => [
                ...(new ComplaintResource($complaint))->toArray($request),
                'assigned_at' => $complaint->latestAssignment()->assigned_at->utc()->format('Y-m-d\TH:i:s\Z'),
            ])->all();
        }

        return response()->json(['data' => $groups]);
    }
}
