<?php

use App\Jobs\Sync\DispatchScheduledSyncsJob;
use App\Jobs\Sync\SyncShopeeCampaignsDailyJob;
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

// Sync Shopee campaigns once per day
Schedule::job(new SyncShopeeCampaignsDailyJob, 'sync')
    ->dailyAt((string) config('integrations.shopee.campaign_sync_at', '09:05'))
    ->withoutOverlapping()
    ->onOneServer();

// Aggregate Clicks into Daily Stats every 15 minutes
Schedule::command('analytics:aggregate-clicks')
    ->everyFifteenMinutes()
    ->withoutOverlapping()
    ->onOneServer();

// Aggregate Orders into Daily Stats every 15 minutes
Schedule::command('analytics:aggregate-orders --platform=shopee --days=2')
    ->everyFifteenMinutes()
    ->withoutOverlapping()
    ->onOneServer();

// Clean up old clicks daily
Schedule::command('analytics:clean-clicks --days=60')
    ->daily()
    ->onOneServer();
