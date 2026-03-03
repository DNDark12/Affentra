<?php

declare(strict_types=1);

namespace App\Services\AI;

use App\Models\AiProviderSetting;
use App\Models\User;
use App\Services\AI\Contracts\AIProviderClient;
use App\Services\AI\Providers\GeminiClient;
use App\Services\AI\Providers\OpenAICompatibleClient;
use Illuminate\Support\Facades\Cache;
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
            'name'         => 'Google Gemini',
            'capabilities' => ['text', 'image'],
            'has_base_url' => false,
        ],
        'openai' => [
            'name'         => 'OpenAI',
            'capabilities' => ['text', 'image'],
            'has_base_url' => false,
        ],
        'self_hosted' => [
            'name'         => 'Self-Hosted (OpenAI-Compatible)',
            'capabilities' => ['text'],
            'has_base_url' => true,
        ],
        'anthropic' => [
            'name'         => 'Anthropic Claude',
            'capabilities' => ['text'],
            'has_base_url' => false,
        ],
    ];

    /**
     * Resolve the correct AIProviderClient for a user.
     *
     * The user must have configured at least one enabled AI provider.
     *
     * @throws RuntimeException If no provider is configured or all are disabled.
     */
    public function resolveClientForUser(User $user, string $type = 'text'): AIProviderClient
    {
        $setting = $this->getActiveSettingForUser($user, $type);

        if ($setting === null) {
            throw new RuntimeException(
                'Bạn chưa cấu hình AI Provider. Vui lòng vào Cài đặt → AI để thêm provider.'
            );
        }

        return $this->buildClient($setting);
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

        // Store encrypted credentials if provided
        $credentials = array_filter([
            'api_key'  => $data['api_key'] ?? null,
            'base_url' => $data['base_url'] ?? null,
        ]);

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
        $model       = $data['default_model'] ?? null;

        // If credentials are not provided, look them up from the saved DB setting.
        if (empty($apiKey) && empty($baseUrl) && $user !== null) {
            $saved = AiProviderSetting::where('user_id', $user->id)
                ->where('provider_key', $providerKey)
                ->first();

            if ($saved) {
                $creds  = $saved->getCredentials();
                $apiKey = $creds['api_key']  ?? null;
                $baseUrl = $creds['base_url'] ?? null;
                $model  = $model ?? $saved->default_model;
            }
        }

        // Build the client directly — no need to go through the model for a test.
        $testResult  = null;
        $testError   = null;

        try {
            $client = match ($providerKey) {
                'gemini'      => new GeminiClient($apiKey, $model),
                'openai',
                'self_hosted' => new OpenAICompatibleClient(
                    apiKey:      $apiKey,
                    baseUrl:     $baseUrl,
                    model:       $model,
                    providerKey: $providerKey,
                ),
                default => throw new \RuntimeException("Unknown provider: {$providerKey}"),
            };

            $client->generateText('Reply with only the word: OK', ['max_tokens' => 10]);
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

    private function getActiveSettingForUser(User $user, string $type): ?AiProviderSetting
    {
        $cacheKey = "ai:settings:{$user->id}:{$type}";

        return Cache::remember($cacheKey, self::CACHE_TTL, function () use ($user, $type) {
            return AiProviderSetting::where('user_id', $user->id)
                ->where('status', 'enabled')
                ->whereJsonContains('capabilities', $type)
                ->orderByDesc('updated_at') // Most recently updated = preferred
                ->first();
        });
    }

    private function buildClient(AiProviderSetting $setting): AIProviderClient
    {
        $creds = $setting->getCredentials();

        return match ($setting->provider_key) {
            'gemini'      => new GeminiClient($creds['api_key'] ?? null, $setting->default_model),
            'openai',
            'self_hosted' => new OpenAICompatibleClient(
                apiKey:  $creds['api_key'] ?? null,
                baseUrl: $creds['base_url'] ?? null,
                model:   $setting->default_model,
                providerKey: $setting->provider_key,
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
