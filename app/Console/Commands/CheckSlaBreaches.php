<?php

namespace App\Console\Commands;

use App\Enums\SlaBreachType;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * The SLA breach check (design §6.3, §11): records an SLA Breach for every Repair Cycle deadline
 * that has passed with its milestone missing, or whose milestone came after it, so a check that
 * runs late still catches every breach. The unique (cycle, type) key makes a re-run add nothing.
 * Runs every five minutes (routes/console.php).
 */
class CheckSlaBreaches extends Command
{
    protected $signature = 'complaints:check-sla';

    protected $description = 'Record an SLA Breach for each Response or Resolution deadline missed';

    public function handle(): int
    {
        $now = now()->utc()->format('Y-m-d H:i:s');
        $recorded = 0;

        foreach ([[SlaBreachType::Response, 'response_due_at', 'responded_at'], [SlaBreachType::Resolution, 'resolution_due_at', 'resolved_at']] as [$breach, $due, $done]) {
            $type = $breach->value;
            $recorded += DB::table('sla_breaches')->insertOrIgnoreUsing(
                ['complaint_id', 'repair_cycle_id', 'type', 'due_at', 'detected_at', 'created_at', 'updated_at'],
                DB::table('repair_cycles')
                    ->select('complaint_id', 'id', DB::raw('?'), $due, DB::raw('?'), DB::raw('?'), DB::raw('?'))
                    ->addBinding([$type, $now, $now, $now], 'select')
                    ->where($due, '<', $now)
                    ->where(fn ($missed) => $missed->whereNull($done)->orWhereColumn($done, '>', $due))
                    ->whereNotExists(fn ($recorded) => $recorded->from('sla_breaches')
                        ->whereColumn('sla_breaches.repair_cycle_id', 'repair_cycles.id')->where('sla_breaches.type', $type)),
            );
        }

        $this->info("Recorded {$recorded} new SLA Breaches.");

        return self::SUCCESS;
    }
}
