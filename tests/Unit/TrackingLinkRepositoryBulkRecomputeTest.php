<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Contracts\Repositories\TrackingLinkRepositoryInterface;
use App\Models\Click;
use App\Models\TrackingLink;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TrackingLinkRepositoryBulkRecomputeTest extends TestCase
{
    use RefreshDatabase;

    public function test_bulk_recompute_updates_links_with_and_without_clicks(): void
    {
        $owner = User::factory()->create(['role' => 'owner']);

        $linkWithClicks = TrackingLink::query()->create([
            'user_id' => $owner->id,
            'campaign_id' => null,
            'short_code' => 'bulkcnt01',
            'destination_url' => 'https://example.com/1',
            'platform' => 'shopee',
            'status' => 'active',
            'clicks_count' => 99,
        ]);

        $linkWithoutClicks = TrackingLink::query()->create([
            'user_id' => $owner->id,
            'campaign_id' => null,
            'short_code' => 'bulkcnt02',
            'destination_url' => 'https://example.com/2',
            'platform' => 'shopee',
            'status' => 'active',
            'clicks_count' => 77,
        ]);

        Click::query()->insert([
            [
                'tracking_link_id' => $linkWithClicks->id,
                'sub_id' => 'sub-a',
                'referer_domain' => 'shopee_sync',
                'created_at' => now(),
            ],
            [
                'tracking_link_id' => $linkWithClicks->id,
                'sub_id' => 'sub-a',
                'referer_domain' => 'shopee_sync',
                'created_at' => now(),
            ],
            [
                'tracking_link_id' => $linkWithClicks->id,
                'sub_id' => 'sub-a',
                'referer_domain' => 'shopee_sync',
                'created_at' => now(),
            ],
        ]);

        $repository = app(TrackingLinkRepositoryInterface::class);
        $affected = $repository->recomputeClicksCountBulk([$linkWithClicks->id, $linkWithoutClicks->id]);

        $this->assertGreaterThanOrEqual(1, $affected);

        $linkWithClicks->refresh();
        $linkWithoutClicks->refresh();

        $this->assertSame(3, (int) $linkWithClicks->clicks_count);
        $this->assertSame(0, (int) $linkWithoutClicks->clicks_count);
    }
}
