<?php

namespace App\Services\Sla;

use App\Models\Complaint;
use App\Models\RepairCycle;
use App\Models\SlaRule;
use Carbon\CarbonInterface;

/**
 * The SLA clock of each Repair Cycle (design §6.3): its deadlines from the Targets it started with,
 * and its Response and Resolution. Called by the workflow inside its transactions.
 */
class SlaClock
{
    /** A new Repair Cycle at $at, snapshotting today's Targets, with deadlines at the Complaint's Priority. */
    public function startCycle(Complaint $complaint, int $number, CarbonInterface $at): RepairCycle
    {
        $cycle = $complaint->repairCycles()->make(['number' => $number, 'started_at' => $at, 'sla_targets' => $this->targets()]);
        $cycle->applyPriority($complaint->priority);
        $cycle->save();

        return $cycle;
    }

    /** After a Priority change: the current cycle's deadlines again, from its start. */
    public function recalculate(Complaint $complaint): void
    {
        $cycle = $this->currentCycle($complaint);
        $cycle->applyPriority($complaint->priority);
        $cycle->save();
    }

    /** The first review ends the Response clock, on cycle 1 only. */
    public function recordResponse(Complaint $complaint, CarbonInterface $at): void
    {
        $complaint->repairCycles()->where('number', 1)->whereNull('responded_at')->update(['responded_at' => $at]);
    }

    /** The Manager's approval ends the current cycle's Resolution clock. */
    public function recordResolution(Complaint $complaint, CarbonInterface $at): void
    {
        $cycle = $this->currentCycle($complaint);
        $cycle->resolved_at = $at;
        $cycle->save();
    }

    private function currentCycle(Complaint $complaint): RepairCycle
    {
        return $complaint->repairCycles()->reorder('number', 'desc')->firstOrFail();
    }

    /** @return array<string, array{response_minutes: int, resolution_minutes: int, version: int}> keyed by Priority */
    private function targets(): array
    {
        return SlaRule::query()->get()->mapWithKeys(fn (SlaRule $rule) => [$rule->priority->value => [
            'response_minutes' => $rule->response_minutes,
            'resolution_minutes' => $rule->resolution_minutes,
            'version' => $rule->version,
        ]])->all();
    }
}
