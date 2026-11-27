<?php

namespace App\Enums;

/** Why a photo was taken: with the report, or by the Technician before and after the repair. */
enum EvidenceType: string
{
    case ReportPhoto = 'report_photo';
    case BeforePhoto = 'before_photo';
    case AfterPhoto = 'after_photo';
}
