<?php

namespace App\Enums;

/** Contractors work for an outside company but use the same account and screens. */
enum TechnicianType: string
{
    case InHouse = 'in_house';
    case Contractor = 'contractor';
}
