<?php

namespace App\Services\Recurring;

use App\Enums\AuditAction;
use App\Enums\RecurringFlagStatus;
use App\Models\RecurringFlag;
use App\Models\User;
use App\Services\Audit\AuditTrail;
use App\Services\Complaints\WorkflowConflict;
use Illuminate\Support\Facades\DB;

/** The Manager acknowledging or dismissing an open Recurring Problem flag, with a reason, once. */
class RecurringFlagDecisions
{
    public function __construct(private readonly AuditTrail $audit) {}

    public function decide(User $manager, int $flagId, RecurringFlagStatus $decision, string $reason): RecurringFlag
    {
        return DB::transaction(function () use ($manager, $flagId, $decision, $reason) {
            $flag = RecurringFlag::query()->lockForUpdate()->findOrFail($flagId);
            if ($flag->superseded_at !== null) {
                throw new WorkflowConflict('This flag has been replaced by a newer one for the same Location and Category.');
            }
            if ($flag->status !== RecurringFlagStatus::Open) {
                throw new WorkflowConflict("This flag has already been {$flag->status->value}.");
            }

            $flag->forceFill(['status' => $decision, 'decided_by' => $manager->id, 'decided_at' => now(), 'reason' => $reason])->save();
            $this->audit->recordChange(
                $decision === RecurringFlagStatus::Acknowledged ? AuditAction::RecurringAcknowledged : AuditAction::RecurringDismissed,
                $flag, metadata: ['reason' => $reason],
            );

            return $flag;
        });
    }
}
