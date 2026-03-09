<?php
use App\Enums\Platform;
use App\Enums\CampaignStatus;
use App\Enums\OrderStatus;
use App\Enums\UserRole;
use App\Enums\LinkStatus;

/**
 * config affentra for Inertia.js
 * 
 * @return array
 */
return [
    'platforms' => collect(Platform::cases())
        ->mapWithKeys(fn ($e) => [$e->value => $e->label()])
        ->all(),

    'campaign_statuses' => collect(CampaignStatus::cases())
        ->mapWithKeys(fn ($e) => [$e->value => $e->label()])
        ->all(),

    'order_statuses' => collect(OrderStatus::cases())
        ->mapWithKeys(fn ($e) => [$e->value => $e->label()])
        ->all(),

    'user_roles' => collect(UserRole::cases())
        ->mapWithKeys(fn ($e) => [$e->value => $e->label()])
        ->all(),

    'link_statuses' => collect(LinkStatus::cases())
        ->mapWithKeys(fn ($e) => [$e->value => $e->label()])
        ->all(),

    'ai_media_disk' => env('FILESYSTEM_AI_MEDIA_DISK', 'ai_media'),
];
