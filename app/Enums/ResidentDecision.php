<?php

namespace App\Enums;

/** What the reporter answers about a Resolved Complaint (design §5): confirm the repair, or return it (a Resident Return). */
enum ResidentDecision: string
{
    case Confirm = 'confirm';
    case Return = 'return';
}
