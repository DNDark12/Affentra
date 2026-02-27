<?php

declare(strict_types=1);

namespace App\Support;

final class ShopeeDomainValidator
{
    /**
     * @var list<string>
     */
    private const ALLOWED_SUFFIXES = [
        'vn',
        'sg',
        'co.id',
        'com.my',
        'co.th',
        'ph',
        'com.br',
        'tw',
        'com',
        'cl',
        'mx',
        'com.co',
        'kr',
        'in',
    ];

    public static function assertAllowedUrl(string $url): void
    {
        $host = self::extractHost($url);

        if ($host === null || ! self::isAllowedHost($host)) {
            throw new \InvalidArgumentException('URL must be a Shopee domain.');
        }
    }

    public static function isAllowedHost(string $host): bool
    {
        $normalizedHost = mb_strtolower(rtrim($host, '.'));
        $escapedSuffixes = implode('|', array_map(
            static fn (string $suffix): string => preg_quote($suffix, '/'),
            self::ALLOWED_SUFFIXES,
        ));
        $pattern = '/(?:^|\.)shopee\.(' . $escapedSuffixes . ')$/i';

        return preg_match($pattern, $normalizedHost) === 1;
    }

    public static function extractHost(string $url): ?string
    {
        $host = parse_url($url, PHP_URL_HOST);

        if (! is_string($host) || $host === '') {
            return null;
        }

        return $host;
    }
}
