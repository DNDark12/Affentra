<?php

declare(strict_types=1);

namespace App\Services\AI;

use App\Models\AiProviderSetting;
use App\Models\User;
use App\Services\AI\Contracts\AIProviderClient;
use App\Services\AI\Providers\GeminiClient;
use App\Services\AI\Providers\OpenAICompatibleClient;
use App\Services\AI\Providers\SeedanceClient;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * AiSettingsService
 *
 * Single source of truth for AI provider resolution per user.
 *
 * Priority chain:
 *   1. User's own DB setting for the requested provider (if configured & enabled)
 *   2. System fallback defaults from config/ai.php
 *
 * Per-user settings are cached in Redis for 5 minutes to avoid repeated DB reads.
 */
class AiSettingsService
{
    private const CACHE_TTL = 300; // 5 min

    /**
     * Available provider keys and their human-readable names.
     *
     * Add new providers here (single place, no UI changes needed).
     *
     * @var array<string, array{name: string, capabilities: list<string>}>
     */
    public const PROVIDER_REGISTRY = [
        'gemini' => [
            'name'              => 'Google Gemini',
            'capabilities'      => ['text', 'image', 'video'],
            'has_base_url'      => false,
            'default_model'     => 'gemini-2.5-flash',
            'models' => [
                ['id' => 'gemini-3.1-pro-preview',        'name' => 'Gemini 3.1 Pro (Preview)',      'capabilities' => ['text', 'image', 'video']],
                ['id' => 'gemini-3.1-flash-lite-preview', 'name' => 'Gemini 3.1 Flash Lite (Preview)', 'capabilities' => ['text']],
                ['id' => 'gemini-3.1-flash-image-preview', 'name' => 'Gemini 3.1 Flash Image (Preview)', 'capabilities' => ['image']],
                ['id' => 'gemini-2.5-pro',                'name' => 'Gemini 2.5 Pro (Stable)',       'capabilities' => ['text', 'image', 'video']],
                ['id' => 'gemini-2.5-flash',              'name' => 'Gemini 2.5 Flash (Stable)',     'capabilities' => ['text', 'image', 'video']],
                ['id' => 'gemini-1.5-pro',                'name' => 'Gemini 1.5 Pro',               'capabilities' => ['text', 'image', 'video']],
                ['id' => 'gemini-1.5-flash',              'name' => 'Gemini 1.5 Flash',             'capabilities' => ['text', 'image', 'video']],
            ]
        ],
        'openai' => [
            'name'              => 'OpenAI',
            'capabilities'      => ['text', 'image', 'video'],
            'has_base_url'      => false,
            'default_model'     => 'gpt-5-mini',
            'models' => [
                ['id' => 'gpt-5.4',            'name' => 'GPT-5.4 (Frontier)',    'capabilities' => ['text', 'image', 'video']],
                ['id' => 'gpt-5.4-pro',        'name' => 'GPT-5.4 Pro',           'capabilities' => ['text', 'image', 'video']],
                ['id' => 'gpt-5-mini',         'name' => 'GPT-5 Mini',            'capabilities' => ['text', 'image', 'video']],
                ['id' => 'gpt-5.3-codex',      'name' => 'GPT-5.3 Codex (Agentic)', 'capabilities' => ['text']],
                ['id' => 'gpt-4.1',            'name' => 'GPT-4.1 (Stable)',      'capabilities' => ['text', 'image', 'video']],
                ['id' => 'gpt-4o',             'name' => 'GPT-4o',                'capabilities' => ['text', 'image', 'video']],
                ['id' => 'gpt-4o-mini',        'name' => 'GPT-4o Mini',           'capabilities' => ['text', 'image', 'video']],
                ['id' => 'o1-preview',         'name' => 'OpenAI o1 Preview',    'capabilities' => ['text']],
                ['id' => 'gpt-image-1.5',      'name' => 'GPT Image 1.5 (DALL-E Next)', 'capabilities' => ['image']],
            ]
        ],
        'self_hosted' => [
            'name'              => 'Self-Hosted / OpenAI Compatible',
            'capabilities'      => ['text'],
            'has_base_url'      => true,
            'default_model'     => '',
            'models' => [] // User manually enters for self-hosted
        ],
        'anthropic' => [
            'name'              => 'Anthropic Claude',
            'capabilities'      => ['text'],
            'has_base_url'      => false,
            'default_model'     => 'claude-sonnet-4-6',
            'models' => [
                ['id' => 'claude-opus-4-6',   'name' => 'Claude 4.6 Opus',   'capabilities' => ['text']],
                ['id' => 'claude-sonnet-4-6', 'name' => 'Claude 4.6 Sonnet', 'capabilities' => ['text']],
                ['id' => 'claude-haiku-4-5',  'name' => 'Claude 4.5 Haiku',  'capabilities' => ['text']],
                ['id' => 'claude-3-5-sonnet-latest', 'name' => 'Claude 3.5 Sonnet', 'capabilities' => ['text']],
            ]
        ],
        'seedance' => [
            'name'              => 'Seedance (Video AI)',
            'capabilities'      => ['video', 'image'],
            'has_base_url'      => true,
            'default_base_url'  => 'https://seedance2.app/api/v1',
            'default_model'     => 'doubao-seedance-2-0',
            'models' => [
                ['id' => 'doubao-seedance-2-0',             'name' => 'Seedance 2.0 (Pro)', 'capabilities' => ['video']],
                ['id' => 'doubao-seedance-1-5-pro',         'name' => 'Seedance 1.5 Pro',   'capabilities' => ['video']],
                ['id' => 'doubao-seedance-1-0-lite-t2v-250428', 'name' => 'Seedance 1.0 Lite (Text2Video)', 'capabilities' => ['video']],
                // --- Image Models ---
                ['id' => 'nano-banana-2-0',                 'name' => 'Nano Banana 2.0',      'capabilities' => ['image']],
                ['id' => 'nano-banana-2-0-edit',            'name' => 'Nano Banana 2.0 Edit', 'capabilities' => ['image']],
            ]
        ],
    ];

    /**
     * Resolve the correct AIProviderClient for a user.
     *
     * The user must have configured at least one enabled AI provider.
     *
     * @throws RuntimeException If no provider is configured or all are disabled.
     */
    public function resolveClientForUser(User $user, string $type = 'text', ?string $providerKey = null, ?string $modelName = null): AIProviderClient
    {
        $setting = $this->getActiveSettingForUser($user, $type, $providerKey);

        if ($setting === null) {
            throw new RuntimeException(
                'Bạn chưa cấu hình AI Provider. Vui lòng vào Cài đặt → AI để thêm provider.'
            );
        }

        return $this->buildClient($setting, $modelName);
    }

    /**
     * Get all provider settings for a user (for Settings UI).
     *
     * @return \Illuminate\Database\Eloquent\Collection<AiProviderSetting>
     */
    public function getSettingsForUser(User $user)
    {
        return AiProviderSetting::where('user_id', $user->id)
            ->orderBy('provider_key')
            ->get();
    }

    /**
     * Upsert a provider setting for a user.
     *
     * @param  array{provider_key: string, api_key?: string, base_url?: string, default_model?: string, label?: string, status?: string, capabilities?: list<string>} $data
     */
    public function upsertSetting(User $user, array $data): AiProviderSetting
    {
        $setting = AiProviderSetting::firstOrNew([
            'user_id'      => $user->id,
            'provider_key' => $data['provider_key'],
        ]);

        $setting->fill([
            'default_model'      => $data['default_model'] ?? null,
            'label'              => $data['label'] ?? null,
            'status'             => $data['status'] ?? 'enabled',
            'capabilities'       => $data['capabilities'] ?? ['text'],
            'token_quota_per_day' => $data['token_quota_per_day'] ?? null,
        ]);
        $setting->save();

        // Store encrypted credentials, merging with existing ones
        $existingCreds = $setting->getCredentials();

        $credentials = [
            'api_key'    => $data['api_key'] ?? $existingCreds['api_key'] ?? null,
            'base_url'   => $data['base_url'] ?? $existingCreds['base_url'] ?? null,
            'api_format' => $data['api_format'] ?? $existingCreds['api_format'] ?? null,
        ];

        $credentials = array_filter($credentials, fn($v) => $v !== null && $v !== '');

        if (! empty($credentials)) {
            $setting->setCredentials($credentials);
        }

        // Bust the user's resolved-provider cache
        $this->bustCache($user);

        return $setting->refresh();
    }

    /**
     * Delete a provider setting by provider key.
     */
    public function deleteSetting(User $user, string $providerKey): void
    {
        AiProviderSetting::where('user_id', $user->id)
            ->where('provider_key', $providerKey)
            ->delete();

        $this->bustCache($user);
    }

    /**
     * Test a provider connection using supplied credentials (does not save).
     *
     * If api_key/base_url are omitted and a $user is provided, the saved
     * DB credentials are used — this allows testing from the provider card.
     *
     * @param  array{provider_key: string, api_key?: string, base_url?: string, default_model?: string} $data
     */
    public function testConnection(array $data, ?User $user = null): array
    {
        $providerKey = $data['provider_key'];
        $apiKey      = $data['api_key']      ?? null;
        $baseUrl     = $data['base_url']     ?? null;
        $apiFormat   = $data['api_format']   ?? null;
        $model       = $data['default_model'] ?? null;

        // If credentials are not provided, look them up from the saved DB setting.
        if (empty($apiKey) && empty($baseUrl) && $user !== null) {
            $saved = AiProviderSetting::where('user_id', $user->id)
                ->where('provider_key', $providerKey)
                ->first();

            if ($saved) {
                $creds    = $saved->getCredentials();
                $apiKey   = $creds['api_key']  ?? null;
                $baseUrl  = $creds['base_url'] ?? null;
                $apiFormat = $creds['api_format'] ?? null;
                $model    = $model ?? $saved->default_model;
            }
        }

        // Build the client directly — no need to go through the model for a test.
        $testResult  = null;
        $testError   = null;

        try {
            $client = match ($providerKey) {
                'gemini'      => new GeminiClient($apiKey, $model),
                'self_hosted' => ($apiFormat === 'gemini')
                    ? new GeminiClient($apiKey, $model, $baseUrl)
                    : new OpenAICompatibleClient(
                        apiKey:      $apiKey,
                        baseUrl:     $baseUrl,
                        model:       $model,
                        providerKey: $providerKey,
                    ),
                'openai'      => new OpenAICompatibleClient(
                    apiKey:      $apiKey,
                    baseUrl:     $baseUrl,
                    model:       $model,
                    providerKey: $providerKey,
                ),
                'seedance'    => new SeedanceClient(
                    apiKey:  $apiKey,
                    baseUrl: $baseUrl,
                    model:   $model,
                ),
                default => throw new \RuntimeException("Unknown provider: {$providerKey}"),
            };

            // Video-only providers need a different test — check credits/health
            if ($client->supportsAsyncMedia()) {
                $client->checkCredits();
            } else {
                $client->generateText('Reply with only the word: OK', ['max_tokens' => 10]);
            }
            $testResult = 'ok';
        } catch (\Exception $e) {
            $testResult = 'error';
            $testError  = $e->getMessage();
        }

        // Persist result to the saved DB record so the card shows connection health.
        if ($user !== null) {
            AiProviderSetting::where('user_id', $user->id)
                ->where('provider_key', $providerKey)
                ->update([
                    'last_test_status' => $testResult,
                    'last_test_error'  => $testError,
                    'last_tested_at'   => now(),
                ]);
        }

        if ($testResult === 'ok') {
            return ['ok' => true,  'model' => $client->modelKey(), 'provider' => $client->providerKey()];
        }

        return ['ok' => false, 'error' => $testError];
    }



    // ── Private helpers ───────────────────────────────────────────────────────

    private function getActiveSettingForUser(User $user, string $type, ?string $providerKey = null): ?AiProviderSetting
    {
        // If a specific provider is requested, don't use cache as it's an explicit override target
        if ($providerKey) {
            return AiProviderSetting::where('user_id', $user->id)
                ->where('provider_key', $providerKey)
                ->where('status', 'enabled')
                ->first();
        }

        $cacheKey = "ai:settings:{$user->id}:{$type}";

        return Cache::remember($cacheKey, self::CACHE_TTL, function () use ($user, $type) {
            return AiProviderSetting::where('user_id', $user->id)
                ->where('status', 'enabled')
                ->whereJsonContains('capabilities', $type)
                ->orderByDesc('updated_at') // Most recently updated = preferred
                ->first();
        });
    }

    private function buildClient(AiProviderSetting $setting, ?string $modelOverride = null): AIProviderClient
    {
        $creds = $setting->getCredentials();
        $model = $modelOverride ?? $setting->default_model;

        return match ($setting->provider_key) {
            'gemini'      => new GeminiClient($creds['api_key'] ?? null, $model),
            'self_hosted' => (($creds['api_format'] ?? 'openai') === 'gemini')
                ? new GeminiClient($creds['api_key'] ?? null, $model, $creds['base_url'] ?? null)
                : new OpenAICompatibleClient(
                    apiKey:  $creds['api_key'] ?? null,
                    baseUrl: $creds['base_url'] ?? null,
                    model:   $model,
                    providerKey: $setting->provider_key,
                ),
            'openai'      => new OpenAICompatibleClient(
                apiKey:  $creds['api_key'] ?? null,
                baseUrl: $creds['base_url'] ?? null,
                model:   $model,
                providerKey: $setting->provider_key,
            ),
            'seedance'    => new SeedanceClient(
                apiKey:  $creds['api_key'] ?? null,
                baseUrl: $creds['base_url'] ?? null,
                model:   $model,
            ),
            default => throw new RuntimeException("Unknown provider: {$setting->provider_key}"),
        };
    }

    private function bustCache(User $user): void
    {
        foreach (['text', 'image', 'video'] as $type) {
            Cache::forget("ai:settings:{$user->id}:{$type}");
        }
    }
}
