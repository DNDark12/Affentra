<?php

declare(strict_types=1);

namespace App\Services\AI;

use App\Models\ContentGeneration;
use App\Models\TrackingLink;
use App\Models\User;
use App\Services\AI\TextGenerationRunner;
use App\Services\AI\MediaGenerationRunner;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Redis;
use App\Jobs\AI\PollSeedanceTaskJob;
use Symfony\Component\HttpKernel\Exception\TooManyRequestsHttpException;

/**
 * ContentGenerationService
 *
 * Central orchestrator for all AI content generation requests.
 *
 * Responsibilities:
 *  1. Resolve per-user AI provider via AiSettingsService
 *  2. Token quota enforcement (Redis counter, per role/per day)
 *  3. Cache deduplication via prompt_hash (creates DB row but serves cached output)
 *  4. ContentGeneration row creation
 *  5. Route to TextGenerationRunner (sync) or future async job
 */
class ContentGenerationService
{
    /** Fallback token quotas per day by role if user has no custom quota. */
    private const DEFAULT_QUOTAS = [
        'partner' => 5_000,
        'leader' => 20_000,
        'owner'  => 100_000,
    ];

    private const DEDUP_CACHE_TTL = 300; // 5 minutes

    public function __construct(
        private readonly AiSettingsService      $aiSettings,
        private readonly PromptTemplateRegistry $registry,
        private readonly ImageFetcherService    $imageFetcher,
        private readonly AiStatisticsCacheService $statisticsCache,
    ) {}

    /**
     * Generate content for a tracking link.
     */
    public function generate(TrackingLink $link, User $user, array $payload): ContentGeneration
    {
        $templateId   = $payload['preset_id'] ?? 'fb_post';
        
        // Derive type & platform from Preset (since request no longer explicitly passes them)
        // Or in a real scenario, the template registry provides this info
        $preset = $this->registry->get($templateId);
        $type = $preset['type'] ?? 'text';
        $platform = $preset['platform'] ?? 'generic';
        
        $options      = $payload['options']  ?? [];
        $forceNewSeed = (bool) ($payload['force_new_seed'] ?? false);
        $imageUrls    = $payload['image_urls'] ?? [];
        
        // Use user-requested provider if exists, otherwise fallback to auto (handled by settings service)
        $providerKey  = $payload['provider_key'] ?? null;
        $modelName    = $payload['model'] ?? null;

        // 1. Resolve per-user provider client (throws RuntimeException if not configured)
        $client = $this->aiSettings->resolveClientForUser($user, $type, $providerKey, $modelName);

        // 2. Fetch images
        $base64Images = [];
        if (!empty($imageUrls)) {
            $base64Images = $this->imageFetcher->fetchAsBase64($imageUrls);
        }

        // 3. Build canonical attributes (sorted for stable hashing)
        $attributes = array_merge($options, [
            'platform'      => $platform,
            'tracking_url'  => route('redirect', $link->short_code),
            'product_title' => $options['product_title'] ?? $link->offer?->title ?? '',
            'product_price' => $options['product_price'] ?? '',
            'images'        => $base64Images, // Include fetched images in attributes
            'variant_count' => $payload['variant_count'] ?? 1
        ]);
        ksort($attributes);

        $promptHash = hash('sha256', $templateId . ':' . json_encode($attributes));

        // 3. Quota check
        $this->enforceTokenQuota($user);

        // 4. Dedup check
        $fromCache     = false;
        $cachedPayload = null;

        if (! $forceNewSeed) {
            $cacheKey      = "ai:gen:dedup:{$type}:{$promptHash}";
            $cachedPayload = Cache::get($cacheKey);
            $fromCache     = $cachedPayload !== null;
        }

        // 5. Create generation row (always; history must reflect user actions)
        $generation = ContentGeneration::create([
            'tracking_link_id'   => $link->id,
            'user_id'            => $user->id,
            'platform_connection_id' => $link->platform_connection_id,
            'type'               => $type,
            'platform'           => $platform,
            'status'             => 'running',
            'prompt_template_id' => $templateId,
            'prompt_attributes'  => $attributes,
            'prompt_hash'        => $promptHash,
            'force_new_seed'     => $forceNewSeed,
            'from_cache'         => $fromCache,
        ]);

        // 6. Serve from cache or execute
        if ($fromCache && $cachedPayload !== null) {
            $generation->update([
                'status'         => 'succeeded',
                'output_payload' => $cachedPayload,
            ]);

            $this->statisticsCache->bumpFor((int) $user->id, (int) $link->id);
            return $generation->refresh();
        }

        // ── Async path: provider returns task_id, poll later ──────────────
        if ($type === 'video' && $client->supportsAsyncMedia()) {
            $result = $client->generateMedia(
                $this->registry->render($templateId, $attributes),
                'video',
                $attributes,
            );

            $generation->update([
                'status'           => 'queued',
                'provider_task_id' => $result->meta['task_id'],
                'provider_status'  => 'queued',
                'ai_provider'      => $client->providerKey(),
                'ai_model'         => $client->modelKey(),
            ]);

            PollSeedanceTaskJob::dispatch($generation->id)
                ->delay(now()->addSeconds(10));

            $this->statisticsCache->bumpFor((int) $user->id, (int) $link->id);
            return $generation->refresh();
        }

        // ── Sync path: text generation ────────────────────────────────────
        // Build appropriate runner
        $runner = new TextGenerationRunner($client, $this->registry);
        $generation = $runner->run($generation);

        // 6.5 Best-effort media generation if requested
        foreach (['image' => 'generate_image', 'video' => 'generate_video'] as $mediaType => $payloadKey) {
            if ($generation->status === 'succeeded' && ($payload[$payloadKey] ?? false)) {
                try {
                    $mediaClient = $this->aiSettings->resolveClientForUser($user, $mediaType, $providerKey, $modelName);
                    $mediaRunner = new MediaGenerationRunner($mediaClient, $this->registry);
                    
                    // We run the media runner with the specific media type override
                    $generation = $mediaRunner->runMediaEnrichment($generation, $mediaType);
                } catch (\Exception $e) {
                    Log::warning("content_generation.{$mediaType}_failed_best_effort", [
                        'id' => $generation->id,
                        'error' => $e->getMessage()
                    ]);
                }
            }
        }

        // 7. Warm dedup cache on success
        if ($generation->status === 'succeeded' && ! $forceNewSeed) {
            Cache::put(
                "ai:gen:dedup:{$type}:{$promptHash}",
                $generation->output_payload,
                self::DEDUP_CACHE_TTL
            );
        }

        // 8. Track token usage in Redis
        if ($generation->tokens_completion > 0) {
            $this->trackTokenUsage($user, $generation->tokens_prompt + $generation->tokens_completion);
        }

        $this->statisticsCache->bumpFor((int) $user->id, (int) $link->id);
        return $generation;
    }

    // ── Private helpers ───────────────────────────────────────────────────────

    private function enforceTokenQuota(User $user): void
    {
        $roleValue = $user->role instanceof \UnitEnum ? $user->role->value : ($user->role ?? 'partner');
        $limit = (int) (self::DEFAULT_QUOTAS[$roleValue] ?? self::DEFAULT_QUOTAS['partner']);

        // Allow per-user quota override from their provider setting
        $setting = $this->aiSettings->getSettingsForUser($user)->first();
        if ($setting?->token_quota_per_day !== null) {
            $limit = $setting->token_quota_per_day;
        }

        $used = (int) Redis::get($this->tokenCounterKey($user));

        if ($used >= $limit) {
            throw new TooManyRequestsHttpException(
                3600,
                "Bạn đã dùng hết quota {$limit} tokens hôm nay. Quota reset vào 00:00."
            );
        }
    }

    private function trackTokenUsage(User $user, int $tokens): void
    {
        $key = $this->tokenCounterKey($user);
        $ttl = (int) now()->endOfDay()->diffInSeconds(now());

        Redis::pipeline(function ($pipe) use ($key, $tokens, $ttl) {
            $pipe->incrby($key, $tokens);
            $pipe->expire($key, $ttl);
        });
    }

    private function tokenCounterKey(User $user): string
    {
        return 'ai:quota:tokens:' . $user->id . ':' . now()->toDateString();
    }
}
