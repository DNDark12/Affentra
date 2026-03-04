<?php

declare(strict_types=1);

namespace Tests\Feature\AI;

use App\Enums\LinkStatus;
use App\Models\ContentGeneration;
use App\Models\TrackingLink;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Feature tests for the async generation status endpoint
 * and status mapping (provider → internal).
 */
class AsyncGenerationFlowTest extends TestCase
{
    use RefreshDatabase;

    private function createLink(User $user): TrackingLink
    {
        return TrackingLink::create([
            'user_id'         => $user->id,
            'short_code'      => 'test' . uniqid(),
            'destination_url' => 'https://example.com',
            'status'          => LinkStatus::Active,
        ]);
    }

    // ─── Status Endpoint ────────────────────────────────────────────────────

    public function test_status_endpoint_returns_queued_generation(): void
    {
        $user = User::factory()->create();
        $link = $this->createLink($user);

        $generation = ContentGeneration::create([
            'user_id'          => $user->id,
            'tracking_link_id' => $link->id,
            'type'             => 'video',
            'platform'         => 'generic',
            'status'           => 'queued',
            'provider_task_id' => 'task_poll_test',
            'provider_status'  => 'queued',
            'poll_attempts'    => 2,
            'ai_provider'      => 'seedance',
            'ai_model'         => 'seedance-2.0',
        ]);

        $response = $this->actingAs($user)
            ->getJson("/api/content-generations/{$generation->id}/status");

        $response->assertOk()
            ->assertJsonPath('ok', true)
            ->assertJsonPath('data.status', 'queued')
            ->assertJsonPath('data.provider_status', 'queued')
            ->assertJsonPath('data.poll_attempts', 2)
            ->assertJsonPath('data.output', null)
            ->assertJsonPath('data.error_message', null);
    }

    public function test_status_endpoint_returns_succeeded_with_media(): void
    {
        $user = User::factory()->create();
        $link = $this->createLink($user);

        $generation = ContentGeneration::create([
            'user_id'          => $user->id,
            'tracking_link_id' => $link->id,
            'type'             => 'video',
            'platform'         => 'generic',
            'status'           => 'succeeded',
            'provider_task_id' => 'task_done',
            'provider_status'  => 'completed',
            'poll_attempts'    => 12,
            'provider_completed_at' => now(),
            'ai_provider'      => 'seedance',
            'ai_model'         => 'seedance-2.0',
            'output_payload'   => [
                'media' => [
                    ['type' => 'video', 'url' => 'https://cdn.test/video.mp4', 'provider' => 'seedance'],
                ],
            ],
        ]);

        $response = $this->actingAs($user)
            ->getJson("/api/content-generations/{$generation->id}/status");

        $response->assertOk()
            ->assertJsonPath('data.status', 'succeeded')
            ->assertJsonPath('data.provider_status', 'completed')
            ->assertJsonPath('data.output.media.0.url', 'https://cdn.test/video.mp4')
            ->assertJsonPath('data.output.media.0.type', 'video')
            ->assertJsonPath('data.poll_attempts', 12);
    }

    public function test_status_endpoint_returns_failed_with_error(): void
    {
        $user = User::factory()->create();
        $link = $this->createLink($user);

        $generation = ContentGeneration::create([
            'user_id'          => $user->id,
            'tracking_link_id' => $link->id,
            'type'             => 'video',
            'platform'         => 'generic',
            'status'           => 'failed',
            'provider_task_id' => 'task_err',
            'provider_status'  => 'failed',
            'poll_attempts'    => 8,
            'provider_completed_at' => now(),
            'ai_provider'      => 'seedance',
            'ai_model'         => 'seedance-2.0',
            'error_code'       => 'PROVIDER_FAILED',
            'error_message'    => 'Content policy violation.',
        ]);

        $response = $this->actingAs($user)
            ->getJson("/api/content-generations/{$generation->id}/status");

        $response->assertOk()
            ->assertJsonPath('data.status', 'failed')
            ->assertJsonPath('data.error_message', 'Content policy violation.');
    }

    // ─── Authorization ──────────────────────────────────────────────────────

    public function test_status_endpoint_returns_404_for_other_users_generation(): void
    {
        $owner  = User::factory()->create();
        $other  = User::factory()->create();
        $link   = $this->createLink($owner);

        $generation = ContentGeneration::create([
            'user_id'          => $owner->id,
            'tracking_link_id' => $link->id,
            'type'             => 'video',
            'platform'         => 'generic',
            'status'           => 'queued',
            'provider_task_id' => 'task_auth',
            'provider_status'  => 'queued',
            'ai_provider'      => 'seedance',
            'ai_model'         => 'seedance-2.0',
        ]);

        $response = $this->actingAs($other)
            ->getJson("/api/content-generations/{$generation->id}/status");

        $response->assertNotFound();
    }

    // ─── Status Mapping: provider completed → internal succeeded ─────────

    public function test_status_mapping_provider_completed_is_internal_succeeded(): void
    {
        $user = User::factory()->create();
        $link = $this->createLink($user);

        $generation = ContentGeneration::create([
            'user_id'          => $user->id,
            'tracking_link_id' => $link->id,
            'type'             => 'video',
            'platform'         => 'generic',
            'status'           => 'succeeded',        // internal
            'provider_status'  => 'completed',         // provider raw
            'provider_task_id' => 'task_map_1',
            'ai_provider'      => 'seedance',
            'ai_model'         => 'seedance-2.0',
        ]);

        $response = $this->actingAs($user)
            ->getJson("/api/content-generations/{$generation->id}/status");

        $data = $response->json('data');

        // Verify the mapping is consistent
        $this->assertEquals('succeeded', $data['status']);          // internal
        $this->assertEquals('completed', $data['provider_status']); // raw
    }

    public function test_status_mapping_provider_failed_is_internal_failed(): void
    {
        $user = User::factory()->create();
        $link = $this->createLink($user);

        $generation = ContentGeneration::create([
            'user_id'          => $user->id,
            'tracking_link_id' => $link->id,
            'type'             => 'video',
            'platform'         => 'generic',
            'status'           => 'failed',
            'provider_status'  => 'failed',
            'provider_task_id' => 'task_map_2',
            'ai_provider'      => 'seedance',
            'ai_model'         => 'seedance-2.0',
        ]);

        $response = $this->actingAs($user)
            ->getJson("/api/content-generations/{$generation->id}/status");

        $data = $response->json('data');

        $this->assertEquals('failed', $data['status']);
        $this->assertEquals('failed', $data['provider_status']);
    }
}
