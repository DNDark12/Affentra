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

class CampaignScopeTest extends TestCase
{
    use RefreshDatabase;

    /**
     * CTV should only see their own campaigns in the index.
     */
    public function test_ctv_only_sees_own_campaigns(): void
    {
        $ctvA = User::factory()->create(['role' => UserRole::CTV, 'status' => UserStatus::Active]);
        $ctvB = User::factory()->create(['role' => UserRole::CTV, 'status' => UserStatus::Active]);

        Campaign::create(['user_id' => $ctvA->id, 'name' => 'Campaign A', 'status' => CampaignStatus::Active]);
        Campaign::create(['user_id' => $ctvB->id, 'name' => 'Campaign B', 'status' => CampaignStatus::Active]);

        $response = $this->actingAs($ctvA)->get('/campaigns');
        $response->assertStatus(200);

        $page = $response->original->getData()['page'];
        $campaigns = $page['props']['campaigns']['data'];

        $this->assertCount(1, $campaigns);
        $this->assertEquals('Campaign A', $campaigns[0]['name']);
    }

    /**
     * CTV cannot update a campaign they don't own.
     */
    public function test_ctv_cannot_update_other_users_campaign(): void
    {
        $ctvA = User::factory()->create(['role' => UserRole::CTV, 'status' => UserStatus::Active]);
        $ctvB = User::factory()->create(['role' => UserRole::CTV, 'status' => UserStatus::Active]);

        $campaign = Campaign::create(['user_id' => $ctvB->id, 'name' => 'Secret Campaign', 'status' => CampaignStatus::Active]);

        $response = $this->actingAs($ctvA)
            ->withSession(['_token' => 'test-token'])
            ->patchJson("/api/campaigns/{$campaign->id}", [
                '_token' => 'test-token',
                'name'   => 'Hijacked',
            ]);

        $response->assertStatus(403);

        $campaign->refresh();
        $this->assertEquals('Secret Campaign', $campaign->name);
    }

    /**
     * Owner can see all campaigns.
     */
    public function test_owner_sees_all_campaigns(): void
    {
        $owner = User::factory()->create(['role' => UserRole::Owner, 'status' => UserStatus::Active]);
        $ctv   = User::factory()->create(['role' => UserRole::CTV, 'status' => UserStatus::Active]);

        Campaign::create(['user_id' => $owner->id, 'name' => 'Owner Campaign', 'status' => CampaignStatus::Active]);
        Campaign::create(['user_id' => $ctv->id, 'name' => 'CTV Campaign', 'status' => CampaignStatus::Active]);

        $response = $this->actingAs($owner)->get('/campaigns');
        $response->assertStatus(200);

        $page = $response->original->getData()['page'];
        $campaigns = $page['props']['campaigns']['data'];

        $this->assertCount(2, $campaigns);
    }
}
