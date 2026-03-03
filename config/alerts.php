<?php

declare(strict_types=1);

return [
    'evaluation' => [
        // Frequency hint for UI and future scheduler integration.
        'interval_minutes' => (int) env('ALERT_EVALUATION_INTERVAL_MINUTES', 15),
    ],
];
