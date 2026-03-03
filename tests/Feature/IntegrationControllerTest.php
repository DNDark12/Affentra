<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\AuditLog;
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

        $response = $this->actingAs($user)
            ->withHeader('X-Request-ID', 'integration-create-req-001')
            ->postJson('/api/integrations', [
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

        $this->assertDatabaseHas('audit_logs', [
            'actor_id' => $user->id,
            'action' => 'integration.connection.create',
            'request_id' => 'integration-create-req-001',
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

    public function test_cookie_profiles_can_be_updated_incrementally_with_one_curl_per_request(): void
    {
        $user = User::factory()->create(['role' => 'owner']);
        $connection = PlatformConnection::factory()->create([
            'user_id' => $user->id,
            'platform' => 'shopee',
            'method' => 'cookie',
            'status' => 'inactive',
            'cookie_header' => null,
            'consent_acknowledged_at' => now(),
        ]);

        $dashboardCurl = "curl 'https://affiliate.shopee.vn/api/v3/dashboard/detail?start_time=1&end_time=2' "
            . "-H 'referer: https://affiliate.shopee.vn/dashboard' "
            . "-H 'user-agent: test-dashboard-ua' "
            . "-H 'affiliate-program-type: 1' "
            . "-H 'x-sap-ri: ri-dashboard' "
            . "-H 'x-sap-sec: sec-dashboard' "
            . "-H 'x-sz-sdk-version: 1.12.21' "
            . "-b 'SPC_EC=dummy-cookie-value'";

        $conversionCurl = "curl 'https://affiliate.shopee.vn/api/v3/report/list?page_size=1&page_num=1' "
            . "-H 'referer: https://affiliate.shopee.vn/report/conversion_report' "
            . "-H 'user-agent: test-conversion-ua' "
            . "-H 'affiliate-program-type: 1' "
            . "-H 'x-sap-ri: ri-conversion' "
            . "-H 'x-sap-sec: sec-conversion' "
            . "-H 'x-sz-sdk-version: 1.12.21' "
            . "-b 'SPC_EC=dummy-cookie-value'";

        $this->actingAs($user)
            ->patchJson("/api/integrations/{$connection->id}", [
                'curl_command' => $dashboardCurl,
            ])
            ->assertOk();

        $this->actingAs($user)
            ->patchJson("/api/integrations/{$connection->id}", [
                'curl_command' => $conversionCurl,
            ])
            ->assertOk();

        $connection->refresh();

        $decoded = json_decode((string) $connection->cookie_header, true);
        $this->assertIsArray($decoded);
        $this->assertIsArray($decoded['profiles'] ?? null);
        $this->assertArrayHasKey('dashboard', $decoded['profiles']);
        $this->assertArrayHasKey('conversion_report', $decoded['profiles']);
        $this->assertSame('SPC_EC=dummy-cookie-value', $decoded['cookie']);
    }
}
