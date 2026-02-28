<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TrackingLinkCreateTest extends TestCase
{
    use RefreshDatabase;

    public function test_store_reuses_existing_link_for_same_user_and_same_destination_identity(): void
    {
        $user = User::factory()->create();

        $first = $this->actingAs($user)->postJson('/api/links', [
            'destination_url' => 'https://shopee.vn/product/1663031317/46553051070?utm_source=a',
            'platform' => 'shopee',
        ]);

        $first->assertStatus(201)
            ->assertJsonPath('ok', true);

        $second = $this->actingAs($user)->postJson('/api/links', [
            'destination_url' => 'https://shopee.vn/product/1663031317/46553051070?utm_medium=b',
            'platform' => 'shopee',
        ]);

        $second->assertStatus(200)
            ->assertJsonPath('ok', true)
            ->assertJsonPath('message', 'Tracking link already exists. Reused existing link.')
            ->assertJsonPath('data.id', $first->json('data.id'));

        $this->assertDatabaseCount('tracking_links', 1);
    }
}
