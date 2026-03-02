<?php

declare(strict_types=1);

namespace App\Jobs\Sync;

use App\Jobs\Sync\SyncPaymentDataJob;
use App\Models\PlatformConnection;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
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
        $intervalMinutes = config('integrations.sync.interval_minutes', 60);

        $connections = PlatformConnection::query()
            ->where('status', 'active')
            ->where('sync_mode', 'scheduled')
            ->where(function ($q) use ($intervalMinutes) {
                $q->whereNull('last_sync_at')
                  ->orWhere('last_sync_at', '<=', now()->subMinutes($intervalMinutes));
            })
            ->get();

        Log::info('DispatchScheduledSyncsJob: found eligible connections', [
            'count' => $connections->count(),
        ]);

        foreach ($connections as $connection) {
            SyncPlatformConnectionJob::dispatch(
                connectionId: $connection->id,
                type: 'auto',
                userId: $connection->user_id,
            )->onQueue('sync');

            // Also schedule payment sync (Finance)
            SyncPaymentDataJob::dispatch($connection)->onQueue('sync');
        }
    }
}
