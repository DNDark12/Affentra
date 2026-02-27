<?php

namespace App\Jobs\Sync;

use App\Models\PlatformConnection;
use App\Models\SyncRun;
use App\Services\Integration\IntegrationFactory;
use App\Services\Payment\AffiliatePaymentService;
use Carbon\Carbon;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Throwable;

class SyncPaymentDataJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * Create a new job instance.
     */
    public function __construct(
        protected PlatformConnection $platformConnection,
        protected ?Carbon $since = null,
        protected ?Carbon $until = null
    ) {
        $this->since = $since ?? now()->subDays(90);
        $this->until = $until ?? now();
    }

    /**
     * Execute the job.
     */
    public function handle(
        IntegrationFactory $factory,
        AffiliatePaymentService $paymentService
    ): void {
        $adapter = $factory->make($this->platformConnection->platform);
        
        $syncRun = SyncRun::create([
            'platform_connection_id' => $this->platformConnection->id,
            'user_id' => $this->platformConnection->user_id,
            'integration' => $this->platformConnection->platform,
            'type' => 'auto',
            'status' => 'processing',
            'started_at' => now(),
        ]);

        try {
            // 1. Fetch & Upsert Billings
            $billings = $adapter->fetchBillings($this->platformConnection, $this->since, $this->until);
            $billingsCount = $paymentService->upsertBillingsFromApi($this->platformConnection, $billings);

            // 2. Fetch & Upsert Payouts
            $payouts = $adapter->fetchPayouts($this->platformConnection, $this->since, $this->until);
            $payoutsCount = $paymentService->upsertPayoutsFromApi($this->platformConnection, $payouts);

            // 3. Update Sync Run
            $syncRun->update([
                'status' => 'completed',
                'finished_at' => now(),
                'records_fetched' => count($billings) + count($payouts),
                'records_upserted' => $billingsCount + $payoutsCount,
            ]);

            Log::info("Payment sync completed for connection {$this->platformConnection->id}", [
                'billings' => $billingsCount,
                'payouts' => $payoutsCount,
            ]);

        } catch (Throwable $e) {
            $syncRun->update([
                'status' => 'failed',
                'finished_at' => now(),
                'error_message' => $e->getMessage(),
            ]);

            Log::error("Payment sync failed for connection {$this->platformConnection->id}: {$e->getMessage()}", [
                'exception' => get_class($e),
                'trace' => $e->getTraceAsString(),
            ]);

            throw $e;
        }
    }
}
