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
