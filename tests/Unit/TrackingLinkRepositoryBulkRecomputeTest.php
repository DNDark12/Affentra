<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Contracts\Repositories\TrackingLinkRepositoryInterface;
use App\Models\Click;
use App\Models\Order;
use App\Models\TrackingLink;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TrackingLinkRepositoryBulkRecomputeTest extends TestCase
{
    use RefreshDatabase;

    public function test_bulk_recompute_updates_links_with_and_without_clicks(): void
    {
        $owner = User::factory()->create(['role' => 'owner']);

        $linkWithClicks = TrackingLink::query()->create([
            'user_id' => $owner->id,
            'campaign_id' => null,
            'short_code' => 'bulkcnt01',
            'destination_url' => 'https://example.com/1',
            'platform' => 'shopee',
            'status' => 'active',
            'clicks_count' => 99,
        ]);

        $linkWithoutClicks = TrackingLink::query()->create([
            'user_id' => $owner->id,
            'campaign_id' => null,
            'short_code' => 'bulkcnt02',
            'destination_url' => 'https://example.com/2',
            'platform' => 'shopee',
            'status' => 'active',
            'clicks_count' => 77,
        ]);

        Click::query()->insert([
            [
                'tracking_link_id' => $linkWithClicks->id,
                'sub_id' => 'sub-a',
                'referer_domain' => 'shopee_sync',
                'created_at' => now(),
            ],
            [
                'tracking_link_id' => $linkWithClicks->id,
                'sub_id' => 'sub-a',
                'referer_domain' => 'shopee_sync',
                'created_at' => now(),
            ],
            [
                'tracking_link_id' => $linkWithClicks->id,
                'sub_id' => 'sub-a',
                'referer_domain' => 'shopee_sync',
                'created_at' => now(),
            ],
        ]);

        $repository = app(TrackingLinkRepositoryInterface::class);
        $affected = $repository->recomputeClicksCountBulk([$linkWithClicks->id, $linkWithoutClicks->id]);

        $this->assertGreaterThanOrEqual(1, $affected);

        $linkWithClicks->refresh();
        $linkWithoutClicks->refresh();

        $this->assertSame(3, (int) $linkWithClicks->clicks_count);
        $this->assertSame(0, (int) $linkWithoutClicks->clicks_count);
    }

    public function test_bulk_recompute_updates_links_with_and_without_orders(): void
    {
        $owner = User::factory()->create(['role' => 'owner']);

        $linkWithOrders = TrackingLink::query()->create([
            'user_id' => $owner->id,
            'campaign_id' => null,
            'short_code' => 'bulkord01',
            'destination_url' => 'https://example.com/order-1',
            'platform' => 'shopee',
            'status' => 'active',
            'orders_count' => 99,
        ]);

        $linkWithoutOrders = TrackingLink::query()->create([
            'user_id' => $owner->id,
            'campaign_id' => null,
            'short_code' => 'bulkord02',
            'destination_url' => 'https://example.com/order-2',
            'platform' => 'shopee',
            'status' => 'active',
            'orders_count' => 77,
        ]);

        Order::query()->insert([
            [
                'user_id' => $owner->id,
                'campaign_id' => null,
                'tracking_link_id' => $linkWithOrders->id,
                'connection_id' => null,
                'platform' => 'shopee',
                'order_code' => 'ORD-BULK-1',
                'sub_id' => null,
                'status' => 'approved',
                'payout_status' => 'unpaid',
                'order_amount' => 100000,
                'commission' => 1000,
                'ordered_at' => now()->subDay(),
                'approved_at' => now()->subDay(),
                'source' => 'api',
                'source_updated_at' => now(),
                'synced_at' => now(),
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'user_id' => $owner->id,
                'campaign_id' => null,
                'tracking_link_id' => $linkWithOrders->id,
                'connection_id' => null,
                'platform' => 'shopee',
                'order_code' => 'ORD-BULK-2',
                'sub_id' => null,
                'status' => 'approved',
                'payout_status' => 'unpaid',
                'order_amount' => 200000,
                'commission' => 2000,
                'ordered_at' => now()->subDay(),
                'approved_at' => now()->subDay(),
                'source' => 'api',
                'source_updated_at' => now(),
                'synced_at' => now(),
                'created_at' => now(),
                'updated_at' => now(),
            ],
        ]);

        $repository = app(TrackingLinkRepositoryInterface::class);
        $affected = $repository->recomputeOrdersCountBulk([$linkWithOrders->id, $linkWithoutOrders->id]);

        $this->assertGreaterThanOrEqual(1, $affected);

        $linkWithOrders->refresh();
        $linkWithoutOrders->refresh();

        $this->assertSame(2, (int) $linkWithOrders->orders_count);
        $this->assertSame(0, (int) $linkWithoutOrders->orders_count);
    }

    public function test_attribution_gap_summary_returns_unattributed_click_and_order_reasons(): void
    {
        $owner = User::factory()->create(['role' => 'owner']);
        $other = User::factory()->create(['role' => 'owner']);
        $placeholderLink = TrackingLink::query()->create([
            'user_id' => $owner->id,
            'campaign_id' => null,
            'short_code' => 'attrsum01',
            'destination_url' => 'https://example.com/attr-sum',
            'platform' => 'shopee',
            'status' => 'active',
        ]);
        $placeholderLinkOther = TrackingLink::query()->create([
            'user_id' => $other->id,
            'campaign_id' => null,
            'short_code' => 'attrsum02',
            'destination_url' => 'https://example.com/attr-sum-2',
            'platform' => 'shopee',
            'status' => 'active',
        ]);

        Click::query()->insert([
            [
                'tracking_link_id' => $placeholderLink->id,
                'connection_id' => null,
                'sub_id' => null,
                'owner_id' => $owner->id,
                'leader_id' => null,
                'partner_user_id' => null,
                'attribution_status' => 'unattributed',
                'source_meta' => json_encode(['attribution_source' => 'none']),
                'created_at' => now(),
            ],
            [
                'tracking_link_id' => $placeholderLink->id,
                'connection_id' => null,
                'sub_id' => null,
                'owner_id' => $owner->id,
                'leader_id' => null,
                'partner_user_id' => null,
                'attribution_status' => 'unattributed',
                'source_meta' => json_encode(['attribution_source' => 'none']),
                'created_at' => now(),
            ],
            [
                'tracking_link_id' => $placeholderLink->id,
                'connection_id' => null,
                'sub_id' => null,
                'owner_id' => $owner->id,
                'leader_id' => null,
                'partner_user_id' => null,
                'attribution_status' => 'unattributed',
                'source_meta' => json_encode(['attribution_source' => 'missing_sub_id']),
                'created_at' => now(),
            ],
            // Out-of-scope row (different owner).
            [
                'tracking_link_id' => $placeholderLinkOther->id,
                'connection_id' => null,
                'sub_id' => null,
                'owner_id' => $other->id,
                'leader_id' => null,
                'partner_user_id' => null,
                'attribution_status' => 'unattributed',
                'source_meta' => json_encode(['attribution_source' => 'none']),
                'created_at' => now(),
            ],
        ]);

        Order::query()->insert([
            [
                'user_id' => $owner->id,
                'campaign_id' => null,
                'tracking_link_id' => null,
                'connection_id' => null,
                'platform' => 'shopee',
                'order_code' => 'ORD-ATTR-1',
                'sub_id' => null,
                'status' => 'approved',
                'payout_status' => 'unpaid',
                'order_amount' => 100000,
                'commission' => 1000,
                'ordered_at' => now()->subHour(),
                'approved_at' => now()->subHour(),
                'source' => 'api',
                'source_updated_at' => now(),
                'synced_at' => now(),
                'source_meta' => json_encode(['attribution_source' => 'unmatched_product']),
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'user_id' => $owner->id,
                'campaign_id' => null,
                'tracking_link_id' => null,
                'connection_id' => null,
                'platform' => 'shopee',
                'order_code' => 'ORD-ATTR-2',
                'sub_id' => null,
                'status' => 'approved',
                'payout_status' => 'unpaid',
                'order_amount' => 120000,
                'commission' => 1100,
                'ordered_at' => now()->subHour(),
                'approved_at' => now()->subHour(),
                'source' => 'api',
                'source_updated_at' => now(),
                'synced_at' => now(),
                'source_meta' => json_encode(['attribution_source' => 'direct']),
                'created_at' => now(),
                'updated_at' => now(),
            ],
            // Out-of-scope row (different owner).
            [
                'user_id' => $other->id,
                'campaign_id' => null,
                'tracking_link_id' => null,
                'connection_id' => null,
                'platform' => 'shopee',
                'order_code' => 'ORD-ATTR-OTHER',
                'sub_id' => null,
                'status' => 'approved',
                'payout_status' => 'unpaid',
                'order_amount' => 99000,
                'commission' => 900,
                'ordered_at' => now()->subHour(),
                'approved_at' => now()->subHour(),
                'source' => 'api',
                'source_updated_at' => now(),
                'synced_at' => now(),
                'source_meta' => json_encode(['attribution_source' => 'direct']),
                'created_at' => now(),
                'updated_at' => now(),
            ],
        ]);

        $repository = app(TrackingLinkRepositoryInterface::class);
        $summary = $repository->attributionGapSummaryForScope([$owner->id], [
            'date_from' => now()->subDay()->toDateString(),
            'date_to' => now()->toDateString(),
        ]);

        $this->assertSame(3, $summary['unattributed_clicks']);
        $this->assertSame(2, $summary['unattributed_orders']);
        $this->assertSame('none', $summary['unattributed_click_reasons'][0]['reason']);
        $this->assertSame(2, $summary['unattributed_click_reasons'][0]['count']);

        $orderReasonMap = collect($summary['unattributed_order_reasons'])
            ->mapWithKeys(static fn (array $row): array => [(string) $row['reason'] => (int) $row['count']])
            ->all();
        $this->assertSame(1, $orderReasonMap['unmatched_product'] ?? 0);
        $this->assertSame(1, $orderReasonMap['direct'] ?? 0);
    }
}
