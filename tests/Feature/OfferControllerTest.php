<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\PlatformConnection;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class OfferControllerTest extends TestCase
{
    use RefreshDatabase;

    public function test_user_can_search_offers()
    {
        $user = User::factory()->create();
        $conn = PlatformConnection::factory()->create([
            'user_id' => $user->id,
            'platform' => 'shopee',
            'app_id' => 'test_app',
            'app_secret' => 'test_secret'
        ]);

        Http::fake([
            '*graphql' => Http::response([
                'data' => [
                    'productOfferV2' => [
                        'nodes' => [
                            ['itemId' => '123', 'productName' => 'Fake Item']
                        ],
                        'pageInfo' => ['hasNextPage' => false, 'endCursor' => '']
                    ]
                ]
            ], 200)
        ]);

        $response = $this->actingAs($user)->getJson('/api/offers/search?connection_id=' . $conn->id . '&keyword=test');

        $response->assertStatus(200)
            ->assertJsonPath('data.nodes.0.itemId', '123');
    }

    public function test_user_can_get_link()
    {
        $user = User::factory()->create();
        $conn = PlatformConnection::factory()->create([
            'user_id' => $user->id,
            'platform' => 'shopee',
            'app_id' => 'test_app',
            'app_secret' => 'test_secret'
        ]);

        Http::fake([
            '*graphql' => Http::response([
                'data' => [
                    'generateShortLink' => [
                        'shortLink' => 'https://shope.ee/fake'
                    ]
                ]
            ], 200)
        ]);

        $response = $this->actingAs($user)->postJson('/api/offers/get-link', [
            'connection_id' => $conn->id,
            'offer_id'      => 'offer_123',
            'offer_link'    => 'https://shopee.vn/test',
            'item_id'       => '12345',
            'product_name'  => 'Fake Item',
            'sub_id'        => 'my_subld',
            'meta'          => ['commission_rate' => 5.5],
        ]);

        $response->assertStatus(200)
            ->assertJsonPath('data.destination_url', 'https://shope.ee/fake');
            
        $this->assertDatabaseHas('tracking_links', [
            'user_id' => $user->id,
        ]);
    }

    public function test_get_link_rejects_non_shopee_domain(): void
    {
        $user = User::factory()->create();
        $conn = PlatformConnection::factory()->create([
            'user_id'  => $user->id,
            'platform' => 'shopee',
        ]);

        $response = $this->actingAs($user)->postJson('/api/offers/get-link', [
            'connection_id' => $conn->id,
            'offer_link'    => 'https://example.com/product/abc',
            'item_id'       => '12345',
            'product_name'  => 'Fake Item',
        ]);

        $response->assertStatus(422)
            ->assertJsonPath('message', 'URL must be a Shopee domain.');

        $this->assertDatabaseCount('tracking_links', 0);
    }

    public function test_search_returns_not_found_for_other_users_connection(): void
    {
        $user = User::factory()->create();
        $otherUser = User::factory()->create();
        $otherConn = PlatformConnection::factory()->create([
            'user_id'  => $otherUser->id,
            'platform' => 'shopee',
        ]);

        $response = $this->actingAs($user)
            ->getJson('/api/offers/search?connection_id=' . $otherConn->id . '&keyword=test');

        $response->assertStatus(404);
    }

    public function test_get_link_returns_not_found_for_other_users_connection(): void
    {
        $user = User::factory()->create();
        $otherUser = User::factory()->create();
        $otherConn = PlatformConnection::factory()->create([
            'user_id'  => $otherUser->id,
            'platform' => 'shopee',
        ]);

        $response = $this->actingAs($user)->postJson('/api/offers/get-link', [
            'connection_id' => $otherConn->id,
            'offer_link'    => 'https://shopee.vn/product/abc',
            'item_id'       => '12345',
            'product_name'  => 'Fake Item',
        ]);

        $response->assertStatus(404);
        $this->assertDatabaseCount('tracking_links', 0);
    }
}
