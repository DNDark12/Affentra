<?php

use App\Jobs\Sync\DispatchScheduledSyncsJob;
use App\Jobs\Sync\SyncShopeeCampaignsDailyJob;
use Illuminate\Support\Facades\Schedule;

/*
|--------------------------------------------------------------------------
| Scheduled Tasks
|--------------------------------------------------------------------------
*/

Schedule::job(new DispatchScheduledSyncsJob)
    ->everyFifteenMinutes()
    ->withoutOverlapping()
    ->onOneServer();

// Sync Shopee campaigns once per day
Schedule::job(new SyncShopeeCampaignsDailyJob)
    ->dailyAt((string) config('integrations.shopee.campaign_sync_at', '09:05'))
    ->withoutOverlapping()
    ->onOneServer();

// Aggregate Clicks into Daily Stats every 15 minutes
Schedule::command('analytics:aggregate-clicks --platform=shopee')
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

// Clean up stale AI generations hourly
Schedule::command('ai:cleanup-zombies --hours=4')
    ->hourly()
    ->onOneServer();

// Clean up stale Integration Sync Runs hourly
Schedule::command('integrations:cleanup-zombies --hours=4')
    ->hourly()
    ->onOneServer();
