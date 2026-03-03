<?php

declare(strict_types=1);

namespace App\Services\AI;

use App\Models\ContentGeneration;
use App\Models\TrackingLink;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Redis;
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
        'ctv'    => 5_000,
        'leader' => 20_000,
        'owner'  => 100_000,
    ];

    private const DEDUP_CACHE_TTL = 300; // 5 minutes

    public function __construct(
        private readonly AiSettingsService      $aiSettings,
        private readonly PromptTemplateRegistry $registry,
    ) {}

    /**
     * Generate content for a tracking link.
     */
    public function generate(TrackingLink $link, User $user, array $payload): ContentGeneration
    {
        $type         = $payload['type']     ?? 'text';
        $platform     = $payload['platform'] ?? 'generic';
        $templateId   = $payload['preset']   ?? 'fb_post_v1';
        $options      = $payload['options']  ?? [];
        $forceNewSeed = (bool) ($payload['force_new_seed'] ?? false);

        // 1. Resolve per-user provider client (throws RuntimeException if not configured)
        $client = $this->aiSettings->resolveClientForUser($user, $type);

        // 2. Build canonical attributes (sorted for stable hashing)
        $attributes = array_merge($options, [
            'platform'      => $platform,
            'tracking_url'  => $link->short_url ?? $link->destination_url,
            'product_title' => $link->offer?->title ?? '',
            'product_price' => '',
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

            return $generation->refresh();
        }

        // Build runner with the resolved client on-the-fly
        $runner     = new TextGenerationRunner($client, $this->registry);
        $generation = $runner->run($generation);

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

        return $generation;
    }

    // ── Private helpers ───────────────────────────────────────────────────────

    private function enforceTokenQuota(User $user): void
    {
        $role  = $user->role ?? 'ctv';
        $limit = (int) (self::DEFAULT_QUOTAS[$role] ?? self::DEFAULT_QUOTAS['ctv']);

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
