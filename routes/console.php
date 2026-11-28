<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Automatic Closure (story 36). Run the scheduler with `php artisan schedule:work` (development) or a cron entry for `schedule:run`.
Schedule::command('complaints:close-resolved')->hourly()->withoutOverlapping(10);   // a run killed mid-way blocks the next ones for 10 minutes, not a day

// SLA breach check (design §6.3): every five minutes; a late run still records every breach, once.
Schedule::command('complaints:check-sla')->everyFiveMinutes()->withoutOverlapping(10);

// Recurring Problem detection (design §6.4): nightly; the Health Score calculation (#50) follows it.
Schedule::command('complaints:detect-recurring')->dailyAt('01:00')->withoutOverlapping(60);
