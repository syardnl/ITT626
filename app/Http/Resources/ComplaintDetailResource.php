<?php

namespace App\Http\Resources;

use App\Enums\ComplaintStatus;
use App\Enums\Role;
use App\Models\Assignment;
use App\Models\ClassificationChange;
use App\Models\Comment;
use App\Models\Complaint;
use App\Models\Evidence;
use App\Models\Expense;
use App\Models\MaintenanceRecord;
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
        'assignments.technician.user', 'assignments.assigner',
        'maintenanceRecords.technician.user', 'maintenanceRecords.verifier', 'maintenanceRecords.expenses', 'maintenanceRecords.photos', 'maintenanceRecords.repairCycle', 'evidence.maintenanceRecord.technician'];

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
            'resolved_at' => $this->resolved_at?->utc()->format('Y-m-d\TH:i:s\Z'),
            // Sent back with a workflow action, so a change made meanwhile by someone else is refused (409).
            'version' => $this->version,
            'evidence' => $this->evidence->filter(fn (Evidence $photo) => $photo->isShownTo($request->user()))->values()->map(fn (Evidence $photo) => [
                'id' => $photo->id,
                'type' => $photo->type->value,
                'maintenance_record_id' => $photo->maintenance_record_id,
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
            'maintenance_records' => $this->maintenanceRecordsFor($request->user()),
            'can_complete_work' => $this->when($request->user()->hasRole(Role::Technician), fn () => $this->isAssignedTo($request->user())
                && $this->status === ComplaintStatus::InProgress),
            'can_start_work' => $this->when($request->user()->hasRole(Role::Technician), fn () => $this->isAssignedTo($request->user())
                && in_array($this->status, ComplaintStatus::STARTABLE, true)),
            'timeline' => $this->timelineFor($request->user()),
        ];
    }

    /**
     * The office sees every Maintenance Record in full; a Technician their own, expenses included;
     * the reporter, once approved, only what was repaired and its photos (#29 visibility).
     */
    private function maintenanceRecordsFor(User $viewer): array
    {
        return $this->maintenanceRecords
            ->filter(fn (MaintenanceRecord $record) => $record->isShownTo($viewer))
            ->values()
            ->map(fn (MaintenanceRecord $record) => $viewer->isStaff() ? [
                'id' => $record->id,
                'attempt' => $record->attempt,
                'repair_cycle' => $record->repairCycle->number,
                'technician' => ['id' => $record->technician->id, 'name' => $record->technician->user->name],
                'repair_result' => $record->repair_result,
                'parts_used' => $record->parts_used,
                'notes' => $record->notes,
                'expenses' => $record->expenses->map(fn (Expense $line) => [
                    'description' => $line->description, 'quantity' => $line->quantity, 'amount' => $line->amount,
                ])->all(),
                'expense_total' => $record->expenseTotal(),
                'photo_ids' => $record->photos->pluck('id')->all(),
                'submitted_at' => $record->submitted_at->utc()->format('Y-m-d\TH:i:s\Z'),
                'verification_outcome' => $record->verification_outcome,
                'verification_reason' => $record->verification_reason,
                'verified_by' => $record->verifier ? ['id' => $record->verifier->id, 'name' => $record->verifier->name] : null,
                'verified_at' => $record->verified_at?->utc()->format('Y-m-d\TH:i:s\Z'),
            ] : [
                'id' => $record->id,
                'attempt' => $record->attempt,
                'repair_result' => $record->repair_result,
                'photo_ids' => $record->photos->pluck('id')->all(),
                'submitted_at' => $record->submitted_at->utc()->format('Y-m-d\TH:i:s\Z'),
            ])->all();
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
     * Status changes, classifications and Assignments (office only), verifications (staff) and the
     * comments this viewer may read, oldest first. Entries at the same second keep the order they
     * were stored in: status changes, classifications, Assignments, verifications, then comments.
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
        // Staff only: a Manager Rejection's reason is not for the reporter.
        $verifications = $viewer->isStaff() ? $this->maintenanceRecords
            ->filter(fn (MaintenanceRecord $record) => $record->verified_at !== null && $record->isShownTo($viewer))
            ->map(fn (MaintenanceRecord $record) => [
                'at' => $record->verified_at->getTimestamp(), 'kind' => 3, 'id' => $record->id, 'entry' => TimelineEntry::verification($record),
            ]) : collect();
        $comments = $this->comments->filter(fn (Comment $comment) => $comment->isVisibleTo($viewer, $this->resource))->map(fn (Comment $comment) => [
            'at' => $comment->created_at->getTimestamp(), 'kind' => 4, 'id' => $comment->id, 'entry' => TimelineEntry::comment($comment),
        ]);

        // `kind` breaks a same-second tie.
        return $statuses->concat($classifications)->concat($assignments)->concat($verifications)->concat($comments)
            ->sortBy([['at', 'asc'], ['kind', 'asc'], ['id', 'asc']])
            ->pluck('entry')
            ->values()
            ->all();
    }
}
