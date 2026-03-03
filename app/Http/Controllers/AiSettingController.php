<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Services\AI\AiSettingsService;
use Inertia\Inertia;
use Inertia\Response;

class AiSettingController
{
    public function __construct(
        private readonly AiSettingsService $service,
    ) {}

    public function index(): Response
    {
        $user     = request()->user();
        $settings = $this->service->getSettingsForUser($user)
            ->map(fn ($s) => [
                'provider_key'        => $s->provider_key,
                'label'               => $s->label,
                'default_model'       => $s->default_model,
                'status'              => $s->status,
                'capabilities'        => $s->capabilities ?? ['text'],
                'token_quota_per_day' => $s->token_quota_per_day,
                'is_configured'       => $s->isConfigured(),
                'last_test_status'    => $s->last_test_status,              // 'ok' | 'error' | null
                'last_test_error'     => $s->last_test_error,
                'last_tested_at'      => $s->last_tested_at?->toDateTimeString(),
                'updated_at'          => $s->updated_at?->toDateTimeString(),
            ])
            ->values();

        return Inertia::render('Settings/AiSettings', [
            'userSettings' => $settings,
            'registry'     => AiSettingsService::PROVIDER_REGISTRY,
        ]);
    }
}
