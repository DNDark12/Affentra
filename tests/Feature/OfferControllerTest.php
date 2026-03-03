<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\PlatformConnection;
use App\Models\TrackingLink;
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

    public function test_get_link_reuses_existing_tracking_link_for_same_product(): void
    {
        $user = User::factory()->create();
        $conn = PlatformConnection::factory()->create([
            'user_id' => $user->id,
            'platform' => 'shopee',
            'app_id' => 'test_app',
            'app_secret' => 'test_secret',
        ]);

        $existing = TrackingLink::create([
            'user_id' => $user->id,
            'short_code' => 'sameprod',
            'destination_url' => 'https://shopee.vn/product/1663031317/46553051070',
            'platform' => 'shopee',
            'sub_id' => 'offer_existing',
            'status' => 'active',
        ]);

        Http::fake([
            '*graphql' => Http::response([
                'data' => [
                    'generateShortLink' => [
                        'shortLink' => 'https://s.shopee.vn/9AAbbCCdd',
                    ],
                ],
            ], 200),
        ]);

        $response = $this->actingAs($user)->postJson('/api/offers/get-link', [
            'connection_id' => $conn->id,
            'offer_id'      => 'offer_123',
            'offer_link'    => 'https://shopee.vn/product/1663031317/46553051070',
            'item_id'       => '46553051070',
            'shop_id'       => '1663031317',
            'product_name'  => 'Fake Item',
        ]);

        $response->assertStatus(200)
            ->assertJsonPath('data.id', $existing->id)
            ->assertJsonPath('data.short_code', $existing->short_code)
            ->assertJsonPath('data.reused_existing', true)
            ->assertJsonPath('data.sub_id', 'offer_existing');

        $this->assertDatabaseCount('tracking_links', 1);
    }

    public function test_categories_returns_200_and_caches_results(): void
    {
        $user = User::factory()->create();
        $conn = PlatformConnection::factory()->create([
            'user_id'    => $user->id,
            'platform'   => 'shopee',
            'method'     => 'open_api',
            'app_id'     => 'test_app',
            'app_secret' => 'test_secret',
        ]);

        Http::fake([
            '*graphql' => Http::response([
                'data' => [
                    'getProductCategory' => [
                        ['catId' => 1, 'catName' => 'Electronics', 'children' => []],
                        ['catId' => 2, 'catName' => 'Fashion',     'children' => []],
                    ],
                ],
            ], 200),
        ]);

        $response = $this->actingAs($user)
            ->getJson('/api/offers/categories?connection_id=' . $conn->id);

        $response->assertStatus(200)
            ->assertJsonPath('data.0.catId', 1)
            ->assertJsonPath('data.0.catName', 'Electronics');
    }

    public function test_categories_returns_empty_array_for_cookie_connection(): void
    {
        $user = User::factory()->create();
        $conn = PlatformConnection::factory()->create([
            'user_id'  => $user->id,
            'platform' => 'shopee',
            'method'   => 'cookie',
        ]);

        $response = $this->actingAs($user)
            ->getJson('/api/offers/categories?connection_id=' . $conn->id);

        $response->assertStatus(200)
            ->assertJsonPath('data', []);
    }

    public function test_categories_returns_404_for_other_users_connection(): void
    {
        $user      = User::factory()->create();
        $otherUser = User::factory()->create();
        $otherConn = PlatformConnection::factory()->create([
            'user_id'  => $otherUser->id,
            'platform' => 'shopee',
        ]);

        $response = $this->actingAs($user)
            ->getJson('/api/offers/categories?connection_id=' . $otherConn->id);

        $response->assertStatus(404);
    }

    public function test_categories_requires_connection_id(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)
            ->getJson('/api/offers/categories');

        $response->assertStatus(422);
    }

    public function test_show_offer_detail_returns_200_for_open_api_connection(): void
    {
        $user = User::factory()->create();
        $conn = PlatformConnection::factory()->create([
            'user_id'    => $user->id,
            'platform'   => 'shopee',
            'method'     => 'open_api',
            'app_id'     => 'test_app',
            'app_secret' => 'test_secret',
        ]);

        Http::fake([
            '*graphql' => Http::response([
                'data' => [
                    'productOfferV2' => [
                        'nodes' => [[
                            'itemId' => '123',
                            'productName' => 'Chi tiet san pham',
                            'productLink' => 'https://shopee.vn/product/1/123',
                            'offerLink' => 'https://s.shopee.vn/abc123',
                            'imageUrl' => 'https://example.com/product.jpg',
                            'priceMin' => 200000,
                            'priceMax' => 250000,
                            'commissionRate' => 5.5,
                            'shopId' => '1',
                            'shopName' => 'Demo Shop',
                            'shopType' => 1,
                        ]],
                        'pageInfo' => ['hasNextPage' => false, 'page' => 1, 'limit' => 1],
                    ],
                ],
            ], 200),
        ]);

        $response = $this->actingAs($user)
            ->getJson('/api/offers/123?connection_id=' . $conn->id . '&shop_id=1');

        $response->assertStatus(200)
            ->assertJsonPath('data.item_id', '123')
            ->assertJsonPath('data.item_name', 'Chi tiet san pham')
            ->assertJsonPath('data.shop_id', '1')
            ->assertJsonPath('data.commission_rate', 5.5);
    }

    public function test_show_offer_detail_returns_404_for_other_users_connection(): void
    {
        $user = User::factory()->create();
        $otherUser = User::factory()->create();
        $otherConn = PlatformConnection::factory()->create([
            'user_id'  => $otherUser->id,
            'platform' => 'shopee',
            'method'   => 'open_api',
        ]);

        $response = $this->actingAs($user)
            ->getJson('/api/offers/123?connection_id=' . $otherConn->id);

        $response->assertStatus(404);
    }

    public function test_show_offer_detail_requires_connection_id(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)
            ->getJson('/api/offers/123');

        $response->assertStatus(422);
    }
}
