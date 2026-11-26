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
}
