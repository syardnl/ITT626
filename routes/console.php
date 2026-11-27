<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Automatic Closure (story 36). Run the scheduler with `php artisan schedule:work` (development) or a cron entry for `schedule:run`.
Schedule::command('complaints:close-resolved')->hourly()->withoutOverlapping();
