<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\PlatformConnection;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class CampaignSyncTest extends TestCase
{
    use RefreshDatabase;

    public function test_user_can_sync_campaigns_from_shopee_cookie_connection(): void
    {
        $user = User::factory()->create(['role' => 'owner']);
        $connection = PlatformConnection::factory()->create([
            'user_id' => $user->id,
            'platform' => 'shopee',
            'method' => 'cookie',
            'status' => 'active',
            'cookie_header' => json_encode([
                'cookie' => 'SPC_EC=dummy-cookie-value',
                'affiliate_program_type' => '1',
            ], JSON_THROW_ON_ERROR),
        ]);

        Http::fake([
            'https://affiliate.shopee.vn/api/v3/gql*' => Http::response([
                'code' => 0,
                'data' => [
                    'affiliateCampaignsList' => [
                        'affiliateCampaignDetailList' => [
                            [
                                'campaignId' => '123456',
                                'campaignName' => 'Shopee 3.3 Mega Sale',
                                'campaignStartTime' => 1771804800,
                                'campaignEndTime' => 1772495999,
                                'campaignDescription' => 'Hot deals in March',
                                'campaignImpressionNum' => 1024,
                                'campaignClickNum' => 210,
                                'bannerImageId' => 'banner-33',
                                'campaignStatus' => 1,
                                'campaignUrl' => 'https://affiliate.shopee.vn/campaign/123456',
                            ],
                        ],
                    ],
                ],
            ], 200),
        ]);

        $response = $this->actingAs($user)->postJson('/api/campaigns/sync');
        $response
            ->assertStatus(200)
            ->assertJsonPath('ok', true)
            ->assertJsonPath('data.connections', 1)
            ->assertJsonPath('data.upserted', 1);

        $this->assertDatabaseHas('campaigns', [
            'user_id' => $user->id,
            'external_id' => '123456',
            'name' => 'Shopee 3.3 Mega Sale',
            'platform' => 'shopee',
            'status' => 'active',
            'impressions' => 1024,
            'clicks' => 210,
            'source' => 'shopee_sync',
        ]);

        $connection->refresh();
        $this->assertNotNull($connection->last_campaign_sync_at);
    }

    public function test_sync_campaigns_returns_validation_error_without_available_connection(): void
    {
        $user = User::factory()->create(['role' => 'owner']);

        $response = $this->actingAs($user)->postJson('/api/campaigns/sync');

        $response
            ->assertStatus(422)
            ->assertJsonPath('ok', false);
    }

    public function test_ctv_cannot_trigger_campaign_sync(): void
    {
        $ctv = User::factory()->create(['role' => 'ctv']);

        $response = $this->actingAs($ctv)->postJson('/api/campaigns/sync');

        $response->assertStatus(403);
    }
}
