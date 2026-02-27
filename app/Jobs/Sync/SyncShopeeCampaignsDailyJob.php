<?php

declare(strict_types=1);

namespace App\Jobs\Sync;

use App\Services\Campaign\CampaignSyncService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class SyncShopeeCampaignsDailyJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function handle(CampaignSyncService $campaignSyncService): void
    {
        $result = $campaignSyncService->syncDaily();

        Log::info('SyncShopeeCampaignsDailyJob completed', $result);
    }
}
