<?php

declare(strict_types=1);

namespace App\Jobs\AI;

use App\Models\ContentGeneration;
use App\Services\AI\AiStatisticsCacheService;
use App\Services\AI\Providers\GeminiClient;
use App\Actions\AI\PersistGeneratedMediaAction;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Polls the Gemini API for async Veo video generation task status.
 *
 * Architecture:
 *  - Only this job calls the Gemini operation poll endpoint.
 *  - FE polls a local DB-read-only endpoint (same as Seedance flow).
 *  - ShouldBeUnique prevents overlapping runs for the same generation.
 *
 * Timeline: Veo 3.1 generates videos in approx. 2-5 minutes.
 * Total max wait: ~15 minutes (60 tries × avg ~15s backoff).
 */
class PollGeminiVideoJob implements ShouldQueue, ShouldBeUnique
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * Max number of poll attempts. Veo can take up to 5 minutes.
     * 60 attempts × ~15s avg = ~15 minutes ceiling.
     */
    public int $tries = 60;

    /**
     * Escalating backoff (seconds). Laravel repeats last value after exhaustion.
     */
    public array $backoff = [10, 15, 20, 30, 30];

    /**
     * Prevent overlapping job runs for the same generation.
     */
    public int $uniqueFor = 60;

    public function __construct(
        public readonly int $generationId,
    ) {}

    public function uniqueId(): string
    {
        return "gemini-video-poll-{$this->generationId}";
    }

    public function handle(
        ?AiStatisticsCacheService $statisticsCache = null,
        ?PersistGeneratedMediaAction $persistAction = null,
    ): void {
        $statisticsCache ??= app(AiStatisticsCacheService::class);
        $persistAction   ??= app(PersistGeneratedMediaAction::class);

        $generation = ContentGeneration::find($this->generationId);

        // ── Guard: missing or already terminal ───────────────────────────────
        if (! $generation) {
            Log::warning('PollGeminiVideoJob: generation not found', [
                'generation_id' => $this->generationId,
            ]);
            return;
        }

        if ($generation->isTerminal()) {
            Log::info('PollGeminiVideoJob: generation already terminal, skipping', [
                'generation_id' => $generation->id,
                'status'        => $generation->status,
            ]);
            return;
        }

        if (empty($generation->provider_task_id)) {
            Log::error('PollGeminiVideoJob: no provider_task_id (operation name) found', [
                'generation_id' => $generation->id,
            ]);
            $generation->update([
                'status'        => 'failed',
                'error_code'    => 'MISSING_TASK_ID',
                'error_message' => 'Không tìm thấy provider_task_id (Gemini operation name) để poll.',
            ]);
            return;
        }

        // ── Increment poll_attempts BEFORE calling API ────────────────────────
        $generation->increment('poll_attempts');

        // ── Build client and poll ─────────────────────────────────────────────
        try {
            $client = $this->buildClient($generation);
            $result = $client->checkTaskStatus($generation->provider_task_id);
        } catch (\Exception $e) {
            Log::error('PollGeminiVideoJob: API poll call failed', [
                'generation_id' => $generation->id,
                'attempt'       => $generation->poll_attempts,
                'operation'     => $generation->provider_task_id,
                'error'         => $e->getMessage(),
            ]);

            // Let Laravel retry with backoff rather than failing immediately
            $this->release($this->backoff[min($this->attempts() - 1, count($this->backoff) - 1)]);
            return;
        }

        $providerStatus = $result['status'] ?? 'unknown';

        // ── Route to appropriate handler ──────────────────────────────────────
        match ($providerStatus) {
            'completed'  => $this->handleCompleted($generation, $result, $statisticsCache, $persistAction),
            'failed'     => $this->handleFailed($generation, $result, $statisticsCache),
            'processing' => $this->handleProcessing($generation, $providerStatus),
            default      => $this->handleProcessing($generation, $providerStatus),
        };
    }

    private function handleCompleted(
        ContentGeneration $generation,
        array $result,
        AiStatisticsCacheService $statisticsCache,
        PersistGeneratedMediaAction $persistAction,
    ): void {
        $attrs     = $generation->prompt_attributes ?? [];
        $duration  = (int) ($attrs['duration_sec'] ?? $attrs['duration'] ?? 5);
        $videoUrl  = $result['video_url'] ?? '';

        $generation->update([
            'status'                => 'succeeded',
            'provider_status'       => 'completed',
            'provider_completed_at' => now(),
            'output_payload'        => [
                'media' => [
                    [
                        'type'     => 'video',
                        'url'      => $videoUrl,
                        'duration' => $duration,
                        'provider' => 'gemini',
                    ],
                ],
            ],
        ]);

        // PersistGeneratedMediaAction downloads the video from Gemini's URI and stores locally.
        // Token estimate: Veo charges per second of output.
        $persistAction->execute($generation, ['total_tokens' => $duration * 5]);

        Log::info('PollGeminiVideoJob: video completed and persisted', [
            'generation_id' => $generation->id,
            'attempts'      => $generation->poll_attempts,
            'video_url'     => $videoUrl,
        ]);

        $statisticsCache->bumpFor((int) $generation->user_id, (int) $generation->tracking_link_id);
    }

    private function handleFailed(
        ContentGeneration $generation,
        array $result,
        AiStatisticsCacheService $statisticsCache,
    ): void {
        $generation->update([
            'status'                => 'failed',
            'provider_status'       => 'failed',
            'provider_completed_at' => now(),
            'error_code'            => 'VEO_PROVIDER_FAILED',
            'error_message'         => $result['error'] ?? 'Gemini Veo generation failed.',
        ]);

        Log::warning('PollGeminiVideoJob: Veo reported failure', [
            'generation_id' => $generation->id,
            'error'         => $result['error'] ?? null,
        ]);

        $statisticsCache->bumpFor((int) $generation->user_id, (int) $generation->tracking_link_id);
    }

    private function handleProcessing(ContentGeneration $generation, string $providerStatus): void
    {
        $generation->update([
            'provider_status' => $providerStatus,
            'status'          => 'processing',
        ]);

        $delay = $this->backoff[min($this->attempts() - 1, count($this->backoff) - 1)];
        $this->release($delay);
    }

    /**
     * Build a GeminiClient from the generation user's stored provider settings.
     */
    private function buildClient(ContentGeneration $generation): GeminiClient
    {
        $user    = $generation->user;
        $setting = $user->aiProviderSettings()
            ?->where('provider_key', 'gemini')
            ->where('status', 'enabled')
            ->first();

        if (! $setting) {
            throw new \RuntimeException('Gemini provider not configured or disabled for user.');
        }

        $creds = $setting->getCredentials();

        return new GeminiClient(
            apiKey: $creds['api_key'] ?? null,
            model:  $generation->ai_model ?? $setting->default_model,
        );
    }

    /**
     * Handle job exhaustion after all retries.
     */
    public function failed(\Throwable $exception): void
    {
        Log::error('ai.poll_gemini_video_job.failed', [
            'generation_id' => $this->generationId,
            'error'         => $exception->getMessage(),
        ]);

        $generation = ContentGeneration::find($this->generationId);
        if ($generation && ! $generation->isTerminal()) {
            $generation->update([
                'status'        => 'failed',
                'error_code'    => 'POLL_TIMEOUT',
                'error_payload' => [
                    'message'   => 'Veo polling timed out after max attempts: ' . $exception->getMessage(),
                    'failed_at' => now()->toIso8601String(),
                ],
            ]);
        }
    }
}
