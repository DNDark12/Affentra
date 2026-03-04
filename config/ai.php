<?php

declare(strict_types=1);

return [
    /*
    |--------------------------------------------------------------------------
    | AI Provider Configuration
    |--------------------------------------------------------------------------
    |
    | Provider credentials are managed via AiSettingsService (DB per-user).
    | Only shared defaults that cannot be per-user live here.
    |
    */

    /*
    |--------------------------------------------------------------------------
    | Feature Flags
    |--------------------------------------------------------------------------
    */
    'features' => [
        'enable_text'          => env('AI_FEATURE_TEXT',  true),
        'enable_image'         => env('AI_FEATURE_IMAGE', true),
        'enable_video'         => env('AI_FEATURE_VIDEO', true),
        'allow_force_new_seed' => env('AI_ALLOW_FORCE_SEED', true),
        'max_variants'         => (int) env('AI_MAX_VARIANTS', 5),
    ],
];
