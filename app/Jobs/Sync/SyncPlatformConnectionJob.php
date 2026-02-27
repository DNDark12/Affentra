<?php

declare(strict_types=1);

namespace App\Jobs\Sync;

use App\Models\PlatformConnection;
use App\Models\SyncRun;
use App\Services\Clicks\ClickAnalyticsService;
use App\Services\Integration\IntegrationFactory;
use App\Services\Order\OrderService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * Syncs orders from a platform connection's API.
 * Uses Redis mutex lock to prevent overlapping syncs per connection.
 */
class SyncPlatformConnectionJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public array $backoff = [60, 300, 900]; // 1min, 5min, 15min

    public function __construct(
        private readonly int $connectionId,
        private readonly string $type = 'auto', // 'auto' | 'manual'
        private readonly ?int $userId = null,
    ) {}

    public function handle(OrderService $orderService, ClickAnalyticsService $clickAnalyticsService): void
    {
        $connection = PlatformConnection::find($this->connectionId);

        if (! $connection) {
            Log::warning('SyncPlatformConnectionJob: connection not found', [
                'connection_id' => $this->connectionId,
            ]);
            return;
        }

        // Guard: skip if connection is disabled
        if (in_array($connection->status, ['disabled', 'expired', 'inactive'])) {
            $this->createSyncRun($connection, 'skipped_disabled', 'Connection is not active');
            return;
        }

        // Mutex lock: only 1 sync per connection at a time
        $lockKey = "sync:connection:{$this->connectionId}";
        $lockTtl = config('integrations.sync.lock_ttl_seconds', 900);
        $lock = Cache::lock($lockKey, $lockTtl);

        if (! $lock->get()) {
            $this->createSyncRun($connection, 'skipped_locked', 'Another sync is in progress');
            return;
        }

        $syncRun = $this->createSyncRun($connection, 'processing');

        try {
            $adapter = IntegrationFactory::make($connection->platform);

            // Calculate sync window
            $backfillDays = (int) ($connection->backfill_days_override
                ?? config("integrations.{$connection->platform}.backfill_days", 14));
            $configuredHardLimitDays = (int) config("integrations.{$connection->platform}.hard_limit_days", 30);
            // Do not silently clamp below the requested backfill window.
            $hardLimitDays = max($configuredHardLimitDays, $backfillDays, 1);

            $since = $connection->last_sync_at
                ? $connection->last_sync_at->copy()->subDays($backfillDays)
                : now()->subDays($hardLimitDays);

            // Clamp to hard limit
            $earliest = now()->subDays($hardLimitDays);
            if ($since->lt($earliest)) {
                $since = $earliest;
            }

            $until = now();

            // Fetch orders from API
            $orders = $adapter->fetchReport($connection, $since, $until);

            // Fetch clicks from API
            $clicks = [];
            if (method_exists($adapter, 'fetchClickReport')) {
                $clicks = $adapter->fetchClickReport($connection, $since, $until);
            }

            $syncRun->update(['records_fetched' => count($orders) + count($clicks)]);

            // Upsert via OrderService (chunk + idempotent)
            $orderResult = $orderService->upsertFromApiSync($connection, $orders);
            
            // Upsert Clicks
            $clickResult = $clickAnalyticsService->upsertFromApiSync($connection, $clicks, $since, $until);

            // Update sync run with results
            $syncRun->update([
                'status'           => 'completed',
                'records_upserted' => ($orderResult['upserted'] ?? 0) + ($clickResult['upserted'] ?? 0),
                'records_failed'   => ($orderResult['failed'] ?? 0) + ($clickResult['failed'] ?? 0),
                'finished_at'      => now(),
            ]);

            // Update connection state
            $connection->update([
                'last_sync_at'     => now(),
                'last_sync_status' => 'completed',
                'status'           => 'active',
            ]);

            Log::info('SyncPlatformConnectionJob completed', [
                'connection_id' => $this->connectionId,
                'platform'      => $connection->platform,
                'fetched'       => count($orders) + count($clicks),
                'upserted'      => ($orderResult['upserted'] ?? 0) + ($clickResult['upserted'] ?? 0),
            ]);

        } catch (\RuntimeException $e) {
            $status = $this->classifyError($e);

            $syncRun->update([
                'status'        => $status,
                'error_message' => mb_substr($e->getMessage(), 0, 500),
                'finished_at'   => now(),
            ]);

            $connection->update([
                'last_sync_status' => $status,
                'last_error'       => mb_substr($e->getMessage(), 0, 500),
                'last_error_at'    => now(),
                'status'           => $status === 'failed_auth' ? 'error' : $connection->status,
            ]);

            Log::error('SyncPlatformConnectionJob failed', [
                'connection_id' => $this->connectionId,
                'platform'      => $connection->platform,
                'status'        => $status,
                'error'         => $e->getMessage(),
            ]);

            // Re-throw for queue retry only on retryable errors
            if (in_array($status, ['failed_api', 'rate_limited'])) {
                throw $e;
            }

        } finally {
            $lock->release();
        }
    }

    /**
     * Classify the error into our status taxonomy.
     */
    private function classifyError(\RuntimeException $e): string
    {
        $message = strtolower($e->getMessage());

        if (str_contains($message, 'auth') || str_contains($message, '401') || str_contains($message, 'token')) {
            return 'failed_auth';
        }

        if (str_contains($message, 'rate limit') || str_contains($message, '429')) {
            return 'rate_limited';
        }

        if (str_contains($message, 'validation') || str_contains($message, 'missing field')) {
            return 'failed_validation';
        }

        return 'failed_api';
    }

    /**
     * Create a SyncRun record.
     */
    private function createSyncRun(
        PlatformConnection $connection,
        string $status,
        ?string $errorMessage = null,
    ): SyncRun {
        return SyncRun::create([
            'user_id'                => $this->userId ?? $connection->user_id,
            'platform_connection_id' => $connection->id,
            'integration'            => $connection->platform,
            'type'                   => $this->type,
            'status'                 => $status,
            'started_at'             => now(),
            'error_message'          => $errorMessage,
        ]);
    }
}
