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
        private readonly string $triggerType = 'manual',
    ) {
        $this->onQueue('sync');
    }

    public function handle(CampaignSyncService $campaignSyncService): void
    {
        $connection = PlatformConnection::query()->find($this->connectionId);
        if (! $connection) {
            return;
        }

        $syncRun = SyncRun::create([
            'platform_connection_id' => $connection->id,
            'user_id' => $connection->user_id,
            'integration' => $connection->platform,
            'type' => $this->triggerType,
            'status' => 'processing',
            'started_at' => now(),
            'records_fetched' => 0,
            'records_upserted' => 0,
            'records_failed' => 0,
        ]);

        try {
            $result = $campaignSyncService->syncForConnection($connection);

            $syncRun->update([
                'status' => 'completed',
                'finished_at' => now(),
                'records_fetched' => (int) ($result['fetched'] ?? 0),
                'records_upserted' => (int) ($result['upserted'] ?? 0),
                'records_failed' => 0,
                'error_message' => sprintf(
                    'Campaigns: fetched=%d | upserted=%d | connections=%d',
                    (int) ($result['fetched'] ?? 0),
                    (int) ($result['upserted'] ?? 0),
                    (int) ($result['connections'] ?? 1),
                ),
                'details' => [
                    'modules' => [
                        'campaign' => [
                            'status' => 'ok',
                            'fetched' => (int) ($result['fetched'] ?? 0),
                            'upserted' => (int) ($result['upserted'] ?? 0),
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
}

