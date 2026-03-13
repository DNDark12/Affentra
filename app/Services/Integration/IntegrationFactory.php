<?php

declare(strict_types=1);

namespace App\Services\Integration;

use App\Contracts\Services\IntegrationContract;
use App\Services\Integration\Lazada\LazadaIntegration;
use App\Services\Integration\Shopee\ShopeeIntegration;

/**
 * Factory that resolves the correct IntegrationContract adapter
 * based on a platform identifier string.
 *
 * Multi-platform ready: add new cases here as new adapters are built.
 */
class IntegrationFactory
{
    /**
     * @var array<string, class-string<IntegrationContract>>
     */
    private static array $adapters = [
        'shopee' => ShopeeIntegration::class,
        'lazada' => LazadaIntegration::class,
        // Future:
        // 'tiktok' => TiktokIntegration::class,
    ];

    /**
     * Resolve the integration adapter for a given platform.
     *
     * @throws \InvalidArgumentException if platform is not supported
     */
    public static function make(string $platform): IntegrationContract
    {
        $class = self::$adapters[$platform] ?? null;

        if ($class === null) {
            throw new \InvalidArgumentException("Unsupported platform: {$platform}");
        }

        return app($class);
    }

    /**
     * Check if a platform has a registered adapter.
     */
    public static function supports(string $platform): bool
    {
        return isset(self::$adapters[$platform]);
    }

    /**
     * Get all supported platform identifiers.
     *
     * @return list<string>
     */
    public static function supportedPlatforms(): array
    {
        return array_keys(self::$adapters);
    }
}
