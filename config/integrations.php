<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Feature Flags
    |--------------------------------------------------------------------------
    */

    'enable_cookie_method' => (bool) env('INTEGRATIONS_ENABLE_COOKIE_METHOD', true),

    /*
    |--------------------------------------------------------------------------
    | Shopee Affiliate Integration
    |--------------------------------------------------------------------------
    */

    'shopee' => [
        // GraphQL API endpoint (region-specific)
        'base_url'        => env('SHOPEE_AFFILIATE_API_URL', 'https://open-api.affiliate.shopee.vn/graphql'),

        'sync_mode'       => env('SHOPEE_SYNC_MODE', 'manual'),     // manual | scheduled
        'sync_interval'   => (int) env('SHOPEE_SYNC_INTERVAL', 60), // minutes

        // Backfill window (days) — how far back to pull orders on each sync
        'backfill_days'   => (int) env('SHOPEE_BACKFILL_DAYS', 14),

        // Hard limit — maximum backfill depth to avoid API quota exhaustion
        'hard_limit_days' => (int) env('SHOPEE_HARD_LIMIT_DAYS', 30),

        // Offer discovery cache TTL (minutes)
        'offer_cache_ttl' => (int) env('SHOPEE_OFFER_CACHE_TTL', 30),

        // Daily campaign sync time in server timezone (HH:MM)
        'campaign_sync_at' => env('SHOPEE_CAMPAIGN_SYNC_AT', '09:05'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Lazada Affiliate Integration
    |--------------------------------------------------------------------------
    */

    'lazada' => [
        // Region code: VN, MY, SG, PH, TH, ID
        'region'          => env('LAZADA_REGION', 'VN'),

        // Backfill window (days) — how far back to pull orders on each sync
        'backfill_days'   => (int) env('LAZADA_BACKFILL_DAYS', 14),

        // Hard limit — maximum backfill depth to avoid dashboard rate-limiting
        'hard_limit_days' => (int) env('LAZADA_HARD_LIMIT_DAYS', 30),
    ],

    /*
    |--------------------------------------------------------------------------
    | TikTok Shop Affiliate Integration
    |--------------------------------------------------------------------------
    */

    'tiktok' => [
        // Global Custom App credentials (NOT per-connection)
        'app_key'       => env('TIKTOK_APP_KEY', ''),
        'app_secret'    => env('TIKTOK_APP_SECRET', ''),

        // OAuth endpoints
        'auth_url'      => env('TIKTOK_AUTH_URL', 'https://auth.tiktok-shops.com/oauth/authorize'),
        'token_url'     => env('TIKTOK_TOKEN_URL', 'https://auth.tiktok-shops.com/api/v2/token/get'),
        'refresh_url'   => env('TIKTOK_REFRESH_URL', 'https://auth.tiktok-shops.com/api/v2/token/refresh'),
        'redirect_uri'  => env('TIKTOK_REDIRECT_URI', ''),

        // Open API base URL
        'base_url'      => env('TIKTOK_API_BASE_URL', 'https://open-api.tiktokglobalshop.com'),

        // API versions — config-driven, not hardcoded
        'api_versions'  => [
            'authorized_shop'  => env('TIKTOK_API_VER_AUTH_SHOP', '202309'),
            'orders_search'    => env('TIKTOK_API_VER_ORDERS', '202309'),
            'promotion_link'   => env('TIKTOK_API_VER_PROMO_LINK', '202405'),
        ],

        // Sync defaults
        'backfill_days'   => (int) env('TIKTOK_BACKFILL_DAYS', 14),
        'hard_limit_days' => (int) env('TIKTOK_HARD_LIMIT_DAYS', 30),

        // Temp auth key TTL (seconds) for multi-shop selection
        'tmp_auth_ttl'  => (int) env('TIKTOK_TMP_AUTH_TTL', 600), // 10 minutes
    ],

    /*
    |--------------------------------------------------------------------------
    | Sync Scheduler Settings
    |--------------------------------------------------------------------------
    */

    'sync' => [
        // How often auto-sync runs (minutes)
        'interval_minutes'  => (int) env('SYNC_INTERVAL_MINUTES', 60),

        // Overlap window (hours) for incremental sync after first successful run
        'incremental_overlap_hours' => (int) env('SYNC_INCREMENTAL_OVERLAP_HOURS', 6),

        // Maximum sync duration before lock expires (seconds)
        'lock_ttl_seconds'  => (int) env('SYNC_LOCK_TTL', 900), // 15 minutes

        // Chunk size for batch upsert
        'upsert_chunk_size' => (int) env('SYNC_UPSERT_CHUNK', 500),

        // Aggregate click job dedupe / overlap lock settings
        'aggregate_unique_for_seconds' => (int) env('SYNC_AGGREGATE_UNIQUE_FOR', 900),
        'aggregate_lock_release_after_seconds' => (int) env('SYNC_AGGREGATE_LOCK_RELEASE_AFTER', 15),
        'aggregate_lock_expire_after_seconds' => (int) env('SYNC_AGGREGATE_LOCK_EXPIRE_AFTER', 300),
    ],

    /*
    |--------------------------------------------------------------------------
    | Rate Limiting
    |--------------------------------------------------------------------------
    */

    'rate_limits' => [
        'redirect' => (int) env('RATE_LIMIT_REDIRECT', 60),  // per minute
        'login'    => (int) env('RATE_LIMIT_LOGIN', 5),       // per minute
        'import'   => (int) env('RATE_LIMIT_IMPORT', 10),     // per minute
    ],

    /*
    |--------------------------------------------------------------------------
    | Retention
    |--------------------------------------------------------------------------
    */

    'retention' => [
        'clicks_days' => (int) env('RETENTION_CLICKS_DAYS', 90),
    ],

];
