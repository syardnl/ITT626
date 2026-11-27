<?php

namespace App\Http\Resources;

use App\Models\Category;
use App\Services\Complaints\Candidate;

/** One ranked Technician for a Complaint, with the reasons for the rank and the warnings to show before assigning. */
final class RecommendationEntry
{
    public static function of(Candidate $candidate, int $rank, Category $category): array
    {
        $technician = $candidate->technician;
        $block = $technician->homeBlock;

        return [
            'rank' => $rank,
            'technician' => [
                'id' => $technician->id,
                'name' => $technician->user->name,
                'type' => $technician->type->value,
                'company_name' => $technician->company_name,
            ],
            'availability' => $technician->availability->value,
            'skill_match' => $candidate->skillMatch,
            'active_tasks' => $candidate->activeTasks,
            'max_tasks' => $technician->max_tasks,
            'at_capacity' => $candidate->isAtCapacity(),
            'same_block' => $candidate->sameBlock,
            'home_block' => ['id' => $block->id, 'code' => $block->code, 'name' => $block->name],
            'current' => $candidate->current,
            'reasons' => [
                $candidate->isBusy() ? 'Busy' : 'Available',
                $candidate->skillMatch ? "Has the {$category->name} Skill" : "No {$category->name} Skill",
                "{$candidate->activeTasks} of {$technician->max_tasks} Active Tasks",
                $candidate->sameBlock ? "Home Block {$block->name}, same as the Complaint" : "Home Block {$block->name}",
            ],
            'warnings' => array_values(array_filter([
                $candidate->isBusy() ? 'Busy: already loaded with other work.' : null,
                $candidate->isAtCapacity()
                    ? "At maximum tasks ({$candidate->activeTasks} of {$technician->max_tasks}): assigning needs an override with a reason."
                    : null,
            ])),
        ];
    }
}
