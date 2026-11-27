<?php

namespace App\Enums;

use App\Models\MaintenanceRecord;

/** What a Manager decides about completed work (design §5): approve it, or return it to the same Technician. */
enum VerificationDecision: string
{
    case Approve = 'approve';
    case Return = 'return';

    /** Where the Complaint goes: Resolved on approval, Returned on a Manager Rejection. */
    public function status(): ComplaintStatus
    {
        return $this === self::Approve ? ComplaintStatus::Resolved : ComplaintStatus::Returned;
    }

    public function auditAction(): AuditAction
    {
        return $this === self::Approve ? AuditAction::WorkApproved : AuditAction::WorkReturned;
    }

    /** The verification_outcome this decision leaves on the Maintenance Record. */
    public function outcome(): string
    {
        return match ($this) {
            self::Approve => MaintenanceRecord::APPROVED,
            self::Return => MaintenanceRecord::RETURNED,
        };
    }
}
