<?php

declare(strict_types=1);

namespace App\Services\Integration;

use App\Models\PlatformConnection;

/**
 * Centralized cookie extraction, normalization, and merge for Shopee connections.
 *
 * The PlatformConnection::cookie_header stores a JSON structure:
 * {
 *   "cookie": "SPC_EC=...; SPC_F=...",
 *   "profiles": { ... },
 *   "raw_headers": { ... },
 *   ...
 * }
 *
 * This service provides the single source of truth for extracting the raw
 * cookie string from that structure.
 */
class CookieCredentialService
{
    /**
     * Extract the raw cookie string from a PlatformConnection's cookie_header.
     *
     * Handles both JSON format (current) and legacy plain-string format.
     */
    public function extractRawCookie(PlatformConnection $connection): string
    {
        $raw = $connection->cookie_header;

        if (empty($raw)) {
            return '';
        }

        // Attempt JSON decode — current format stores { "cookie": "...", ... }
        $decoded = json_decode($raw, true);

        if (is_array($decoded) && isset($decoded['cookie'])) {
            return $this->normalize((string) $decoded['cookie']);
        }

        // Legacy format: plain cookie string
        if (json_last_error() !== JSON_ERROR_NONE) {
            return $this->normalize($raw);
        }

        return '';
    }

    /**
     * Parse a raw cookie string into [name => value] pairs.
     * Deduplicates by name (last value wins).
     * Handles values containing '=' (e.g., base64 tokens).
     */
    public function parseCookiePairs(string $raw): array
    {
        $pairs = [];

        foreach (explode(';', $raw) as $segment) {
            $segment = trim($segment);
            if ($segment === '' || ! str_contains($segment, '=')) {
                continue;
            }

            // Split only on the first '=' to preserve base64 values
            [$name, $value] = explode('=', $segment, 2);
            $name = trim($name);
            $value = trim($value);

            if ($name !== '') {
                $pairs[$name] = $value;
            }
        }

        return $pairs;
    }

    /**
     * Merge two raw cookie strings. New overrides old by name.
     * Returns the merged cookie string in canonical (sorted) form.
     */
    public function mergeCookies(string $old, string $new): string
    {
        $oldPairs = $this->parseCookiePairs($old);
        $newPairs = $this->parseCookiePairs($new);

        $merged = array_merge($oldPairs, $newPairs);
        ksort($merged);

        return $this->pairsToString($merged);
    }

    /**
     * Compute a canonical hash of a raw cookie string (order-insensitive).
     * Returns first 12 chars of sha256 hex digest.
     */
    public function hashCookie(string $raw): string
    {
        $pairs = $this->parseCookiePairs($raw);
        ksort($pairs);

        $canonical = implode(';', array_map(
            static fn (string $name, string $value): string => "{$name}={$value}",
            array_keys($pairs),
            array_values($pairs),
        ));

        return substr(hash('sha256', $canonical), 0, 12);
    }

    /**
     * Validate that a raw cookie string contains the minimum required
     * Shopee session key (SPC_EC).
     */
    public function isValidShopeeCookie(string $raw): bool
    {
        if ($raw === '') {
            return false;
        }

        $pairs = $this->parseCookiePairs($raw);

        return isset($pairs['SPC_EC']) && $pairs['SPC_EC'] !== '';
    }

    /**
     * Normalize a raw cookie string:
     * - Trim whitespace
     * - Remove leading "Cookie:" prefix (if accidentally included)
     * - Collapse multiple spaces
     * - Remove trailing semicolons
     */
    public function normalize(string $raw): string
    {
        $raw = trim($raw);

        // Remove accidental "Cookie:" prefix
        if (stripos($raw, 'cookie:') === 0) {
            $raw = trim(substr($raw, 7));
        }

        // Collapse multiple spaces
        $raw = (string) preg_replace('/\s+/', ' ', $raw);

        // Remove trailing semicolons
        $raw = rtrim($raw, '; ');

        return $raw;
    }

    /**
     * Convert pairs array to canonical cookie string.
     */
    private function pairsToString(array $pairs): string
    {
        $segments = [];
        foreach ($pairs as $name => $value) {
            $segments[] = "{$name}={$value}";
        }

        return implode('; ', $segments);
    }
}
