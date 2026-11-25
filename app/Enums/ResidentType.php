<?php

namespace App\Enums;

/** Descriptive only: no effect on what a Resident can do. */
enum ResidentType: string
{
    case Owner = 'owner';
    case Tenant = 'tenant';
    case Student = 'student';
}
