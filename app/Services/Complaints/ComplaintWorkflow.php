<?php

namespace App\Services\Complaints;

use App\Enums\AuditAction;
use App\Enums\CommentVisibility;
use App\Enums\ComplaintStatus;
use App\Enums\Priority;
use App\Enums\VerificationDecision;
use App\Models\Category;
use App\Models\Complaint;
use App\Models\MaintenanceRecord;
use App\Models\Technician;
use App\Models\User;
use App\Services\Assignments\AssignmentDetails;
use App\Services\Audit\AuditTrail;
use Closure;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * The explicit actions that move a Complaint through the workflow (design §5). Each one locks
 * the Complaint, checks its status and version, applies the change, and writes the status
 * history and the audit log, all in one transaction. There is no client-controlled status field.
 */
class ComplaintWorkflow
{
    public function __construct(private readonly AuditTrail $audit, private readonly EvidencePhotos $photos) {}

    /** Reported → Under Review; the review time is the Response (design §5). */
    public function beginReview(User $manager, int $complaintId, ?int $version): Complaint
    {
        return $this->act($manager, $complaintId, $version, [ComplaintStatus::Reported], function (Complaint $complaint) use ($manager) {
            $this->moveTo($complaint, ComplaintStatus::UnderReview, $manager, ['reviewed_at' => now()]);
            $this->audit->recordChange(AuditAction::Reviewed, $complaint);
        });
    }

    /**
     * Confirms or changes the Category and final Priority, in any open status once review has
     * begun. initial_priority keeps the computed value; the reason is kept with the change.
     */
    public function classify(User $manager, int $complaintId, Category $category, Priority $priority, ?string $reason, ?int $version): Complaint
    {
        return $this->act($manager, $complaintId, $version, ComplaintStatus::CLASSIFIABLE, function (Complaint $complaint) use ($manager, $category, $priority, $reason) {
            $change = [
                'changed_by' => $manager->id,
                'from_category_id' => $complaint->category_id, 'to_category_id' => $category->id,
                'from_priority' => $complaint->priority, 'to_priority' => $priority,
                'reason' => $reason,
            ];
            $complaint->forceFill(['category_id' => $category->id, 'priority' => $priority, 'version' => $complaint->version + 1])->save();
            $complaint->classificationChanges()->create($change);
            $this->audit->recordChange(AuditAction::Classified, $complaint, metadata: $reason === null ? [] : ['reason' => $reason]);
        }, fn (Complaint $complaint) => self::classificationRefusal($complaint->status));
    }

    /** Why a Complaint in this status cannot be classified; shared with ClassifyComplaintRequest's early check. */
    public static function classificationRefusal(ComplaintStatus $status): string
    {
        return $status === ComplaintStatus::Reported
            ? 'Begin review before classifying this Complaint.'
            : "This Complaint is already {$status->label()}.";
    }

    /**
     * Assigns a Technician (Under Review → Assigned) or reassigns (Assigned, In Progress or Returned
     * → Assigned), ending the current Assignment with the reason. The Technician is locked and their
     * Active Tasks counted in this transaction, so capacity cannot be exceeded without an override.
     */
    public function assign(User $manager, int $complaintId, AssignmentDetails $details, ?int $version): Complaint
    {
        return $this->act($manager, $complaintId, $version, ComplaintStatus::ASSIGNABLE, function (Complaint $complaint) use ($manager, $details) {
            if ($complaint->category_id === null) {
                throw new WorkflowConflict(self::assignmentRefusal($complaint));
            }
            // Locked before any plain read: under REPEATABLE READ the first plain read fixes the snapshot,
            // and concurrent Assignments to this Technician must queue here to see each other's.
            $technician = Technician::query()->lockForUpdate()->findOrFail($details->technicianId);
            $current = $complaint->currentAssignment()->with('technician.user')->first();
            if ($current && $details->reason === null) {
                throw ValidationException::withMessages(['reason' => 'Give a reason for reassigning this Complaint.']);
            }
            $overrideReason = $this->checkTechnician($technician, $current?->technician_id, $details);

            $current?->forceFill(['ended_at' => now(), 'end_reason' => $details->reason])->save();
            $complaint->assignments()->create([
                'technician_id' => $technician->id, 'assigned_by' => $manager->id, 'assigned_at' => now(), 'override_reason' => $overrideReason,
            ]);
            if ($complaint->status === ComplaintStatus::Assigned) {
                // Reassigning an Assigned Complaint changes whose work it is, not its status.
                $complaint->forceFill(['version' => $complaint->version + 1])->save();
            } else {
                // No reason on the status row: everyone who sees the timeline reads those, and a reassignment
                // reason is staff only. It is kept on the ended Assignment and in the audit log.
                $this->moveTo($complaint, ComplaintStatus::Assigned, $manager);
            }
            if ($details->note !== null) {
                $complaint->comments()->create(['author_id' => $manager->id, 'visibility' => CommentVisibility::Internal, 'body' => $details->note]);
            }

            $this->audit->recordChange($current ? AuditAction::Reassigned : AuditAction::Assigned, $complaint, metadata: array_filter([
                'technician_id' => $technician->id,
                'previous_technician_id' => $current?->technician_id,
                'reason' => $details->reason,
                'override_reason' => $overrideReason,
            ], fn ($value) => $value !== null));
        }, self::assignmentRefusal(...));
    }

    /**
     * Assigned or Returned → In Progress, by the Technician holding the current Assignment. The
     * Assignment keeps the first start time; starting again after a return does not move it.
     */
    public function startWork(User $technician, int $complaintId, ?int $version): Complaint
    {
        return $this->act($technician, $complaintId, $version, ComplaintStatus::STARTABLE, function (Complaint $complaint) use ($technician) {
            // act() found it through visibleTo, so the latest Assignment is theirs; a startable status is
            // not Closed, so that Assignment has not ended. A reassignment meanwhile answers 404 there.
            $assignment = $complaint->currentAssignment;
            $assignment->started_at ??= now();
            $assignment->save();
            $this->moveTo($complaint, ComplaintStatus::InProgress, $technician);
            $this->audit->recordChange(AuditAction::WorkStarted, $complaint, metadata: [
                'assignment_id' => $assignment->id,
                'started_at' => $assignment->started_at->utc()->format('Y-m-d\TH:i:s\Z'),
            ]);
        });
    }

    /**
     * In Progress → Pending Verification, by the assigned Technician: one new Maintenance Record
     * (the next attempt, in the current Repair Cycle) with its expense lines and tagged photos.
     * Photos are re-encoded before the transaction; a retry with the same key creates nothing and
     * returns the Complaint as it is now.
     *
     * @throws ValidationException when a photo cannot be used; nothing is saved
     */
    public function completeWork(User $technician, int $complaintId, CompletionDetails $details, ?int $version): Complaint
    {
        if ($original = $this->findCompletedWith($technician, $complaintId, $details->key)) {
            return $original;
        }

        $prepared = $this->photos->prepare(array_column($details->photos, 'file'));
        $written = [];

        try {
            return $this->act($technician, $complaintId, $version, [ComplaintStatus::InProgress], function (Complaint $complaint) use ($technician, $details, $prepared, &$written) {
                $this->photos->discard($written);   // a retried attempt starts clean
                $written = [];

                $assignment = $complaint->currentAssignment;   // theirs: see startWork
                $record = $complaint->maintenanceRecords()->create([
                    'assignment_id' => $assignment->id,
                    'technician_id' => $assignment->technician_id,
                    'repair_cycle_id' => $complaint->repairCycles()->reorder('number', 'desc')->value('id'),
                    'attempt' => ($complaint->maintenanceRecords()->max('attempt') ?? 0) + 1,
                    'repair_result' => $details->repairResult,
                    'parts_used' => $details->partsUsed,
                    'notes' => $details->notes,
                    'submitted_at' => now(),
                    'submission_key' => $details->key,
                ]);
                $record->expenses()->createMany($details->expenses);
                foreach ($prepared as $index => $photo) {
                    $this->photos->store($complaint, $technician, [$photo], $written, $details->photos[$index]['type'], $record);
                }

                $this->moveTo($complaint, ComplaintStatus::PendingVerification, $technician);
                $this->audit->recordChange(AuditAction::WorkCompleted, $complaint, metadata: [
                    'maintenance_record_id' => $record->id, 'attempt' => $record->attempt,
                ]);
            }, fn (Complaint $complaint) => self::completionRefusal($complaint->status));
        } catch (Throwable $failure) {
            $this->photos->discard($written);   // the rows rolled back; the files must go too

            // The same key arrived twice at once and the other request committed first: it waited on the
            // row lock and then found Pending Verification (409), or lost the race to insert (unique key).
            if ($failure instanceof WorkflowConflict || $failure instanceof UniqueConstraintViolationException) {
                if ($original = $this->findCompletedWith($technician, $complaintId, $details->key)) {
                    return $original;
                }
            }
            if ($failure instanceof UniqueConstraintViolationException && $details->key !== null) {
                throw ValidationException::withMessages(['idempotency_key' => 'This Idempotency-Key was already used for another completion.']);
            }
            throw $failure;
        }
    }

    /**
     * The Manager's verification of the latest Maintenance Record. Approve: Pending Verification →
     * Resolved, recording the Resolution time. Return (a Manager Rejection, with a reason): →
     * Returned, to the same Technician, in the same Repair Cycle (ADR 0005). The reason stays with
     * the record and the audit log, for staff only.
     */
    public function verify(User $manager, int $complaintId, VerificationDecision $decision, ?string $reason, ?int $version): Complaint
    {
        return $this->act($manager, $complaintId, $version, [ComplaintStatus::PendingVerification], function (Complaint $complaint) use ($manager, $decision, $reason) {
            $record = $complaint->maintenanceRecords()->reorder('attempt', 'desc')->firstOrFail();
            $record->forceFill([
                'verification_outcome' => $decision->outcome(),
                'verification_reason' => $decision === VerificationDecision::Return ? $reason : null,
                'verified_by' => $manager->id,
                'verified_at' => now(),
            ])->save();

            $decision === VerificationDecision::Approve
                ? $this->moveTo($complaint, ComplaintStatus::Resolved, $manager, ['resolved_at' => now()])
                : $this->moveTo($complaint, ComplaintStatus::Returned, $manager);
            $this->audit->recordChange(AuditAction::Verified, $complaint, metadata: array_filter([
                'decision' => $decision->value,
                'maintenance_record_id' => $record->id,
                'reason' => $decision === VerificationDecision::Return ? $reason : null,
            ], fn ($value) => $value !== null));
        }, fn (Complaint $complaint) => self::verificationRefusal($complaint->status));
    }

    /** Why work in this status cannot be verified; shared with VerifyWorkRequest's early check. */
    public static function verificationRefusal(ComplaintStatus $status): string
    {
        return "This Complaint is {$status->label()}, not Pending Verification.";
    }

    /** Why work in this status cannot be completed; shared with CompleteWorkRequest's early check. */
    public static function completionRefusal(ComplaintStatus $status): string
    {
        return in_array($status, ComplaintStatus::STARTABLE, true)
            ? 'Start work before completing it.'
            : "This Complaint is already {$status->label()}.";
    }

    /** The Complaint a completion with this key already moved on, as the Technician may still see it; null if none. */
    public function findCompletedWith(User $technician, int $complaintId, ?string $key): ?Complaint
    {
        if ($key === null || ! $technician->technician) {
            return null;
        }
        $completed = MaintenanceRecord::query()->where('technician_id', $technician->technician->id)
            ->where('submission_key', $key)->where('complaint_id', $complaintId)->exists();

        return $completed ? Complaint::query()->visibleTo($technician)->find($complaintId) : null;
    }

    /**
     * Why a Technician cannot yet be recommended or assigned for this Complaint, or null when one can;
     * shared with the requests' early check.
     */
    public static function assignmentRefusal(Complaint $complaint): ?string
    {
        return match (true) {
            $complaint->status === ComplaintStatus::Reported => 'Begin review before assigning a Technician.',
            ! in_array($complaint->status, ComplaintStatus::ASSIGNABLE, true) => "This Complaint is already {$complaint->status->label()}.",
            $complaint->category_id === null => 'Classify this Complaint before assigning a Technician.',
            default => null,
        };
    }

    /**
     * Refuses (422) a Technician who cannot take new work, already has the Complaint, or is at
     * maximum tasks without an explained override. Returns the override reason when one was needed.
     */
    private function checkTechnician(Technician $technician, ?int $currentTechnicianId, AssignmentDetails $details): ?string
    {
        $refusal = $technician->newWorkRefusal()
            ?? ($technician->id === $currentTechnicianId ? "This Complaint is already assigned to {$technician->user->name}." : null);
        if ($refusal !== null) {
            throw ValidationException::withMessages(['technician_id' => $refusal]);
        }

        // A locking read over a join (not a subquery, whose rows a locking read neither locks nor reads
        // fresh) sees the latest committed Assignments and Complaint statuses, not the transaction's snapshot.
        $activeTasks = $technician->assignments()->current()
            ->join('complaints', 'complaints.id', '=', 'complaint_assignments.complaint_id')
            ->whereIn('complaints.status', ComplaintStatus::ACTIVE_TASK)
            ->sharedLock()->count();
        if (! $technician->isAtCapacity($activeTasks)) {
            return null;
        }
        if (! $details->overrideCapacity) {
            throw ValidationException::withMessages(['override_capacity' => "{$technician->user->name} is at maximum tasks ({$activeTasks} of {$technician->max_tasks}). Confirm the override to assign anyway."]);
        }
        if ($details->overrideReason === null) {
            throw ValidationException::withMessages(['override_reason' => 'Give a reason for assigning above maximum tasks.']);
        }

        return $details->overrideReason;
    }

    /**
     * Locks the Complaint the actor can see (404 otherwise), refuses with 409 when its status is not
     * one of $allowed or its version is not the one the actor loaded, then runs $change. A null
     * $version skips that check: only for the system acting on its own (Automatic Closure, seeding);
     * every HTTP request must send one.
     *
     * @param  list<ComplaintStatus>  $allowed
     * @param  ?Closure(Complaint): ?string  $refusal  the 409 message for a status not allowed
     */
    private function act(User $actor, int $complaintId, ?int $version, array $allowed, Closure $change, ?Closure $refusal = null): Complaint
    {
        return DB::transaction(function () use ($actor, $complaintId, $version, $allowed, $change, $refusal) {
            $complaint = Complaint::query()->visibleTo($actor)->lockForUpdate()->findOrFail($complaintId);

            if (! in_array($complaint->status, $allowed, true)) {
                throw new WorkflowConflict(($refusal ? $refusal($complaint) : null) ?? "This Complaint is already {$complaint->status->label()}.");
            }
            if ($version !== null && $version !== $complaint->version) {
                throw new WorkflowConflict("Someone else changed this Complaint after you opened it (it is now {$complaint->status->label()}). Reload it to see the latest.");
            }

            $change($complaint);

            return $complaint;
        }, attempts: 3);   // two reassignments in opposite directions can deadlock; InnoDB rolls one back and it is retried
    }

    /** Saves the new status (and any timestamps), bumps the version and appends the history row. */
    private function moveTo(Complaint $complaint, ComplaintStatus $to, ?User $actor, array $attributes = [], ?string $reason = null): void
    {
        $from = $complaint->status;
        $complaint->forceFill([...$attributes, 'status' => $to, 'version' => $complaint->version + 1])->save();
        $complaint->statusHistory()->create(['changed_by' => $actor?->id, 'from_status' => $from, 'to_status' => $to, 'reason' => $reason]);
    }
}
