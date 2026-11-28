<?php

namespace App\Console\Commands;

use App\Services\Recurring\RecurringDetection;
use Illuminate\Console\Command;

/** Nightly Recurring Problem detection (design §6.4, §11); see RecurringDetection. Scheduled in routes/console.php. */
class DetectRecurringProblems extends Command
{
    protected $signature = 'complaints:detect-recurring';

    protected $description = 'Flag Locations with three or more Complaints of one Category in the last 90 days';

    public function handle(RecurringDetection $detection): int
    {
        ['raised' => $raised, 'updated' => $updated, 'inactive' => $inactive] = $detection->run();
        $this->info("Raised {$raised} new Recurring Problem flags, updated {$updated}, marked {$inactive} inactive.");

        return self::SUCCESS;
    }
}
