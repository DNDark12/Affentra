<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Models\Campaign;
use App\Services\Campaign\CampaignSyncService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use ReflectionMethod;
use Tests\TestCase;

class CampaignSyncServiceTest extends TestCase
{
    use RefreshDatabase;

    private CampaignSyncService $service;

    private ReflectionMethod $method;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = app(CampaignSyncService::class);
        $this->method = new ReflectionMethod(CampaignSyncService::class, 'shouldProvisionTrackingLink');
    }

    /**
     * @dataProvider campaignFilterProvider
     */
    public function test_should_provision_tracking_link(string $label, ?string $url, string $name, bool $expected): void
    {
        $campaign = new Campaign();
        $campaign->id = 1;
        $campaign->external_id = 'test_ext_id';
        $campaign->campaign_url = $url;
        $campaign->name = $name;

        $result = $this->method->invoke($this->service, $campaign);

        $this->assertSame($expected, $result, "Failed assertion for case: {$label}");
    }

    /**
     * @return array<string, array{string, ?string, string, bool}>
     */
    public static function campaignFilterProvider(): array
    {
        return [
            // ── Allow Cases ──────────────────────────────────────────
            'Product URL with /product/ path' => [
                'Product URL with /product/ path',
                'https://shopee.vn/product/59892367/19760277380',
                'Some Product Campaign',
                true,
            ],
            'Product URL with -i. slug pattern' => [
                'Product URL with -i. slug pattern',
                'https://shopee.vn/Some-Product-Name-i.59892367.19760277380',
                'Normal Campaign',
                true,
            ],
            'Product URL even with mission in name (allow-list priority)' => [
                'Product URL even with mission in name',
                'https://shopee.vn/product/123/456',
                'Video Mission Product Pack',
                true,
            ],
            'Normal affiliate campaign URL' => [
                'Normal affiliate campaign URL',
                'https://affiliate.shopee.vn/offer/product_offer/19760277380',
                'Regular Offer',
                true,
            ],
            'Generic shopee URL without block signals' => [
                'Generic shopee URL without block signals',
                'https://shopee.vn/some-category-page',
                'Category Promo',
                true,
            ],

            // ── Block Cases ──────────────────────────────────────────
            'No URL at all' => [
                'No URL at all',
                null,
                'Some Campaign',
                false,
            ],
            'Empty URL string' => [
                'Empty URL string',
                '',
                'Some Campaign',
                false,
            ],
            'Blocked host: giaitri.shopee.vn' => [
                'Blocked host: giaitri.shopee.vn',
                'https://giaitri.shopee.vn/some-page',
                'Entertainment Campaign',
                false,
            ],
            'Blocked path prefix /m/' => [
                'Blocked path prefix /m/',
                'https://shopee.vn/m/welcome-mission',
                'Welcome Mission',
                false,
            ],
            'Mission keyword in name (Vietnamese)' => [
                'Mission keyword in name (Vietnamese)',
                'https://shopee.vn/some-non-product-page',
                'Nhiệm vụ hàng ngày',
                false,
            ],
            'Mission keyword in name (English)' => [
                'Mission keyword in name (English)',
                'https://shopee.vn/some-page',
                'Daily Mission Rewards',
                false,
            ],
            'Mission keyword mixed case' => [
                'Mission keyword mixed case',
                'https://shopee.vn/rewards',
                'VIDEO MISSION BONUS',
                false,
            ],
        ];
    }
}
