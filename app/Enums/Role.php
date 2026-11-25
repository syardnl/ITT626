<?php

namespace App\Enums;

/** The four account roles; each account holds exactly one. */
enum Role: string
{
    case Resident = 'resident';
    case Manager = 'manager';
    case Technician = 'technician';
    case Administrator = 'administrator';
}
