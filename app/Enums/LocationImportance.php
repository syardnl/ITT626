<?php

namespace App\Enums;

/** How critical a Location is to residents' daily life; set only on Common Areas. */
enum LocationImportance: string
{
    case Normal = 'normal';
    case Important = 'important';
    case Essential = 'essential';
}
