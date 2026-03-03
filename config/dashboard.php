<?php

declare(strict_types=1);

return [
    /*
    |--------------------------------------------------------------------------
    | Dashboard Cache TTL (seconds)
    |--------------------------------------------------------------------------
    |
    | Set to 0 to disable cache entirely. Keep this low so manual sync updates
    | appear quickly while still reducing repeated heavy aggregation queries.
    |
    */
    'cache_ttl_seconds' => (int) env('DASHBOARD_CACHE_TTL_SECONDS', 120),
];
