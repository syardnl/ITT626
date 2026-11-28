<?php

namespace App\Enums;

/** Where a Recurring Problem flag stands: waiting for the Manager, confirmed as real, or dismissed. */
enum RecurringFlagStatus: string
{
    case Open = 'open';
    case Acknowledged = 'acknowledged';
    case Dismissed = 'dismissed';
}
