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
        private readonly SystemPromptRegistry   $systemPrompts,
        private readonly ImageFetcherService    $imageFetcher,
        private readonly AiStatisticsCacheService $statisticsCache,
        private readonly \App\Actions\AI\PersistGeneratedMediaAction $persistAction,
    ) {}

    /**
     * Generate content for a tracking link.
     */
    public function generate(TrackingLink $link, User $user, array $payload): ContentGeneration
    {
        $templateId   = $payload['preset_id'] ?? 'fb_post';
        $preset       = $this->registry->get($templateId);
        $type         = $preset['default_type'] ?? ($preset['types'][0] ?? 'text');
        $platform     = $preset['platform'] ?? 'generic';

        $options      = $payload['options']  ?? [];
        $forceNewSeed = (bool) ($payload['force_new_seed'] ?? false);
        $imageUrls    = $payload['image_urls'] ?? [];
        $providerKey  = $payload['provider_key'] ?? null;
        $modelName    = $payload['model'] ?? null;

        // 1. Determine requested outputs
        $requestedTypes = [];
        if ($payload['generate_text'] ?? false)  $requestedTypes[] = 'text';
        if ($payload['generate_image'] ?? false) $requestedTypes[] = 'image';
        if ($payload['generate_video'] ?? false) $requestedTypes[] = 'video';

        if (empty($requestedTypes)) {
            // Fallback to preset default if nothing selected (backward compat)
            $requestedTypes = [$type]; 
        }

        // 2. Identify the "Primary" output type
        $primaryType = 'text';
        if (in_array('video', $requestedTypes)) $primaryType = 'video';
        elseif (in_array('image', $requestedTypes)) $primaryType = 'image';
        elseif (in_array('text', $requestedTypes)) $primaryType = 'text';

        // 3. Resolve per-user provider client for the PRIMARY type
        $client = $this->aiSettings->resolveClientForUser($user, $primaryType, $providerKey, $modelName);

        // 4. Fetch images for reference
        $base64Images = [];
        if (!empty($imageUrls)) {
            $base64Images = $this->imageFetcher->fetchAsBase64($imageUrls);
        }

        // 5. Build canonical attributes (sorted for stable hashing)
        $attributes = array_merge($options, [
            'platform'      => $platform,
            'tracking_url'  => route('redirect', $link->short_code),
            'product_title' => $options['product_title'] ?? $link->offer?->title ?? '',
            'product_price' => $options['product_price'] ?? '',
            'images'        => $base64Images,
            'variant_count' => $payload['variant_count'] ?? 1
        ]);
        ksort($attributes);

        // 6. Build System Prompt & Context
        $sysContext = [
            'platform'     => $platform,
            'language'     => $payload['language'] ?? 'Vietnamese',
            'policy_flags' => [
                'safety_no_absolute'  => (bool) ($payload['options']['safety_no_absolute'] ?? false),
                'safety_no_medical'   => (bool) ($payload['options']['safety_no_medical'] ?? false),
                'safety_no_sensitive' => (bool) ($payload['options']['safety_no_sensitive'] ?? false),
            ],
        ];
        $systemPrompt = $this->systemPrompts->getSystemPrompt($primaryType, $sysContext);
        $attributes['system_prompt'] = $systemPrompt;

        $promptHash = hash('sha256', $templateId . ':' . json_encode($attributes) . ':' . implode(',', $requestedTypes));

        // 7. Quota check
        $this->enforceTokenQuota($user);

        // 8. Dedup check
        $fromCache     = false;
        $cachedPayload = null;

        if (! $forceNewSeed) {
            $cacheKey      = "ai:gen:dedup:{$templateId}:{$promptHash}";
            $cachedPayload = Cache::get($cacheKey);
            $fromCache     = $cachedPayload !== null;
        }

        // 9. Create generation row
        $generation = ContentGeneration::create([
            'tracking_link_id'   => $link->id,
            'user_id'            => $user->id,
            'platform_connection_id' => $link->platform_connection_id,
            'type'               => $primaryType,
            'platform'           => $platform,
            'status'             => 'running',
            'prompt_template_id' => $templateId,
            'prompt_attributes'  => $attributes,
            'prompt_hash'        => $promptHash,
            'force_new_seed'     => $forceNewSeed,
            'from_cache'         => $fromCache,
        ]);

        // 10. Serve from cache or execute
        if ($fromCache && $cachedPayload !== null) {
            $generation->update([
                'status'         => 'succeeded',
                'output_payload' => $cachedPayload,
            ]);

            $this->statisticsCache->bumpFor((int) $user->id, (int) $link->id);
            return $generation->refresh();
        }

        // 11. Execute PRIMARY generation
        $renderedPrompt = $this->registry->render($templateId, $attributes);

        // Capability-aware prompt prepending for providers without native system prompt support
        if (! $client->supportsNativeSystemPrompt($primaryType)) {
            Log::info("ai.generation_fallback_prepend_sys_prompt", [
                'provider' => $client->providerKey(),
                'modality' => $primaryType,
            ]);
            $renderedPrompt = "[SYSTEM INSTRUCTION]\n{$systemPrompt}\n\n[USER BRIEF]\n{$renderedPrompt}";
        }

        // -- Async Video Path (Primary) --
        if ($primaryType === 'video' && $client->supportsAsyncMedia()) {
            $result = $client->generateMedia($renderedPrompt, 'video', $attributes);

            $generation->update([
                'status'           => 'queued',
                'provider_task_id' => $result->meta['task_id'] ?? null,
                'provider_status'  => 'queued',
                'ai_provider'      => $client->providerKey(),
                'ai_model'         => $client->modelKey(),
            ]);

            PollSeedanceTaskJob::dispatch($generation->id)->delay(now()->addSeconds(10));
            $this->statisticsCache->bumpFor((int) $user->id, (int) $link->id);
            return $generation->refresh();
        }

        // -- Sync Path (Primary: Text or Image) --
        if ($primaryType === 'text') {
            $runner = new TextGenerationRunner($client, $this->registry);
            $generation = $runner->run($generation, $systemPrompt);
        } else {
            // Primary is Image (or sync Video if ever supported)
            $runner = new MediaGenerationRunner($client, $this->registry, $this->persistAction);
            $generation = $runner->run($generation, $systemPrompt);
        }

        // 12. Execute SECONDARY generations (Enrichment)
        foreach (['text', 'image', 'video'] as $secType) {
            if ($secType === $primaryType) continue; // Already done
            if (!in_array($secType, $requestedTypes)) continue; // Not requested

            if ($generation->status !== 'succeeded' && $generation->status !== 'queued') continue;

            try {
                $secClient       = $this->aiSettings->resolveClientForUser($user, $secType, $providerKey, $modelName);
                $secSystemPrompt = $this->systemPrompts->getSystemPrompt($secType, $sysContext);
                
                if ($secType === 'text') {
                    $secRunner = new TextGenerationRunner($secClient, $this->registry);
                    $generation = $secRunner->run($generation, $secSystemPrompt);
                } else {
                    $secRunner = new MediaGenerationRunner($secClient, $this->registry, $this->persistAction);
                    $generation = $secRunner->runMediaEnrichment($generation, $secType, $secSystemPrompt);
                }
            } catch (\Exception $e) {
                Log::warning("content_generation.secondary_{$secType}_failed", [
                    'id' => $generation->id,
                    'error' => $e->getMessage()
                ]);
            }
        }

        // 7. Warm dedup cache on success
        if ($generation->status === 'succeeded' && ! $forceNewSeed) {
            Cache::put(
                "ai:gen:dedup:{$templateId}:{$promptHash}",
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
