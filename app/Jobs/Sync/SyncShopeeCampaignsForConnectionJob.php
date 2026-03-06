<?php

declare(strict_types=1);

namespace App\Jobs\Sync;

use App\Models\PlatformConnection;
use App\Models\SyncRun;
use App\Services\Campaign\CampaignSyncService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class SyncShopeeCampaignsForConnectionJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(
        private readonly int $connectionId,
        private readonly string $triggerType = 'campaign_sync',
        private readonly ?int $syncRunId = null,
    ) {}

    /**
     * Handle job failure: clean up any orphaned 'processing' SyncRuns.
     */
    public function failed(?\Throwable $exception): void
    {
        SyncRun::where('platform_connection_id', $this->connectionId)
            ->where('type', 'campaign_sync')
            ->where('status', 'processing')
            ->update([
                'status' => 'failed',
                'finished_at' => now(),
                'error_message' => 'Job failed (worker exception/timeout): ' . ($exception?->getMessage() ?? 'Unknown error'),
            ]);

        Log::warning('SyncShopeeCampaignsForConnectionJob::failed — cleaned up orphaned processing runs', [
            'connection_id' => $this->connectionId,
            'trigger_type' => $this->triggerType,
            'error' => $exception?->getMessage(),
        ]);
    }

    public function handle(CampaignSyncService $campaignSyncService): void
    {
        $connection = PlatformConnection::query()->find($this->connectionId);
        if (! $connection) {
            return;
        }

        $syncRun = $this->syncRunId !== null 
            ? SyncRun::find($this->syncRunId) 
            : null;

        if (! $syncRun) {
            $syncRun = SyncRun::create([
                'platform_connection_id' => $connection->id,
                'user_id' => $connection->user_id,
                'integration' => $connection->platform,
                'type' => 'campaign_sync',
                'status' => 'processing',
                'started_at' => now(),
                'records_fetched' => 0,
                'records_upserted' => 0,
                'records_failed' => 0,
            ]);
        } else {
            $syncRun->update([
                'status' => 'processing',
                'started_at' => now(),
            ]);
        }

        try {
            $result = $campaignSyncService->syncForConnection($connection);

            $syncRun->update([
                'status' => 'completed',
                'finished_at' => now(),
                'records_fetched' => (int) ($result['fetched'] ?? 0),
                'records_upserted' => (int) ($result['upserted'] ?? 0),
                'records_failed' => 0,
                'error_message' => sprintf(
                    'Campaigns: fetched=%d | upserted=%d | links_provisioned=%d | connections=%d',
                    (int) ($result['fetched'] ?? 0),
                    (int) ($result['upserted'] ?? 0),
                    (int) ($result['links_provisioned'] ?? 0),
                    (int) ($result['connections'] ?? 1),
                ),
                'details' => [
                    'modules' => [
                        'campaign' => [
                            'status' => 'ok',
                            'fetched' => (int) ($result['fetched'] ?? 0),
                            'upserted' => (int) ($result['upserted'] ?? 0),
                            'links_provisioned' => (int) ($result['links_provisioned'] ?? 0),
                        ],
                    ],
                    'warnings' => [],
                ],
            ]);

            Log::info('SyncShopeeCampaignsForConnectionJob completed', [
                'connection_id' => $connection->id,
                ...$result,
            ]);
        } catch (\Throwable $e) {
            if ($this->isShopeeSoftBlock($e)) {
                $syncRun->update([
                    'status' => 'completed_with_warnings',
                    'finished_at' => now(),
                    'records_failed' => 0,
                    'error_message' => mb_substr(
                        'Campaign sync bị Shopee chặn tạm thời (anti-bot challenge 90309999). Hãy lấy cURL từ trang Campaign List và thử lại.',
                        0,
                        1000
                    ),
                    'details' => [
                        'modules' => [
                            'campaign' => [
                                'status' => 'warning',
                                'fetched' => 0,
                                'upserted' => 0,
                                'reason' => 'soft_block',
                            ],
                        ],
                        'warnings' => [$e->getMessage()],
                    ],
                ]);

                Log::warning('SyncShopeeCampaignsForConnectionJob soft blocked', [
                    'connection_id' => $connection->id,
                    'error' => $e->getMessage(),
                ]);

                return;
            }

            $syncRun->update([
                'status' => 'failed_api',
                'finished_at' => now(),
                'records_failed' => 1,
                'error_message' => mb_substr($e->getMessage(), 0, 1000),
                'details' => [
                    'modules' => [
                        'campaign' => [
                            'status' => 'failed',
                        ],
                    ],
                    'warnings' => [$e->getMessage()],
                ],
            ]);

            Log::warning('SyncShopeeCampaignsForConnectionJob failed', [
                'connection_id' => $connection->id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    private function isShopeeSoftBlock(\Throwable $e): bool
    {
        $message = mb_strtolower($e->getMessage());

        return str_contains($message, '90309999')
            || str_contains($message, 'anti-bot challenge');
    }
}
