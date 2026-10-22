<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

/*
|--------------------------------------------------------------------------
| Console Routes
|--------------------------------------------------------------------------
*/

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

/*
|--------------------------------------------------------------------------
| Scheduled Tasks
|--------------------------------------------------------------------------
|
| These tasks are driven by a single system cron job calling:
|   php artisan schedule:run
|
| cPanel is limited to once every 5 minutes, so all tasks use
| everyFiveMinutes() to align with the system trigger.
|
*/

Schedule::command('commission:process --limit=50')
    ->everyFiveMinutes()          // Changed from everyMinute()
    ->withoutOverlapping()
    ->runInBackground()
    ->onOneServer();

Schedule::command('reservations:release-expired')
    ->everyFiveMinutes()          // Changed from everySecond()
    ->withoutOverlapping(1)
    ->runInBackground();