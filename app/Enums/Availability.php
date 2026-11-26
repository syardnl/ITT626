<?php

namespace App\Enums;

/** A Technician's current state for new work (design §6.2). */
enum Availability: string
{
    case Available = 'available';
    case Busy = 'busy';
    case OnLeave = 'on_leave';
}
