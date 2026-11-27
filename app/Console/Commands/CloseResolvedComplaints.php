<?php

namespace App\Console\Commands;

use App\Enums\ComplaintStatus;
use App\Models\Complaint;
use App\Services\Complaints\ComplaintWorkflow;
use App\Services\Complaints\WorkflowConflict;
use Illuminate\Console\Command;

/**
 * Automatic Closure (story 36): closes every Complaint Resolved for at least 7 full calendar days
 * with no answer from the reporter. Runs hourly (routes/console.php); each closed as a confirmation would close it.
 */
class CloseResolvedComplaints extends Command
{
    protected $signature = 'complaints:close-resolved';

    protected $description = 'Close Complaints left Resolved for 7 days with no answer from the reporter';

    public function handle(ComplaintWorkflow $workflow): int
    {
        $due = Complaint::query()->where('status', ComplaintStatus::Resolved)
            ->where('resolved_at', '<=', now()->subDays(ComplaintWorkflow::AUTOMATIC_CLOSURE_DAYS))
            ->pluck('id');

        $closed = 0;
        foreach ($due as $id) {
            try {
                $workflow->closeAutomatically($id);
                $closed++;
            } catch (WorkflowConflict) {
                // The reporter answered meanwhile; their answer stands.
            }
        }
        $this->info("Closed {$closed} of {$due->count()} Complaints Resolved for ".ComplaintWorkflow::AUTOMATIC_CLOSURE_DAYS.' days.');

        return self::SUCCESS;
    }
}
