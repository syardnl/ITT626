<?php

namespace App\Services\Complaints;

use App\Enums\ResidentDecision;

/** The reporter's answer to a Resolved Complaint: a confirmation with an optional Rating, or a Resident Return with its reason. */
final readonly class ResidentAnswer
{
    public function __construct(
        public ResidentDecision $decision,
        public ?int $score = null,
        public ?string $feedback = null,
        public ?string $reason = null,
    ) {}
}
