<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Opt-in local automation; run `php artisan schedule:work` during localhost tests.
Schedule::command('admissions:import-folder')
    ->everyMinute()
    ->withoutOverlapping(1440)
    ->when(fn () => (bool) config('admissions-import.enabled'));

// Retention sweep (import temp files, Review Queue previews, saved reports) - see
// config/data-retention.php. Not gated behind admissions-import.enabled: it's a privacy/
// compliance job independent of whether folder-import automation is turned on.
Schedule::command('retention:prune')->daily()->withoutOverlapping(1440);
