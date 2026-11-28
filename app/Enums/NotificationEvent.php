<?php

namespace App\Enums;

/** What an in-app notification is about (design §11). */
enum NotificationEvent: string
{
    // Managers
    case ComplaintSubmitted = 'complaint_submitted';
    case WorkPendingVerification = 'work_pending_verification';
    case ResidentReturned = 'resident_returned';
    case SlaBreached = 'sla_breached';
    // Technicians
    case AssignedToYou = 'assigned_to_you';
    case ReassignedAway = 'reassigned_away';
    case WorkReturned = 'work_returned';
    // The reporter
    case YourComplaintAssigned = 'your_complaint_assigned';
    case WorkStarted = 'work_started';
    case YourComplaintResolved = 'your_complaint_resolved';
    case YourComplaintClosed = 'your_complaint_closed';
    // Followers
    case FollowedComplaintChanged = 'followed_complaint_changed';

    /** The four events that also send an email (design §11); Followers never get one. */
    public function sendsEmail(): bool
    {
        return in_array($this, [self::ComplaintSubmitted, self::AssignedToYou, self::YourComplaintAssigned, self::YourComplaintResolved, self::SlaBreached], true);
    }

    public function emailSubject(string $reference): string
    {
        return match ($this) {
            self::ComplaintSubmitted => "New Complaint {$reference}",
            self::AssignedToYou => "New task: {$reference}",
            self::YourComplaintAssigned => "Your Complaint {$reference} has been assigned",
            self::YourComplaintResolved => "Please confirm the repair of {$reference}",
            self::SlaBreached => "SLA breached: {$reference}",
            default => $reference,
        };
    }
}
