<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\LinkStatus;
use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Models\DailyStat;
use App\Models\TrackingLink;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TrackingLinkListScopeTest extends TestCase
{
    use RefreshDatabase;

    public function test_leader_list_includes_descendants_only(): void
    {
        $leader = User::factory()->create([
            'role' => UserRole::Leader,
            'status' => UserStatus::Active,
            'path' => 'leader',
        ]);

        $child = User::factory()->create([
            'role' => UserRole::Partner,
            'status' => UserStatus::Active,
            'parent_id' => $leader->id,
            'path' => 'leader/' . $leader->id,
        ]);

        $outsider = User::factory()->create([
            'role' => UserRole::Partner,
            'status' => UserStatus::Active,
        ]);

        $leaderLink = TrackingLink::create([
            'user_id' => $leader->id,
            'short_code' => 'lead001a',
            'destination_url' => 'https://example.com/leader',
            'status' => LinkStatus::Active,
        ]);

        $childLink = TrackingLink::create([
            'user_id' => $child->id,
            'short_code' => 'child001',
            'destination_url' => 'https://example.com/child',
            'status' => LinkStatus::Active,
        ]);

        $outsiderLink = TrackingLink::create([
            'user_id' => $outsider->id,
            'short_code' => 'other001',
            'destination_url' => 'https://example.com/outsider',
            'status' => LinkStatus::Active,
        ]);

        DailyStat::create([
            'date' => now()->toDateString(),
            'platform' => 'shopee',
            'user_id' => $child->id,
            'campaign_id' => null,
            'tracking_link_id' => $childLink->id,
            'clicks' => 120,
            'orders' => 12,
            'approved' => 10,
            'commission' => 250000,
        ]);

        $response = $this->actingAs($leader)->getJson('/api/links');

        $response->assertStatus(200);

        $ids = collect($response->json('data.data'))->pluck('id')->all();
        $this->assertContains($leaderLink->id, $ids);
        $this->assertContains($childLink->id, $ids);
        $this->assertNotContains($outsiderLink->id, $ids);

        $childPayload = collect($response->json('data.data'))->firstWhere('id', $childLink->id);
        $this->assertSame(120, $childPayload['metrics_clicks']);
        $this->assertSame(12, $childPayload['metrics_orders']);
        $this->assertSame(250000.0, (float) $childPayload['metrics_commission']);
    }

    public function test_metrics_respect_date_range_filters(): void
    {
        $user = User::factory()->create([
            'role' => UserRole::Partner,
            'status' => UserStatus::Active,
        ]);

        $link = TrackingLink::create([
            'user_id' => $user->id,
            'short_code' => 'daterng1',
            'destination_url' => 'https://example.com/date',
            'status' => LinkStatus::Active,
        ]);

        DailyStat::create([
            'date' => now()->subDays(10)->toDateString(),
            'platform' => 'shopee',
            'user_id' => $user->id,
            'campaign_id' => null,
            'tracking_link_id' => $link->id,
            'clicks' => 99,
            'orders' => 8,
            'approved' => 7,
            'commission' => 100000,
        ]);

        DailyStat::create([
            'date' => now()->toDateString(),
            'platform' => 'shopee',
            'user_id' => $user->id,
            'campaign_id' => null,
            'tracking_link_id' => $link->id,
            'clicks' => 10,
            'orders' => 1,
            'approved' => 1,
            'commission' => 20000,
        ]);

        $response = $this->actingAs($user)->getJson('/api/links?date_from=' . now()->subDays(1)->toDateString() . '&date_to=' . now()->toDateString());

        $response->assertStatus(200);
        $payload = $response->json('data.data.0');

        $this->assertSame(10, $payload['metrics_clicks']);
        $this->assertSame(1, $payload['metrics_orders']);
        $this->assertSame(20000.0, (float) $payload['metrics_commission']);
    }

    public function test_show_returns_uniform_404_for_missing_and_out_of_scope_ids(): void
    {
        $user = User::factory()->create([
            'role' => UserRole::Partner,
            'status' => UserStatus::Active,
        ]);

        $other = User::factory()->create([
            'role' => UserRole::Partner,
            'status' => UserStatus::Active,
        ]);

        $otherLink = TrackingLink::create([
            'user_id' => $other->id,
            'short_code' => 'hideo001',
            'destination_url' => 'https://example.com/other',
            'status' => LinkStatus::Active,
        ]);

        $outOfScope = $this->actingAs($user)->getJson('/api/links/' . $otherLink->id);
        $missing = $this->actingAs($user)->getJson('/api/links/999999');

        $outOfScope->assertStatus(404)->assertJsonPath('message', 'Not found.');
        $missing->assertStatus(404)->assertJsonPath('message', 'Not found.');
    }
}
