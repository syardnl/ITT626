<?php

namespace App\Services\Assignments;

/** What a Manager sends to assign or reassign a Complaint; ComplaintWorkflow::assign says which parts each case needs. */
final readonly class AssignmentDetails
{
    public function __construct(
        public int $technicianId,
        /** Saved as an Internal Comment to brief the Technician. */
        public ?string $note = null,
        /** Required to reassign. */
        public ?string $reason = null,
        /** Required, with $overrideReason, when the Technician is at or above maximum tasks. */
        public bool $overrideCapacity = false,
        public ?string $overrideReason = null,
    ) {}
}
