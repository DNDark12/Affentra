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
        $defaultBackfillDays = (int) ($platformConnection->backfill_days_override
            ?? config("integrations.{$platformConnection->platform}.backfill_days", 90));

        $this->onQueue('sync');
        $this->since = $since ?? now()->subDays(max($defaultBackfillDays, 1));
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
            $warnings = [];

            $billings = [];
            $payouts = [];
            $serviceFeeInvoices = [];
            $billingsCount = 0;
            $payoutsCount = 0;

            $billingFetched = false;
            $payoutFetched = false;
            $serviceFeeFetched = false;

            // 1. Fetch & Upsert Billings (primary source)
            try {
                $billings = $adapter->fetchBillings($this->platformConnection, $this->since, $this->until);
                $billingFetched = true;
                $billingsCount = $paymentService->upsertBillingsFromApi($this->platformConnection, $billings);
            } catch (Throwable $billingError) {
                $warnings[] = 'Billing: '.$billingError->getMessage();
            }

            // 2. Fetch & Upsert Payouts (optional when only 1 cURL profile is available)
            try {
                $payouts = $adapter->fetchPayouts($this->platformConnection, $this->since, $this->until);
                $payoutFetched = true;
                $payoutsCount = $paymentService->upsertPayoutsFromApi($this->platformConnection, $payouts);
            } catch (Throwable $payoutError) {
                $warnings[] = 'Payout: '.$payoutError->getMessage();
            }

            // 3. Fetch service-fee invoices (optional)
            if (method_exists($adapter, 'fetchBillFeeInvoices')) {
                try {
                    $serviceFeeInvoices = $adapter->fetchBillFeeInvoices($this->platformConnection, $this->since, $this->until);
                    $serviceFeeFetched = true;
                } catch (Throwable $serviceFeeError) {
                    $warnings[] = 'Service fee invoice: '.$serviceFeeError->getMessage();
                }
            }

            if (! $billingFetched && ! $payoutFetched && ! $serviceFeeFetched) {
                throw new \RuntimeException(
                    'Không lấy được dữ liệu Finance từ endpoint nào. '.implode(' | ', $warnings)
                );
            }

            // 4. Reconcile order payout status from settlement data
            $reconciledOrders = $paymentService->reconcileOrdersFromPaymentSync(
                $this->platformConnection,
                $billings,
                $payouts,
            );

            // 5. Update Sync Run
            $syncRun->update([
                'status' => 'completed',
                'finished_at' => now(),
                'records_fetched' => count($billings) + count($payouts) + count($serviceFeeInvoices),
                'records_upserted' => $billingsCount + $payoutsCount + $reconciledOrders,
                'records_failed' => 0,
                'error_message' => $warnings !== [] ? mb_substr(implode("\n", $warnings), 0, 1000) : null,
            ]);

            $this->platformConnection->update([
                'last_sync_at' => now(),
                'last_sync_status' => 'completed',
                'status' => 'active',
                'last_error' => null,
                'last_error_at' => null,
            ]);

            Log::info("Payment sync completed for connection {$this->platformConnection->id}", [
                'billings' => $billingsCount,
                'payouts' => $payoutsCount,
                'service_fee_invoices' => count($serviceFeeInvoices),
                'orders_reconciled' => $reconciledOrders,
                'warnings' => $warnings,
            ]);

        } catch (Throwable $e) {
            $syncRun->update([
                'status' => 'failed',
                'finished_at' => now(),
                'error_message' => $e->getMessage(),
            ]);

            $this->platformConnection->update([
                'last_sync_status' => 'failed_api',
                'last_error' => mb_substr($e->getMessage(), 0, 500),
                'last_error_at' => now(),
            ]);

            Log::error("Payment sync failed for connection {$this->platformConnection->id}: {$e->getMessage()}", [
                'exception' => get_class($e),
                'trace' => $e->getTraceAsString(),
            ]);

            throw $e;
        }
    }
}
