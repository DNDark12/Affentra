<?php

declare(strict_types=1);

namespace App\Jobs\Sync;

use App\Jobs\Tracking\AggregateDailyClicksJob;
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

            $orders = [];
            $clicks = [];

            $warnings = [];
            $segmentsPassed = [];
            $segmentsFailed = [];

            // Fetch orders from API (independent segment)
            try {
                $orders = $adapter->fetchReport($connection, $since, $until);
                $segmentsPassed[] = 'orders';
            } catch (\Throwable $e) {
                $segmentsFailed[] = 'orders';
                $warnings[] = 'orders: ' . $e->getMessage();
                Log::warning('SyncPlatformConnectionJob fetch orders failed', [
                    'connection_id' => $this->connectionId,
                    'platform' => $connection->platform,
                    'error' => $e->getMessage(),
                ]);
            }

            // Fetch clicks from API (independent segment)
            try {
                $clicks = $adapter->fetchClickReport($connection, $since, $until);
                $segmentsPassed[] = 'clicks';
            } catch (\Throwable $e) {
                $segmentsFailed[] = 'clicks';
                $warnings[] = 'clicks: ' . $e->getMessage();
                Log::warning('SyncPlatformConnectionJob fetch clicks failed', [
                    'connection_id' => $this->connectionId,
                    'platform' => $connection->platform,
                    'error' => $e->getMessage(),
                ]);
            }

            if ($segmentsPassed === []) {
                throw new \RuntimeException(
                    mb_substr('All fetch segments failed. ' . implode(' | ', $warnings), 0, 500)
                );
            }

            $syncRun->update(['records_fetched' => count($orders) + count($clicks)]);

            $orderResult = ['upserted' => 0, 'failed' => 0];
            $clickResult = [
                'upserted' => 0,
                'failed' => 0,
                'deleted' => 0,
                'matched' => 0,
                'unattributed' => 0,
                'affected_link_ids' => [],
                'min_date' => null,
                'max_date' => null,
            ];
            $ordersUpsertedSuccessfully = false;
            $clicksUpsertedSuccessfully = false;

            if ($segmentsPassed !== [] && ! in_array('orders', $segmentsFailed, true)) {
                try {
                    $orderResult = $orderService->upsertFromApiSync($connection, $orders);
                    $ordersUpsertedSuccessfully = true;
                } catch (\Throwable $e) {
                    $segmentsFailed[] = 'orders_upsert';
                    $warnings[] = 'orders_upsert: ' . $e->getMessage();
                    Log::warning('SyncPlatformConnectionJob upsert orders failed', [
                        'connection_id' => $this->connectionId,
                        'platform' => $connection->platform,
                        'error' => $e->getMessage(),
                    ]);
                }
            }

            if ($segmentsPassed !== [] && ! in_array('clicks', $segmentsFailed, true)) {
                try {
                    $clickResult = $clickAnalyticsService->upsertFromApiSync($connection, $clicks, $since, $until);
                    $clicksUpsertedSuccessfully = true;

                    if (
                        ! empty($clickResult['min_date'])
                        && ! empty($clickResult['max_date'])
                        && (((int) ($clickResult['upserted'] ?? 0)) > 0 || ((int) ($clickResult['deleted'] ?? 0)) > 0)
                    ) {
                        AggregateDailyClicksJob::dispatch(
                            platform: $connection->platform,
                            minDate: (string) $clickResult['min_date'],
                            maxDate: (string) $clickResult['max_date'],
                        )->onQueue('sync');
                    }
                } catch (\Throwable $e) {
                    $segmentsFailed[] = 'clicks_upsert';
                    $warnings[] = 'clicks_upsert: ' . $e->getMessage();
                    Log::warning('SyncPlatformConnectionJob upsert clicks failed', [
                        'connection_id' => $this->connectionId,
                        'platform' => $connection->platform,
                        'error' => $e->getMessage(),
                    ]);
                }
            }

            if (! $ordersUpsertedSuccessfully && ! $clicksUpsertedSuccessfully) {
                throw new \RuntimeException(
                    mb_substr('All upsert segments failed. ' . implode(' | ', $warnings), 0, 500)
                );
            }

            $status = $warnings === [] ? 'completed' : 'completed_with_warnings';
            $errorMessage = $warnings === [] ? null : mb_substr(implode(' | ', $warnings), 0, 1000);
            $failedSegmentsCount = count(array_unique($segmentsFailed));
            $recordsFailed = (int) ($orderResult['failed'] ?? 0)
                + (int) ($clickResult['failed'] ?? 0)
                + $failedSegmentsCount;

            // Update sync run with results
            $syncRun->update([
                'status'           => $status,
                'records_upserted' => ($orderResult['upserted'] ?? 0) + ($clickResult['upserted'] ?? 0),
                'records_failed'   => $recordsFailed,
                'error_message'    => $errorMessage,
                'finished_at'      => now(),
            ]);

            // Update connection state
            $connection->update([
                'last_sync_at'     => now(),
                'last_sync_status' => $status,
                'status'           => 'active',
                'last_error'       => $errorMessage,
                'last_error_at'    => $errorMessage !== null ? now() : null,
            ]);

            Log::info('SyncPlatformConnectionJob completed', [
                'connection_id' => $this->connectionId,
                'platform'      => $connection->platform,
                'fetched'       => count($orders) + count($clicks),
                'upserted'      => ($orderResult['upserted'] ?? 0) + ($clickResult['upserted'] ?? 0),
                'status'        => $status,
                'segments_passed' => $segmentsPassed,
                'segments_failed' => $segmentsFailed,
                'warnings'      => $warnings,
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
