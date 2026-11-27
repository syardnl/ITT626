<?php

namespace App\Services\Complaints;

use App\Models\Complaint;
use App\Models\Technician;

/**
 * Ranks the Technicians who can take new work for a classified Complaint (design §6.2): Available
 * before Busy, matching Skill first, lowest Active Task to capacity ratio next, same home Block
 * next, then the lower id. On leave Technicians and deactivated accounts are left out.
 */
class TechnicianRecommendations
{
    /** @return list<Candidate> best first */
    public function for(Complaint $complaint): array
    {
        $blockId = $complaint->location->blockId();
        $currentId = $complaint->currentAssignment?->technician_id;

        $candidates = Technician::query()->canTakeNewWork()->withActiveTasks()->with(Technician::DISPLAY_RELATIONS)->get()
            ->map(fn (Technician $technician) => new Candidate(
                technician: $technician,
                activeTasks: $technician->active_tasks,
                skillMatch: $technician->hasSkillFor($complaint->category),
                sameBlock: $blockId !== null && $technician->home_block_id === $blockId,
                current: $technician->id === $currentId,
            ))
            ->all();

        usort($candidates, fn (Candidate $a, Candidate $b) => self::rankKey($a) <=> self::rankKey($b));

        return $candidates;
    }

    private static function rankKey(Candidate $candidate): array
    {
        return [$candidate->isBusy(), ! $candidate->skillMatch, $candidate->load(), ! $candidate->sameBlock, $candidate->technician->id];
    }
}
