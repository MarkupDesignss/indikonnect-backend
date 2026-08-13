<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule; // <-- IMPORT THIS

/*
|--------------------------------------------------------------------------
| Console Routes
|--------------------------------------------------------------------------
|
| This file is where you may define all of your Closure based console
| commands. Each Closure is bound to a command instance allowing a
| simple approach to interacting with each command's IO methods.
|
*/

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

/*
|--------------------------------------------------------------------------
| Scheduled Tasks
|--------------------------------------------------------------------------
|
| Define all scheduled tasks here using Laravel's Schedule facade.
| These tasks will be run by the scheduler based on the configured
| schedule frequency.
|
*/

Schedule::command('commission:process --limit=50')
    ->everyMinute()
    ->withoutOverlapping()
    ->runInBackground()
    ->onOneServer(); // Prevents multiple servers from running the same job

// Optional: If you want to run it less frequently in production,
// you can use ->everyFiveMinutes() instead.