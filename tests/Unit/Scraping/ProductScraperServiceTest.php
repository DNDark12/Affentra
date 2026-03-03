<?php

declare(strict_types=1);

namespace Tests\Unit\Scraping;

use App\Models\PlatformConnection;
use App\Models\User;
use App\Services\Scraping\ProductScraperService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class ProductScraperServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_scrape_supports_affiliate_product_offer_url_with_cookie_api(): void
    {
        $user = User::factory()->create();
        PlatformConnection::factory()->create([
            'user_id' => $user->id,
            'platform' => 'shopee',
            'method' => 'cookie',
            'status' => 'active',
            'cookie_header' => json_encode([
                'cookie' => 'SPC_EC=dummy;',
                'profiles' => [
                    'offer_product' => [
                        'af_ac_enc_dat' => 'enc-dat',
                        'af_ac_enc_sz_token' => 'enc-token',
                    ],
                ],
            ], JSON_UNESCAPED_SLASHES),
        ]);

        Http::fake([
            'https://affiliate.shopee.vn/api/v3/offer/product?item_id=19760277380' => Http::response([
                'code' => 0,
                'msg' => 'success',
                'data' => [
                    'product' => [
                        'product_name' => 'Bộ bình truyền test',
                        'price' => 209000,
                        'image_url' => 'https://cf.shopee.vn/file/test.jpg',
                    ],
                ],
            ], 200),
        ]);

        $service = app(ProductScraperService::class);
        $result = $service->scrape('https://affiliate.shopee.vn/offer/product_offer/19760277380', $user);

        $this->assertSame('shopee_affiliate_api', $result['source']);
        $this->assertSame('Bộ bình truyền test', $result['data']['title']);
        $this->assertSame(209000.0, (float) $result['data']['price_value']);
        $this->assertNotEmpty($result['data']['images']);
    }

    public function test_scrape_falls_back_to_offer_page_meta_when_affiliate_api_returns_no_data(): void
    {
        $user = User::factory()->create();
        PlatformConnection::factory()->create([
            'user_id' => $user->id,
            'platform' => 'shopee',
            'method' => 'cookie',
            'status' => 'active',
            'cookie_header' => json_encode([
                'cookie' => 'SPC_EC=dummy;',
                'profiles' => [
                    'campaign_list' => [
                        'af_ac_enc_dat' => 'enc-dat',
                        'af_ac_enc_sz_token' => 'enc-token',
                    ],
                ],
            ], JSON_UNESCAPED_SLASHES),
        ]);

        Http::fake([
            'https://affiliate.shopee.vn/api/v3/offer/*' => Http::response([
                'code' => 90309999,
                'msg' => 'Unknown error',
                'data' => null,
            ], 200),
            'https://affiliate.shopee.vn/offer/product_offer/19760277380' => Http::response(
                '<html><head>'
                . '<meta property="og:title" content="Sản phẩm fallback từ trang offer" />'
                . '<meta property="og:image" content="https://cf.shopee.vn/file/fallback.jpg" />'
                . '</head><body></body></html>',
                200,
                ['Content-Type' => 'text/html']
            ),
        ]);

        $service = app(ProductScraperService::class);
        $result = $service->scrape('https://affiliate.shopee.vn/offer/product_offer/19760277380', $user);

        $this->assertSame('shopee_affiliate_page', $result['source']);
        $this->assertSame('Sản phẩm fallback từ trang offer', $result['data']['title']);
        $this->assertSame(['https://cf.shopee.vn/file/fallback.jpg'], $result['data']['images']);
    }

    public function test_scrape_uses_offer_graphql_when_offer_product_api_unavailable(): void
    {
        $user = User::factory()->create();
        PlatformConnection::factory()->create([
            'user_id' => $user->id,
            'platform' => 'shopee',
            'method' => 'portal_export',
            'status' => 'active',
            'cookie_header' => json_encode([
                'cookie' => 'SPC_EC=dummy;',
                'profiles' => [
                    'campaign_list' => [
                        'af_ac_enc_dat' => 'enc-dat',
                        'af_ac_enc_sz_token' => 'enc-token',
                    ],
                ],
            ], JSON_UNESCAPED_SLASHES),
        ]);

        Http::fake([
            'https://affiliate.shopee.vn/api/v3/offer/*' => Http::response([
                'code' => 90309999,
                'msg' => 'Unknown error',
                'data' => null,
            ], 200),
            'https://affiliate.shopee.vn/api/v3/gql?q=productOfferV2' => Http::response([
                'data' => [
                    'productOfferV2' => [
                        'nodes' => [
                            [
                                'itemId' => '19760277380',
                                'productName' => 'Sản phẩm từ GraphQL',
                                'imageUrl' => 'https://cf.shopee.vn/file/graphql.jpg',
                                'priceMin' => 20900000000,
                            ],
                        ],
                    ],
                ],
            ], 200),
        ]);

        $service = app(ProductScraperService::class);
        $result = $service->scrape('https://affiliate.shopee.vn/offer/product_offer/19760277380', $user);

        $this->assertSame('shopee_affiliate_api', $result['source']);
        $this->assertSame('Sản phẩm từ GraphQL', $result['data']['title']);
        $this->assertSame(209000.0, (float) $result['data']['price_value']);
        $this->assertSame(['https://cf.shopee.vn/file/graphql.jpg'], $result['data']['images']);
    }
}
