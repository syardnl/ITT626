<?php

namespace App\Services\Recurring;

use App\Enums\RecurringFlagStatus;
use App\Models\Complaint;
use App\Models\RecurringFlag;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Nightly Recurring Problem detection (design §6.4): groups classified Complaints by exact Location
 * and Category over the last 90 days and keeps one current flag per group. Each Complaint counts
 * once, however many Repair Cycles it has; Followers are not Complaints, so they never count.
 */
class RecurringDetection
{
    /** @return array{raised: int, updated: int, inactive: int} */
    public function run(): array
    {
        $groups = $this->groupsInWindow();
        $counts = ['raised' => 0, 'updated' => 0, 'inactive' => 0];

        DB::transaction(function () use ($groups, &$counts) {
            $current = RecurringFlag::query()->current()->lockForUpdate()->get()->keyBy(fn (RecurringFlag $flag) => "{$flag->location_id}:{$flag->category_id}");

            foreach ($groups as $key => $complaints) {
                $flag = $current->pull($key);
                $counts[$this->apply($flag, $complaints) ? 'raised' : 'updated']++;
            }

            // A group below three keeps its flag, marked inactive.
            foreach ($current as $flag) {
                if ($flag->active) {
                    $flag->forceFill(['active' => false])->save();
                    $counts['inactive']++;
                }
            }
        });

        return $counts;
    }

    /**
     * Updates the group's current flag with today's evidence, or raises a new open flag when there
     * is none, or when a Complaint has joined since the current one was dismissed (it is superseded
     * and kept). A dismissed flag with no new Complaint stays as it was decided. True when raised.
     *
     * @param  Collection<int, Complaint>  $complaints
     */
    private function apply(?RecurringFlag $flag, Collection $complaints): bool
    {
        $ids = $complaints->pluck('id')->sort()->values()->all();

        if ($flag?->status === RecurringFlagStatus::Dismissed) {
            if (array_diff($ids, $flag->complaint_ids) === []) {
                if (! $flag->active) {
                    $flag->forceFill(['active' => true])->save();
                }

                return false;
            }
            $flag->forceFill(['superseded_at' => now()])->save();
            $flag = null;
        }

        $evidence = [
            'complaint_ids' => $ids,
            'complaint_count' => count($ids),
            'first_reported_at' => $complaints->min('created_at'),
            'last_reported_at' => $complaints->max('created_at'),
            'active' => true,
        ];

        if ($flag) {
            $flag->forceFill($evidence)->save();

            return false;
        }

        $first = $complaints->first();
        RecurringFlag::query()->create([...$evidence, 'location_id' => $first->location_id, 'category_id' => $first->category_id, 'status' => RecurringFlagStatus::Open]);

        return true;
    }

    /** @return Collection<string, Collection<int, Complaint>> keyed "location:category", only groups at the threshold */
    private function groupsInWindow(): Collection
    {
        return Complaint::query()
            ->whereNotNull('category_id')
            ->where('created_at', '>=', now()->subDays(RecurringFlag::WINDOW_DAYS))
            ->get(['id', 'location_id', 'category_id', 'created_at'])
            ->groupBy(fn (Complaint $complaint) => "{$complaint->location_id}:{$complaint->category_id}")
            ->filter(fn (Collection $group) => $group->count() >= RecurringFlag::THRESHOLD);
    }
}
