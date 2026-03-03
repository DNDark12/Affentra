<?php

declare(strict_types=1);

namespace App\Http\Controllers\API;

use App\Http\Requests\AI\StoreAiProviderSettingRequest;
use App\Services\AI\AiSettingsService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * API Controller for per-user AI Provider Settings.
 *
 * Routes:
 *   GET    /api/ai/settings            → list (index)
 *   POST   /api/ai/settings            → create/update (upsert)
 *   DELETE /api/ai/settings/{key}      → remove provider
 *   POST   /api/ai/settings/test       → test connection (no save)
 *   GET    /api/ai/settings/providers  → registry (for UI dropdowns)
 */
class AiSettingController
{
    public function __construct(
        private readonly AiSettingsService $service,
    ) {}

    /**
     * GET /api/ai/settings
     * Returns all configured providers for the authenticated user.
     */
    public function index(Request $request): JsonResponse
    {
        $settings = $this->service->getSettingsForUser($request->user())
            ->map(function ($s) {
                return [
                    'provider_key'        => $s->provider_key,
                    'label'               => $s->label,
                    'default_model'       => $s->default_model,
                    'status'              => $s->status,
                    'capabilities'        => $s->capabilities,
                    'token_quota_per_day' => $s->token_quota_per_day,
                    'is_configured'       => $s->isConfigured(),
                    'updated_at'          => $s->updated_at?->toDateTimeString(),
                ];
            });

        return response()->json(['ok' => true, 'data' => $settings]);
    }

    /**
     * GET /api/ai/settings/providers
     * Returns the static provider registry for the UI to build dropdowns.
     */
    public function providers(): JsonResponse
    {
        return response()->json([
            'ok'   => true,
            'data' => AiSettingsService::PROVIDER_REGISTRY,
        ]);
    }

    /**
     * POST /api/ai/settings
     * Upserts a provider setting for the authenticated user.
     */
    public function upsert(StoreAiProviderSettingRequest $request): JsonResponse
    {
        $setting = $this->service->upsertSetting(
            $request->user(),
            $request->validated(),
        );

        return response()->json([
            'ok'      => true,
            'data'    => [
                'provider_key'  => $setting->provider_key,
                'is_configured' => $setting->isConfigured(),
            ],
            'message' => 'Provider đã được lưu thành công.',
        ], 200);
    }

    /**
     * DELETE /api/ai/settings/{providerKey}
     * Remove a provider configuration.
     */
    public function destroy(Request $request, string $providerKey): JsonResponse
    {
        $this->service->deleteSetting($request->user(), $providerKey);

        return response()->json([
            'ok'      => true,
            'message' => "Provider '{$providerKey}' đã được xóa.",
        ]);
    }

    /**
     * POST /api/ai/settings/test
     * Test provider connection without persisting credentials.
     */
    public function test(Request $request): JsonResponse
    {
        $data = $request->validate([
            'provider_key'  => ['required', 'string'],
            'api_key'       => ['nullable', 'string'],
            'base_url'      => ['nullable', 'url'],
            'default_model' => ['nullable', 'string', 'max:100'],
        ]);

        $result = $this->service->testConnection($data, $request->user());

        return response()->json([
            'ok'      => $result['ok'],
            'data'    => $result,
            'message' => $result['ok']
                ? 'Kết nối thành công!'
                : ($result['error'] ?? 'Kết nối thất bại.'),
        ], $result['ok'] ? 200 : 422);
    }
}
