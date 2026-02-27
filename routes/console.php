<?php

use App\Jobs\Sync\DispatchScheduledSyncsJob;
use Illuminate\Support\Facades\Schedule;

/*
|--------------------------------------------------------------------------
| Scheduled Tasks
|--------------------------------------------------------------------------
*/

Schedule::job(new DispatchScheduledSyncsJob, 'sync')
    ->everyFifteenMinutes()
    ->withoutOverlapping()
    ->onOneServer();

// Aggregate Clicks into Daily Stats every 15 minutes
Schedule::command('analytics:aggregate-clicks')
    ->everyFifteenMinutes()
    ->withoutOverlapping()
    ->onOneServer();

// Clean up old clicks daily
Schedule::command('analytics:clean-clicks --days=60')
    ->daily()
    ->onOneServer();
