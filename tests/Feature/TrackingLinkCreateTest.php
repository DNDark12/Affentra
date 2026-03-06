<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\PlatformConnection;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TrackingLinkCreateTest extends TestCase
{
    use RefreshDatabase;

    public function test_store_reuses_existing_link_for_same_user_and_same_destination_identity(): void
    {
        $user = User::factory()->create();
        $connection = PlatformConnection::factory()->create([
            'user_id' => $user->id,
            'platform' => 'shopee',
            'status' => 'active',
        ]);

        $first = $this->actingAs($user)->postJson('/api/links', [
            'destination_url' => 'https://shopee.vn/product/1663031317/46553051070?utm_source=a',
            'platform' => 'shopee',
            'platform_connection_id' => $connection->id,
        ]);

        $first->assertStatus(201)
            ->assertJsonPath('ok', true);

        $second = $this->actingAs($user)->postJson('/api/links', [
            'destination_url' => 'https://shopee.vn/product/1663031317/46553051070?utm_medium=b',
            'platform' => 'shopee',
            'platform_connection_id' => $connection->id,
        ]);

        $second->assertStatus(200)
            ->assertJsonPath('ok', true)
            ->assertJsonPath('message', 'Tracking link already exists. Reused existing link.')
            ->assertJsonPath('data.id', $first->json('data.id'));

        $this->assertDatabaseCount('tracking_links', 1);
    }

    public function test_store_requires_platform_connection_id(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->postJson('/api/links', [
            'destination_url' => 'https://shopee.vn/product/1663031317/46553051070',
            'platform' => 'shopee',
        ]);

        $response->assertStatus(422)
            ->assertJsonPath('errors.platform_connection_id.0', 'The platform connection id field is required.');
    }

    public function test_store_does_not_reuse_link_across_different_shops(): void
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

        $first = $this->actingAs($user)->postJson('/api/links', [
            'destination_url' => 'https://shopee.vn/product/1663031317/46553051070?utm_source=a',
            'platform' => 'shopee',
            'platform_connection_id' => $connectionA->id,
        ]);
        $first->assertStatus(201)
            ->assertJsonPath('ok', true);

        $second = $this->actingAs($user)->postJson('/api/links', [
            'destination_url' => 'https://shopee.vn/product/1663031317/46553051070?utm_source=a',
            'platform' => 'shopee',
            'platform_connection_id' => $connectionB->id,
        ]);
        $second->assertStatus(201)
            ->assertJsonPath('ok', true);

        $this->assertNotSame($first->json('data.id'), $second->json('data.id'));
        $this->assertDatabaseCount('tracking_links', 2);
    }

    public function test_store_rejects_platform_connection_that_belongs_to_another_user(): void
    {
        $user = User::factory()->create();
        $otherUser = User::factory()->create();
        $foreignConnection = PlatformConnection::factory()->create([
            'user_id' => $otherUser->id,
            'platform' => 'shopee',
            'status' => 'active',
        ]);

        $response = $this->actingAs($user)->postJson('/api/links', [
            'destination_url' => 'https://shopee.vn/product/1663031317/46553051070',
            'platform' => 'shopee',
            'platform_connection_id' => $foreignConnection->id,
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['platform_connection_id']);
    }
}
