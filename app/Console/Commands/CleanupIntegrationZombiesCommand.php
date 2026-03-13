<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\PlatformConnection;
use App\Models\SyncRun;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class CleanupIntegrationZombiesCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'integrations:cleanup-zombies {--hours=4 : Keep records younger than this}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Mark stale "pending" or "processing" integration sync runs as "failed".';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $hours = (int) $this->option('hours');
        $cutoff = Carbon::now()->subHours($hours);

        $this->info("Cleaning up Integration Sync zombies older than {$hours} hours (Cutoff: {$cutoff->toDateTimeString()})...");

        // Find SyncRuns stuck in pending or processing
        $staleRuns = SyncRun::whereIn('status', ['pending', 'processing'])
            ->where('updated_at', '<', $cutoff)
            ->get();

        if ($staleRuns->isEmpty()) {
            $this->info('No zombie sync runs found.');
            
            // Just as a fallback, also check connections that say they are pending/processing but have no active sync runs
            $this->cleanupStaleConnectionStatuses();

            return self::SUCCESS;
        }

        $count = $staleRuns->count();
        $this->info("Found {$count} stale SyncRun(s). Marking as failed and releasing locks...");

        foreach ($staleRuns as $run) {
            $run->update([
                'status'        => 'failed',
                'error_message' => "Job timed out or worker crashed after being stale for more than {$hours} hours.",
                'finished_at'   => now(),
            ]);

            // Release the Cache lock for this connection if it exists
            $lockKey = "sync:connection:{$run->platform_connection_id}";
            Cache::forget($lockKey);

            Log::warning('integrations.cleanup_zombies.marked_failed', [
                'sync_run_id'            => $run->id,
                'platform_connection_id' => $run->platform_connection_id,
                'last_updated'           => $run->updated_at->toIso8601String(),
            ]);
        }

        // Clean up any PlatformConnections that are stuck showing pending or processing
        $this->cleanupStaleConnectionStatuses();

        $this->info("Successfully cleaned up {$count} SyncRun(s) and reset states.");

        return self::SUCCESS;
    }

    /**
     * Fix any connections that permanently say "processing" or "pending" 
     * but no longer have an active running SyncRun
     */
    private function cleanupStaleConnectionStatuses(): void
    {
        // Get all connections that claim to be syncing
        $stuckConnections = PlatformConnection::whereIn('last_sync_status', ['pending', 'processing'])->get();

        foreach ($stuckConnections as $connection) {
            // Does this connection have ANY sync runs that are currently pending or processing?
            $hasActiveRuns = SyncRun::where('platform_connection_id', $connection->id)
                ->whereIn('status', ['pending', 'processing'])
                ->exists();

            if (! $hasActiveRuns) {
                $connection->update([
                    'last_sync_status' => 'failed',
                    'last_error'       => 'Connection state was stuck without an active sync run. Background cleaned.',
                    'last_error_at'    => now(),
                ]);

                $lockKey = "sync:connection:{$connection->id}";
                Cache::forget($lockKey);

                Log::warning('integrations.cleanup_zombies.stuck_connection_reset', [
                    'platform_connection_id' => $connection->id,
                ]);
            }
        }
    }
}
