<?php

namespace App\Enums;

/** The SLA Indicator of an open Complaint, from its current Repair Cycle. */
enum SlaState: string
{
    case OnTrack = 'on_track';
    case DueSoon = 'due_soon';
    case Breached = 'breached';

    /** Due soon once less than this share of the target time is left (#44, story 9). */
    public const float DUE_SOON_SHARE = 0.2;
}
