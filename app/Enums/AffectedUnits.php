<?php

namespace App\Enums;

/** How many Units a problem affects: one, several (2–10) or many (more than 10, or a whole Block). */
enum AffectedUnits: string
{
    case One = 'one';
    case Several = 'several';
    case Many = 'many';
}
