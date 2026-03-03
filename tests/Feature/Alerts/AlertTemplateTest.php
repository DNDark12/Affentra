<?php

declare(strict_types=1);

namespace Tests\Feature\Alerts;

use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AlertTemplateTest extends TestCase
{
    use RefreshDatabase;

    public function test_authenticated_user_can_view_templates_page_and_defaults(): void
    {
        $user = User::factory()->create([
            'role' => UserRole::Owner->value,
            'path' => '1',
        ]);

        $this->actingAs($user)
            ->get('/alerts/templates')
            ->assertOk();

        $response = $this->actingAs($user)
            ->getJson('/api/alerts/templates')
            ->assertOk()
            ->assertJsonPath('ok', true);

        $this->assertCount(5, $response->json('data.metric_options'));
        $this->assertArrayHasKey('sync_failed_24h', (array) $response->json('data.templates'));
    }

    public function test_user_can_save_single_template_and_restore_default(): void
    {
        $user = User::factory()->create([
            'role' => UserRole::Leader->value,
            'path' => '1/2',
        ]);

        $this->actingAs($user)
            ->putJson('/api/alerts/templates/sync_failed_24h', [
                'in_app_template' => 'INAPP {metric_label} {triggered_value}',
                'telegram_template' => 'TG {metric_label} {triggered_value}',
            ])
            ->assertOk()
            ->assertJsonPath('ok', true);

        $this->assertDatabaseHas('alert_message_templates', [
            'user_id' => $user->id,
            'metric' => 'sync_failed_24h',
            'in_app_template' => 'INAPP {metric_label} {triggered_value}',
            'telegram_template' => 'TG {metric_label} {triggered_value}',
        ]);

        $this->actingAs($user)
            ->postJson('/api/alerts/templates/sync_failed_24h/restore')
            ->assertOk()
            ->assertJsonPath('ok', true);

        $this->assertDatabaseMissing('alert_message_templates', [
            'user_id' => $user->id,
            'metric' => 'sync_failed_24h',
        ]);
    }

    public function test_user_can_save_all_dirty_templates_in_one_request(): void
    {
        $user = User::factory()->create([
            'role' => UserRole::Owner->value,
            'path' => '1',
        ]);

        $this->actingAs($user)
            ->putJson('/api/alerts/templates', [
                'templates' => [
                    [
                        'metric' => 'sync_failed_24h',
                        'in_app_template' => 'A1',
                        'telegram_template' => 'B1',
                    ],
                    [
                        'metric' => 'pending_payouts',
                        'in_app_template' => 'A2',
                        'telegram_template' => 'B2',
                    ],
                ],
            ])
            ->assertOk()
            ->assertJsonPath('ok', true);

        $this->assertDatabaseHas('alert_message_templates', [
            'user_id' => $user->id,
            'metric' => 'sync_failed_24h',
            'in_app_template' => 'A1',
            'telegram_template' => 'B1',
        ]);
        $this->assertDatabaseHas('alert_message_templates', [
            'user_id' => $user->id,
            'metric' => 'pending_payouts',
            'in_app_template' => 'A2',
            'telegram_template' => 'B2',
        ]);
    }

    public function test_invalid_metric_is_rejected(): void
    {
        $user = User::factory()->create([
            'role' => UserRole::Owner->value,
            'path' => '1',
        ]);

        $this->actingAs($user)
            ->putJson('/api/alerts/templates/not-supported', [
                'in_app_template' => 'A',
                'telegram_template' => 'B',
            ])
            ->assertStatus(422)
            ->assertJsonPath('ok', false);
    }

    public function test_unauthenticated_requests_are_rejected(): void
    {
        $this->getJson('/api/alerts/templates')->assertStatus(401);
        $this->putJson('/api/alerts/templates/sync_failed_24h', [])->assertStatus(401);
        $this->putJson('/api/alerts/templates', [])->assertStatus(401);
        $this->postJson('/api/alerts/templates/sync_failed_24h/restore')->assertStatus(401);
    }
}

