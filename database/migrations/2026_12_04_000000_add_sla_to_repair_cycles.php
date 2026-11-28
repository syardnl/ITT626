<?php

use Carbon\CarbonImmutable;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Each Repair Cycle keeps the SLA Targets it started with (design §6.3), its deadlines and its milestones.
        Schema::table('repair_cycles', function (Blueprint $table) {
            $table->json('sla_targets')->nullable()->after('started_at');
            $table->timestamp('response_due_at')->nullable()->after('sla_targets');      // cycle 1 only
            $table->timestamp('resolution_due_at')->nullable()->after('response_due_at');
            $table->timestamp('responded_at')->nullable()->after('resolution_due_at');   // cycle 1 only
            $table->timestamp('resolved_at')->nullable()->after('responded_at');
            $table->index('response_due_at');
            $table->index('resolution_due_at');
        });

        $this->backfill();
    }

    /**
     * Cycles started before this migration get today's SLA Targets (the ones they started with
     * were not kept) at the Complaint's current Priority. Response is the review time; each cycle's
     * Resolution is the first move to Resolved between its start and the next cycle's.
     */
    private function backfill(): void
    {
        $targets = DB::table('sla_rules')->get()->mapWithKeys(fn ($rule) => [$rule->priority => [
            'response_minutes' => (int) $rule->response_minutes,
            'resolution_minutes' => (int) $rule->resolution_minutes,
            'version' => (int) $rule->version,
        ]])->all();

        DB::table('complaints')->select('id', 'priority', 'reviewed_at')->orderBy('id')->chunk(200, function ($complaints) use ($targets) {
            foreach ($complaints as $complaint) {
                $cycles = DB::table('repair_cycles')->where('complaint_id', $complaint->id)->orderBy('number')->get()->values();
                $resolutions = DB::table('complaint_status_history')->where('complaint_id', $complaint->id)
                    ->where('to_status', 'resolved')->orderBy('created_at')->orderBy('id')->pluck('created_at')
                    ->map(fn ($at) => CarbonImmutable::parse($at, 'UTC'));
                $target = $targets[$complaint->priority] ?? null;

                foreach ($cycles as $index => $cycle) {
                    $start = CarbonImmutable::parse($cycle->started_at, 'UTC');
                    $end = isset($cycles[$index + 1]) ? CarbonImmutable::parse($cycles[$index + 1]->started_at, 'UTC') : null;
                    $resolved = $resolutions->first(fn (CarbonImmutable $at) => $at->gte($start) && ($end === null || $at->lt($end)));
                    $first = (int) $cycle->number === 1;

                    DB::table('repair_cycles')->where('id', $cycle->id)->update([
                        'sla_targets' => json_encode($targets),
                        'response_due_at' => $first && $target ? $start->addMinutes($target['response_minutes']) : null,
                        'resolution_due_at' => $target ? $start->addMinutes($target['resolution_minutes']) : null,
                        'responded_at' => $first ? $complaint->reviewed_at : null,
                        'resolved_at' => $resolved,
                    ]);
                }
            }
        });
    }

    public function down(): void
    {
        Schema::table('repair_cycles', function (Blueprint $table) {
            $table->dropIndex(['response_due_at']);
            $table->dropIndex(['resolution_due_at']);
            $table->dropColumn(['sla_targets', 'response_due_at', 'resolution_due_at', 'responded_at', 'resolved_at']);
        });
    }
};
