<?php

namespace App\Services\Complaints;

use App\Enums\AuditAction;
use App\Enums\CommentVisibility;
use App\Enums\ComplaintStatus;
use App\Enums\Priority;
use App\Enums\ResidentDecision;
use App\Enums\VerificationDecision;
use App\Models\Category;
use App\Models\Complaint;
use App\Models\MaintenanceRecord;
use App\Models\Technician;
use App\Models\User;
use App\Services\Assignments\AssignmentDetails;
use App\Services\Audit\AuditTrail;
use Carbon\CarbonInterface;
use Closure;
use Illuminate\Auth\Access\AuthorizationException;
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
            $this->ensureCurrentAssignee($complaint, $technician);
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

                $this->ensureCurrentAssignee($complaint, $technician);
                $assignment = $complaint->currentAssignment;
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
        $reason = $decision === VerificationDecision::Return ? $reason : null;   // only a Manager Rejection has one

        return $this->act($manager, $complaintId, $version, [ComplaintStatus::PendingVerification], function (Complaint $complaint) use ($manager, $decision, $reason) {
            $now = now();   // one instant for the verification, the Resolution and the status row
            $record = $complaint->maintenanceRecords()->reorder('attempt', 'desc')->firstOrFail();
            $record->forceFill([
                'verification_outcome' => $decision->outcome(),
                'verification_reason' => $reason,
                'verified_by' => $manager->id,
                'verified_at' => $now,
            ])->save();

            // The reason stays off the status row, which the reporter reads; staff see it as a verification entry.
            $this->moveTo($complaint, $decision->status(), $manager, $decision === VerificationDecision::Approve ? ['resolved_at' => $now] : [], at: $now);
            $this->audit->recordChange($decision->auditAction(), $complaint, metadata: array_filter([
                'maintenance_record_id' => $record->id,
                'reason' => $reason,
            ], fn ($value) => $value !== null));
        }, fn (Complaint $complaint) => self::verificationRefusal($complaint->status));
    }

    /**
     * The reporter's answer to a Resolved Complaint. Confirm: it is closed, with an optional Rating.
     * Return (a Resident Return, with a reason): → Returned, to the same Technician, starting a new
     * Repair Cycle (ADR 0005). The reason goes on the status row: the Technician needs it.
     */
    public function answerResolution(User $resident, int $complaintId, ResidentAnswer $answer, ?int $version): Complaint
    {
        return $this->act($resident, $complaintId, $version, [ComplaintStatus::Resolved], function (Complaint $complaint) use ($resident, $answer) {
            if ($answer->decision === ResidentDecision::Confirm) {
                if ($answer->score !== null) {
                    $complaint->rating()->create(['user_id' => $resident->id, 'score' => $answer->score, 'feedback' => $answer->feedback]);
                }
                $this->closeComplaint($complaint, $resident, array_filter(['rating' => $answer->score], fn ($value) => $value !== null));

                return;
            }

            $now = now();
            $cycle = $complaint->repairCycles()->create(['number' => $complaint->repairCycles()->max('number') + 1, 'started_at' => $now]);
            // resolved_at keeps the last Resolution (Phase 4 measures it); the next approval sets it again.
            $this->moveTo($complaint, ComplaintStatus::Returned, $resident, [], $answer->reason, at: $now);
            $this->audit->recordChange(AuditAction::ResidentReturned, $complaint, metadata: [
                'reason' => $answer->reason, 'repair_cycle' => $cycle->number,
            ]);
        }, self::answerRefusal(...));
    }

    /**
     * Automatic Closure: Resolved → Closed by the system, 7 days after the Resolution with no answer
     * from the reporter. Closed and locked as a confirmation is, so only one of them wins.
     */
    public function closeAutomatically(int $complaintId): Complaint
    {
        return $this->act(null, $complaintId, null, [ComplaintStatus::Resolved], function (Complaint $complaint) {
            if ($complaint->resolved_at === null || $complaint->resolved_at->gt(now()->subDays(self::AUTOMATIC_CLOSURE_DAYS))) {
                throw new WorkflowConflict('This Complaint has not been Resolved for '.self::AUTOMATIC_CLOSURE_DAYS.' days.');
            }
            $this->closeComplaint($complaint, null, ['automatic' => true]);
        });
    }

    /** Days (of calendar time, so 168 hours for 7) a Complaint stays Resolved before Automatic Closure. */
    public const AUTOMATIC_CLOSURE_DAYS = 7;

    /** Why the reporter cannot answer this Complaint now, or null when they can; shared with ConfirmResolutionRequest's early check. */
    public static function answerRefusal(Complaint $complaint): ?string
    {
        return $complaint->status === ComplaintStatus::Resolved ? null : "This Complaint is {$complaint->status->label()}, not Resolved.";
    }

    /** Resolved → Closed, ending the Assignment. A null actor is the system. */
    private function closeComplaint(Complaint $complaint, ?User $actor, array $metadata): void
    {
        $now = now();
        $complaint->currentAssignment?->forceFill(['ended_at' => $now])->save();
        $this->moveTo($complaint, ComplaintStatus::Closed, $actor, ['closed_at' => $now], at: $now);
        $this->audit->recordChange(AuditAction::Closed, $complaint, metadata: $metadata, bySystem: $actor === null);
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
     * Locks the Complaint the actor can see (404 otherwise; a null actor is the system), refuses with 409 when its status is not
     * one of $allowed or its version is not the one the actor loaded, then runs $change. A null
     * $version skips that check: only for the system acting on its own (Automatic Closure, seeding);
     * every HTTP request must send one.
     *
     * @param  list<ComplaintStatus>  $allowed
     * @param  ?Closure(Complaint): ?string  $refusal  the 409 message for a status not allowed
     */
    private function act(?User $actor, int $complaintId, ?int $version, array $allowed, Closure $change, ?Closure $refusal = null): Complaint
    {
        return DB::transaction(function () use ($actor, $complaintId, $version, $allowed, $change, $refusal) {
            // The system (a null actor) sees every Complaint.
            $complaint = Complaint::query()->when($actor, fn ($query) => $query->visibleTo($actor))->lockForUpdate()->findOrFail($complaintId);

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

    /**
     * Checked under the lock: a previous assignee can still open the Complaint (read-only history),
     * so a reassignment made after their request was checked must still refuse them.
     */
    private function ensureCurrentAssignee(Complaint $complaint, User $technician): void
    {
        if (! $complaint->isAssignedTo($technician)) {
            throw new AuthorizationException('This Complaint has been reassigned to another Technician.');
        }
    }

    /** Saves the new status (and any timestamps), bumps the version and appends the history row. */
    private function moveTo(Complaint $complaint, ComplaintStatus $to, ?User $actor, array $attributes = [], ?string $reason = null, ?CarbonInterface $at = null): void
    {
        $from = $complaint->status;
        $complaint->forceFill([...$attributes, 'status' => $to, 'version' => $complaint->version + 1])->save();
        $row = $complaint->statusHistory()->make(['changed_by' => $actor?->id, 'from_status' => $from, 'to_status' => $to, 'reason' => $reason]);
        if ($at !== null) {
            $row->created_at = $at;   // the same instant as the change's other timestamps
        }
        $row->save();
    }
}
