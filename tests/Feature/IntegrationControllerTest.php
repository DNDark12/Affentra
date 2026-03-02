<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\PlatformConnection;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;
use App\Jobs\Sync\SyncPlatformConnectionJob;
use App\Jobs\Sync\SyncPaymentDataJob;
use App\Jobs\Sync\SyncShopeeCampaignsForConnectionJob;
use Illuminate\Support\Facades\Queue;

class IntegrationControllerTest extends TestCase
{
    use RefreshDatabase;

    public function test_user_can_create_connection()
    {
        $user = User::factory()->create(['role' => 'owner']);

        $response = $this->actingAs($user)->postJson('/api/integrations', [
            'platform'   => 'shopee',
            'method'     => 'open_api',
            'app_id'     => 'test_app',
            'app_secret' => 'test_secret',
        ]);

        $response->assertStatus(200)
            ->assertJsonPath('data.platform', 'shopee')
            ->assertJsonStructure(['data' => ['id', 'platform']]);

        $this->assertDatabaseHas('platform_connections', [
            'user_id'  => $user->id,
            'platform' => 'shopee',
            'status'   => 'inactive',
            'method'   => 'open_api',
        ]);
    }

    public function test_user_can_sync_own_connection()
    {
        Queue::fake();

        $user = User::factory()->create();
        $conn = PlatformConnection::factory()->create([
            'user_id' => $user->id,
            'status'  => 'active'
        ]);

        $response = $this->actingAs($user)->postJson("/api/integrations/{$conn->id}/sync");

        $response->assertStatus(200)
            ->assertJsonPath('data.status', 'pending')
            ->assertJsonStructure(['data' => ['sync_run_id', 'status']]);

        Queue::assertPushed(SyncPlatformConnectionJob::class, function ($job) use ($conn) {
            $reflection = new \ReflectionClass($job);
            $connIdProp = $reflection->getProperty('connectionId');
            $connIdProp->setAccessible(true);
            return $connIdProp->getValue($job) === $conn->id;
        });
        Queue::assertPushed(SyncPaymentDataJob::class);
        Queue::assertPushed(SyncShopeeCampaignsForConnectionJob::class);
    }

    public function test_ctv_cannot_sync_other_connection()
    {
        Queue::fake();

        $user = User::factory()->create(['role' => 'ctv']);
        $owner = User::factory()->create(['role' => 'owner']);
        $conn = PlatformConnection::factory()->create([
            'user_id' => $owner->id,
        ]);

        $response = $this->actingAs($user)->postJson("/api/integrations/{$conn->id}/sync");

        $response->assertStatus(403);
        Queue::assertNotPushed(SyncPlatformConnectionJob::class);
    }

    public function test_user_cannot_create_unsupported_platform_connection(): void
    {
        $user = User::factory()->create(['role' => 'owner']);

        $response = $this->actingAs($user)->postJson('/api/integrations', [
            'platform'   => 'lazada',
            'method'     => 'open_api',
            'app_id'     => 'test_app',
            'app_secret' => 'test_secret',
        ]);

        $response->assertStatus(422);
    }
}
