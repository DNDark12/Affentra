<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\LinkStatus;
use App\Models\TrackingLink;
use App\Models\User;
use App\Services\Scraping\ProductScraperService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery\MockInterface;
use Tests\TestCase;

class TrackingLinkRefreshProductTest extends TestCase
{
    use RefreshDatabase;

    public function test_refresh_product_uses_authenticated_user_context_and_updates_fields(): void
    {
        $user = User::factory()->create();

        $link = TrackingLink::create([
            'user_id' => $user->id,
            'short_code' => 'rfpctx01',
            'destination_url' => 'https://shopee.vn/product/1663031317/46553051070',
            'status' => LinkStatus::Active,
        ]);

        $this->mock(ProductScraperService::class, function (MockInterface $mock) use ($user, $link): void {
            $mock->shouldReceive('scrape')
                ->once()
                ->withArgs(function ($url, $actor) use ($user, $link): bool {
                    return $url === $link->destination_url
                        && $actor instanceof User
                        && $actor->is($user);
                })
                ->andReturn([
                    'data' => [
                        'title' => 'Áo khoác test',
                        'price_value' => 499000,
                        'price_display' => '499.000₫',
                        'images' => ['https://example.com/p.jpg'],
                    ],
                    'confidence' => 0.9,
                    'source' => 'test',
                ]);
        });

        $response = $this->actingAs($user)
            ->postJson("/api/links/{$link->id}/refresh-product");

        $response->assertOk()
            ->assertJsonPath('ok', true)
            ->assertJsonPath('data.product_name', 'Áo khoác test')
            ->assertJsonPath('data.product_price', '499.000₫');

        $this->assertDatabaseHas('tracking_links', [
            'id' => $link->id,
            'product_name' => 'Áo khoác test',
            'product_price' => '499.000₫',
            'product_scrape_source' => 'test',
            'product_scrape_error' => null,
        ]);
    }

    public function test_refresh_product_returns_422_when_scraper_has_no_meaningful_data(): void
    {
        $user = User::factory()->create();

        $link = TrackingLink::create([
            'user_id' => $user->id,
            'short_code' => 'rfpctx02',
            'destination_url' => 'https://shopee.vn/product/1663031317/49501812276',
            'status' => LinkStatus::Active,
        ]);

        $this->mock(ProductScraperService::class, function (MockInterface $mock): void {
            $mock->shouldReceive('scrape')
                ->once()
                ->andReturn([
                    'data' => [
                        'title' => null,
                        'price_value' => null,
                        'price_display' => null,
                        'images' => [],
                    ],
                    'confidence' => 0.0,
                    'source' => 'test',
                ]);
        });

        $response = $this->actingAs($user)
            ->postJson("/api/links/{$link->id}/refresh-product");

        $response->assertStatus(422)
            ->assertJsonPath('ok', false);

        $link->refresh();
        $this->assertNotNull($link->product_scrape_error);
        $this->assertStringContainsString('Không lấy được dữ liệu sản phẩm', $link->product_scrape_error);
    }

    public function test_refresh_active_products_only_processes_active_links_in_scope(): void
    {
        $user = User::factory()->create();
        $otherUser = User::factory()->create();

        $activeA = TrackingLink::create([
            'user_id' => $user->id,
            'short_code' => 'rfpbulk01',
            'destination_url' => 'https://shopee.vn/product/1/111',
            'status' => LinkStatus::Active,
        ]);
        $paused = TrackingLink::create([
            'user_id' => $user->id,
            'short_code' => 'rfpbulk02',
            'destination_url' => 'https://shopee.vn/product/1/222',
            'status' => LinkStatus::Paused,
        ]);
        $activeB = TrackingLink::create([
            'user_id' => $user->id,
            'short_code' => 'rfpbulk03',
            'destination_url' => 'https://shopee.vn/product/1/333',
            'status' => LinkStatus::Active,
        ]);
        $otherActive = TrackingLink::create([
            'user_id' => $otherUser->id,
            'short_code' => 'rfpbulk04',
            'destination_url' => 'https://shopee.vn/product/1/444',
            'status' => LinkStatus::Active,
        ]);

        $this->mock(ProductScraperService::class, function (MockInterface $mock) use ($user, $activeA, $activeB): void {
            $mock->shouldReceive('scrape')
                ->twice()
                ->andReturnUsing(function ($url, $actor) use ($user, $activeA, $activeB): array {
                    $this->assertInstanceOf(User::class, $actor);
                    $this->assertTrue($actor->is($user));

                    if ($url === $activeA->destination_url) {
                        return [
                            'data' => [
                                'title' => 'Sản phẩm bulk A',
                                'price_value' => 101000,
                                'price_display' => '101.000₫',
                                'images' => [],
                            ],
                            'confidence' => 0.8,
                            'source' => 'test',
                        ];
                    }

                    if ($url === $activeB->destination_url) {
                        return [
                            'data' => [
                                'title' => 'Sản phẩm bulk B',
                                'price_value' => 202000,
                                'price_display' => '202.000₫',
                                'images' => [],
                            ],
                            'confidence' => 0.8,
                            'source' => 'test',
                        ];
                    }

                    throw new \RuntimeException('Unexpected URL: ' . (string) $url);
                });
        });

        $response = $this->actingAs($user)
            ->postJson('/api/links/refresh-product-active');

        $response->assertOk()
            ->assertJsonPath('ok', true)
            ->assertJsonPath('data.total', 2)
            ->assertJsonPath('data.success', 2)
            ->assertJsonPath('data.failed', 0);

        $activeA->refresh();
        $paused->refresh();
        $activeB->refresh();
        $otherActive->refresh();

        $this->assertSame('Sản phẩm bulk A', $activeA->product_name);
        $this->assertSame('Sản phẩm bulk B', $activeB->product_name);
        $this->assertNull($paused->product_name);
        $this->assertNull($otherActive->product_name);
    }
}
