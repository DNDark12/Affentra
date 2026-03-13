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
use App\Jobs\AI\PollGeminiVideoJob;
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
     * Preview the prompt plan for a tracking link.
     */
    public function preview(TrackingLink $link, User $user, array $payload): array
    {
        $templateId = $payload['preset_id'] ?? 'fb_post';
        $preset     = $this->registry->get($templateId);
        $platform   = $preset['platform'] ?? 'generic';
        $options     = $payload['options']  ?? [];
        $imageUrls   = $payload['image_urls'] ?? [];

        // 1. Fetch images for reference
        $base64Images = [];
        if (!empty($imageUrls)) {
            $base64Images = $this->imageFetcher->fetchAsBase64($imageUrls);
        }

        // 2. Build canonical attributes
        $attributes = array_merge($options, [
            'platform'      => $platform,
            'tracking_url'  => route('redirect', $link->short_code),
            'product_title' => $options['product_title'] ?? $link->offer?->title ?? '',
            'product_price' => $options['product_price'] ?? '',
            'images'        => $base64Images,
            'variant_count' => $payload['variant_count'] ?? 1
        ]);
        ksort($attributes);

        // 3. System context
        $language = $payload['language'] ?? 'Vietnamese';
        $policyFlags = [
            'safety_no_absolute'  => (bool) ($payload['options']['safety_no_absolute'] ?? false),
            'safety_no_medical'   => (bool) ($payload['options']['safety_no_medical'] ?? false),
            'safety_no_sensitive' => (bool) ($payload['options']['safety_no_sensitive'] ?? false),
        ];
        $sysContext = [
            'platform'     => $platform,
            'language'     => $language,
            'policy_flags' => $policyFlags,
        ];

        // 4. Determine requested outputs
        $requestedTypes = [];
        if ($payload['generate_text'] ?? false)  $requestedTypes[] = 'text';
        if ($payload['generate_image'] ?? false) $requestedTypes[] = 'image';
        if ($payload['generate_video'] ?? false) $requestedTypes[] = 'video';
        if (empty($requestedTypes)) {
            $type = $preset['default_type'] ?? ($preset['types'][0] ?? 'text');
            $requestedTypes = [$type];
        }
        sort($requestedTypes);

        // 5. Build Execution Plan
        $plan = [];
        foreach ($requestedTypes as $modality) {
            $renderedPrompt = null;
            $systemPrompt = null;

            try {
                $renderedPrompt = $this->registry->render($templateId, $attributes, $modality);
                $systemPrompt = $this->systemPrompts->getSystemPrompt($modality, $sysContext);
            } catch (\Exception $e) {
                Log::warning("content_generation.preview_resolve_failed", [
                    'modality' => $modality,
                    'error' => $e->getMessage()
                ]);
            }

            $plan[$modality] = [
                'prompt'         => $renderedPrompt,
                'system_prompt'  => $systemPrompt,
            ];
        }

        return [
            'template_id' => $templateId,
            'plan' => $plan,
        ];
    }

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
        $sortedReqTypes = $requestedTypes;
        sort($sortedReqTypes);

        // 2. Fetch images for reference
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
            'images'        => $base64Images,
            'variant_count' => $payload['variant_count'] ?? 1
        ]);
        ksort($attributes);

        // 4. System context needed for prompt and hash
        $language = $payload['language'] ?? 'Vietnamese';
        $policyFlags = [
            'safety_no_absolute'  => (bool) ($payload['options']['safety_no_absolute'] ?? false),
            'safety_no_medical'   => (bool) ($payload['options']['safety_no_medical'] ?? false),
            'safety_no_sensitive' => (bool) ($payload['options']['safety_no_sensitive'] ?? false),
        ];
        $sysContext = [
            'platform'     => $platform,
            'language'     => $language,
            'policy_flags' => $policyFlags,
        ];

        // 5. Build Execution Plan
        $executionPlan = [];
        foreach ($sortedReqTypes as $modality) {
            $client = null;
            $supported = false;
            $skippedReason = null;
            $renderedPrompt = null;
            $systemPrompt = null;

            try {
                $client = $this->aiSettings->resolveClientForUser($user, $modality, $providerKey, $modelName);
                $supported = true;
            } catch (\Exception $e) {
                $skippedReason = 'provider_not_supported';
            }

            if ($supported) {
                try {
                    $renderedPrompt = $this->registry->render($templateId, $attributes, $modality);
                    $systemPrompt = $this->systemPrompts->getSystemPrompt($modality, $sysContext);
                } catch (\Exception $e) {
                    $supported = false;
                    $skippedReason = 'template_mismatch';
                    Log::warning("content_generation.resolve_prompt_failed", [
                        'modality' => $modality,
                        'error' => $e->getMessage()
                    ]);
                }
            }

            $executionPlan[$modality] = [
                'requested'      => true,
                'resolved'       => $renderedPrompt !== null,
                'supported'      => $supported,
                'skipped_reason' => $skippedReason,
                'prompt'         => $renderedPrompt,
                'system_prompt'  => $systemPrompt,
                'client'         => $client,
            ];
        }

        // 6. Define "Primary" type for database marker & Auto Mode fallback
        if (isset($executionPlan['video']['supported']) && $executionPlan['video']['supported']) {
            $primaryType = 'video';
        } elseif (isset($executionPlan['image']['supported']) && $executionPlan['image']['supported']) {
            $primaryType = 'image';
        } elseif (isset($executionPlan['text']['supported']) && $executionPlan['text']['supported']) {
            $primaryType = 'text';
        } else {
            $primaryType = $sortedReqTypes[0] ?? 'text'; // fallback if all fail
        }

        // 7. Calculate Hash from stable semantic inputs
        $hashPayload = [
            'template_id'       => $templateId,
            'attributes'        => $attributes,
            'requested_outputs' => $sortedReqTypes,
            'language'          => $language,
            'policy_flags'      => $policyFlags,
        ];
        $promptHash = hash('sha256', json_encode($hashPayload));

        // 8. Quota check
        $this->enforceTokenQuota($user);

        // 9. Dedup check
        $fromCache     = false;
        $cachedPayload = null;

        if (! $forceNewSeed) {
            $cacheKey      = "ai:gen:dedup:{$promptHash}";
            $cachedPayload = Cache::get($cacheKey);
            $fromCache     = $cachedPayload !== null;
        }

        // 10. Create generation row
        $generation = ContentGeneration::create([
            'tracking_link_id'   => $link->id,
            'user_id'            => $user->id,
            'platform_connection_id' => $link->platform_connection_id,
            'type'               => $primaryType,
            'platform'           => $platform,
            'status'             => 'running',
            'prompt_template_id' => $templateId,
            'prompt_attributes'  => $attributes, // Clean, no system_prompt
            'prompt_hash'        => $promptHash,
            'force_new_seed'     => $forceNewSeed,
            'from_cache'         => $fromCache,
        ]);

        // 11. Serve from cache or execute
        if ($fromCache && $cachedPayload !== null) {
            $generation->update([
                'status'         => 'succeeded',
                'output_payload' => $cachedPayload,
            ]);

            $this->statisticsCache->bumpFor((int) $user->id, (int) $link->id);
            return $generation->refresh();
        }

        // 12. Execution Loop
        $totalTokens = 0;
        $isAsyncVideo = false;
        $asyncTaskData = [];

        foreach ($executionPlan as $modality => $plan) {
            if (!$plan['supported'] || !$plan['resolved']) {
                Log::info("content_generation.skipped_{$modality}", [
                    'id'     => $generation->id,
                    'reason' => $plan['skipped_reason']
                ]);
                continue;
            }

            $currentClient    = $plan['client'];
            $currentPrompt    = $plan['prompt'];
            $currentSysPrompt = $plan['system_prompt'];

            // Prep prompt if provider lacks native system prompt
            if (! $currentClient->supportsNativeSystemPrompt($modality)) {
                $currentPrompt = "[SYSTEM INSTRUCTION]\n{$currentSysPrompt}\n\n[USER BRIEF]\n{$currentPrompt}";
            }

            // Async Video
            if ($modality === 'video' && $currentClient->supportsAsyncMedia()) {
                try {
                    $result = $currentClient->generateMedia($currentPrompt, 'video', $attributes);
                    $isAsyncVideo = true;
                    $asyncTaskData = [
                        'status'           => 'queued',
                        'provider_task_id' => $result->meta['task_id'] ?? null,
                        'provider_status'  => 'queued',
                        'ai_provider'      => $currentClient->providerKey(),
                        'ai_model'         => $currentClient->modelKey(),
                    ];
                } catch (\Exception $e) {
                    Log::error("content_generation.async_video_failed", ['id' => $generation->id, 'error' => $e->getMessage()]);
                }
                continue;
            }

            // Sync Execution
            try {
                if ($modality === 'text') {
                    $runner = new TextGenerationRunner($currentClient);
                    $generation = $runner->run($generation, $modality, $currentPrompt, $currentSysPrompt);
                } else {
                    $runner = new MediaGenerationRunner($currentClient, $this->persistAction);
                    $generation = $runner->run($generation, $modality, $currentPrompt, $currentSysPrompt);
                }
                
                // Aggregate Usage (best-effort)
                if (isset($generation->tokens_completion) && $generation->tokens_completion > 0) {
                     $totalTokens += ($generation->tokens_prompt ?? 0) + $generation->tokens_completion;
                }
            } catch (\Exception $e) {
                Log::error("content_generation.sync_{$modality}_failed", ['id' => $generation->id, 'error' => $e->getMessage()]);
            }
        }

        // 13. Async finalize — route to provider-specific polling job
        if ($isAsyncVideo) {
            $generation->update($asyncTaskData);

            $providerKey = $asyncTaskData['ai_provider'] ?? 'seedance';
            if ($providerKey === 'gemini') {
                PollGeminiVideoJob::dispatch($generation->id)->delay(now()->addSeconds(15));
            } else {
                PollSeedanceTaskJob::dispatch($generation->id)->delay(now()->addSeconds(10));
            }

            $this->statisticsCache->bumpFor((int) $user->id, (int) $link->id);
            return $generation->refresh();
        }

        // 14. Final Cache & Track status
        if ($generation->status === 'running') {
             // If execution loop finishes without updating status from runners
             // It means nothing was successfully generated.
             $generation->update(['status' => 'failed', 'error_message' => 'All modalities failed or skipped']);
        }

        if ($generation->status === 'succeeded' && ! $forceNewSeed) {
            Cache::put("ai:gen:dedup:{$promptHash}", $generation->output_payload, self::DEDUP_CACHE_TTL);
        }

        if ($totalTokens > 0) {
            $this->trackTokenUsage($user, $totalTokens);
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
