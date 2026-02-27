<?php

namespace App\Services\Payment;

use App\Models\AffiliateBilling;
use App\Models\AffiliatePayout;
use App\Models\PlatformConnection;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class AffiliatePaymentService
{
    /**
     * Upsert billings from API synchronization.
     */
    public function upsertBillingsFromApi(PlatformConnection $connection, array $billings): int
    {
        if (empty($billings)) {
            return 0;
        }

        $count = 0;
        DB::transaction(function () use ($connection, $billings, &$count) {
            foreach ($billings as $row) {
                $billingId = (string) ($row['billing_id'] ?? $row['settlement_id'] ?? '');
                if (!$billingId) continue;

                AffiliateBilling::updateOrCreate(
                    [
                        'platform' => $connection->platform,
                        'billing_id' => $billingId,
                    ],
                    [
                        'platform_connection_id' => $connection->id,
                        'user_id' => $connection->user_id,
                        'period_start' => isset($row['period_start']) ? Carbon::createFromTimestamp($row['period_start']) : null,
                        'period_end' => isset($row['period_end']) ? Carbon::createFromTimestamp($row['period_end']) : null,
                        'total_commission' => $row['total_commission'] ?? 0,
                        'service_fee' => $row['service_fee'] ?? 0,
                        'net_amount' => $row['net_amount'] ?? 0,
                        'status' => $row['status'] ?? 'settled',
                        'raw_json' => $row,
                    ]
                );
                $count++;
            }
        });

        return $count;
    }

    /**
     * Upsert payouts from API synchronization.
     */
    public function upsertPayoutsFromApi(PlatformConnection $connection, array $payouts): int
    {
        if (empty($payouts)) {
            return 0;
        }

        $count = 0;
        DB::transaction(function () use ($connection, $payouts, &$count) {
            foreach ($payouts as $row) {
                $payoutId = (string) ($row['payout_id'] ?? '');
                if (!$payoutId) continue;

                AffiliatePayout::updateOrCreate(
                    [
                        'platform' => $connection->platform,
                        'payout_id' => $payoutId,
                    ],
                    [
                        'platform_connection_id' => $connection->id,
                        'user_id' => $connection->user_id,
                        'payout_at' => isset($row['payout_time']) ? Carbon::createFromTimestamp($row['payout_time']) : null,
                        'amount' => $row['amount'] ?? 0,
                        'currency' => $row['currency'] ?? 'VND',
                        'bank_name' => $row['bank_name'] ?? null,
                        'account_number_masked' => $row['bank_account_number'] ?? null,
                        'status' => $row['status'] ?? 'completed',
                        'raw_json' => $row,
                    ]
                );
                $count++;
            }
        });

        return $count;
    }
}
