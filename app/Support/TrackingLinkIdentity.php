<?php

declare(strict_types=1);

namespace App\Support;

final class TrackingLinkIdentity
{
    /**
     * Build a stable identity key for duplicate detection.
     */
    public static function identityKey(string $platform, ?string $destinationUrl, ?array $meta = null): ?string
    {
        $platformValue = mb_strtolower(trim($platform));

        if ($platformValue === 'shopee') {
            $productKey = self::extractShopeeProductKey($destinationUrl, $meta);
            if ($productKey !== null) {
                return "shopee:product:{$productKey}";
            }
        }

        $normalizedUrl = self::normalizeUrl($destinationUrl);
        if ($normalizedUrl === null) {
            return null;
        }

        return "{$platformValue}:url:{$normalizedUrl}";
    }

    public static function extractShopeeProductKey(?string $destinationUrl, ?array $meta = null): ?string
    {
        if (is_array($meta)) {
            $metaShopId = trim((string) ($meta['offer_shop_id'] ?? $meta['shop_id'] ?? $meta['product_shop_id'] ?? ''));
            $metaItemId = trim((string) ($meta['offer_item_id'] ?? $meta['item_id'] ?? $meta['product_id'] ?? ''));
            if ($metaShopId !== '' && $metaItemId !== '') {
                return "{$metaShopId}:{$metaItemId}";
            }
        }

        if ($destinationUrl === null || trim($destinationUrl) === '') {
            return null;
        }

        $url = trim($destinationUrl);
        $path = (string) (parse_url($url, PHP_URL_PATH) ?? '');

        if (preg_match('~/(?:product|i)/(?:[^/]+/)?(\d+)/(\d+)~', $path, $matches) === 1) {
            return "{$matches[1]}:{$matches[2]}";
        }

        if (preg_match('~i\.(\d+)\.(\d+)~', $path, $matches) === 1) {
            return "{$matches[1]}:{$matches[2]}";
        }

        if (preg_match('~/product/(\d+)/(\d+)~', $url, $matches) === 1) {
            return "{$matches[1]}:{$matches[2]}";
        }

        parse_str((string) (parse_url($url, PHP_URL_QUERY) ?? ''), $query);
        $shopId = trim((string) ($query['shopid'] ?? $query['shop_id'] ?? ''));
        $itemId = trim((string) ($query['itemid'] ?? $query['item_id'] ?? ''));
        if ($shopId !== '' && $itemId !== '') {
            return "{$shopId}:{$itemId}";
        }

        return null;
    }

    public static function normalizeUrl(?string $url): ?string
    {
        if ($url === null) {
            return null;
        }

        $raw = trim($url);
        if ($raw === '') {
            return null;
        }

        $parts = parse_url($raw);
        if ($parts === false) {
            return mb_strtolower($raw);
        }

        $scheme = mb_strtolower((string) ($parts['scheme'] ?? 'https'));
        $host = mb_strtolower((string) ($parts['host'] ?? ''));
        $path = (string) ($parts['path'] ?? '');

        if ($path === '') {
            $path = '/';
        }
        $path = preg_replace('~/+~', '/', $path) ?? $path;
        if ($path !== '/') {
            $path = rtrim($path, '/');
        }

        parse_str((string) ($parts['query'] ?? ''), $query);
        if (is_array($query) && $query !== []) {
            foreach (array_keys($query) as $key) {
                $normalizedKey = mb_strtolower((string) $key);
                if (
                    str_starts_with($normalizedKey, 'utm_')
                    || in_array($normalizedKey, ['fbclid', 'gclid', 'sub_id', 'subid', 'aff_sub', 'aff_sub1'], true)
                ) {
                    unset($query[$key]);
                }
            }
            ksort($query);
        }

        $queryString = is_array($query) && $query !== []
            ? http_build_query($query, '', '&', PHP_QUERY_RFC3986)
            : '';

        if ($host === '') {
            return mb_strtolower($raw);
        }

        return $scheme . '://' . $host . $path . ($queryString !== '' ? "?{$queryString}" : '');
    }
}
