<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Click;
use App\Models\PlatformConnection;
use App\Models\TrackingLink;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ClicksForeignKeyBehaviorTest extends TestCase
{
    use RefreshDatabase;

    public function test_deleting_connection_keeps_click_history_and_sets_connection_id_to_null(): void
    {
        $owner = User::factory()->create(['role' => 'owner']);
        $connection = PlatformConnection::factory()->create([
            'user_id' => $owner->id,
            'platform' => 'shopee',
            'method' => 'cookie',
        ]);

        $link = TrackingLink::query()->create([
            'user_id' => $owner->id,
            'campaign_id' => null,
            'short_code' => 'fkclick01',
            'destination_url' => 'https://example.com/track',
            'platform' => 'shopee',
            'status' => 'active',
        ]);

        $click = Click::query()->create([
            'tracking_link_id' => $link->id,
            'connection_id' => $connection->id,
            'sub_id' => 'sub-fk',
            'referer_domain' => 'shopee_sync',
            'created_at' => now(),
        ]);

        $connection->delete();

        $click->refresh();
        $this->assertNull($click->connection_id);
        $this->assertSame($link->id, $click->tracking_link_id);
    }
}
