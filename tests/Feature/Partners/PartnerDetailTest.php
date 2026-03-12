<?php

declare(strict_types=1);

namespace Tests\Feature\Partners;

use App\Enums\UserRole;
use App\Enums\PlatformConnectionStatus;
use App\Models\User;
use App\Models\UserProfile;
use App\Models\PlatformConnection;
use App\Models\Order;
use App\Models\TrackingLink;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;
use Carbon\Carbon;

class PartnerDetailTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;
    private User $partner;

    protected function setUp(): void
    {
        parent::setUp();
        
        $this->owner = User::factory()->create(['role' => UserRole::Owner]);
        $this->partner = User::factory()->create(['role' => UserRole::Partner]);
        UserProfile::factory()->create([
            'user_id' => $this->partner->id,
            'bank_account_number' => '123456789',
            'bank_name' => 'Vietcombank',
        ]);
        
        // Ensure default tracking links exist
        TrackingLink::create([
            'id' => 1,
            'user_id' => $this->partner->id,
            'sub_id' => 'link1',
            'short_code' => 'link1_code',
            'destination_url' => 'https://shopee.vn',
            'platform' => 'shopee',
            'status' => 'active',
        ]);
    }

    public function test_owner_can_view_partner_detail(): void
    {
        $response = $this->actingAs($this->owner)->get("/partners/{$this->partner->id}");

        $response->assertStatus(200)
            ->assertInertia(fn (Assert $page) => $page
                ->component('Partners/Show')
                ->has('partner')
                ->has('connections_meta')
            );
    }

    public function test_summary_returns_platform_breakdown(): void
    {
        PlatformConnection::create([
            'user_id' => $this->partner->id,
            'platform' => 'shopee',
            'label' => 'Shopee',
            'method' => 'cookie',
            'status' => 'active',
        ]);
        
        DB::table('daily_stats')->insert([
            'user_id' => $this->partner->id,
            'platform' => 'shopee',
            'date' => Carbon::today()->subDays(2)->toDateString(),
            'clicks' => 10,
            'orders' => 2,
            'approved' => 1,
            'commission' => 15000,
        ]);
        
        DB::table('daily_stats')->insert([
            'user_id' => $this->partner->id,
            'platform' => 'lazada',
            'date' => Carbon::today()->subDays(1)->toDateString(),
            'clicks' => 5,
            'orders' => 1,
            'approved' => 0,
            'commission' => 0,
        ]);

        $response = $this->actingAs($this->owner)->getJson("/api/partners/{$this->partner->id}/summary?days=7");

        $response->assertStatus(200)
            ->assertJsonPath('data.totals.clicks', 15)
            ->assertJsonPath('data.totals.orders', 3)
            ->assertJsonPath('data.totals.approved_orders', 1)
            ->assertJsonPath('data.totals.commission', 15000)
            ->assertJsonPath('data.platforms.0.platform', 'shopee')
            ->assertJsonPath('data.platforms.0.clicks', 10)
            ->assertJsonPath('data.platforms.0.connections_count', 1)
            ->assertJsonPath('data.platforms.1.platform', 'lazada');
    }

    public function test_summary_respects_days_filter(): void
    {
        DB::table('daily_stats')->insert([
            'user_id' => $this->partner->id,
            'platform' => 'shopee',
            'date' => Carbon::today()->subDays(10)->toDateString(), // Outside 7d window
            'clicks' => 100,
            'orders' => 5,
            'approved' => 5,
            'commission' => 100000,
        ]);
        
        DB::table('daily_stats')->insert([
            'user_id' => $this->partner->id,
            'platform' => 'shopee',
            'date' => Carbon::today()->subDays(2)->toDateString(), // Inside 7d window
            'clicks' => 10,
            'orders' => 1,
            'approved' => 1,
            'commission' => 10000,
        ]);

        $response7d = $this->actingAs($this->owner)->getJson("/api/partners/{$this->partner->id}/summary?days=7");
        $response7d->assertStatus(200)->assertJsonPath('data.totals.clicks', 10);

        $response30d = $this->actingAs($this->owner)->getJson("/api/partners/{$this->partner->id}/summary?days=30");
        $response30d->assertStatus(200)->assertJsonPath('data.totals.clicks', 110);
    }

    public function test_trend_returns_daily_data_grouped_by_platform(): void
    {
        DB::table('daily_stats')->insert([
            'user_id' => $this->partner->id,
            'platform' => 'shopee',
            'date' => Carbon::today()->subDays(1)->toDateString(),
            'clicks' => 10,
            'orders' => 2,
            'approved' => 1,
            'commission' => 15000,
        ]);

        $response = $this->actingAs($this->owner)->getJson("/api/partners/{$this->partner->id}/trend?days=2");

        $response->assertStatus(200);
        $data = $response->json('data');
        
        $yesterdayData = collect($data)->where('date', Carbon::today()->subDays(1)->toDateString())->first();
        $this->assertNotNull($yesterdayData);
        $this->assertEquals('shopee', $yesterdayData['platform']);
        $this->assertEquals(10, $yesterdayData['clicks']);
    }

    public function test_trend_fills_empty_dates_in_range(): void
    {
        DB::table('daily_stats')->insert([
            'user_id' => $this->partner->id,
            'platform' => 'shopee',
            'date' => Carbon::today()->subDays(5)->toDateString(),
            'clicks' => 10,
            'orders' => 2,
            'approved' => 1,
            'commission' => 15000,
        ]);

        // Request 7 days trend. Dates 1,2,3,4,6,7 ago should be filled with 0.
        $response = $this->actingAs($this->owner)->getJson("/api/partners/{$this->partner->id}/trend?days=7");
        
        $data = $response->json('data');
        
        // 7 days inclusive + today = 8 days length minimum if there's 1 platform
        $this->assertGreaterThanOrEqual(8, count($data));
        
        $todayData = collect($data)->where('date', Carbon::today()->toDateString())->first();
        $this->assertNotNull($todayData);
        $this->assertEquals(0, $todayData['clicks']);
    }

    public function test_trend_can_filter_by_platform(): void
    {
        DB::table('daily_stats')->insert([
            ['user_id' => $this->partner->id, 'platform' => 'shopee', 'date' => Carbon::today()->toDateString(), 'clicks' => 10, 'orders' => 0, 'approved' => 0, 'commission' => 0],
            ['user_id' => $this->partner->id, 'platform' => 'lazada', 'date' => Carbon::today()->toDateString(), 'clicks' => 20, 'orders' => 0, 'approved' => 0, 'commission' => 0]
        ]);

        $response = $this->actingAs($this->owner)->getJson("/api/partners/{$this->partner->id}/trend?days=1&platform=lazada");
        
        $data = $response->json('data');
        $this->assertEquals(2, count($data)); // Today and Yesterday (days=1 means start yesterday to today)
        $this->assertEquals('lazada', $data[1]['platform']);
        $this->assertEquals(20, $data[1]['clicks']);
    }

    public function test_connections_returns_sync_health(): void
    {
        PlatformConnection::forceCreate([
            'user_id' => $this->partner->id,
            'platform' => 'shopee',
            'status' => 'active',
            'last_sync_status' => 'failed',
        ]);

        $response = $this->actingAs($this->owner)->getJson("/api/partners/{$this->partner->id}/connections");
        $response->assertStatus(200)
            ->assertJsonPath('data.0.sync_health', 'error');
    }

    public function test_tracking_links_uses_approved_commission_sort(): void
    {
        $link2 = TrackingLink::create(['user_id' => $this->partner->id, 'sub_id' => 'link2', 'short_code' => 'link2_code', 'destination_url' => 'https://shopee.vn', 'platform' => 'shopee', 'status' => 'active']);

        // Link 1: 100 clicks, 0 commission
        DB::table('daily_stats')->insert([
            'user_id' => $this->partner->id, 'platform' => 'shopee', 'date' => Carbon::today()->toDateString(), 'tracking_link_id' => 1,
            'clicks' => 100, 'orders' => 0, 'approved' => 0, 'commission' => 0
        ]);

        // Link 2: 5 clicks, 1 commission
        DB::table('daily_stats')->insert([
            'user_id' => $this->partner->id, 'platform' => 'shopee', 'date' => Carbon::today()->toDateString(), 'tracking_link_id' => $link2->id,
            'clicks' => 5, 'orders' => 1, 'approved' => 1, 'commission' => 50000
        ]);

        $response = $this->actingAs($this->owner)->getJson("/api/partners/{$this->partner->id}/tracking-links");
        $response->assertStatus(200);
        $data = $response->json('data');
        $this->assertEquals('link2', $data[0]['sub_id']); // Highest commission first despite fewer clicks
        $this->assertEquals('link1', $data[1]['sub_id']);
    }

    public function test_leader_cannot_view_partner_outside_scope(): void
    {
        /** @var User $leader1 */
        $leader1 = User::factory()->create(['role' => UserRole::Leader]);
        /** @var User $leader2 */
        $leader2 = User::factory()->create(['role' => UserRole::Leader]);
        
        // Partner belongs to Leader2
        $this->partner->update(['parent_id' => $leader2->id]);

        // Leader1 tries to view Leader2's partner
        $response = $this->actingAs($leader1)->getJson("/api/partners/{$this->partner->id}/summary");
        $response->assertStatus(404);
    }

    public function test_partner_cannot_view_own_detail_page(): void
    {
        $response = $this->actingAs($this->partner)->getJson("/api/partners/{$this->partner->id}/summary");
        $response->assertStatus(403); // Or 404 depending on scope resolver/policy. Let's see... the controller throws 404 if not in scope or if user is partner. Wait, is a partner in their own scope? Usually partners cannot manage users.
        // The api routes are wrapped in `can:manage,App\Models\User` middleware!
        // So hitting the API as partner will yield 403 Forbidden.
    }

    public function test_masked_banking_info_not_fully_exposed(): void
    {
        $response = $this->actingAs($this->owner)->get("/partners/{$this->partner->id}");

        $response->assertInertia(fn (Assert $page) => $page
            ->where('partner.masked_bank_name', 'Vietcombank')
            ->where('partner.masked_bank_account', '*****6789')
        );
    }

    public function test_paginated_orders_returns_correct_page(): void
    {
        // Add 15 orders
        for ($i = 1; $i <= 15; $i++) {
            Order::create([
                'user_id' => $this->partner->id,
                'platform' => 'shopee',
                'order_code' => "ORD-{$i}",
                'status' => 'pending',
                'order_amount' => 100000,
                'commission' => 10000,
                'ordered_at' => now()->subMinutes($i)
            ]);
        }

        $response1 = $this->actingAs($this->owner)->getJson("/api/partners/{$this->partner->id}/orders?per_page=10&page=1");
        $response1->assertJsonCount(10, 'data');
        
        $response2 = $this->actingAs($this->owner)->getJson("/api/partners/{$this->partner->id}/orders?per_page=10&page=2");
        $response2->assertJsonCount(5, 'data');
    }
}
