<?php

declare(strict_types=1);

namespace Tests\Feature\AI;

use App\Enums\UserRole;
use App\Models\ContentGeneration;
use App\Models\PlatformConnection;
use App\Models\TrackingLink;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ContentStatisticsTest extends TestCase
{
    use RefreshDatabase;

    public function test_statistics_returns_account_partner_shop_unknown_and_link_segments(): void
    {
        $owner = User::factory()->create([
            'role' => UserRole::Owner->value,
        ]);
        $partner = User::factory()->create([
            'role' => UserRole::Partner->value,
            'parent_id' => $owner->id,
        ]);

        $ownerConnection = PlatformConnection::factory()->create([
            'user_id' => $owner->id,
            'platform' => 'shopee',
            'label' => 'Owner Shop',
            'status' => 'active',
        ]);
        $partnerConnection = PlatformConnection::factory()->create([
            'user_id' => $partner->id,
            'platform' => 'shopee',
            'label' => 'Partner Shop',
            'status' => 'active',
        ]);

        $ownerLink = TrackingLink::create([
            'user_id' => $owner->id,
            'platform_connection_id' => $ownerConnection->id,
            'short_code' => 'owner001',
            'destination_url' => 'https://shopee.vn/product/1/1',
            'platform' => 'shopee',
            'status' => 'active',
        ]);
        $partnerLink = TrackingLink::create([
            'user_id' => $partner->id,
            'platform_connection_id' => $partnerConnection->id,
            'short_code' => 'partner001',
            'destination_url' => 'https://shopee.vn/product/2/2',
            'platform' => 'shopee',
            'status' => 'active',
        ]);

        ContentGeneration::create([
            'tracking_link_id' => $ownerLink->id,
            'user_id' => $owner->id,
            'platform_connection_id' => $ownerConnection->id,
            'type' => 'text',
            'platform' => 'facebook',
            'status' => 'succeeded',
            'tokens_prompt' => 10,
            'tokens_completion' => 5,
            'cost_amount' => 1.2,
            'output_payload' => ['variants' => [['text' => 'ok']]],
        ]);
        ContentGeneration::create([
            'tracking_link_id' => $ownerLink->id,
            'user_id' => $owner->id,
            'platform_connection_id' => $ownerConnection->id,
            'type' => 'image',
            'platform' => 'facebook',
            'status' => 'succeeded',
            'from_cache' => true,
            'tokens_prompt' => 2,
            'tokens_completion' => 3,
            'output_payload' => ['media' => [['url' => 'a'], ['url' => 'b']]],
        ]);
        ContentGeneration::create([
            'tracking_link_id' => $ownerLink->id,
            'user_id' => $owner->id,
            'platform_connection_id' => $ownerConnection->id,
            'type' => 'text',
            'platform' => 'facebook',
            'status' => 'failed',
            'output_payload' => ['variants' => []],
        ]);
        ContentGeneration::create([
            'tracking_link_id' => $ownerLink->id,
            'user_id' => $owner->id,
            'platform_connection_id' => null,
            'type' => 'video',
            'platform' => 'facebook',
            'status' => 'succeeded',
            'tokens_prompt' => 1,
            'tokens_completion' => 1,
            'output_payload' => ['media' => [['url' => 'v1']]],
        ]);
        ContentGeneration::create([
            'tracking_link_id' => $partnerLink->id,
            'user_id' => $partner->id,
            'platform_connection_id' => $partnerConnection->id,
            'type' => 'text',
            'platform' => 'tiktok',
            'status' => 'succeeded',
            'tokens_prompt' => 4,
            'tokens_completion' => 6,
            'output_payload' => ['variants' => [['text' => 'partner']]],
        ]);

        $response = $this->actingAs($owner)
            ->getJson("/api/links/{$ownerLink->id}/content/statistics");

        $response->assertOk()
            ->assertJsonPath('ok', true)
            ->assertJsonPath('data.meta.shop_id', $ownerConnection->id)
            ->assertJsonPath('data.meta.shop_label', 'Owner Shop')
            ->assertJsonPath('data.meta.has_partner', true)
            ->assertJsonPath('data.meta.partner_count', 1)
            ->assertJsonPath('data.meta.total_shop_count', 1)
            ->assertJsonPath('data.account.requests_total', 4)
            ->assertJsonPath('data.account.requests_succeeded', 3)
            ->assertJsonPath('data.account.requests_failed', 1)
            ->assertJsonPath('data.account.tokens', 22)
            ->assertJsonPath('data.total_shop.requests_total', 3)
            ->assertJsonPath('data.shop.requests_total', 3)
            ->assertJsonPath('data.unknown_shop.requests_total', 1)
            ->assertJsonPath('data.partner.requests_total', 1)
            ->assertJsonPath('data.link.requests_total', 4);
    }

    public function test_statistics_returns_null_partner_when_actor_has_no_partner(): void
    {
        $user = User::factory()->create([
            'role' => UserRole::Partner->value,
        ]);
        $connection = PlatformConnection::factory()->create([
            'user_id' => $user->id,
            'platform' => 'shopee',
            'label' => 'Solo Shop',
            'status' => 'active',
        ]);
        $link = TrackingLink::create([
            'user_id' => $user->id,
            'platform_connection_id' => $connection->id,
            'short_code' => 'solo001',
            'destination_url' => 'https://shopee.vn/product/3/3',
            'platform' => 'shopee',
            'status' => 'active',
        ]);

        ContentGeneration::create([
            'tracking_link_id' => $link->id,
            'user_id' => $user->id,
            'platform_connection_id' => $connection->id,
            'type' => 'text',
            'platform' => 'facebook',
            'status' => 'succeeded',
            'tokens_prompt' => 2,
            'tokens_completion' => 3,
            'output_payload' => ['variants' => [['text' => 'solo']]],
        ]);

        $response = $this->actingAs($user)
            ->getJson("/api/links/{$link->id}/content/statistics");

        $response->assertOk()
            ->assertJsonPath('data.meta.has_partner', false)
            ->assertJsonPath('data.meta.partner_count', 0)
            ->assertJsonPath('data.partner', null);
    }

    public function test_account_statistics_endpoint_returns_account_scope_without_link_context(): void
    {
        $user = User::factory()->create([
            'role' => UserRole::Owner->value,
        ]);
        $link = TrackingLink::create([
            'user_id' => $user->id,
            'platform_connection_id' => null,
            'short_code' => 'acc001',
            'destination_url' => 'https://shopee.vn/product/4/4',
            'platform' => 'shopee',
            'status' => 'active',
        ]);

        ContentGeneration::create([
            'tracking_link_id' => $link->id,
            'user_id' => $user->id,
            'platform_connection_id' => null,
            'type' => 'text',
            'platform' => 'facebook',
            'status' => 'succeeded',
            'tokens_prompt' => 3,
            'tokens_completion' => 4,
            'output_payload' => ['variants' => [['text' => 'standalone']]],
        ]);

        $response = $this->actingAs($user)
            ->getJson('/api/content/statistics/account');

        $response->assertOk()
            ->assertJsonPath('ok', true)
            ->assertJsonPath('data.meta.total_shop_count', 0)
            ->assertJsonPath('data.account.requests_total', 1)
            ->assertJsonPath('data.unknown_shop.requests_total', 1)
            ->assertJsonPath('data.total_shop.requests_total', 0)
            ->assertJsonPath('data.meta.has_partner', true)
            ->assertJsonPath('data.partner.requests_total', 0)
            ->assertJsonPath('data.partner.tokens', 0);
    }

    public function test_owner_account_statistics_includes_partner_scope_even_without_parent_chain(): void
    {
        $owner = User::factory()->create([
            'role' => UserRole::Owner->value,
        ]);

        $partner = User::factory()->create([
            'role' => UserRole::Partner->value,
            'parent_id' => null,
        ]);

        $partnerLink = TrackingLink::create([
            'user_id' => $partner->id,
            'platform_connection_id' => null,
            'short_code' => 'partner001',
            'destination_url' => 'https://shopee.vn/product/99/99',
            'platform' => 'shopee',
            'status' => 'active',
        ]);

        ContentGeneration::create([
            'tracking_link_id' => $partnerLink->id,
            'user_id' => $partner->id,
            'platform_connection_id' => null,
            'type' => 'text',
            'platform' => 'facebook',
            'status' => 'succeeded',
            'tokens_prompt' => 9,
            'tokens_completion' => 1,
            'output_payload' => ['variants' => [['text' => 'partner']]],
        ]);

        $response = $this->actingAs($owner)
            ->getJson('/api/content/statistics/account');

        $response->assertOk()
            ->assertJsonPath('data.meta.has_partner', true)
            ->assertJsonPath('data.meta.partner_count', 1)
            ->assertJsonPath('data.partner.requests_total', 1)
            ->assertJsonPath('data.partner.tokens', 10);
    }

    public function test_account_statistics_maps_partner_role_to_partner_segment(): void
    {
        $owner = User::factory()->create([
            'role' => UserRole::Owner->value,
        ]);

        $partner = User::factory()->create([
            'role' => UserRole::Partner->value,
            'parent_id' => $owner->id,
        ]);

        $partnerLink = TrackingLink::create([
            'user_id' => $partner->id,
            'platform_connection_id' => null,
            'short_code' => 'partner001',
            'destination_url' => 'https://shopee.vn/product/88/88',
            'platform' => 'shopee',
            'status' => 'active',
        ]);

        ContentGeneration::create([
            'tracking_link_id' => $partnerLink->id,
            'user_id' => $partner->id,
            'platform_connection_id' => null,
            'type' => 'text',
            'platform' => 'facebook',
            'status' => 'succeeded',
            'tokens_prompt' => 3,
            'tokens_completion' => 2,
            'output_payload' => ['variants' => [['text' => 'legacy']]],
        ]);

        $response = $this->actingAs($owner)
            ->getJson('/api/content/statistics/account');

        $response->assertOk()
            ->assertJsonPath('data.meta.has_partner', true)
            ->assertJsonPath('data.meta.partner_count', 1)
            ->assertJsonPath('data.partner.requests_total', 1);
    }
}
