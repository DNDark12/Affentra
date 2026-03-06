<?php

declare(strict_types=1);

namespace Tests\Feature\AI;

use App\Models\ContentGeneration;
use App\Models\Order;
use App\Models\PlatformConnection;
use App\Models\TrackingLink;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AiShopMappingBackfillCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_backfill_maps_tracking_link_and_content_generation_from_order_evidence(): void
    {
        $user = User::factory()->create();
        $connection = PlatformConnection::factory()->create([
            'user_id' => $user->id,
            'platform' => 'shopee',
            'status' => 'active',
        ]);

        $link = TrackingLink::create([
            'user_id' => $user->id,
            'platform_connection_id' => null,
            'short_code' => 'bf001',
            'destination_url' => 'https://shopee.vn/product/11/22',
            'platform' => 'shopee',
            'status' => 'active',
        ]);

        Order::create([
            'user_id' => $user->id,
            'tracking_link_id' => $link->id,
            'connection_id' => $connection->id,
            'platform' => 'shopee',
            'order_code' => 'ORD-BF-1',
            'status' => 'pending',
            'order_amount' => 120000,
            'commission' => 12000,
            'ordered_at' => now(),
        ]);

        $generation = ContentGeneration::create([
            'tracking_link_id' => $link->id,
            'user_id' => $user->id,
            'platform_connection_id' => null,
            'type' => 'text',
            'platform' => 'facebook',
            'status' => 'succeeded',
            'tokens_prompt' => 5,
            'tokens_completion' => 6,
            'output_payload' => ['variants' => [['text' => 'ok']]],
        ]);

        $this->artisan('ai:shop-mapping:backfill')
            ->assertSuccessful();

        $link->refresh();
        $generation->refresh();

        $this->assertSame($connection->id, $link->platform_connection_id);
        $this->assertSame($connection->id, $generation->platform_connection_id);
    }

    public function test_backfill_dry_run_keeps_ambiguous_links_unmapped(): void
    {
        $user = User::factory()->create();
        $connectionA = PlatformConnection::factory()->create([
            'user_id' => $user->id,
            'platform' => 'shopee',
            'status' => 'active',
        ]);
        $connectionB = PlatformConnection::factory()->create([
            'user_id' => $user->id,
            'platform' => 'shopee',
            'status' => 'active',
        ]);

        $link = TrackingLink::create([
            'user_id' => $user->id,
            'platform_connection_id' => null,
            'short_code' => 'bf002',
            'destination_url' => 'https://shopee.vn/product/33/44',
            'platform' => 'shopee',
            'status' => 'active',
        ]);

        Order::create([
            'user_id' => $user->id,
            'tracking_link_id' => $link->id,
            'connection_id' => $connectionA->id,
            'platform' => 'shopee',
            'order_code' => 'ORD-BF-2A',
            'status' => 'pending',
            'order_amount' => 10000,
            'commission' => 1000,
            'ordered_at' => now(),
        ]);
        Order::create([
            'user_id' => $user->id,
            'tracking_link_id' => $link->id,
            'connection_id' => $connectionB->id,
            'platform' => 'shopee',
            'order_code' => 'ORD-BF-2B',
            'status' => 'pending',
            'order_amount' => 20000,
            'commission' => 2000,
            'ordered_at' => now(),
        ]);

        $this->artisan('ai:shop-mapping:backfill --dry-run')
            ->assertSuccessful();

        $link->refresh();
        $this->assertNull($link->platform_connection_id);
    }
}
