<?php

declare(strict_types=1);

$toOrigin = static function (?string $url): ?string {
    if (! is_string($url) || trim($url) === '') {
        return null;
    }

    $parts = parse_url(trim($url));
    if (! is_array($parts) || ! isset($parts['scheme'], $parts['host'])) {
        return null;
    }

    $host = (string) $parts['host'];
    if (str_contains($host, ':') && ! str_starts_with($host, '[')) {
        $host = '[' . $host . ']';
    }

    $port = isset($parts['port']) ? ':' . (int) $parts['port'] : '';

    return sprintf('%s://%s%s', $parts['scheme'], $host, $port);
};

$appEnv = (string) env('APP_ENV', 'production');
$isLocalLike = in_array($appEnv, ['local', 'development', 'testing'], true);

$scriptSrc = ["'self'", "'unsafe-inline'", "'unsafe-eval'"];
$styleSrc = ["'self'", "'unsafe-inline'", 'https://fonts.bunny.net'];
$fontSrc = ["'self'", 'data:', 'https://fonts.bunny.net'];
$connectSrc = ["'self'", 'https://open-api.affiliate.shopee.vn'];

if ($isLocalLike) {
    $devOrigins = [];

    $defaultDevOrigins = [
        (string) env('VITE_DEV_SERVER_URL', 'http://[::1]:5173'),
        'http://localhost:5173',
        'http://127.0.0.1:5173',
    ];

    foreach ($defaultDevOrigins as $candidate) {
        $origin = $toOrigin($candidate);
        if ($origin !== null) {
            $devOrigins[] = $origin;
        }
    }

    foreach (explode(',', (string) env('SECURITY_CSP_DEV_ORIGINS', '')) as $candidate) {
        $origin = $toOrigin($candidate);
        if ($origin !== null) {
            $devOrigins[] = $origin;
        }
    }

    $devOrigins = array_values(array_unique($devOrigins));

    $connectDevOrigins = [];
    foreach ($devOrigins as $origin) {
        $connectDevOrigins[] = $origin;
        if (str_starts_with($origin, 'http://')) {
            $connectDevOrigins[] = 'ws://' . substr($origin, strlen('http://'));
        }
        if (str_starts_with($origin, 'https://')) {
            $connectDevOrigins[] = 'wss://' . substr($origin, strlen('https://'));
        }
    }

    $scriptSrc = array_values(array_unique([...$scriptSrc, ...$devOrigins]));
    $styleSrc = array_values(array_unique([...$styleSrc, ...$devOrigins]));
    $connectSrc = array_values(array_unique([...$connectSrc, ...$connectDevOrigins]));
}

return [
    'headers' => [
        'x_frame_options' => env('SECURITY_X_FRAME_OPTIONS', 'DENY'),
        'x_content_type_options' => env('SECURITY_X_CONTENT_TYPE_OPTIONS', 'nosniff'),
        'referrer_policy' => env('SECURITY_REFERRER_POLICY', 'strict-origin-when-cross-origin'),
        'permissions_policy' => env('SECURITY_PERMISSIONS_POLICY', 'camera=(), microphone=(), geolocation=()'),
        'x_permitted_cross_domain_policies' => env('SECURITY_X_PERMITTED_CROSS_DOMAIN_POLICIES', 'none'),
        'hsts' => [
            'enabled' => (bool) env('SECURITY_HSTS_ENABLED', false),
            'max_age' => (int) env('SECURITY_HSTS_MAX_AGE', 31536000),
            'include_subdomains' => (bool) env('SECURITY_HSTS_INCLUDE_SUBDOMAINS', true),
            'preload' => (bool) env('SECURITY_HSTS_PRELOAD', false),
        ],
    ],

    'csp' => [
        'enabled' => (bool) env('SECURITY_CSP_ENABLED', true),
        'report_only' => (bool) env('SECURITY_CSP_REPORT_ONLY', true),
        'report_uri' => env('SECURITY_CSP_REPORT_URI'),
        'directives' => [
            'default-src' => ["'self'"],
            'script-src' => $scriptSrc,
            'script-src-elem' => $scriptSrc,
            'style-src' => $styleSrc,
            'style-src-elem' => $styleSrc,
            'img-src' => ["'self'", 'data:', 'https:'],
            'connect-src' => $connectSrc,
            'font-src' => $fontSrc,
            'object-src' => ["'none'"],
            'base-uri' => ["'self'"],
            'form-action' => ["'self'"],
            'frame-ancestors' => ["'none'"],
        ],
    ],
];
