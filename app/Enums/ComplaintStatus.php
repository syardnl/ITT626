<?php

namespace App\Enums;

/** A Complaint's place in the workflow; names as in the case study (design §5). */
enum ComplaintStatus: string
{
    case Reported = 'reported';
    case UnderReview = 'under_review';
    case Assigned = 'assigned';
    case InProgress = 'in_progress';
    case PendingVerification = 'pending_verification';
    case Resolved = 'resolved';
    case Returned = 'returned';
    case Closed = 'closed';

    /** Resolved and Closed, the statuses that are no longer open: lists of open Complaints at a Location and Follow leave these out. */
    public const array NOT_OPEN = [self::Resolved, self::Closed];

    /** Where a Manager may confirm or change the Category and Priority: open, once review has begun. */
    public const array CLASSIFIABLE = [self::UnderReview, self::Assigned, self::InProgress, self::PendingVerification, self::Returned];

    /** Open means anything not Resolved or Closed. */
    public function isOpen(): bool
    {
        return ! in_array($this, self::NOT_OPEN, true);
    }

    /** The case-study name, as messages show it. */
    public function label(): string
    {
        return match ($this) {
            self::Reported => 'Reported',
            self::UnderReview => 'Under Review',
            self::Assigned => 'Assigned',
            self::InProgress => 'In Progress',
            self::PendingVerification => 'Pending Verification',
            self::Resolved => 'Resolved',
            self::Returned => 'Returned',
            self::Closed => 'Closed',
        };
    }
}
