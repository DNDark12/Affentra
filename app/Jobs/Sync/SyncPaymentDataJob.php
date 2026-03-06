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
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Throwable;

class SyncPaymentDataJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 2;

    public int $maxExceptions = 1;

    public int $timeout = 300; // 5 minutes max

    /**
     * Create a new job instance.
     */
    public function __construct(
        protected PlatformConnection $platformConnection,
        protected ?Carbon $since = null,
        protected ?Carbon $until = null,
        protected string $triggerType = 'payment_sync',
        protected ?int $syncRunId = null,
    ) {}

    /**
     * Handle job failure: finalize any orphan processing SyncRuns.
     */
    public function failed(?\Throwable $exception): void
    {
        // Mark any SyncRuns left in 'processing' for this connection as 'failed'
        SyncRun::where('platform_connection_id', $this->platformConnection->id)
            ->where('type', 'payment_sync')
            ->where('status', 'processing')
            ->update([
                'status' => 'failed',
                'finished_at' => now(),
                'error_message' => 'Job failed: ' . ($exception?->getMessage() ?? 'Unknown error'),
            ]);

        Log::warning('SyncPaymentDataJob::failed — cleaned up orphan runs', [
            'connection_id' => $this->platformConnection->id,
            'error' => $exception?->getMessage(),
        ]);
    }

    /**
     * Execute the job.
     */
    public function handle(
        IntegrationFactory $factory,
        AffiliatePaymentService $paymentService
    ): void {
        $this->platformConnection = $this->platformConnection->fresh() ?? $this->platformConnection;
        [$since, $until] = $this->resolveSyncWindow($this->platformConnection);

        $lockTtlSeconds = max(300, (int) config('integrations.sync.lock_ttl_seconds', 900));
        $lock = Cache::lock('sync:payment_connection:' . $this->platformConnection->id, $lockTtlSeconds);
        if (! $lock->get()) {
            Log::info('payment_sync.skipped_locked', [
                'platform_connection_id' => $this->platformConnection->id,
            ]);

            return;
        }

        $adapter = $factory->make($this->platformConnection->platform);
        
        $syncRun = $this->syncRunId !== null 
            ? SyncRun::find($this->syncRunId) 
            : null;

        if (! $syncRun) {
            $syncRun = SyncRun::create([
                'platform_connection_id' => $this->platformConnection->id,
                'user_id' => $this->platformConnection->user_id,
                'integration' => $this->platformConnection->platform,
                'type' => 'payment_sync',
                'status' => 'processing',
                'started_at' => now(),
            ]);
        } else {
            $syncRun->update([
                'status' => 'processing',
                'started_at' => now(),
            ]);
        }

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
                $billings = $adapter->fetchBillings($this->platformConnection, $since, $until);
                $billingFetched = true;
                $billingsCount = $paymentService->upsertBillingsFromApi($this->platformConnection, $billings);
            } catch (Throwable $billingError) {
                $warnings[] = 'Billing: '.$billingError->getMessage();
            }

            // 2. Fetch & Upsert Payouts (optional when only 1 cURL profile is available)
            try {
                $payouts = $adapter->fetchPayouts($this->platformConnection, $since, $until);
                $payoutFetched = true;
            } catch (Throwable $payoutError) {
                $warnings[] = 'Payout: '.$payoutError->getMessage();
            }

            // Fallback: derive payout rows from billing payload when payout endpoint is unavailable.
            if ($payouts === [] && $billings !== []) {
                $derivedPayouts = $this->derivePayoutsFromBillings($billings);
                if ($derivedPayouts !== []) {
                    $payouts = $derivedPayouts;
                    $warnings[] = 'Payout: dùng dữ liệu suy luận từ billing.';
                }
            }

            if ($payouts !== []) {
                $payoutsCount = $paymentService->upsertPayoutsFromApi($this->platformConnection, $payouts);
            }

            // 3. Fetch service-fee invoices (optional)
            if (method_exists($adapter, 'fetchBillFeeInvoices')) {
                try {
                    $serviceFeeInvoices = $adapter->fetchBillFeeInvoices($this->platformConnection, $since, $until);
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
            $detailMessage = $this->buildSyncDetailMessage(
                billingsFetched: count($billings),
                payoutsFetched: count($payouts),
                serviceFeeFetched: count($serviceFeeInvoices),
                billingsUpserted: $billingsCount,
                payoutsUpserted: $payoutsCount,
                ordersReconciled: $reconciledOrders,
                warnings: $warnings,
            );
            $details = $this->buildSyncDetails(
                billingsFetched: count($billings),
                payoutsFetched: count($payouts),
                serviceFeeFetched: count($serviceFeeInvoices),
                billingsUpserted: $billingsCount,
                payoutsUpserted: $payoutsCount,
                ordersReconciled: $reconciledOrders,
                warnings: $warnings,
            );

            $syncRun->update([
                'status' => $warnings === [] ? 'completed' : 'completed_with_warnings',
                'finished_at' => now(),
                'records_fetched' => count($billings) + count($payouts) + count($serviceFeeInvoices),
                'records_upserted' => $billingsCount + $payoutsCount + $reconciledOrders,
                'records_failed' => 0,
                'error_message' => $detailMessage,
                'details' => $details,
            ]);

            $this->platformConnection->update([
                'last_sync_at' => now(),
                'last_sync_status' => $warnings === [] ? 'completed' : 'completed_with_warnings',
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
                'details' => [
                    'modules' => [
                        'finance' => ['status' => 'failed'],
                    ],
                    'warnings' => [$e->getMessage()],
                ],
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
        } finally {
            optional($lock)->release();
        }
    }

    /**
     * @return array{0:Carbon,1:Carbon}
     */
    private function resolveSyncWindow(PlatformConnection $connection): array
    {
        if ($this->since !== null && $this->until !== null) {
            return [$this->since->copy(), $this->until->copy()];
        }

        $backfillDays = max((int) ($connection->backfill_days_override
            ?? config("integrations.{$connection->platform}.backfill_days", 90)), 1);
        $configuredHardLimitDays = (int) config("integrations.{$connection->platform}.hard_limit_days", 90);
        $hardLimitDays = max($configuredHardLimitDays, $backfillDays, 1);
        $incrementalOverlapHours = max((int) config('integrations.sync.incremental_overlap_hours', 6), 1);

        $since = $this->since
            ? $this->since->copy()
            : ($connection->last_sync_at
                ? $connection->last_sync_at->copy()->subHours($incrementalOverlapHours)
                : now()->subDays($backfillDays));

        $earliest = now()->subDays($hardLimitDays);
        if ($since->lt($earliest)) {
            $since = $earliest;
        }

        $until = $this->until?->copy() ?? now();

        return [$since, $until];
    }

    /**
     * @param  list<array<string, mixed>>  $billings
     * @return list<array<string, mixed>>
     */
    private function derivePayoutsFromBillings(array $billings): array
    {
        $rows = [];
        $seenPayoutIds = [];

        foreach ($billings as $row) {
            if (! is_array($row)) {
                continue;
            }

            $payoutId = trim((string) ($row['payout_id'] ?? $row['payoutId'] ?? ''));
            if ($payoutId === '' || isset($seenPayoutIds[$payoutId])) {
                continue;
            }

            $seenPayoutIds[$payoutId] = true;

            $rows[] = [
                'payout_id' => $payoutId,
                'payout_time' => $row['payment_completed_time']
                    ?? $row['payout_created_time']
                    ?? $row['payment_time']
                    ?? null,
                'amount' => $row['bill_total_amount']
                    ?? $row['total_payment_amount']
                    ?? $row['bill_commission_amount']
                    ?? $row['eligible_total_amount']
                    ?? 0,
                'status' => $row['validation_payout_status']
                    ?? $row['payment_status']
                    ?? $row['status']
                    ?? null,
                'currency' => 'VND',
                'raw_payload' => $row,
            ];
        }

        return $rows;
    }

    /**
     * @param  list<string>  $warnings
     */
    private function buildSyncDetailMessage(
        int $billingsFetched,
        int $payoutsFetched,
        int $serviceFeeFetched,
        int $billingsUpserted,
        int $payoutsUpserted,
        int $ordersReconciled,
        array $warnings,
    ): string {
        $lines = [
            sprintf('Billing: fetched=%d | upserted=%d', $billingsFetched, $billingsUpserted),
            sprintf('Payout: fetched=%d | upserted=%d', $payoutsFetched, $payoutsUpserted),
            sprintf('Service fee invoices: fetched=%d', $serviceFeeFetched),
            sprintf('Orders payout reconciled: %d', $ordersReconciled),
            $warnings === [] ? 'Warnings: none' : 'Warnings: ' . implode(' | ', $warnings),
        ];

        return mb_substr(implode("\n", $lines), 0, 2000);
    }

    /**
     * @param  list<string>  $warnings
     * @return array<string, mixed>
     */
    private function buildSyncDetails(
        int $billingsFetched,
        int $payoutsFetched,
        int $serviceFeeFetched,
        int $billingsUpserted,
        int $payoutsUpserted,
        int $ordersReconciled,
        array $warnings,
    ): array {
        return [
            'modules' => [
                'finance_billing' => [
                    'status' => 'ok',
                    'fetched' => $billingsFetched,
                    'upserted' => $billingsUpserted,
                ],
                'finance_payout' => [
                    'status' => 'ok',
                    'fetched' => $payoutsFetched,
                    'upserted' => $payoutsUpserted,
                ],
                'finance_service_fee' => [
                    'status' => 'ok',
                    'fetched' => $serviceFeeFetched,
                ],
                'finance_order_reconcile' => [
                    'status' => 'ok',
                    'updated' => $ordersReconciled,
                ],
            ],
            'warnings' => $warnings,
        ];
    }
}
