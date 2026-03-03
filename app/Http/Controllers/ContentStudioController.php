<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\AiProviderSetting;
use App\Models\TrackingLink;
use App\Services\AI\AiSettingsService;
use Inertia\Inertia;
use Inertia\Response;

class ContentStudioController extends Controller
{
    /**
     * Presets exposed to the frontend.
     * Must stay in sync with PromptTemplateRegistry.
     */
    private const PRESETS = [
        [
            'id'       => 'fb_post_v1',
            'label'    => 'Facebook Post',
            'platform' => 'facebook',
        ],
        [
            'id'       => 'tiktok_caption_v1',
            'label'    => 'TikTok Caption',
            'platform' => 'tiktok',
        ],
        [
            'id'       => 'hashtags_pack_v1',
            'label'    => 'Hashtag Pack',
            'platform' => 'generic',
        ],
    ];

    public function index(): Response
    {
        $user = request()->user();

        /** @var \Illuminate\Database\Eloquent\Collection $links */
        $links = TrackingLink::query()
            ->where('user_id', $user->id)
            ->active()                        // uses scopeActive() → LinkStatus::Active enum
            ->with(['campaign:id,name'])
            ->select(['id', 'short_code', 'destination_url', 'campaign_id', 'product_name', 'product_price', 'product_image_urls', 'created_at'])
            ->latest()
            ->limit(100)
            ->get()
            ->map(fn ($link) => [
                'id'                 => $link->id,
                'short_code'         => $link->short_code,
                'track_url'          => route('redirect', $link->short_code),
                'destination_url'    => $link->destination_url,
                'campaign_name'      => $link->campaign?->name,
                'product_name'       => $link->product_name,
                'product_price'      => $link->product_price,
                'product_image_urls' => $link->product_image_urls ?? [],
            ]);

        // User's configured AI providers (for provider/model selector)
        $configuredProviders = AiProviderSetting::query()
            ->where('user_id', $user->id)
            ->where('status', 'enabled')
            ->get(['provider_key', 'default_model', 'label', 'capabilities'])
            ->map(fn ($s) => [
                'key'          => $s->provider_key,
                'label'        => $s->label ?: AiSettingsService::PROVIDER_REGISTRY[$s->provider_key]['name'] ?? $s->provider_key,
                'model'        => $s->default_model,
                'capabilities' => $s->capabilities ?? [],
            ])
            ->values();

        return Inertia::render('AI/ContentStudio', [
            'trackingLinks'      => $links,
            'presets'            => self::PRESETS,
            'configuredProviders'=> $configuredProviders,
        ]);
    }
}
