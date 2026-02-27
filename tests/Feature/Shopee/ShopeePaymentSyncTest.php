<?php

namespace Tests\Feature\Shopee;

use App\Jobs\Sync\SyncPaymentDataJob;
use App\Models\AffiliateBilling;
use App\Models\AffiliatePayout;
use App\Models\PlatformConnection;
use App\Models\User;
use App\Services\Integration\Shopee\ShopeeIntegration;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class ShopeePaymentSyncTest extends TestCase
{
    use RefreshDatabase;

    public function test_shopee_payment_sync_works()
    {
        $user = User::factory()->create();
        $connection = PlatformConnection::create([
            'user_id' => $user->id,
            'platform' => 'shopee',
            'method' => 'cookie',
            'status' => 'active',
            'cookie_header' => 'test-cookie',
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
                    'getPayoutList' => [
                        'list' => [
                            [
                                'payout_id' => 'PAY-456',
                                'payout_time' => now()->timestamp,
                                'amount' => 500000,
                                'currency' => 'VND',
                                'bank_name' => 'TPBank',
                                'bank_account_number' => '****1234',
                                'status' => 'completed',
                            ]
                        ]
                    ]
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
}
