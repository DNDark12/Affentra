<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\AlertIncident;
use App\Models\AlertRule;
use App\Models\Campaign;
use App\Models\Click;
use App\Models\Order;
use App\Models\TrackingLink;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

class DashboardControllerTest extends TestCase
{
    use RefreshDatabase;

    public function test_dashboard_uses_requested_period_and_returns_real_aggregates(): void
    {
        $owner = User::factory()->create([
            'role' => UserRole::Owner->value,
            'path' => '1',
        ]);

        $campaign = Campaign::query()->create([
            'user_id' => $owner->id,
            'name' => 'Campaign A',
            'platform' => 'shopee',
            'status' => 'active',
        ]);

        $link = TrackingLink::query()->create([
            'user_id' => $owner->id,
            'campaign_id' => $campaign->id,
            'short_code' => 'db' . $owner->id,
            'destination_url' => 'https://shopee.vn/product/1/1',
            'platform' => 'shopee',
            'status' => 'active',
        ]);

        Click::query()->create([
            'tracking_link_id' => $link->id,
            'ctv_user_id' => $owner->id,
            'created_at' => now()->subDay(),
        ]);

        Order::query()->create([
            'user_id' => $owner->id,
            'campaign_id' => $campaign->id,
            'tracking_link_id' => null,
            'platform' => 'shopee',
            'order_code' => 'ORD-DB-001',
            'status' => 'approved',
            'payout_status' => 'unpaid',
            'order_amount' => 120000,
            'commission' => 15000,
            'ordered_at' => now()->subDay(),
        ]);

        $rule = AlertRule::query()->create([
            'user_id' => $owner->id,
            'name' => 'Rule A',
            'metric' => 'sync_failed_24h',
            'operator' => '>=',
            'threshold' => 1,
            'channel' => 'in_app',
            'is_active' => true,
        ]);

        AlertIncident::query()->create([
            'alert_rule_id' => $rule->id,
            'user_id' => $owner->id,
            'triggered_value' => 2,
            'message' => 'Sync failed 2 lần',
            'context' => ['metric' => 'sync_failed_24h'],
        ]);

        $response = $this->actingAs($owner)->get('/dashboard?period=7days');

        $response->assertStatus(200);
        $response->assertInertia(fn (AssertableInertia $page) => $page
            ->component('Dashboard/Index')
            ->where('summary.period', '7days')
            ->where('summary.clicks', 1)
            ->where('summary.orders', 1)
            ->where('summary.approved', 1)
            ->where('summary.active_links', 1)
            ->where('summary.active_campaigns', 1)
            ->where('summary.unattributed_orders', 1)
            ->where('summary.pending_commission', 15000)
            ->where('summary.open_alerts_count', 1)
            ->where('summary.unseen_alerts_count', 1)
            ->has('summary.daily', 7)
            ->has('summary.alerts', 1)
        );
    }
}
