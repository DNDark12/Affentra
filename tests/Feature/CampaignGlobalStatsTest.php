<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\CampaignStatus;
use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Models\Campaign;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CampaignGlobalStatsTest extends TestCase
{
    use RefreshDatabase;

    public function test_campaign_summary_uses_full_scope_not_paginated_subset(): void
    {
        $owner = User::factory()->create([
            'role' => UserRole::Owner,
            'status' => UserStatus::Active,
        ]);

        for ($i = 1; $i <= 20; $i++) {
            Campaign::query()->create([
                'user_id' => $owner->id,
                'name' => "Campaign {$i}",
                'status' => $i <= 12 ? CampaignStatus::Active : CampaignStatus::Paused,
                'impressions' => 100 * $i,
                'clicks' => 10 * $i,
            ]);
        }

        $response = $this->actingAs($owner)->get('/campaigns?page=2');
        $response->assertStatus(200);

        $page = $response->original->getData()['page'];
        $props = $page['props'];

        $this->assertCount(5, $props['campaigns']['data']);
        $this->assertSame(20, $props['summary']['total_campaigns']);
        $this->assertSame(12, $props['summary']['active_campaigns']);
        $this->assertSame(21000, $props['summary']['total_impressions']);
        $this->assertSame(2100, $props['summary']['total_clicks']);
    }
}

