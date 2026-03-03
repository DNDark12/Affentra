<?php

declare(strict_types=1);

namespace Tests\Feature\Alerts;

use App\Enums\UserRole;
use App\Jobs\Alert\SendTelegramAlertJob;
use App\Models\AlertRule;
use App\Models\AuditLog;
use App\Models\SyncRun;
use App\Models\User;
use App\Models\UserTelegramConfig;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class TelegramConfigTest extends TestCase
{
    use RefreshDatabase;

    public function test_authenticated_user_can_manage_telegram_config_without_exposing_raw_token(): void
    {
        $owner = User::factory()->create([
            'role' => UserRole::Owner->value,
            'path' => '1',
        ]);

        $token = '123456789:ABCDEFGHIJKLMNOPQRSTUVWXYZ1234567890';
        $groupId = '-1001234567890';

        $updateResponse = $this->actingAs($owner)->putJson('/api/alerts/telegram-config', [
            'bot_token' => $token,
            'group_id' => $groupId,
            'is_enabled' => true,
            'fallback_to_in_app' => true,
        ]);

        $updateResponse
            ->assertOk()
            ->assertJsonPath('ok', true)
            ->assertJsonPath('data.telegram.has_token', true)
            ->assertJsonPath('data.telegram.has_group_id', true)
            ->assertJsonPath('data.telegram.enabled', true);

        $masked = (string) $updateResponse->json('data.telegram.masked_bot_token');
        $this->assertNotSame($token, $masked);
        $this->assertTrue(str_contains($masked, '*'));

        $getResponse = $this->actingAs($owner)->getJson('/api/alerts/telegram-config');
        $getResponse
            ->assertOk()
            ->assertJsonPath('ok', true)
            ->assertJsonPath('data.telegram.has_token', true);

        Http::fake([
            'https://api.telegram.org/*' => Http::response(['ok' => true], 200),
        ]);

        $this->actingAs($owner)->postJson('/api/alerts/telegram-config/test', [
            'message' => 'Test message',
        ])->assertOk()->assertJsonPath('ok', true);

        $config = UserTelegramConfig::query()->where('user_id', $owner->id)->first();
        $this->assertNotNull($config);
        $this->assertSame('success', $config?->last_test_status);

        $this->actingAs($owner)
            ->deleteJson('/api/alerts/telegram-config')
            ->assertOk()
            ->assertJsonPath('ok', true)
            ->assertJsonPath('data.telegram.has_token', false);

        $auditRows = AuditLog::query()
            ->whereIn('action', ['alert.telegram.updated', 'alert.telegram.tested'])
            ->get();

        foreach ($auditRows as $row) {
            $payload = json_encode($row->new_state ?? [], JSON_UNESCAPED_UNICODE);
            $this->assertFalse(str_contains((string) $payload, $token));
        }
    }

    public function test_user_can_save_config_without_group_id(): void
    {
        $owner = User::factory()->create([
            'role' => UserRole::Owner->value,
            'path' => '1',
        ]);

        $token = '123456789:ABCDEFGHIJKLMNOPQRSTUVWXYZ1234567890';

        $response = $this->actingAs($owner)->putJson('/api/alerts/telegram-config', [
            'bot_token' => $token,
            'group_id' => null,
            'is_enabled' => true,
            'fallback_to_in_app' => true,
        ]);

        $response
            ->assertOk()
            ->assertJsonPath('ok', true)
            ->assertJsonPath('data.telegram.has_token', true)
            ->assertJsonPath('data.telegram.has_group_id', false)
            ->assertJsonPath('data.telegram.has_chat_id', false);
    }

    public function test_user_can_toggle_options_without_reentering_token(): void
    {
        $owner = User::factory()->create([
            'role' => UserRole::Owner->value,
            'path' => '1',
        ]);

        $token = '123456789:ABCDEFGHIJKLMNOPQRSTUVWXYZ1234567890';

        $this->actingAs($owner)->putJson('/api/alerts/telegram-config', [
            'bot_token' => $token,
            'group_id' => '-1005223515097',
            'is_enabled' => true,
            'fallback_to_in_app' => true,
        ])->assertOk();

        $response = $this->actingAs($owner)->putJson('/api/alerts/telegram-config', [
            'is_enabled' => false,
            'fallback_to_in_app' => false,
            'group_id' => '-1005223515097',
        ]);

        $response
            ->assertOk()
            ->assertJsonPath('ok', true)
            ->assertJsonPath('data.telegram.has_token', true)
            ->assertJsonPath('data.telegram.enabled', false)
            ->assertJsonPath('data.telegram.fallback_to_in_app', false);
    }

    public function test_group_id_from_web_telegram_link_is_parsed_and_kept(): void
    {
        $owner = User::factory()->create([
            'role' => UserRole::Owner->value,
            'path' => '1',
        ]);

        $this->actingAs($owner)->putJson('/api/alerts/telegram-config', [
            'bot_token' => '123456789:ABCDEFGHIJKLMNOPQRSTUVWXYZ1234567890',
            'group_id' => 'https://web.telegram.org/a/#-5223515097',
            'is_enabled' => true,
            'fallback_to_in_app' => true,
        ])->assertOk()->assertJsonPath('data.telegram.group_id', '-5223515097');
    }

    public function test_unauthenticated_request_is_rejected_for_telegram_config_api(): void
    {
        $this->getJson('/api/alerts/telegram-config')->assertStatus(401);
        $this->putJson('/api/alerts/telegram-config', [])->assertStatus(401);
        $this->postJson('/api/alerts/telegram-config/test', [])->assertStatus(401);
        $this->deleteJson('/api/alerts/telegram-config')->assertStatus(401);
    }

    public function test_ctv_can_manage_own_telegram_config(): void
    {
        $ctv = User::factory()->create([
            'role' => UserRole::CTV->value,
            'path' => '1/2',
        ]);

        $response = $this->actingAs($ctv)->putJson('/api/alerts/telegram-config', [
            'bot_token' => '123456789:ABCDEFGHIJKLMNOPQRSTUVWXYZ1234567890',
            'group_id' => '-1005223515097',
            'is_enabled' => true,
            'fallback_to_in_app' => true,
        ]);

        $response
            ->assertOk()
            ->assertJsonPath('ok', true)
            ->assertJsonPath('data.telegram.has_token', true);
    }

    public function test_user_can_test_message_with_override_token_and_group_chat_id_without_saving_config(): void
    {
        $owner = User::factory()->create([
            'role' => UserRole::Owner->value,
            'path' => '1',
        ]);

        Http::fake([
            'https://api.telegram.org/*/sendMessage' => function ($request) {
                $chatId = (string) ($request['chat_id'] ?? '');

                if ($chatId === '-5223515097') {
                    return Http::response([
                        'ok' => false,
                        'description' => 'Bad Request: chat not found',
                    ], 400);
                }

                return Http::response(['ok' => true], 200);
            },
        ]);

        $this->actingAs($owner)
            ->postJson('/api/alerts/telegram-config/test', [
                'bot_token' => '123456789:ABCDEFGHIJKLMNOPQRSTUVWXYZ1234567890',
                'group_id' => 'https://web.telegram.org/a/#-5223515097',
                'message' => 'hello group test',
            ])
            ->assertOk()
            ->assertJsonPath('ok', true);

        Http::assertSent(function ($request): bool {
            return str_contains((string) $request->url(), '/sendMessage')
                && in_array((string) ($request['chat_id'] ?? ''), ['-5223515097', '-1005223515097'], true);
        });
    }

    public function test_user_can_test_message_without_chat_id_by_resolving_from_get_updates(): void
    {
        $owner = User::factory()->create([
            'role' => UserRole::Owner->value,
            'path' => '1',
        ]);

        Http::fake([
            'https://api.telegram.org/*/getUpdates*' => Http::response([
                'ok' => true,
                'result' => [
                    ['update_id' => 1, 'message' => ['chat' => ['id' => -1005223515097]]],
                ],
            ], 200),
            'https://api.telegram.org/*/sendMessage' => Http::response(['ok' => true], 200),
        ]);

        $this->actingAs($owner)
            ->postJson('/api/alerts/telegram-config/test', [
                'bot_token' => '123456789:ABCDEFGHIJKLMNOPQRSTUVWXYZ1234567890',
                'message' => 'hello auto chat',
            ])
            ->assertOk()
            ->assertJsonPath('ok', true);
    }

    public function test_alert_rule_requests_reject_unsupported_values(): void
    {
        $owner = User::factory()->create([
            'role' => UserRole::Owner->value,
            'path' => '1',
        ]);

        $this->actingAs($owner)
            ->postJson('/api/alerts/rules', [
                'name' => 'Invalid metric',
                'metric' => 'unsupported_metric',
                'operator' => '>=',
                'threshold' => 1,
                'channel' => 'in_app',
                'is_active' => true,
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['metric']);

        $this->actingAs($owner)
            ->postJson('/api/alerts/rules', [
                'name' => 'Invalid operator',
                'metric' => 'sync_failed_24h',
                'operator' => 'contains',
                'threshold' => 1,
                'channel' => 'in_app',
                'is_active' => true,
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['operator']);

        $this->actingAs($owner)
            ->postJson('/api/alerts/rules', [
                'name' => 'Invalid channel',
                'metric' => 'sync_failed_24h',
                'operator' => '>=',
                'threshold' => 1,
                'channel' => 'email',
                'is_active' => true,
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['channel']);

        $this->actingAs($owner)
            ->postJson('/api/alerts/telegram-config/test', [
                'group_id' => 'not-a-valid-chat-id',
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['group_id']);
    }

    public function test_evaluate_dispatches_telegram_job_when_config_enabled(): void
    {
        Queue::fake();

        $owner = User::factory()->create([
            'role' => UserRole::Owner->value,
            'path' => '1',
        ]);

        SyncRun::query()->create([
            'user_id' => $owner->id,
            'integration' => 'shopee',
            'type' => 'manual',
            'status' => 'failed_auth',
            'started_at' => now()->subMinutes(10),
            'finished_at' => now()->subMinutes(9),
            'records_fetched' => 0,
            'records_upserted' => 0,
            'records_failed' => 1,
            'error_message' => 'Auth failed',
        ]);

        UserTelegramConfig::query()->create([
            'user_id' => $owner->id,
            'bot_token' => '123456789:ABCDEFGHIJKLMNOPQRSTUVWXYZ1234567890',
            'group_id' => '-1005223515097',
            'is_enabled' => true,
            'fallback_to_in_app' => true,
        ]);

        AlertRule::query()->create([
            'user_id' => $owner->id,
            'name' => 'Sync failed',
            'metric' => 'sync_failed_24h',
            'operator' => '>=',
            'threshold' => 1,
            'channel' => 'telegram',
            'is_active' => true,
        ]);

        $this->actingAs($owner)
            ->postJson('/api/alerts/evaluate')
            ->assertOk()
            ->assertJsonPath('ok', true);

        Queue::assertPushed(SendTelegramAlertJob::class, 1);
    }

    public function test_evaluate_does_not_dispatch_telegram_job_when_config_is_disabled(): void
    {
        Queue::fake();

        $owner = User::factory()->create([
            'role' => UserRole::Owner->value,
            'path' => '1',
        ]);

        SyncRun::query()->create([
            'user_id' => $owner->id,
            'integration' => 'shopee',
            'type' => 'manual',
            'status' => 'failed_auth',
            'started_at' => now()->subMinutes(10),
            'finished_at' => now()->subMinutes(9),
            'records_fetched' => 0,
            'records_upserted' => 0,
            'records_failed' => 1,
            'error_message' => 'Auth failed',
        ]);

        UserTelegramConfig::query()->create([
            'user_id' => $owner->id,
            'bot_token' => '123456789:ABCDEFGHIJKLMNOPQRSTUVWXYZ1234567890',
            'group_id' => '-1005223515097',
            'is_enabled' => false,
            'fallback_to_in_app' => true,
        ]);

        AlertRule::query()->create([
            'user_id' => $owner->id,
            'name' => 'Sync failed',
            'metric' => 'sync_failed_24h',
            'operator' => '>=',
            'threshold' => 1,
            'channel' => 'telegram',
            'is_active' => true,
        ]);

        $this->actingAs($owner)
            ->postJson('/api/alerts/evaluate')
            ->assertOk()
            ->assertJsonPath('ok', true);

        Queue::assertNotPushed(SendTelegramAlertJob::class);
    }
}
