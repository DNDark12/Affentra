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
     * Partner should only see their own campaigns in the index.
     */
    public function test_partner_only_sees_own_campaigns(): void
    {
        $partnerA = User::factory()->create(['role' => UserRole::Partner, 'status' => UserStatus::Active]);
        $partnerB = User::factory()->create(['role' => UserRole::Partner, 'status' => UserStatus::Active]);

        Campaign::create(['user_id' => $partnerA->id, 'name' => 'Campaign A', 'status' => CampaignStatus::Active]);
        Campaign::create(['user_id' => $partnerB->id, 'name' => 'Campaign B', 'status' => CampaignStatus::Active]);

        $response = $this->actingAs($partnerA)->get('/campaigns');
        $response->assertStatus(200);

        $page = $response->original->getData()['page'];
        $campaigns = $page['props']['campaigns']['data'];

        $this->assertCount(1, $campaigns);
        $this->assertEquals('Campaign A', $campaigns[0]['name']);
    }

    /**
     * Partner cannot update a campaign they don't own.
     */
    public function test_partner_cannot_update_other_users_campaign(): void
    {
        $partnerA = User::factory()->create(['role' => UserRole::Partner, 'status' => UserStatus::Active]);
        $partnerB = User::factory()->create(['role' => UserRole::Partner, 'status' => UserStatus::Active]);

        $campaign = Campaign::create(['user_id' => $partnerB->id, 'name' => 'Secret Campaign', 'status' => CampaignStatus::Active]);

        $response = $this->actingAs($partnerA)
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
        $partner   = User::factory()->create(['role' => UserRole::Partner, 'status' => UserStatus::Active]);

        Campaign::create(['user_id' => $owner->id, 'name' => 'Owner Campaign', 'status' => CampaignStatus::Active]);
        Campaign::create(['user_id' => $partner->id, 'name' => 'Partner Campaign', 'status' => CampaignStatus::Active]);

        $response = $this->actingAs($owner)->get('/campaigns');
        $response->assertStatus(200);

        $page = $response->original->getData()['page'];
        $campaigns = $page['props']['campaigns']['data'];

        $this->assertCount(2, $campaigns);
    }
}
