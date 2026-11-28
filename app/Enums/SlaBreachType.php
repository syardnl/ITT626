<?php

namespace App\Enums;

/** Which SLA Target a Repair Cycle missed. */
enum SlaBreachType: string
{
    case Response = 'response';
    case Resolution = 'resolution';
}
