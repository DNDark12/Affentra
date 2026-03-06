<?php

declare(strict_types=1);

namespace App\Jobs\AI;

use App\Models\ContentGeneration;
use App\Services\AI\AiStatisticsCacheService;
use App\Services\AI\Providers\SeedanceClient;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Polls the Seedance API for async video generation task status.
 *
 * Architecture:
 *  - Only this job calls the Seedance API (FE never calls provider directly).
 *  - FE polls a local DB-read-only endpoint.
 *  - ShouldBeUnique prevents overlapping runs for the same generation.
 */
class PollSeedanceTaskJob implements ShouldQueue, ShouldBeUnique
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * Max number of poll attempts before giving up.
     */
    public int $tries = 30;

    /**
     * Escalating backoff (seconds). Laravel repeats last value after exhaustion.
     *
     * Total max wait: ~10 minutes (30 tries × avg ~20s).
     */
    public array $backoff = [10, 15, 20, 30];

    /**
     * Prevent overlapping job runs for the same generation (seconds).
     */
    public int $uniqueFor = 60;

    public function __construct(
        public readonly int $generationId,
    ) {}

    /**
     * Unique key to prevent duplicate jobs for the same generation.
     */
    public function uniqueId(): string
    {
        return "seedance-poll-{$this->generationId}";
    }

    public function handle(?AiStatisticsCacheService $statisticsCache = null): void
    {
        $statisticsCache ??= app(AiStatisticsCacheService::class);
        $generation = ContentGeneration::find($this->generationId);

        // ── Guard: missing or already terminal ────────────────────────────────
        if (! $generation) {
            Log::warning('PollSeedanceTaskJob: generation not found', [
                'generation_id' => $this->generationId,
            ]);
            return;
        }

        if ($generation->isTerminal()) {
            Log::info('PollSeedanceTaskJob: generation already terminal, skipping', [
                'generation_id' => $generation->id,
                'status'        => $generation->status,
            ]);
            return;
        }

        if (empty($generation->provider_task_id)) {
            Log::error('PollSeedanceTaskJob: no provider_task_id', [
                'generation_id' => $generation->id,
            ]);
            $generation->update([
                'status'        => 'failed',
                'error_code'    => 'MISSING_TASK_ID',
                'error_message' => 'Không tìm thấy provider_task_id để poll.',
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
            Log::error('PollSeedanceTaskJob: API call failed', [
                'generation_id' => $generation->id,
                'attempt'       => $generation->poll_attempts,
                'error'         => $e->getMessage(),
            ]);

            // Let Laravel retry with backoff rather than failing immediately
            $this->release($this->backoff[min($this->attempts() - 1, count($this->backoff) - 1)]);
            return;
        }

        $providerStatus = $result['status'] ?? 'unknown';

        // ── Map provider status → internal status ─────────────────────────────
        match ($providerStatus) {
            'completed' => $this->handleCompleted($generation, $result, $statisticsCache),
            'failed'    => $this->handleFailed($generation, $result, $statisticsCache),
            'queued', 'processing' => $this->handleProcessing($generation, $providerStatus),
            default => $this->handleProcessing($generation, $providerStatus),
        };
    }

    private function handleCompleted(
        ContentGeneration $generation,
        array $result,
        AiStatisticsCacheService $statisticsCache,
    ): void {
        $generation->update([
            'status'                => 'succeeded',
            'provider_status'       => 'completed',
            'provider_completed_at' => now(),
            'output_payload'        => [
                'media' => [
                    [
                        'type'     => 'video',
                        'url'      => $result['video_url'] ?? '',
                        'provider' => 'seedance',
                    ],
                ],
            ],
        ]);

        Log::info('PollSeedanceTaskJob: completed', [
            'generation_id' => $generation->id,
            'attempts'      => $generation->poll_attempts,
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
            'error_code'            => 'PROVIDER_FAILED',
            'error_message'         => $result['error'] ?? 'Seedance generation failed.',
        ]);

        Log::warning('PollSeedanceTaskJob: provider reported failure', [
            'generation_id' => $generation->id,
            'error'         => $result['error'] ?? null,
        ]);

        $statisticsCache->bumpFor((int) $generation->user_id, (int) $generation->tracking_link_id);
    }

    private function handleProcessing(ContentGeneration $generation, string $providerStatus): void
    {
        $generation->update([
            'provider_status' => $providerStatus,
            'status'          => $providerStatus === 'processing' ? 'processing' : $generation->status,
        ]);

        // Job will be retried automatically via $backoff
        $delay = $this->backoff[min($this->attempts() - 1, count($this->backoff) - 1)];
        $this->release($delay);
    }

    /**
     * Build a SeedanceClient from the generation's user settings.
     */
    private function buildClient(ContentGeneration $generation): SeedanceClient
    {
        $user    = $generation->user;
        $setting = $user->aiProviderSettings()
            ?->where('provider_key', 'seedance')
            ->where('status', 'enabled')
            ->first();

        if (! $setting) {
            throw new \RuntimeException('Seedance provider not configured for user.');
        }

        $creds = $setting->getCredentials();

        return new SeedanceClient(
            apiKey:  $creds['api_key'] ?? null,
            baseUrl: $creds['base_url'] ?? null,
            model:   $setting->default_model,
        );
    }
}
