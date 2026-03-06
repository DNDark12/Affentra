<?php

namespace Tests\Feature\Analytics;

use App\Enums\UserRole;
use App\Models\DailyStat;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ClickAnalyticsTest extends TestCase
{
    use RefreshDatabase;

    public function test_leader_can_only_see_their_own_and_descendants_stats()
    {
        $leader = User::factory()->create(['role' => UserRole::Leader]);
        $partnerUnderLeader = User::factory()->create([
            'role' => UserRole::Partner,
            'parent_id' => $leader->id,
            'path' => (string) $leader->id
        ]);
        $otherUser = User::factory()->create(['role' => UserRole::Partner]);

        DailyStat::factory()->create([
            'user_id' => $leader->id,
            'leader_id' => $leader->id,
            'owner_id' => $leader->parent_id ?? $leader->id,
            'clicks' => 10,
            'date' => now()->toDateString()
        ]);
        DailyStat::factory()->create([
            'user_id' => $partnerUnderLeader->id,
            'leader_id' => $leader->id,
            'owner_id' => $leader->parent_id ?? $leader->id,
            'clicks' => 5,
            'date' => now()->toDateString()
        ]);
        DailyStat::factory()->create([
            'user_id' => $otherUser->id,
            'leader_id' => $otherUser->parent_id, // null
            'clicks' => 100,
            'date' => now()->toDateString()
        ]);

        $response = $this->actingAs($leader)->get(route('clicks.index'));

        $response->assertStatus(200);
        // Summary should be 10 + 5 = 15 clicks, not 115
        $response->assertInertia(fn ($page) => $page
            ->where('summary.totals.clicks', 15)
        );
    }

    public function test_owner_can_see_all_stats()
    {
        $owner = User::factory()->create(['role' => UserRole::Owner]);
        $user1 = User::factory()->create();
        $user2 = User::factory()->create();

        DailyStat::factory()->create(['user_id' => $user1->id, 'clicks' => 10, 'date' => now()->toDateString()]);
        DailyStat::factory()->create(['user_id' => $user2->id, 'clicks' => 20, 'date' => now()->toDateString()]);

        $response = $this->actingAs($owner)->get(route('clicks.index'));

        $response->assertInertia(fn ($page) => $page
            ->where('summary.totals.clicks', 30)
        );
    }

    public function test_click_report_filtering_by_date()
    {
        $user = User::factory()->create(['role' => UserRole::Owner]);
        
        DailyStat::factory()->create([
            'user_id' => $user->id,
            'date' => '2025-01-01',
            'clicks' => 50
        ]);
        DailyStat::factory()->create([
            'user_id' => $user->id,
            'date' => '2025-02-01',
            'clicks' => 100
        ]);

        $response = $this->actingAs($user)->get(route('clicks.index', [
            'date_from' => '2025-01-01',
            'date_to' => '2025-01-31'
        ]));

        $response->assertInertia(fn ($page) => $page
            ->where('summary.totals.clicks', 50)
        );
    }
}
