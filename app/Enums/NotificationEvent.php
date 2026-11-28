<?php

namespace App\Enums;

/** What an in-app notification is about (design §11). */
enum NotificationEvent: string
{
    case ComplaintSubmitted = 'complaint_submitted';
    case AssignedToYou = 'assigned_to_you';
    case YourComplaintAssigned = 'your_complaint_assigned';
}
