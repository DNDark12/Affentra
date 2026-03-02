<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ClickAnalyticsApiEnvelopeTest extends TestCase
{
    use RefreshDatabase;

    public function test_summary_endpoint_returns_standard_envelope(): void
    {
        $user = User::factory()->create(['role' => 'owner']);

        $response = $this->actingAs($user)->getJson('/api/analytics/clicks/summary');

        $response->assertOk()
            ->assertJsonPath('ok', true)
            ->assertJsonPath('message', null)
            ->assertJsonPath('errors', null)
            ->assertJsonStructure([
                'ok',
                'data' => [
                    'funnel' => ['clicks', 'orders', 'approved'],
                    'totals' => ['clicks', 'unique_clicks', 'valid_clicks', 'bot_clicks', 'orders', 'approved', 'commission'],
                    'cvr',
                    'approved_rate',
                    'epc',
                    'trend_by_day',
                    'campaign_breakdown',
                ],
                'message',
                'errors',
            ]);
    }

    public function test_report_endpoint_returns_standard_envelope(): void
    {
        $user = User::factory()->create(['role' => 'owner']);

        $response = $this->actingAs($user)->getJson('/api/analytics/clicks/report');

        $response->assertOk()
            ->assertJsonPath('ok', true)
            ->assertJsonPath('message', null)
            ->assertJsonPath('errors', null)
            ->assertJsonStructure([
                'ok',
                'data' => [
                    'items',
                    'pagination' => [
                        'current_page',
                        'per_page',
                        'total',
                        'last_page',
                        'from',
                        'to',
                        'has_more_pages',
                    ],
                ],
                'message',
                'errors',
            ]);
    }

    public function test_conversion_endpoint_returns_standard_envelope(): void
    {
        $user = User::factory()->create(['role' => 'owner']);

        $response = $this->actingAs($user)->getJson('/api/analytics/clicks/conversion');

        $response->assertOk()
            ->assertJsonPath('ok', true)
            ->assertJsonPath('message', null)
            ->assertJsonPath('errors', null)
            ->assertJsonStructure([
                'ok',
                'data' => [
                    'items',
                    'pagination' => [
                        'current_page',
                        'per_page',
                        'total',
                        'last_page',
                        'from',
                        'to',
                        'has_more_pages',
                    ],
                ],
                'message',
                'errors',
            ]);
    }
}
