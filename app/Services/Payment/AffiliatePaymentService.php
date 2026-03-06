<?php

namespace App\Services\Payment;

use App\Models\AffiliateBilling;
use App\Models\AffiliatePayout;
use App\Models\Order;
use App\Models\PlatformConnection;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

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
                $billingId = (string) ($this->firstValueByKeys($row, [
                    'billing_id',
                    'settlement_id',
                    'bill_id',
                    'validation_id',
                    'validationId',
                    'payment_summary_bill_id',
                ]) ?? '');
                if (! $billingId) {
                    continue;
                }

                $platformCommission = $this->toDisplayMoney($this->firstValueByKeys($row, [
                    'shopee_commission_dis',
                    'shopee_commission',
                ]));
                $brandCommission = $this->toDisplayMoney($this->firstValueByKeys($row, [
                    'brand_commission_dis',
                    'brand_commission',
                ]));
                $bonusCommission = $this->toDisplayMoney($this->firstValueByKeys($row, [
                    'bonus_commission_dis',
                    'bonus_commission',
                ]));

                $totalCommission = $this->toDisplayMoney($this->firstValueByKeys($row, [
                    'total_commission',
                    'total_commission_amount',
                    'commission_amount',
                    'eligible_total_amount_dis',
                    'gross_amount',
                    'eligible_total_amount',
                    'bill_commission_amount',
                ]));

                if ($totalCommission <= 0) {
                    $totalCommission = $platformCommission + $brandCommission + $bonusCommission;
                }

                $netAmount = $this->toDisplayMoney($this->firstValueByKeys($row, [
                    'total_payment_amount_dis',
                    'net_amount',
                    'actual_payment_amount',
                    'payment_amount',
                    'bill_total_amount',
                    'total_payment_amount',
                ]));
                if ($netAmount <= 0) {
                    $netAmount = $this->toDisplayMoney($this->firstValueByKeys($row, [
                        'bill_total_amount',
                        'total_payment_amount',
                    ]));
                }

                $serviceFee = $this->extractServiceFee($row);
                if ($totalCommission > 0 && $netAmount > 0) {
                    $serviceFee = max(round($totalCommission - $netAmount, 2), 0.0);
                }

                AffiliateBilling::updateOrCreate(
                    [
                        'platform' => $connection->platform,
                        'billing_id' => $billingId,
                    ],
                    [
                        'platform_connection_id' => $connection->id,
                        'user_id' => $connection->user_id,
                        'period_start' => $this->parseTimestamp($this->firstValueByKeys($row, [
                            'period_start',
                            'order_completed_start_time',
                            'order_completed_period_start_time',
                            'start_time',
                            'settlement_start_time',
                            'validation_period_start_time',
                            'payment_period_start_time',
                            'payment_complete_period_start_time',
                        ])),
                        'period_end' => $this->parseTimestamp($this->firstValueByKeys($row, [
                            'period_end',
                            'order_completed_end_time',
                            'order_completed_period_end_time',
                            'end_time',
                            'settlement_end_time',
                            'validation_period_end_time',
                            'payment_period_end_time',
                            'payment_complete_period_end_time',
                        ])),
                        'total_commission' => $totalCommission,
                        'service_fee' => $serviceFee,
                        'net_amount' => $netAmount,
                        'status' => (string) ($this->firstValueByKeys($row, [
                            'status',
                            'payment_status',
                            'validation_payout_status',
                            'settlement_status',
                            'billing_status',
                            'billFeeStatus',
                            'bill_fee_status',
                        ]) ?? 'settled'),
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
                $paymentPayout = is_array($row['paymentPayout'] ?? null) ? $row['paymentPayout'] : [];
                $payoutId = (string) ($this->firstValueByKeys($row, [
                    'payout_id',
                    'payoutId',
                ]) ?? ($paymentPayout['payoutId'] ?? ''));
                if (! $payoutId) {
                    continue;
                }

                AffiliatePayout::updateOrCreate(
                    [
                        'platform' => $connection->platform,
                        'payout_id' => $payoutId,
                    ],
                    [
                        'platform_connection_id' => $connection->id,
                        'user_id' => $connection->user_id,
                        'payout_at' => $this->parseTimestamp($this->firstValueByKeys($row, [
                            'payout_time',
                            'payout_at',
                            'transfer_time',
                            'payoutCreatedTime',
                            'payArrivalTime',
                        ])),
                        'amount' => $this->toDisplayMoney($this->firstValueByKeys($row, [
                            'amount',
                            'totalPaymentAmount',
                            'total_payment_amount',
                        ])),
                        'currency' => (string) ($row['currency'] ?? 'VND'),
                        'bank_name' => $row['bank_name'] ?? null,
                        'account_number_masked' => $row['bank_account_number'] ?? null,
                        'status' => $this->normalizePayoutStatus($this->firstValueByKeys($row, [
                            'status',
                            'payoutPaymentStatus',
                            'payout_payment_status',
                        ]) ?? 'completed'),
                        'raw_json' => $row,
                    ]
                );
                $count++;
            }
        });

        return $count;
    }

    /**
     * Reconcile paid/processing state back to approved orders.
     * Uses billing periods as source-of-truth windows and payout time as paid_at fallback.
     */
    public function reconcileOrdersFromPaymentSync(
        PlatformConnection $connection,
        array $billings,
        array $payouts = [],
    ): int {
        if (empty($billings)) {
            return 0;
        }

        $updated = 0;
        $defaultPaidAt = $this->resolveLatestPayoutAt($payouts);

        DB::transaction(function () use ($connection, $billings, $defaultPaidAt, &$updated): void {
            foreach ($billings as $billing) {
                [$periodStart, $periodEnd] = $this->resolveBillingWindow($billing);
                if (! $periodStart || ! $periodEnd) {
                    continue;
                }

                $nextPayoutStatus = $this->mapBillingStatusToPayoutStatus(
                    $this->firstValueByKeys($billing, [
                        'validation_payout_status',
                        'status',
                        'payment_status',
                        'settlement_status',
                        'billing_status',
                    ]),
                );

                if ($nextPayoutStatus === null) {
                    continue;
                }

                $paidAt = $this->parseTimestamp($this->firstValueByKeys($billing, [
                    'payment_completed_time',
                    'settled_time',
                    'settlement_time',
                    'payout_time',
                    'payout_created_time',
                    'payment_time',
                ])) ?? $defaultPaidAt;

                $query = Order::query()
                    ->where('connection_id', $connection->id)
                    ->where('platform', $connection->platform)
                    ->where('status', 'approved')
                    ->where(function ($q) use ($periodStart, $periodEnd): void {
                        $q->whereBetween('completed_at', [$periodStart, $periodEnd])
                            ->orWhere(function ($inner) use ($periodStart, $periodEnd): void {
                                $inner->whereNull('completed_at')
                                    ->whereBetween('approved_at', [$periodStart, $periodEnd]);
                            })
                            ->orWhere(function ($inner) use ($periodStart, $periodEnd): void {
                                $inner->whereNull('completed_at')
                                    ->whereNull('approved_at')
                                    ->whereBetween('ordered_at', [$periodStart, $periodEnd]);
                            });
                    });

                if ($nextPayoutStatus === 'paid') {
                    $rows = $query->where('payout_status', '!=', 'paid')->update([
                        'payout_status' => 'paid',
                        'paid_at' => $paidAt ?? now(),
                        'updated_at' => now(),
                    ]);

                    $updated += $rows;

                    if ($paidAt !== null) {
                        $rows = Order::query()
                            ->where('connection_id', $connection->id)
                            ->where('platform', $connection->platform)
                            ->where('status', 'approved')
                            ->where('payout_status', 'paid')
                            ->where(function ($q) use ($paidAt): void {
                                $q->whereNull('paid_at')
                                    ->orWhere('paid_at', '!=', $paidAt);
                            })
                            ->where(function ($q) use ($periodStart, $periodEnd): void {
                                $q->whereBetween('completed_at', [$periodStart, $periodEnd])
                                    ->orWhere(function ($inner) use ($periodStart, $periodEnd): void {
                                        $inner->whereNull('completed_at')
                                            ->whereBetween('approved_at', [$periodStart, $periodEnd]);
                                    })
                                    ->orWhere(function ($inner) use ($periodStart, $periodEnd): void {
                                        $inner->whereNull('completed_at')
                                            ->whereNull('approved_at')
                                            ->whereBetween('ordered_at', [$periodStart, $periodEnd]);
                                    });
                            })
                            ->update([
                                'paid_at' => $paidAt,
                                'updated_at' => now(),
                            ]);

                        $updated += $rows;
                    }

                    continue;
                }

                $rows = $query
                    ->whereIn('payout_status', ['unpaid', 'processing'])
                    ->update([
                        'payout_status' => $nextPayoutStatus,
                        'updated_at' => now(),
                    ]);

                $updated += $rows;
            }
        });

        return $updated;
    }

    /**
     * @param  array<string, mixed>  $row
     * @return array{0: Carbon|null, 1: Carbon|null}
     */
    private function resolveBillingWindow(array $row): array
    {
        $start = $this->parseTimestamp($this->firstValueByKeys($row, [
            'period_start',
            'order_completed_start_time',
            'order_completed_period_start_time',
            'start_time',
            'settlement_start_time',
            'validation_period_start_time',
            'payment_period_start_time',
            'billing_start_time',
        ]));

        $end = $this->parseTimestamp($this->firstValueByKeys($row, [
            'period_end',
            'order_completed_end_time',
            'order_completed_period_end_time',
            'end_time',
            'settlement_end_time',
            'validation_period_end_time',
            'payment_period_end_time',
            'billing_end_time',
        ]));

        return [$start, $end];
    }

    private function resolveLatestPayoutAt(array $payouts): ?Carbon
    {
        $latest = null;
        foreach ($payouts as $row) {
            if (! is_array($row)) {
                continue;
            }

            $current = $this->parseTimestamp($this->firstValueByKeys($row, [
                'payout_time',
                'payout_at',
                'transfer_time',
            ]));

            if ($current === null) {
                continue;
            }

            if ($latest === null || $current->greaterThan($latest)) {
                $latest = $current;
            }
        }

        return $latest;
    }

    private function mapBillingStatusToPayoutStatus(mixed $status): ?string
    {
        if ($status === null || $status === '') {
            return null;
        }

        $normalized = mb_strtolower(trim((string) $status));
        if ($normalized === '') {
            return null;
        }

        if (
            str_contains($normalized, 'paid')
            || str_contains($normalized, 'settled')
            || str_contains($normalized, 'complete')
            || str_contains($normalized, 'success')
            || str_contains($normalized, 'đã thanh toán')
            || $normalized === '2'
            || $normalized === '3'
            || $normalized === '6'
        ) {
            return 'paid';
        }

        if (
            str_contains($normalized, 'process')
            || str_contains($normalized, 'pend')
            || str_contains($normalized, 'review')
            || $normalized === '0'
            || $normalized === '1'
        ) {
            return 'processing';
        }

        if (
            str_contains($normalized, 'reject')
            || str_contains($normalized, 'cancel')
            || str_contains($normalized, 'fail')
        ) {
            return 'unpaid';
        }

        return null;
    }

    private function normalizePayoutStatus(mixed $status): string
    {
        $raw = mb_strtolower(trim((string) $status));
        if (
            in_array($raw, ['2', '3', '6', 'paid', 'settled', 'completed', 'success', 'done', 'đã thanh toán'], true)
        ) {
            return 'paid';
        }

        if (
            in_array($raw, ['0', '1', 'pending', 'processing', 'review', 'created', 'init', 'đang xử lý'], true)
        ) {
            return 'pending';
        }

        if (
            in_array($raw, ['-1', '4', '5', 'failed', 'rejected', 'cancelled', 'canceled', 'closed', 'không thanh toán'], true)
        ) {
            return 'failed';
        }

        return 'pending';
    }

    /**
     * @param  array<string, mixed>  $row
     * @param  list<string>  $keys
     */
    private function firstValueByKeys(array $row, array $keys): mixed
    {
        foreach ($keys as $key) {
            if (array_key_exists($key, $row)) {
                return $row[$key];
            }
        }

        return null;
    }

    private function parseTimestamp(mixed $value): ?Carbon
    {
        if ($value === null || $value === '') {
            return null;
        }

        try {
            if (is_numeric($value) || (is_string($value) && preg_match('/^\d+$/', trim($value)) === 1)) {
                $timestamp = (int) $value;
                if ($timestamp > 9_999_999_999) {
                    $timestamp = (int) floor($timestamp / 1000);
                }

                return Carbon::createFromTimestamp($timestamp);
            }

            return Carbon::parse((string) $value);
        } catch (\Throwable) {
            return null;
        }
    }

    private function toNumeric(mixed $value): float
    {
        if ($value === null || $value === '') {
            return 0.0;
        }

        if (is_numeric($value)) {
            return (float) $value;
        }

        if (is_string($value)) {
            $normalized = trim((string) $value);
            $normalized = preg_replace('/[^\d,\.\-]/u', '', $normalized) ?? '';
            if ($normalized === '' || $normalized === '-' || $normalized === '.' || $normalized === ',') {
                return 0.0;
            }

            if (preg_match('/^-?\d{1,3}(\.\d{3})+(,\d+)?$/', $normalized) === 1) {
                $normalized = str_replace('.', '', $normalized);
                $normalized = str_replace(',', '.', $normalized);
                return is_numeric($normalized) ? (float) $normalized : 0.0;
            }

            if (preg_match('/^-?\d{1,3}(,\d{3})+(\.\d+)?$/', $normalized) === 1) {
                $normalized = str_replace(',', '', $normalized);
                return is_numeric($normalized) ? (float) $normalized : 0.0;
            }

            if (str_contains($normalized, ',') && ! str_contains($normalized, '.')) {
                $normalized = str_replace(',', '.', $normalized);
            }

            $normalized = preg_replace('/[^\d\.\-]/', '', $normalized) ?? '';
            return is_numeric($normalized) ? (float) $normalized : 0.0;
        }

        return 0.0;
    }

    private function toDisplayMoney(mixed $value): float
    {
        $numeric = $this->toNumeric($value);
        if ($numeric === 0.0) {
            return 0.0;
        }

        if (is_int($value) || is_float($value)) {
            if (abs($numeric) >= 1_000_000) {
                return round($numeric / 100_000, 2);
            }

            return round($numeric, 2);
        }

        if (is_string($value)) {
            $trimmed = trim($value);
            if ($trimmed !== '' && preg_match('/^\d+$/', $trimmed) === 1 && abs($numeric) >= 1_000_000) {
                return round($numeric / 100_000, 2);
            }
        }

        return round($numeric, 2);
    }

    /**
     * @param  array<string, mixed>  $row
     */
    private function extractServiceFee(array $row): float
    {
        $direct = $this->toDisplayMoney($this->firstValueByKeys($row, [
            'service_fee',
            'total_service_fee',
            'service_fee_amount',
            'service_fee_dis',
            'total_service_fee_dis',
            'mcn_management_fee_dis',
            'mcn_management_fee',
        ]));

        if ($direct > 0) {
            return $direct;
        }

        $serviceFees = $row['service_fees'] ?? null;
        if (is_array($serviceFees) && array_is_list($serviceFees)) {
            $sum = 0.0;
            foreach ($serviceFees as $feeItem) {
                if (! is_array($feeItem)) {
                    continue;
                }

                $sum += $this->toDisplayMoney($this->firstValueByKeys($feeItem, [
                    'service_fee_dis',
                    'total_fee_dis',
                    'service_fee',
                    'fee_amount',
                    'amount',
                    'value',
                ]));
            }

            if ($sum > 0) {
                return $sum;
            }
        }

        return 0.0;
    }
}
