<?php

declare(strict_types=1);

namespace App\Jobs\Sync;

use App\Jobs\Sync\SyncPaymentDataJob;
use App\Jobs\Sync\SyncPlatformConnectionJob;
use App\Jobs\Sync\SyncShopeeCampaignsForConnectionJob;
use App\Models\PlatformConnection;
use App\Models\SyncRun;
use Carbon\Carbon;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Log;

/**
 * Cron-dispatched job that finds all active scheduled connections
 * and dispatches individual SyncPlatformConnectionJob for each.
 *
 * Kernel schedule: ->everyFifteenMinutes() or custom interval.
 */
class DispatchScheduledSyncsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function handle(): void
    {
        $connections = PlatformConnection::query()
            ->where('status', 'active')
            ->where('sync_mode', 'scheduled')
            ->get();

        $eligibleConnections = $connections->filter(function (PlatformConnection $connection) {
            if ($connection->last_sync_at === null) {
                return true;
            }

            if ($connection->sync_interval === 'daily') {
                $syncTimeStr = $connection->sync_time ?? '00:00';
                $now = now();
                
                try {
                    $targetTimeToday = \Carbon\Carbon::createFromFormat('H:i', $syncTimeStr, config('app.timezone'))->setDateFrom($now);
                } catch (\Exception $e) {
                    $targetTimeToday = $now->copy()->startOfDay();
                }
                
                if ($now->gte($targetTimeToday)) {
                    return $connection->last_sync_at->lt($targetTimeToday);
                }
                
                $targetTimeYesterday = $targetTimeToday->copy()->subDay();
                return $connection->last_sync_at->lt($targetTimeYesterday);
            }

            $intervalMinutes = $connection->getSyncIntervalMinutes();

            return $connection->last_sync_at->lte(now()->subMinutes($intervalMinutes));
        });

        Log::info('DispatchScheduledSyncsJob: found eligible connections', [
            'count' => $eligibleConnections->count(),
            'total_active' => $connections->count(),
        ]);

        foreach ($eligibleConnections as $connection) {
            if ($connection->platform === 'shopee') {
                // Shopee: chain campaign + payment sync BEFORE the main conversion sync
                // to ensure finance data is available for reconciliation.
                Bus::chain([
                    new SyncShopeeCampaignsForConnectionJob($connection->id),
                    new SyncPaymentDataJob($connection),
                    new SyncPlatformConnectionJob(
                        connectionId: $connection->id,
                        type: 'auto',
                        userId: $connection->user_id,
                    ),
                ])->dispatch();
            } else {
                // Other platforms: dispatch only the conversion/click sync.
                // Payment sync and campaign sync are Shopee-specific.
                SyncPlatformConnectionJob::dispatch(
                    connectionId: $connection->id,
                    type: 'auto',
                    userId: $connection->user_id,
                );
            }
        }
    }
}
