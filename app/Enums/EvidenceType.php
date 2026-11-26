<?php

namespace App\Enums;

/** Why a photo was taken; Phase 3 adds work-completion photos from Technicians. */
enum EvidenceType: string
{
    case ReportPhoto = 'report_photo';
}
