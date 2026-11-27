<?php

namespace App\Services\Complaints;

use App\Enums\Availability;
use App\Models\Technician;

/** One Technician as TechnicianRecommendations ranks them for a Complaint, with the facts behind the rank. */
final readonly class Candidate
{
    public function __construct(
        public Technician $technician,
        public int $activeTasks,
        public bool $skillMatch,
        public bool $sameBlock,
        public bool $current,
    ) {}

    public function isBusy(): bool
    {
        return $this->technician->availability === Availability::Busy;
    }

    public function isAtCapacity(): bool
    {
        return $this->technician->isAtCapacity($this->activeTasks);
    }

    /** Active Tasks to maximum tasks: lower ranks first. */
    public function load(): float
    {
        return $this->activeTasks / $this->technician->max_tasks;
    }
}
