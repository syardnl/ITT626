<?php

namespace App\Http\Resources;

use App\Enums\ComplaintStatus;
use App\Enums\Role;
use App\Models\Assignment;
use App\Models\ClassificationChange;
use App\Models\Comment;
use App\Models\Complaint;
use App\Models\Evidence;
use App\Models\RepairCycle;
use App\Models\StatusChange;
use App\Models\User;
use Illuminate\Http\Request;

/**
 * A Complaint opened in full: the answers, the Priority Score, the photos and the timeline of status changes and comments.
 * Staff also see who reported it (the office with their email); the assigned Technician sees what they may do. @mixin Complaint
 */
class ComplaintDetailResource extends ComplaintResource
{
    public const RELATIONS = ['location', 'category', 'statusHistory.actor', 'reporter.unit', 'evidence.uploader', 'comments.author.roles', 'repairCycles',
        'classificationChanges.actor', 'classificationChanges.fromCategory', 'classificationChanges.toCategory',
        'assignments.technician.user', 'assignments.assigner'];

    public function toArray(Request $request): array
    {
        return [
            ...parent::toArray($request),
            // The office sees the Category's default priority as a baseline next to the Priority Score.
            'category' => $this->category ? [
                'id' => $this->category->id,
                'name' => $this->category->name,
                ...($request->user()->isOffice() ? ['default_priority' => $this->category->default_priority->value] : []),
            ] : null,
            // The office and the Technician; only the office sees the email.
            'reporter' => $this->when($request->user()->isStaff(), fn () => [
                'id' => $this->reporter->id,
                'name' => $this->reporter->name,
                ...($request->user()->isOffice() ? ['email' => $this->reporter->email] : []),
                'unit' => $this->reporter->unit ? ['id' => $this->reporter->unit->id, 'code' => $this->reporter->unit->code] : null,
            ]),
            'description' => $this->description,
            'urgency' => $this->urgency->value,
            'affected_units' => $this->affected_units->value,
            'essential_service_down' => $this->essential_service_down,
            'priority_score' => $this->priority_score,
            'priority_breakdown' => $this->priority_breakdown,
            'priority_rule_version' => $this->priority_rule_version,
            'initial_priority' => $this->initial_priority->value,
            'reviewed_at' => $this->reviewed_at?->utc()->format('Y-m-d\TH:i:s\Z'),
            // Sent back with a workflow action, so a change made meanwhile by someone else is refused (409).
            'version' => $this->version,
            'evidence' => $this->evidence->map(fn (Evidence $photo) => [
                'id' => $photo->id,
                'type' => $photo->type->value,
                'mime' => $photo->mime,
                'size_bytes' => $photo->size_bytes,
                'width' => $photo->width,
                'height' => $photo->height,
                'uploaded_by' => ['id' => $photo->uploader->id, 'name' => $photo->uploader->name],
                'url' => "/api/v1/attachments/{$photo->id}",
            ])->all(),
            'repair_cycles' => $this->when($request->user()->isOffice(), fn () => $this->repairCycles->map(fn (RepairCycle $cycle) => [
                'number' => $cycle->number,
                'started_at' => $cycle->started_at->utc()->format('Y-m-d\TH:i:s\Z'),
            ])->all()),
            // The office sees whose work it is now; a Technician gets assigned_to_you instead.
            'assignment' => $this->when($request->user()->isOffice(), fn () => $this->currentAssignmentEntry()),
            // Staff only: when the Technician first started the latest Assignment's work.
            'started_at' => $this->when($request->user()->isStaff(), fn () => $this->latestAssignment()?->started_at?->utc()->format('Y-m-d\TH:i:s\Z')),
            // A Technician's own Complaint: they may comment while it is theirs, and start work when ComplaintStatus::STARTABLE.
            'assigned_to_you' => $this->when($request->user()->hasRole(Role::Technician), fn () => $this->isAssignedTo($request->user())),
            'can_start_work' => $this->when($request->user()->hasRole(Role::Technician), fn () => $this->isAssignedTo($request->user())
                && in_array($this->status, ComplaintStatus::STARTABLE, true)),
            'timeline' => $this->timelineFor($request->user()),
        ];
    }

    private function currentAssignmentEntry(): ?array
    {
        $current = $this->assignments->firstWhere('ended_at', null);

        return $current ? [
            'id' => $current->id,
            'technician' => $current->technicianRef(),
            'assigned_by' => ['id' => $current->assigner->id, 'name' => $current->assigner->name],
            'assigned_at' => $current->assigned_at->utc()->format('Y-m-d\TH:i:s\Z'),
            'override_reason' => $current->override_reason,
        ] : null;
    }

    /**
     * Status changes, classifications and Assignments (office only) and the comments this viewer may
     * read, oldest first. Entries at the same second keep the order they were stored in: status
     * changes, then classifications, then Assignments, then comments.
     */
    private function timelineFor(User $viewer): array
    {
        $statuses = $this->statusHistory->map(fn (StatusChange $change) => [
            'at' => $change->created_at->getTimestamp(), 'kind' => 0, 'id' => $change->id, 'entry' => TimelineEntry::status($change),
        ]);
        $classifications = $viewer->isOffice() ? $this->classificationChanges->map(fn (ClassificationChange $change) => [
            'at' => $change->created_at->getTimestamp(), 'kind' => 1, 'id' => $change->id, 'entry' => TimelineEntry::classification($change),
        ]) : collect();
        // Each Assignment after the first replaced the one before it.
        $assignments = $viewer->isOffice() ? $this->assignments->values()->map(fn (Assignment $assignment, int $index) => [
            'at' => $assignment->assigned_at->getTimestamp(), 'kind' => 2, 'id' => $assignment->id,
            'entry' => TimelineEntry::assignment($assignment, $index > 0 ? $this->assignments[$index - 1] : null),
        ]) : collect();
        $comments = $this->comments->filter(fn (Comment $comment) => $comment->isVisibleTo($viewer, $this->resource))->map(fn (Comment $comment) => [
            'at' => $comment->created_at->getTimestamp(), 'kind' => 3, 'id' => $comment->id, 'entry' => TimelineEntry::comment($comment),
        ]);

        // `kind` breaks a same-second tie.
        return $statuses->concat($classifications)->concat($assignments)->concat($comments)
            ->sortBy([['at', 'asc'], ['kind', 'asc'], ['id', 'asc']])
            ->pluck('entry')
            ->values()
            ->all();
    }
}
