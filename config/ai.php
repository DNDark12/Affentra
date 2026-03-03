<?php

declare(strict_types=1);

return [
    /*
    |--------------------------------------------------------------------------
    | AI Provider Configuration
    |--------------------------------------------------------------------------
    |
    | All AI-related settings. Runtime will read from AiSettingsService
    | (Phase 14) which overlays DB config on top of these .env defaults.
    |
    */

    'providers' => [
        'default_text'  => env('AI_PROVIDER_TEXT',  'gemini'),
        'default_image' => env('AI_PROVIDER_IMAGE', 'gemini'),
        'default_video' => env('AI_PROVIDER_VIDEO', 'veo'),

        'gemini' => [
            'key'   => env('AI_PROVIDER_GEMINI_KEY'),
            'model' => env('AI_PROVIDER_GEMINI_MODEL', 'gemini-1.5-flash'),
        ],

        'openai' => [
            'key'   => env('AI_PROVIDER_OPENAI_KEY'),
            'model' => env('AI_PROVIDER_OPENAI_MODEL', 'gpt-4o-mini'),
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Daily Token Quotas per Role
    |--------------------------------------------------------------------------
    |
    | Used by ContentGenerationService to enforce Redis-backed daily limits.
    |
    */
    'quotas' => [
        'ctv_tokens_per_day'    => env('AI_QUOTA_CTV',    5_000),
        'leader_tokens_per_day' => env('AI_QUOTA_LEADER', 20_000),
        'owner_tokens_per_day'  => env('AI_QUOTA_OWNER',  100_000),
    ],

    /*
    |--------------------------------------------------------------------------
    | Feature Flags
    |--------------------------------------------------------------------------
    */
    'features' => [
        'enable_text'         => env('AI_FEATURE_TEXT',  true),
        'enable_image'        => env('AI_FEATURE_IMAGE', false), // Phase 2
        'enable_video'        => env('AI_FEATURE_VIDEO', false), // Phase 3
        'allow_force_new_seed' => env('AI_ALLOW_FORCE_SEED', true),
        'max_variants'        => (int) env('AI_MAX_VARIANTS', 10),
    ],
];
