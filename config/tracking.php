<?php

declare(strict_types=1);

return [
    'presets' => [
        'top_performing' => [
            'min_orders' => (int) env('TRACKING_PRESET_TOP_MIN_ORDERS', 1),
            'min_commission' => (float) env('TRACKING_PRESET_TOP_MIN_COMMISSION', 50000),
        ],
        'underperforming' => [
            'min_clicks' => (int) env('TRACKING_PRESET_LOW_MIN_CLICKS', 100),
            'max_order_rate' => (float) env('TRACKING_PRESET_LOW_MAX_ORDER_RATE', 0.01),
        ],
    ],
];

