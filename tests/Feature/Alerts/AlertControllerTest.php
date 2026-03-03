<?php

declare(strict_types=1);

namespace Tests\Feature\Alerts;

use App\Enums\UserRole;
use App\Models\AlertIncident;
use App\Models\AlertRule;
use App\Models\SyncRun;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AlertControllerTest extends TestCase
{
    use RefreshDatabase;

    public function test_owner_can_create_rule_and_evaluate_creates_incident(): void
    {
        $owner = User::factory()->create([
            'role' => UserRole::Owner->value,
            'path' => '1',
        ]);

        SyncRun::query()->create([
            'user_id' => $owner->id,
            'integration' => 'shopee',
            'type' => 'manual',
            'status' => 'failed_auth',
            'started_at' => now()->subHour(),
            'finished_at' => now()->subHour()->addMinutes(1),
            'records_fetched' => 0,
            'records_upserted' => 0,
            'records_failed' => 1,
            'error_message' => 'Auth failed',
        ]);

        $createResponse = $this->actingAs($owner)->postJson('/api/alerts/rules', [
            'name' => 'Sync failed > 0',
            'metric' => 'sync_failed_24h',
            'operator' => '>=',
            'threshold' => 1,
            'channel' => 'in_app',
            'is_active' => true,
        ]);

        $createResponse->assertStatus(201)->assertJsonPath('ok', true);
        $ruleId = (int) $createResponse->json('data.id');

        $evalResponse = $this->actingAs($owner)->postJson('/api/alerts/evaluate');
        $evalResponse->assertOk()->assertJsonPath('ok', true);

        $this->assertDatabaseHas('alert_incidents', [
            'alert_rule_id' => $ruleId,
            'user_id' => $owner->id,
        ]);

        $this->actingAs($owner)
            ->get('/alerts')
            ->assertOk();
    }

    public function test_ctv_cannot_manage_alert_rules(): void
    {
        $ctv = User::factory()->create([
            'role' => UserRole::CTV->value,
            'path' => '1/2',
        ]);

        $response = $this->actingAs($ctv)->postJson('/api/alerts/rules', [
            'name' => 'CTV rule',
            'metric' => 'sync_failed_24h',
            'operator' => '>=',
            'threshold' => 1,
            'channel' => 'in_app',
        ]);

        $response->assertStatus(403);
    }

    public function test_user_cannot_view_other_users_incident_detail(): void
    {
        $ownerA = User::factory()->create([
            'role' => UserRole::Owner->value,
            'path' => '1',
        ]);
        $ownerB = User::factory()->create([
            'role' => UserRole::Owner->value,
            'path' => '2',
        ]);

        $rule = AlertRule::query()->create([
            'user_id' => $ownerA->id,
            'name' => 'Rule A',
            'metric' => 'sync_failed_24h',
            'operator' => '>=',
            'threshold' => 1,
            'channel' => 'in_app',
            'is_active' => true,
        ]);

        $incident = AlertIncident::query()->create([
            'alert_rule_id' => $rule->id,
            'user_id' => $ownerA->id,
            'triggered_value' => 3,
            'message' => 'Rule A triggered',
            'context' => ['metric' => 'sync_failed_24h'],
        ]);

        $this->actingAs($ownerA)
            ->get('/alerts/incidents/' . $incident->id)
            ->assertOk();

        $this->actingAs($ownerB)
            ->get('/alerts/incidents/' . $incident->id)
            ->assertStatus(404);
    }

    public function test_owner_can_mark_seen_and_resolve_incident(): void
    {
        $owner = User::factory()->create([
            'role' => UserRole::Owner->value,
            'path' => '1',
        ]);

        $rule = AlertRule::query()->create([
            'user_id' => $owner->id,
            'name' => 'Rule A',
            'metric' => 'sync_failed_24h',
            'operator' => '>=',
            'threshold' => 1,
            'channel' => 'in_app',
            'is_active' => true,
        ]);

        $incident = AlertIncident::query()->create([
            'alert_rule_id' => $rule->id,
            'user_id' => $owner->id,
            'triggered_value' => 3,
            'message' => 'Rule A triggered',
            'context' => ['metric' => 'sync_failed_24h'],
        ]);

        $this->actingAs($owner)
            ->postJson('/api/alerts/incidents/' . $incident->id . '/seen')
            ->assertOk()
            ->assertJsonPath('ok', true)
            ->assertJsonPath('data.id', $incident->id);

        $this->assertDatabaseHas('alert_incidents', [
            'id' => $incident->id,
        ]);
        $this->assertNotNull($incident->fresh()?->seen_at);

        $this->actingAs($owner)
            ->postJson('/api/alerts/incidents/' . $incident->id . '/resolve')
            ->assertOk()
            ->assertJsonPath('ok', true)
            ->assertJsonPath('data.id', $incident->id);

        $refreshed = $incident->fresh();
        $this->assertNotNull($refreshed?->seen_at);
        $this->assertNotNull($refreshed?->resolved_at);
    }

    public function test_opening_incident_detail_marks_seen_automatically(): void
    {
        $owner = User::factory()->create([
            'role' => UserRole::Owner->value,
            'path' => '1',
        ]);

        $rule = AlertRule::query()->create([
            'user_id' => $owner->id,
            'name' => 'Rule A',
            'metric' => 'sync_failed_24h',
            'operator' => '>=',
            'threshold' => 1,
            'channel' => 'in_app',
            'is_active' => true,
        ]);

        $incident = AlertIncident::query()->create([
            'alert_rule_id' => $rule->id,
            'user_id' => $owner->id,
            'triggered_value' => 3,
            'message' => 'Rule A triggered',
            'context' => ['metric' => 'sync_failed_24h'],
            'seen_at' => null,
        ]);

        $this->actingAs($owner)
            ->get('/alerts/incidents/' . $incident->id)
            ->assertOk();

        $this->assertNotNull($incident->fresh()?->seen_at);
    }

    public function test_resolve_with_comment_does_not_create_clone_while_metric_still_triggered(): void
    {
        $owner = User::factory()->create([
            'role' => UserRole::Owner->value,
            'path' => '1',
        ]);

        SyncRun::query()->create([
            'user_id' => $owner->id,
            'integration' => 'shopee',
            'type' => 'manual',
            'status' => 'failed_auth',
            'started_at' => now()->subHour(),
            'finished_at' => now()->subHour()->addMinutes(1),
            'records_fetched' => 0,
            'records_upserted' => 0,
            'records_failed' => 1,
            'error_message' => 'Auth failed',
        ]);

        $rule = AlertRule::query()->create([
            'user_id' => $owner->id,
            'name' => 'Sync failed > 0',
            'metric' => 'sync_failed_24h',
            'operator' => '>=',
            'threshold' => 1,
            'channel' => 'in_app',
            'is_active' => true,
        ]);

        $this->actingAs($owner)
            ->postJson('/api/alerts/evaluate')
            ->assertOk()
            ->assertJsonPath('ok', true);

        $incident = AlertIncident::query()
            ->where('alert_rule_id', $rule->id)
            ->latest('id')
            ->firstOrFail();

        $this->actingAs($owner)
            ->postJson('/api/alerts/incidents/' . $incident->id . '/resolve', [
                'comment' => 'Đã kiểm tra và tạm đóng sự cố.',
            ])
            ->assertOk()
            ->assertJsonPath('ok', true);

        $resolved = $incident->fresh();
        $this->assertNotNull($resolved?->resolved_at);
        $this->assertTrue((bool) ($resolved?->context['manual_resolve_lock'] ?? false));
        $this->assertSame('Đã kiểm tra và tạm đóng sự cố.', $resolved?->context['comments'][0]['comment'] ?? null);

        $this->actingAs($owner)
            ->postJson('/api/alerts/evaluate')
            ->assertOk()
            ->assertJsonPath('ok', true);

        $this->assertSame(
            1,
            AlertIncident::query()->where('alert_rule_id', $rule->id)->count(),
            'Resolved incidents should not be cloned while metric is still triggered and manual lock is active.'
        );
    }

    public function test_owner_can_add_comment_on_incident_detail(): void
    {
        $owner = User::factory()->create([
            'role' => UserRole::Owner->value,
            'path' => '1',
        ]);

        $rule = AlertRule::query()->create([
            'user_id' => $owner->id,
            'name' => 'Rule A',
            'metric' => 'sync_failed_24h',
            'operator' => '>=',
            'threshold' => 1,
            'channel' => 'in_app',
            'is_active' => true,
        ]);

        $incident = AlertIncident::query()->create([
            'alert_rule_id' => $rule->id,
            'user_id' => $owner->id,
            'triggered_value' => 2,
            'message' => 'Rule A triggered',
            'context' => ['metric' => 'sync_failed_24h'],
        ]);

        $this->actingAs($owner)
            ->postJson('/api/alerts/incidents/' . $incident->id . '/comment', [
                'comment' => 'Đã xác minh nguyên nhân.',
            ])
            ->assertOk()
            ->assertJsonPath('ok', true);

        $refreshed = $incident->fresh();
        $this->assertSame('Đã xác minh nguyên nhân.', $refreshed?->context['comments'][0]['comment'] ?? null);
    }
}
