<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Missed-conversation agent emails (runs via the scheduler service —
// `php artisan schedule:work` in the deployed stack).
Schedule::command('notifications:missed')->everyMinute();
