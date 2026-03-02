<?php

namespace Tests\Feature\Shopee;

use App\Jobs\Sync\SyncPaymentDataJob;
use App\Models\AffiliateBilling;
use App\Models\AffiliatePayout;
use App\Models\Order;
use App\Models\PlatformConnection;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class ShopeePaymentSyncTest extends TestCase
{
    use RefreshDatabase;

    public function test_payment_sync_allows_partial_finance_profiles(): void
    {
        $user = User::factory()->create();
        $connection = PlatformConnection::create([
            'user_id' => $user->id,
            'platform' => 'shopee',
            'method' => 'cookie',
            'status' => 'active',
            'cookie_header' => json_encode([
                'cookie' => 'SPC_EC=test-cookie',
                'affiliate_program_type' => '1',
                'profiles' => [
                    'billing' => [],
                ],
            ], JSON_THROW_ON_ERROR),
            'cookie_user_agent' => 'test-ua',
        ]);

        Http::fake([
            'affiliate.shopee.vn/api/v3/payment/billing_list*' => Http::response([
                'code' => 0,
                'data' => [
                    'list' => [
                        [
                            'billing_id' => 'BILL-PARTIAL-1',
                            'period_start' => now()->subDays(30)->timestamp,
                            'period_end' => now()->timestamp,
                            'total_commission' => 12345,
                            'net_amount' => 12000,
                            'status' => 'settled',
                        ],
                    ],
                    'total_count' => 1,
                ],
            ]),
            'affiliate.shopee.vn/api/v3/gql*' => Http::response([
                'data' => [],
            ]),
        ]);

        SyncPaymentDataJob::dispatchSync($connection);

        $this->assertDatabaseHas('affiliate_billings', [
            'billing_id' => 'BILL-PARTIAL-1',
        ]);

        $this->assertDatabaseHas('sync_runs', [
            'platform_connection_id' => $connection->id,
            'status' => 'completed',
        ]);
    }

    public function test_shopee_payment_sync_works(): void
    {
        $user = User::factory()->create();
        $connection = PlatformConnection::create([
            'user_id' => $user->id,
            'platform' => 'shopee',
            'method' => 'cookie',
            'status' => 'active',
            'cookie_header' => json_encode([
                'cookie' => 'SPC_EC=test-cookie',
                'affiliate_program_type' => '1',
                'profiles' => [
                    'billing' => [],
                    'payout_record' => [],
                    'service_fee_invoice' => [],
                ],
            ], JSON_THROW_ON_ERROR),
            'cookie_user_agent' => 'test-ua',
        ]);

        // Mock Billing List API
        Http::fake([
            'affiliate.shopee.vn/api/v3/payment/billing_list*' => Http::response([
                'data' => [
                    'list' => [
                        [
                            'billing_id' => 'BILL-123',
                            'period_start' => now()->subDays(30)->timestamp,
                            'period_end' => now()->timestamp,
                            'total_commission' => 1000000,
                            'service_fee' => 10000,
                            'net_amount' => 990000,
                            'status' => 'settled',
                        ]
                    ]
                ]
            ]),
            'affiliate.shopee.vn/api/v3/gql*' => Http::response([
                'data' => [
                    'getPaymentPayoutBillingList' => [
                        'payoutList' => [
                            [
                                'paymentPayout' => [
                                    'payoutId' => 'PAY-456',
                                    'payoutCreatedTime' => now()->timestamp,
                                    'totalPaymentAmount' => 500000,
                                    'payoutPaymentStatus' => 'completed',
                                ],
                            ]
                        ]
                    ],
                    'getPaymentSummaryBillFeeInvoiceList' => [
                        'paymentSummaryBillFeeInvoices' => [],
                        'totalCount' => 0,
                    ],
                ]
            ]),
        ]);

        // Dispatch Job
        SyncPaymentDataJob::dispatch($connection);

        // Assert Billing stored
        $this->assertDatabaseHas('affiliate_billings', [
            'billing_id' => 'BILL-123',
            'net_amount' => 990000,
        ]);

        // Assert Payout stored
        $this->assertDatabaseHas('affiliate_payouts', [
            'payout_id' => 'PAY-456',
            'amount' => 500000,
        ]);
    }

    public function test_payment_sync_marks_approved_orders_as_paid(): void
    {
        $user = User::factory()->create();
        $connection = PlatformConnection::create([
            'user_id' => $user->id,
            'platform' => 'shopee',
            'method' => 'cookie',
            'status' => 'active',
            'cookie_header' => json_encode([
                'cookie' => 'SPC_EC=test-cookie',
                'affiliate_program_type' => '1',
                'profiles' => [
                    'billing' => [],
                    'payout_record' => [],
                    'service_fee_invoice' => [],
                ],
            ], JSON_THROW_ON_ERROR),
            'cookie_user_agent' => 'test-ua',
        ]);

        $periodStart = now()->subDays(10)->startOfDay();
        $periodEnd = now()->subDays(1)->endOfDay();
        $payoutAt = now()->subDay()->startOfHour();

        $order = Order::create([
            'user_id' => $user->id,
            'connection_id' => $connection->id,
            'platform' => 'shopee',
            'order_code' => 'ORDER-1',
            'status' => 'approved',
            'payout_status' => 'unpaid',
            'order_amount' => 500000,
            'commission' => 50000,
            'ordered_at' => now()->subDays(9),
            'approved_at' => now()->subDays(8),
            'completed_at' => now()->subDays(7),
            'source' => 'api',
        ]);

        Http::fake([
            'affiliate.shopee.vn/api/v3/payment/billing_list*' => Http::response([
                'code' => 0,
                'data' => [
                    'list' => [
                        [
                            'billing_id' => 'BILL-PAID-1',
                            'period_start' => $periodStart->timestamp,
                            'period_end' => $periodEnd->timestamp,
                            'status' => 'settled',
                        ],
                    ],
                    'total_count' => 1,
                ],
            ]),
            'affiliate.shopee.vn/api/v3/gql*' => Http::response([
                'data' => [
                    'getPaymentPayoutBillingList' => [
                        'payoutList' => [
                            [
                                'paymentPayout' => [
                                    'payoutId' => 'PAY-PAID-1',
                                    'payoutCreatedTime' => $payoutAt->timestamp,
                                    'totalPaymentAmount' => 500000,
                                    'payoutPaymentStatus' => 'completed',
                                ],
                            ],
                        ],
                        'pagination' => [
                            'totalCount' => 1,
                        ],
                    ],
                    'getPaymentSummaryBillFeeInvoiceList' => [
                        'paymentSummaryBillFeeInvoices' => [],
                        'totalCount' => 0,
                    ],
                ],
            ]),
        ]);

        SyncPaymentDataJob::dispatchSync($connection, $periodStart, now());

        $this->assertDatabaseHas('orders', [
            'id' => $order->id,
            'payout_status' => 'paid',
        ]);

        $order->refresh();
        $this->assertNotNull($order->paid_at);
    }

    public function test_payment_sync_marks_paid_when_billing_status_is_numeric_and_payout_endpoint_missing(): void
    {
        $user = User::factory()->create();
        $connection = PlatformConnection::create([
            'user_id' => $user->id,
            'platform' => 'shopee',
            'method' => 'cookie',
            'status' => 'active',
            'cookie_header' => json_encode([
                'cookie' => 'SPC_EC=test-cookie',
                'affiliate_program_type' => '1',
                'profiles' => [
                    'billing' => [],
                ],
            ], JSON_THROW_ON_ERROR),
            'cookie_user_agent' => 'test-ua',
        ]);

        $periodStart = now()->subDays(10)->startOfDay();
        $periodEnd = now()->subDays(1)->endOfDay();
        $paidAt = now()->subDay()->startOfHour();

        $order = Order::create([
            'user_id' => $user->id,
            'connection_id' => $connection->id,
            'platform' => 'shopee',
            'order_code' => 'ORDER-NUMERIC-STATUS',
            'status' => 'approved',
            'payout_status' => 'unpaid',
            'order_amount' => 500000,
            'commission' => 50000,
            'ordered_at' => now()->subDays(9),
            'approved_at' => now()->subDays(8),
            'completed_at' => now()->subDays(7),
            'source' => 'api',
        ]);

        Http::fake([
            'affiliate.shopee.vn/api/v3/payment/billing_list*' => Http::response([
                'code' => 0,
                'data' => [
                    'list' => [
                        [
                            'billing_id' => 'BILL-NUMERIC-PAID',
                            'payout_id' => 'PAY-FROM-BILLING',
                            'order_completed_period_start_time' => $periodStart->timestamp,
                            'order_completed_period_end_time' => $periodEnd->timestamp,
                            'validation_payout_status' => 2,
                            'payment_status' => 6,
                            'payment_completed_time' => $paidAt->timestamp,
                            'bill_total_amount' => 5032200000,
                        ],
                    ],
                    'total_count' => 1,
                ],
            ]),
            'affiliate.shopee.vn/api/v3/gql*' => Http::response([
                'data' => [],
            ]),
        ]);

        SyncPaymentDataJob::dispatchSync($connection, $periodStart, now());

        $this->assertDatabaseHas('orders', [
            'id' => $order->id,
            'payout_status' => 'paid',
        ]);
        $this->assertDatabaseHas('affiliate_payouts', [
            'platform_connection_id' => $connection->id,
            'payout_id' => 'PAY-FROM-BILLING',
        ]);
    }
}
