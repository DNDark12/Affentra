<?php

declare(strict_types=1);

namespace Tests\Feature\AI;

use App\Enums\LinkStatus;
use App\Jobs\AI\PollSeedanceTaskJob;
use App\Models\AiProviderSetting;
use App\Models\ContentGeneration;
use App\Models\TrackingLink;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Feature tests for PollSeedanceTaskJob lifecycle.
 *
 * Uses Http::fake() to simulate Seedance API responses.
 * Creates real AiProviderSetting records so buildClient() works.
 */
class PollSeedanceTaskJobTest extends TestCase
{
    use RefreshDatabase;

    private function createGenerationWithProvider(array $overrides = []): ContentGeneration
    {
        $user = User::factory()->create();
        $link = TrackingLink::create([
            'user_id'         => $user->id,
            'short_code'      => 'test' . uniqid(),
            'destination_url' => 'https://example.com',
            'status'          => LinkStatus::Active,
        ]);

        // Create provider setting so job's buildClient() resolves
        $setting = AiProviderSetting::create([
            'user_id'       => $user->id,
            'provider_key'  => 'seedance',
            'status'        => 'enabled',
            'default_model' => 'doubao-seedance-2-0',
        ]);
        $setting->setCredentials([
            'api_key'  => 'test-api-key',
            'base_url' => 'https://seedance.test/v1',
        ]);

        return ContentGeneration::create(array_merge([
            'user_id'          => $user->id,
            'tracking_link_id' => $link->id,
            'type'             => 'video',
            'platform'         => 'generic',
            'status'           => 'queued',
            'provider_task_id' => 'task_test_123',
            'provider_status'  => 'queued',
            'poll_attempts'    => 0,
            'ai_provider'      => 'seedance',
            'ai_model'         => 'doubao-seedance-2-0',
        ], $overrides));
    }

    // ─── Completed → Succeeded ──────────────────────────────────────────────

    public function test_updates_generation_to_succeeded_on_provider_completed(): void
    {
        Http::fake([
            'seedance.test/*' => Http::response([
                'data' => [
                    'status'    => 'completed',
                    'video_url' => 'https://cdn.seedance.test/video.mp4',
                    'progress'  => 100,
                ],
                'error' => null,
            ], 200),
        ]);

        $generation = $this->createGenerationWithProvider();

        $job = new PollSeedanceTaskJob($generation->id);
        $job->handle();

        $generation->refresh();

        $this->assertEquals('succeeded', $generation->status);
        $this->assertEquals('completed', $generation->provider_status);
        $this->assertNotNull($generation->provider_completed_at);
        $this->assertEquals(
            'https://cdn.seedance.test/video.mp4',
            $generation->output_payload['media'][0]['url'] ?? null,
        );
    }

    // ─── Failed → Failed ────────────────────────────────────────────────────

    public function test_updates_generation_to_failed_on_provider_failed(): void
    {
        Http::fake([
            'seedance.test/*' => Http::response([
                'data' => [
                    'status' => 'failed',
                    'error'  => 'Content policy violation.',
                ],
                'error' => null,
            ], 200),
        ]);

        $generation = $this->createGenerationWithProvider();

        $job = new PollSeedanceTaskJob($generation->id);
        $job->handle();

        $generation->refresh();

        $this->assertEquals('failed', $generation->status);
        $this->assertEquals('failed', $generation->provider_status);
        $this->assertNotNull($generation->provider_completed_at);
        $this->assertEquals('Content policy violation.', $generation->error_message);
    }

    // ─── Terminal Skip (Idempotency) ────────────────────────────────────────

    public function test_skips_processing_when_generation_is_terminal(): void
    {
        $generation = $this->createGenerationWithProvider(['status' => 'succeeded']);

        $job = new PollSeedanceTaskJob($generation->id);
        $job->handle();

        // Should NOT make any API call since status is terminal
        Http::assertNothingSent();
    }

    // ─── Missing Provider Task ID ───────────────────────────────────────────

    public function test_fails_when_provider_task_id_missing(): void
    {
        $generation = $this->createGenerationWithProvider([
            'provider_task_id' => null,
        ]);

        $job = new PollSeedanceTaskJob($generation->id);
        $job->handle();

        $generation->refresh();

        $this->assertEquals('failed', $generation->status);
        $this->assertEquals('MISSING_TASK_ID', $generation->error_code);
    }

    // ─── Poll Attempts Increment ────────────────────────────────────────────

    public function test_increments_poll_attempts_before_api_call(): void
    {
        Http::fake([
            'seedance.test/*' => Http::response([
                'data' => [
                    'status'   => 'processing',
                    'progress' => 50,
                ],
                'error' => null,
            ], 200),
        ]);

        $generation = $this->createGenerationWithProvider(['poll_attempts' => 5]);

        $job = new PollSeedanceTaskJob($generation->id);
        $job->handle();

        $generation->refresh();

        $this->assertEquals(6, $generation->poll_attempts);
        $this->assertEquals('processing', $generation->provider_status);
    }

    // ─── Unique Job ID ──────────────────────────────────────────────────────

    public function test_unique_id_includes_generation_id(): void
    {
        $job = new PollSeedanceTaskJob(42);

        $this->assertEquals('seedance-poll-42', $job->uniqueId());
    }
}
