<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Jobs\Tracking\AggregateDailyClicksJob;
use App\Jobs\Sync\SyncPlatformConnectionJob;
use App\Models\Click;
use App\Models\DailyStat;
use App\Models\PlatformConnection;
use App\Models\TrackingLink;
use App\Models\User;
use App\Services\Clicks\ClickAnalyticsService;
use App\Services\Order\OrderService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class SyncPlatformConnectionJobTest extends TestCase
{
    use RefreshDatabase;

    public function test_auth_failure_marks_connection_error_and_sync_run_failed_auth(): void
    {
        $owner = User::factory()->create(['role' => 'owner']);
        $connection = PlatformConnection::factory()->create([
            'user_id'  => $owner->id,
            'platform' => 'shopee',
            'status'   => 'active',
        ]);

        Http::fake([
            '*' => Http::response([], 401),
        ]);

        $job = new SyncPlatformConnectionJob(
            connectionId: $connection->id,
            type: 'manual',
            userId: $owner->id,
        );

        $job->handle(
            app(OrderService::class),
            app(ClickAnalyticsService::class),
        );

        $connection->refresh();

        $this->assertSame('error', $connection->status);
        $this->assertDatabaseHas('sync_runs', [
            'platform_connection_id' => $connection->id,
            'status'                 => 'failed_auth',
        ]);
    }

    public function test_cookie_sync_imports_conversion_rows_from_portal_endpoint(): void
    {
        $owner = User::factory()->create(['role' => 'owner']);
        $connection = PlatformConnection::factory()->create([
            'user_id' => $owner->id,
            'platform' => 'shopee',
            'method' => 'cookie',
            'status' => 'active',
            'cookie_header' => json_encode([
                'cookie' => 'SPC_EC=dummy-cookie-value',
                'affiliate_program_type' => '1',
            ], JSON_THROW_ON_ERROR),
            'cookie_user_agent' => 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7)',
        ]);

        Http::fake([
            'https://affiliate.shopee.vn/api/v3/report/list*' => Http::response([
                'code' => 0,
                'data' => [
                    'list' => [
                        [
                            'purchase_time' => 1767526304,
                            'checkout_status' => 'Waiting for payment',
                            'conversion_status' => 2,
                            'affiliate_net_commission' => '5082000000',
                            'sub_id' => 'sub-demo',
                            'orders' => [
                                [
                                    'order_id' => '221225504217979',
                                    'order_sn' => '2601046J0HJJUU',
                                    'order_status' => 'COMPLETED',
                                    'display_order_status' => 2,
                                    'complete_time' => 1768135832,
                                    'items' => [
                                        [
                                            'item_price' => 55000000000,
                                            'actual_amount' => 48400000000,
                                            'item_commission' => 2178000000,
                                            'capped_brand_commission' => 2904000000,
                                        ],
                                    ],
                                ],
                            ],
                        ],
                        [
                            'purchase_time' => 1769877224,
                            'checkout_status' => 'Waiting for payment',
                            'conversion_status' => 3,
                            'sub_id' => 'sub-demo-2',
                            'orders' => [
                                [
                                    'order_id' => '223576420241531',
                                    'order_sn' => '260201GRTN5R3U',
                                    'order_status' => 'COMPLETED',
                                    'display_order_status' => 3,
                                    'items' => [
                                        [
                                            'actual_amount' => 23540000000,
                                            'item_commission' => 0,
                                            'affiliate_item_status' => 3,
                                            'fraud_status' => 3,
                                            'fraud_reason' => 'Rejected due to fraudulent activity detected',
                                        ],
                                    ],
                                ],
                            ],
                        ],
                    ],
                    'total_count' => 2,
                ],
            ], 200),
            'https://affiliate.shopee.vn/api/v1/click_report/list*' => Http::response([
                'code' => 0,
                'data' => [
                    'list' => [],
                    'total_count' => 0,
                ],
            ], 200),
        ]);

        $job = new SyncPlatformConnectionJob(
            connectionId: $connection->id,
            type: 'manual',
            userId: $owner->id,
        );

        $job->handle(
            app(OrderService::class),
            app(ClickAnalyticsService::class),
        );

        $this->assertDatabaseHas('orders', [
            'connection_id' => $connection->id,
            'platform' => 'shopee',
            'order_code' => '2601046J0HJJUU',
            'status' => 'approved',
            'order_amount' => 484000,
            'listed_amount' => 550000,
            'commission' => 50820,
            'commission_platform' => 21780,
            'commission_brand' => 29040,
            'external_order_id' => '221225504217979',
        ]);

        $this->assertDatabaseHas('orders', [
            'connection_id' => $connection->id,
            'platform' => 'shopee',
            'order_code' => '260201GRTN5R3U',
            'status' => 'rejected',
        ]);

        $this->assertDatabaseHas('sync_runs', [
            'platform_connection_id' => $connection->id,
            'status' => 'completed',
            'records_fetched' => 2,
            'records_upserted' => 2,
        ]);

        Http::assertSent(function ($request): bool {
            return str_starts_with($request->url(), 'https://affiliate.shopee.vn/api/v3/report/list')
                && $request->hasHeader('affiliate-program-type', '1')
                && $request->hasHeader('Cookie');
        });
    }

    public function test_incremental_sync_uses_overlap_hours_instead_of_full_backfill_window(): void
    {
        config()->set('integrations.shopee.backfill_days', 90);
        config()->set('integrations.shopee.hard_limit_days', 90);
        config()->set('integrations.sync.incremental_overlap_hours', 6);

        $owner = User::factory()->create(['role' => 'owner']);
        $lastSyncAt = now()->subHours(2);
        $connection = PlatformConnection::factory()->create([
            'user_id' => $owner->id,
            'platform' => 'shopee',
            'method' => 'cookie',
            'status' => 'active',
            'last_sync_at' => $lastSyncAt,
            'cookie_header' => json_encode([
                'cookie' => 'SPC_EC=dummy-cookie-value',
                'affiliate_program_type' => '1',
            ], JSON_THROW_ON_ERROR),
            'cookie_user_agent' => 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7)',
        ]);

        Http::fake([
            'https://affiliate.shopee.vn/api/v3/report/list*' => Http::response([
                'code' => 0,
                'data' => [
                    'list' => [],
                    'total_count' => 0,
                ],
            ], 200),
            'https://affiliate.shopee.vn/api/v1/click_report/list*' => Http::response([
                'code' => 0,
                'data' => [
                    'list' => [],
                    'total_count' => 0,
                ],
            ], 200),
        ]);

        $expectedSince = $lastSyncAt->copy()->subHours(6)->timestamp;

        $job = new SyncPlatformConnectionJob(
            connectionId: $connection->id,
            type: 'manual',
            userId: $owner->id,
        );

        $job->handle(
            app(OrderService::class),
            app(ClickAnalyticsService::class),
        );

        Http::assertSent(function ($request) use ($expectedSince): bool {
            if (! str_starts_with($request->url(), 'https://affiliate.shopee.vn/api/v3/report/list')) {
                return false;
            }

            parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);
            if (! isset($query['purchase_time_s'])) {
                return false;
            }

            return abs((int) $query['purchase_time_s'] - $expectedSince) <= 5;
        });
    }

    public function test_cookie_sync_unauthorized_marks_connection_error(): void
    {
        $owner = User::factory()->create(['role' => 'owner']);
        $connection = PlatformConnection::factory()->create([
            'user_id' => $owner->id,
            'platform' => 'shopee',
            'method' => 'cookie',
            'status' => 'active',
            'cookie_header' => 'SPC_EC=dummy-cookie-value',
        ]);

        Http::fake([
            'https://affiliate.shopee.vn/api/v3/report/list*' => Http::response([], 401),
        ]);

        $job = new SyncPlatformConnectionJob(
            connectionId: $connection->id,
            type: 'manual',
            userId: $owner->id,
        );

        $job->handle(
            app(OrderService::class),
            app(ClickAnalyticsService::class),
        );

        $connection->refresh();
        $this->assertSame('error', $connection->status);
        $this->assertSame('manual', $connection->sync_mode);

        $this->assertDatabaseHas('sync_runs', [
            'platform_connection_id' => $connection->id,
            'status' => 'failed_auth',
        ]);
    }

    public function test_cookie_sync_fallback_maps_order_to_tracking_link_by_product_url(): void
    {
        $owner = User::factory()->create(['role' => 'owner']);
        $connection = PlatformConnection::factory()->create([
            'user_id' => $owner->id,
            'platform' => 'shopee',
            'method' => 'cookie',
            'status' => 'active',
            'cookie_header' => json_encode([
                'cookie' => 'SPC_EC=dummy-cookie-value',
                'affiliate_program_type' => '1',
            ], JSON_THROW_ON_ERROR),
            'cookie_user_agent' => 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7)',
        ]);

        $trackingLink = TrackingLink::query()->create([
            'user_id' => $owner->id,
            'campaign_id' => null,
            'short_code' => 'abc123xy',
            'destination_url' => 'https://shopee.vn/product/1663031317/46553051070',
            'platform' => 'shopee',
            'status' => 'active',
        ]);

        Http::fake([
            'https://affiliate.shopee.vn/api/v3/report/list*' => Http::response([
                'code' => 0,
                'data' => [
                    'list' => [
                        [
                            'purchase_time' => 1767526304,
                            'conversion_status' => 2,
                            'sub_id' => '----',
                            'orders' => [
                                [
                                    'order_id' => '221225504217979',
                                    'order_sn' => '2601046J0HJJUU',
                                    'order_status' => 'COMPLETED',
                                    'display_order_status' => 2,
                                    'items' => [
                                        [
                                            'shop_id' => '1663031317',
                                            'item_id' => '46553051070',
                                            'item_price' => 55000000000,
                                            'actual_amount' => 48400000000,
                                            'item_commission' => 2178000000,
                                            'capped_brand_commission' => 2904000000,
                                        ],
                                    ],
                                ],
                            ],
                        ],
                    ],
                    'total_count' => 1,
                ],
            ], 200),
            'https://affiliate.shopee.vn/api/v1/click_report/list*' => Http::response([
                'code' => 0,
                'data' => [
                    'list' => [],
                    'total_count' => 0,
                ],
            ], 200),
        ]);

        $job = new SyncPlatformConnectionJob(
            connectionId: $connection->id,
            type: 'manual',
            userId: $owner->id,
        );

        $job->handle(
            app(OrderService::class),
            app(ClickAnalyticsService::class),
        );

        $this->assertDatabaseHas('orders', [
            'connection_id' => $connection->id,
            'order_code' => '2601046J0HJJUU',
            'tracking_link_id' => $trackingLink->id,
            'missing_sub_id' => 0,
        ]);

        $this->assertDatabaseHas('daily_stats', [
            'platform' => 'shopee',
            'tracking_link_id' => $trackingLink->id,
            'orders' => 1,
        ]);

        $this->assertSame(1, DailyStat::query()->where('tracking_link_id', $trackingLink->id)->sum('orders'));
    }

    public function test_cookie_sync_enriches_click_report_with_conversion_click_id_mapping(): void
    {
        $owner = User::factory()->create(['role' => 'owner']);
        $connection = PlatformConnection::factory()->create([
            'user_id' => $owner->id,
            'platform' => 'shopee',
            'method' => 'cookie',
            'status' => 'active',
            'cookie_header' => json_encode([
                'cookie' => 'SPC_EC=dummy-cookie-value',
                'affiliate_program_type' => '1',
            ], JSON_THROW_ON_ERROR),
            'cookie_user_agent' => 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7)',
        ]);

        $trackingLink = TrackingLink::query()->create([
            'user_id' => $owner->id,
            'campaign_id' => null,
            'short_code' => 'clickid01',
            'destination_url' => 'https://shopee.vn/product/314455038/24606873009',
            'platform' => 'shopee',
            'status' => 'active',
            'sub_id' => null,
        ]);

        Http::fake([
            'https://affiliate.shopee.vn/api/v3/report/list*' => Http::response([
                'code' => 0,
                'data' => [
                    'list' => [
                        [
                            'click_id' => 'click-id-001',
                            'utm_content' => '----',
                            'purchase_time' => 1769877224,
                            'orders' => [
                                [
                                    'order_sn' => '260201GRTN5R3U',
                                    'order_id' => '223576420241531',
                                    'order_status' => 'COMPLETED',
                                    'display_order_status' => 3,
                                    'items' => [
                                        [
                                            'shop_id' => 314455038,
                                            'item_id' => 24606873009,
                                            'actual_amount' => 23540000000,
                                            'item_commission' => 0,
                                            'affiliate_item_status' => 3,
                                            'fraud_status' => 3,
                                            'fraud_reason' => 'Rejected due to fraudulent activity detected',
                                        ],
                                    ],
                                ],
                            ],
                        ],
                    ],
                    'total_count' => 1,
                ],
            ], 200),
            'https://affiliate.shopee.vn/api/v1/click_report/list*' => Http::response([
                'code' => 0,
                'data' => [
                    'list' => [
                        [
                            'click_id' => 'click-id-001',
                            'click_time' => now()->subHour()->timestamp,
                            'sub_id' => '----',
                            'click_count' => 1,
                        ],
                    ],
                    'total_count' => 1,
                ],
            ], 200),
        ]);

        $job = new SyncPlatformConnectionJob(
            connectionId: $connection->id,
            type: 'manual',
            userId: $owner->id,
        );

        $job->handle(
            app(OrderService::class),
            app(ClickAnalyticsService::class),
        );

        $this->assertDatabaseHas('clicks', [
            'tracking_link_id' => $trackingLink->id,
            'connection_id' => $connection->id,
        ]);

        $click = Click::query()
            ->where('tracking_link_id', $trackingLink->id)
            ->where('connection_id', $connection->id)
            ->latest('created_at')
            ->first();

        $this->assertNotNull($click);
        $this->assertSame('item_id', $click->source_meta['attribution_source'] ?? null);
        $this->assertSame('click-id-001', $click->source_meta['click_id'] ?? null);
        $this->assertSame('24606873009', $click->source_meta['item_id'] ?? null);
    }

    public function test_partial_sync_marks_completed_with_warnings_when_orders_fail_but_clicks_pass(): void
    {
        Queue::fake();

        $owner = User::factory()->create(['role' => 'owner']);
        $connection = PlatformConnection::factory()->create([
            'user_id' => $owner->id,
            'platform' => 'shopee',
            'method' => 'cookie',
            'status' => 'active',
            'cookie_header' => json_encode([
                'cookie' => 'SPC_EC=dummy-cookie-value',
                'affiliate_program_type' => '1',
            ], JSON_THROW_ON_ERROR),
            'cookie_user_agent' => 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7)',
        ]);

        $trackingLink = TrackingLink::query()->create([
            'user_id' => $owner->id,
            'campaign_id' => null,
            'short_code' => 'syncwarn01',
            'destination_url' => 'https://shopee.vn/product/1/2',
            'platform' => 'shopee',
            'status' => 'active',
            'sub_id' => 'sub-sync-warning',
        ]);

        Http::fake([
            'https://affiliate.shopee.vn/api/v3/report/list*' => Http::response([
                'code' => 999,
                'msg' => 'conversion unavailable',
            ], 500),
            'https://affiliate.shopee.vn/api/v1/click_report/list*' => Http::response([
                'code' => 0,
                'data' => [
                    'list' => [
                        [
                            'click_time' => now()->timestamp,
                            'sub_id1' => 'sub-sync-warning',
                            'click_count' => 2,
                        ],
                    ],
                    'total_count' => 1,
                ],
            ], 200),
        ]);

        $job = new SyncPlatformConnectionJob(
            connectionId: $connection->id,
            type: 'manual',
            userId: $owner->id,
        );

        $job->handle(
            app(OrderService::class),
            app(ClickAnalyticsService::class),
        );

        $this->assertDatabaseHas('sync_runs', [
            'platform_connection_id' => $connection->id,
            'status' => 'completed_with_warnings',
        ]);

        $connection->refresh();
        $this->assertSame('completed_with_warnings', $connection->last_sync_status);

        $this->assertDatabaseHas('clicks', [
            'tracking_link_id' => $trackingLink->id,
            'connection_id' => $connection->id,
            'referer_domain' => 'shopee_sync',
        ]);
        $this->assertSame(
            2,
            \App\Models\Click::query()
                ->where('tracking_link_id', $trackingLink->id)
                ->where('connection_id', $connection->id)
                ->count()
        );

        Queue::assertPushed(AggregateDailyClicksJob::class, 1);
    }
}
