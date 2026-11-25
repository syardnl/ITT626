<?php

namespace App\Enums;

/** The level that drives a Complaint's SLA Targets; the Manager confirms or overrides it. */
enum Priority: string
{
    case Critical = 'critical';
    case High = 'high';
    case Medium = 'medium';
    case Low = 'low';

    /** 0 for the most urgent, so lists can run Critical → Low. */
    public function rank(): int
    {
        return array_search($this, self::cases(), true);
    }
}
