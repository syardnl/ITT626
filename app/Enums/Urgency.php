<?php

namespace App\Enums;

/** How urgent the Resident says the problem is; one input to the Priority Score, not the Priority. */
enum Urgency: string
{
    case Low = 'low';
    case Medium = 'medium';
    case High = 'high';
    case Critical = 'critical';
}
