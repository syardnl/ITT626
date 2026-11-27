<?php

namespace App\Services\Complaints;

use App\Enums\AuditAction;
use App\Enums\ComplaintStatus;
use App\Enums\Priority;
use App\Models\Category;
use App\Models\Complaint;
use App\Models\User;
use App\Services\Audit\AuditTrail;
use Closure;
use Illuminate\Support\Facades\DB;

/**
 * The explicit actions that move a Complaint through the workflow (design §5). Each one locks
 * the Complaint, checks its status and version, applies the change, and writes the status
 * history and the audit log, all in one transaction. There is no client-controlled status field.
 */
class ComplaintWorkflow
{
    public function __construct(private readonly AuditTrail $audit) {}

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
        }, self::classificationRefusal(...));
    }

    /** Why a Complaint in this status cannot be classified; shared with ClassifyComplaintRequest's early check. */
    public static function classificationRefusal(ComplaintStatus $status): string
    {
        return $status === ComplaintStatus::Reported
            ? 'Begin review before classifying this Complaint.'
            : "This Complaint is already {$status->label()}.";
    }

    /**
     * Locks the Complaint the actor can see (404 otherwise), refuses with 409 when its status is not
     * one of $allowed or its version is not the one the actor loaded, then runs $change. A null
     * $version skips that check: only for the system acting on its own (Automatic Closure, seeding);
     * every HTTP request must send one.
     *
     * @param  list<ComplaintStatus>  $allowed
     * @param  ?Closure(ComplaintStatus): string  $refusal  the 409 message for a status not allowed
     */
    private function act(User $actor, int $complaintId, ?int $version, array $allowed, Closure $change, ?Closure $refusal = null): Complaint
    {
        return DB::transaction(function () use ($actor, $complaintId, $version, $allowed, $change, $refusal) {
            $complaint = Complaint::query()->visibleTo($actor)->lockForUpdate()->findOrFail($complaintId);

            if (! in_array($complaint->status, $allowed, true)) {
                throw new WorkflowConflict($refusal ? $refusal($complaint->status) : "This Complaint is already {$complaint->status->label()}.");
            }
            if ($version !== null && $version !== $complaint->version) {
                throw new WorkflowConflict("Someone else changed this Complaint after you opened it (it is now {$complaint->status->label()}). Reload it to see the latest.");
            }

            $change($complaint);

            return $complaint;
        });
    }

    /** Saves the new status (and any timestamps), bumps the version and appends the history row. */
    private function moveTo(Complaint $complaint, ComplaintStatus $to, ?User $actor, array $attributes = [], ?string $reason = null): void
    {
        $from = $complaint->status;
        $complaint->forceFill([...$attributes, 'status' => $to, 'version' => $complaint->version + 1])->save();
        $complaint->statusHistory()->create(['changed_by' => $actor?->id, 'from_status' => $from, 'to_status' => $to, 'reason' => $reason]);
    }
}
