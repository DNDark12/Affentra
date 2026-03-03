<?php

declare(strict_types=1);

namespace Tests\Feature\Partner;

use App\Enums\UserRole;
use App\Models\AffiliateBilling;
use App\Models\AffiliatePayout;
use App\Models\AuditLog;
use App\Models\DailyStat;
use App\Models\Order;
use App\Models\PlatformConnection;
use App\Models\TrackingLink;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

class PartnerDetailTest extends TestCase
{
    use RefreshDatabase;

    public function test_owner_can_view_partner_detail_with_aggregated_data(): void
    {
        $owner = User::factory()->create([
            'role' => UserRole::Owner->value,
            'path' => '1',
        ]);

        $leader = User::factory()->create([
            'role' => UserRole::Leader->value,
            'parent_id' => $owner->id,
            'path' => $owner->id . '/2',
        ]);

        $partner = User::factory()->create([
            'role' => UserRole::CTV->value,
            'parent_id' => $leader->id,
            'path' => $owner->id . '/2/3',
        ]);

        DailyStat::factory()->create([
            'user_id' => $partner->id,
            'clicks' => 12,
            'orders' => 2,
            'commission' => 50000,
            'date' => now()->toDateString(),
        ]);

        TrackingLink::query()->create([
            'user_id' => $partner->id,
            'short_code' => 'ptn' . $partner->id,
            'destination_url' => 'https://shopee.vn/product/1/1',
            'platform' => 'shopee',
            'status' => 'active',
        ]);

        $connection = PlatformConnection::factory()->create([
            'user_id' => $partner->id,
            'platform' => 'shopee',
            'method' => 'open_api',
        ]);

        Order::query()->create([
            'user_id' => $partner->id,
            'tracking_link_id' => TrackingLink::query()->where('user_id', $partner->id)->value('id'),
            'connection_id' => $connection->id,
            'platform' => 'shopee',
            'order_code' => 'ORD-001',
            'status' => 'approved',
            'payout_status' => 'unpaid',
            'order_amount' => 300000,
            'listed_amount' => 350000,
            'commission' => 50000,
            'ordered_at' => now()->subDay(),
            'approved_at' => now()->subHours(12),
            'source' => 'api',
        ]);

        AffiliateBilling::query()->create([
            'platform_connection_id' => $connection->id,
            'user_id' => $partner->id,
            'platform' => 'shopee',
            'billing_id' => 'BILL-001',
            'period_start' => now()->subDays(10),
            'period_end' => now()->subDays(2),
            'total_commission' => 50000,
            'service_fee' => 1000,
            'net_amount' => 49000,
            'status' => 'settled',
        ]);

        AffiliatePayout::query()->create([
            'platform_connection_id' => $connection->id,
            'user_id' => $partner->id,
            'platform' => 'shopee',
            'payout_id' => 'PO-001',
            'payout_at' => now()->subDay(),
            'amount' => 49000,
            'currency' => 'VND',
            'status' => 'paid',
        ]);

        AuditLog::query()->create([
            'actor_id' => $owner->id,
            'target_type' => User::class,
            'target_id' => $partner->id,
            'action' => 'partner.review',
            'reason' => 'Manual QA',
            'created_at' => now()->subMinutes(5),
        ]);

        $response = $this->actingAs($owner)->get(route('partners.show', $partner));

        $response->assertStatus(200);
        $response->assertInertia(fn (AssertableInertia $page) => $page
            ->component('Partners/Show')
            ->where('partner.id', $partner->id)
            ->where('overview.total_clicks', 12)
            ->where('overview.total_orders', 2)
            ->where('overview.active_links_count', 1)
            ->where('financeSummary.total_earned', 49000)
            ->where('financeSummary.total_paid', 49000)
            ->has('recentOrders', 1)
            ->has('recentBillings', 1)
            ->has('recentPayouts', 1)
            ->has('activity', 1)
        );
    }

    public function test_leader_gets_404_for_partner_outside_scope(): void
    {
        $owner = User::factory()->create([
            'role' => UserRole::Owner->value,
            'path' => '1',
        ]);

        $leader = User::factory()->create([
            'role' => UserRole::Leader->value,
            'parent_id' => $owner->id,
            'path' => $owner->id . '/2',
        ]);

        $otherLeader = User::factory()->create([
            'role' => UserRole::Leader->value,
            'parent_id' => $owner->id,
            'path' => $owner->id . '/4',
        ]);

        $outsidePartner = User::factory()->create([
            'role' => UserRole::CTV->value,
            'parent_id' => $otherLeader->id,
            'path' => $owner->id . '/4/5',
        ]);

        $response = $this->actingAs($leader)->get(route('partners.show', $outsidePartner));

        $response->assertStatus(404);
    }

    public function test_ctv_cannot_access_partner_detail(): void
    {
        $ctv = User::factory()->create([
            'role' => UserRole::CTV->value,
        ]);
        $otherPartner = User::factory()->create([
            'role' => UserRole::CTV->value,
        ]);

        $response = $this->actingAs($ctv)->get(route('partners.show', $otherPartner));

        $response->assertStatus(403);
    }
}
