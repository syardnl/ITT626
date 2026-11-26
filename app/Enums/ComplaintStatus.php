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

    /** Open means anything not Resolved or Closed. */
    public function isOpen(): bool
    {
        return ! in_array($this, self::NOT_OPEN, true);
    }
}
