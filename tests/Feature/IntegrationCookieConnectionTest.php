<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\PlatformConnection;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class IntegrationCookieConnectionTest extends TestCase
{
    use RefreshDatabase;

    public function test_cookie_connection_is_valid_when_any_probe_passes(): void
    {
        $owner = User::factory()->create(['role' => 'owner']);
        $connection = PlatformConnection::factory()->create([
            'user_id' => $owner->id,
            'platform' => 'shopee',
            'method' => 'cookie',
            'status' => 'inactive',
            'cookie_header' => json_encode([
                'cookie' => 'SPC_EC=dummy-cookie-value',
                'affiliate_program_type' => '1',
            ], JSON_THROW_ON_ERROR),
        ]);

        Http::fake([
            'https://affiliate.shopee.vn/api/v3/dashboard/detail*' => Http::response([
                'code' => 401,
                'msg' => 'unauthorized',
            ], 401),
            'https://affiliate.shopee.vn/api/v3/report/list*' => Http::response([
                'code' => 999,
                'msg' => 'conversion unavailable',
            ], 200),
            'https://affiliate.shopee.vn/api/v1/click_report/list*' => Http::response([
                'code' => 0,
                'data' => ['list' => [], 'total_count' => 0],
            ], 200),
        ]);

        $response = $this->actingAs($owner)
            ->postJson("/api/integrations/{$connection->id}/test");

        $response->assertOk()
            ->assertJsonPath('ok', true)
            ->assertJsonPath('data.valid', true)
            ->assertJsonPath('data.checks.dashboard.ok', false)
            ->assertJsonPath('data.checks.conversion_report.ok', false)
            ->assertJsonPath('data.checks.click_report.ok', true);

        $connection->refresh();

        $this->assertSame('active', $connection->status);
        $this->assertNotNull($connection->cookie_validated_at);
        $this->assertNotNull($connection->last_error);
        $this->assertStringContainsString('Partial cookie validation', (string) $connection->last_error);
    }

    public function test_cookie_connection_fails_when_all_probes_fail(): void
    {
        $owner = User::factory()->create(['role' => 'owner']);
        $connection = PlatformConnection::factory()->create([
            'user_id' => $owner->id,
            'platform' => 'shopee',
            'method' => 'cookie',
            'status' => 'active',
            'cookie_header' => json_encode([
                'cookie' => 'SPC_EC=dummy-cookie-value',
                'affiliate_program_type' => '1',
            ], JSON_THROW_ON_ERROR),
        ]);

        Http::fake([
            'https://affiliate.shopee.vn/api/v3/dashboard/detail*' => Http::response([
                'code' => 401,
                'msg' => 'unauthorized',
            ], 401),
            'https://affiliate.shopee.vn/api/v3/report/list*' => Http::response([
                'code' => 401,
                'msg' => 'unauthorized',
            ], 401),
            'https://affiliate.shopee.vn/api/v1/click_report/list*' => Http::response([
                'code' => 401,
                'msg' => 'unauthorized',
            ], 401),
        ]);

        $response = $this->actingAs($owner)
            ->postJson("/api/integrations/{$connection->id}/test");

        $response->assertOk()
            ->assertJsonPath('ok', true)
            ->assertJsonPath('data.valid', false)
            ->assertJsonPath('data.checks.dashboard.ok', false)
            ->assertJsonPath('data.checks.conversion_report.ok', false)
            ->assertJsonPath('data.checks.click_report.ok', false);

        $this->assertStringContainsString(
            'Cookie test failed for all probes',
            (string) $response->json('message')
        );

        $connection->refresh();

        $this->assertSame('error', $connection->status);
        $this->assertNull($connection->cookie_validated_at);
        $this->assertNotNull($connection->last_error);
    }

    public function test_cookie_connection_is_marked_valid_when_shopee_returns_soft_block_code(): void
    {
        $owner = User::factory()->create(['role' => 'owner']);
        $connection = PlatformConnection::factory()->create([
            'user_id' => $owner->id,
            'platform' => 'shopee',
            'method' => 'cookie',
            'status' => 'inactive',
            'cookie_header' => json_encode([
                'cookie' => 'SPC_EC=dummy-cookie-value',
                'affiliate_program_type' => '1',
                'x_sap_ri' => 'ri-test',
                'x_sap_sec' => 'sec-test',
                'x_sz_sdk_version' => '1.12.21',
            ], JSON_THROW_ON_ERROR),
        ]);

        Http::fake([
            'https://affiliate.shopee.vn/api/v3/dashboard/detail*' => Http::response([
                'code' => 90309999,
                'msg' => 'anti-bot challenge',
            ], 200),
            'https://affiliate.shopee.vn/api/v3/report/list*' => Http::response([
                'code' => 90309999,
                'msg' => 'anti-bot challenge',
            ], 200),
            'https://affiliate.shopee.vn/api/v1/click_report/list*' => Http::response([
                'code' => 90309999,
                'msg' => 'anti-bot challenge',
            ], 200),
        ]);

        $response = $this->actingAs($owner)
            ->postJson("/api/integrations/{$connection->id}/test");

        $response->assertOk()
            ->assertJsonPath('ok', true)
            ->assertJsonPath('data.valid', true)
            ->assertJsonPath('data.checks.dashboard.code', 90309999);

        $this->assertStringContainsString(
            'Shopee đang chặn một phần request',
            (string) $response->json('message')
        );

        $connection->refresh();

        $this->assertSame('active', $connection->status);
        $this->assertNotNull($connection->cookie_validated_at);
        $this->assertStringContainsString('anti-bot challenge', (string) $connection->last_error);
    }
}
