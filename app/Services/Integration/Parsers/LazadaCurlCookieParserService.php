<?php

declare(strict_types=1);

namespace App\Services\Integration\Parsers;

use RuntimeException;

/**
 * Parses cURL commands captured from the Lazada Affiliate Dashboard.
 *
 * Unlike Shopee (GraphQL + special tokens), Lazada's dashboard is
 * a standard HTTP JSON API. We extract the Cookie header plus any
 * Lazada-specific tokens needed to authenticate requests.
 *
 * Cookie-based integration is a pragmatic fallback while the affiliate
 * account is pending AdSense API approval.
 */
class LazadaCurlCookieParserService
{
    /**
     * Expected Lazada affiliate session cookies (at least one must be present).
     *
     * NOTE: These are placeholder names. Update with real values once a cURL
     * sample from the actual Lazada Affiliate Dashboard is available.
     * Common patterns: _lzd_*, hng, t_uid, aep_usuc_f, etc.
     */
    private const LAZADA_SESSION_MARKERS = [
        '_lzd_',   // Lazada auth prefix
        'hng=',    // Lazada country/auth cookie
        't_uid=',  // Lazada user ID cookie
        'lzd_cid=', // Lazada consumer ID
    ];

    /**
     * Accepted domains for Lazada Affiliate Dashboard.
     */
    private const ALLOWED_DOMAINS = [
        'adsense.lazada.vn',
        'adsense.lazada.com.sg',
        'adsense.lazada.com.my',
        'adsense.lazada.com.ph',
        'adsense.lazada.co.th',
        'adsense.lazada.co.id',
        'lazada.vn',
        'lazada.com',
    ];

    /**
     * Parse one or many cURL commands from a textarea input.
     *
     * @return list<array{
     *   cookie: string,
     *   user_agent: string|null,
     *   accept_language: string|null,
     *   csrf_token: string|null,
     *   x_csrf_token: string|null,
     *   authorization: string|null,
     *   raw_headers: array<string, string>,
     *   raw_header_lines: list<array{name: string, value: string}>,
     *   request_url: string|null,
     *   referer: string|null,
     *   origin: string|null,
     *   request_body: string|null,
     * }>
     */
    public function parseMany(string $input): array
    {
        $trimmed = trim($input);
        if ($trimmed === '') {
            return [];
        }

        $blocks = [];
        $current = [];

        foreach (preg_split('/\R/u', $trimmed) ?: [] as $line) {
            $lineTrimmed = ltrim($line);
            if (str_starts_with($lineTrimmed, 'curl ')) {
                if ($current !== []) {
                    $blocks[] = implode("\n", $current);
                    $current = [];
                }
            }

            $current[] = $line;
        }

        if ($current !== []) {
            $blocks[] = implode("\n", $current);
        }

        if ($blocks === []) {
            $blocks = [$trimmed];
        }

        $parsed = [];
        foreach ($blocks as $block) {
            if (trim($block) === '') {
                continue;
            }

            $parsed[] = $this->parse($block);
        }

        return $parsed;
    }

    /**
     * Parse a single cURL command from the Lazada Affiliate Dashboard.
     *
     * @return array{
     *   cookie: string,
     *   user_agent: string|null,
     *   accept_language: string|null,
     *   csrf_token: string|null,
     *   x_csrf_token: string|null,
     *   authorization: string|null,
     *   raw_headers: array<string, string>,
     *   raw_header_lines: list<array{name: string, value: string}>,
     *   request_url: string|null,
     *   referer: string|null,
     *   origin: string|null,
     *   request_body: string|null,
     * }
     */
    public function parse(string $curlCommand): array
    {
        // 1. Validate that the URL belongs to a Lazada domain.
        $requestUrl = $this->extractRequestUrl($curlCommand);
        if (! $this->isLazadaDomain($curlCommand)) {
            throw new RuntimeException(
                'cURL command must target a Lazada domain (e.g., adsense.lazada.vn). ' .
                'Please copy the cURL from your Lazada Affiliate Dashboard.'
            );
        }

        // 2. Extract the Cookie header.
        $cookie = $this->extractHeader('Cookie', $curlCommand)
            ?? $this->extractHeader('cookie', $curlCommand)
            ?? $this->extractCookieArgument($curlCommand);

        if (empty($cookie)) {
            throw new RuntimeException(
                'Failed to extract Cookie from cURL command. ' .
                'Please ensure you copied the full cURL request from the Lazada dashboard.'
            );
        }

        // 3. Validate session cookie presence (at least one Lazada session marker).
        if (! $this->hasLazadaSessionCookie($cookie)) {
            throw new RuntimeException(
                'Cookie does not appear to contain a valid Lazada session. ' .
                'Please ensure you are copying a cURL from the Lazada Affiliate Dashboard, ' .
                'not from a third-party page.'
            );
        }

        // 4. Extract supplementary headers.
        $rawHeaderLines = $this->extractAllHeaderLines($curlCommand);
        $rawHeaders     = $this->extractAllHeaders($rawHeaderLines);

        $userAgent      = $this->extractHeader('user-agent', $curlCommand)
            ?? $this->extractHeader('User-Agent', $curlCommand);
        $acceptLanguage = $this->extractHeader('accept-language', $curlCommand)
            ?? $this->extractHeader('Accept-Language', $curlCommand);
        $csrfToken      = $this->extractHeader('csrf-token', $curlCommand)
            ?? $this->extractHeader('x-csrf-token', $curlCommand);
        $xCsrfToken     = $this->extractHeader('x-csrf-token', $curlCommand);
        $authorization  = $this->extractHeader('authorization', $curlCommand)
            ?? $this->extractHeader('Authorization', $curlCommand);
        $referer        = $this->extractHeader('referer', $curlCommand)
            ?? $this->extractHeader('Referer', $curlCommand);
        $origin         = $this->extractHeader('origin', $curlCommand)
            ?? $this->extractHeader('Origin', $curlCommand);
        $requestBody    = $this->extractDataPayload($curlCommand);

        return [
            'cookie'           => $cookie,
            'user_agent'       => $userAgent,
            'accept_language'  => $acceptLanguage,
            'csrf_token'       => $csrfToken,
            'x_csrf_token'     => $xCsrfToken,
            'authorization'    => $authorization,
            'raw_headers'      => $rawHeaders,
            'raw_header_lines' => $rawHeaderLines,
            'request_url'      => $requestUrl,
            'referer'          => $referer,
            'origin'           => $origin,
            'request_body'     => $requestBody,
        ];
    }

    // ─── Private Helpers ─────────────────────────────────────────────────────

    private function isLazadaDomain(string $command): bool
    {
        foreach (self::ALLOWED_DOMAINS as $domain) {
            if (preg_match('/\bcurl\b.*https?:\/\/' . preg_quote($domain, '/') . '/is', trim($command))) {
                return true;
            }
        }

        return false;
    }

    private function hasLazadaSessionCookie(string $cookie): bool
    {
        foreach (self::LAZADA_SESSION_MARKERS as $marker) {
            if (str_contains($cookie, $marker)) {
                return true;
            }
        }

        // Fallback: if cookie is non-empty and long enough, accept it (some regions may use different names).
        // This makes the parser more flexible when anh provides a cURL sample.
        return strlen($cookie) > 50;
    }

    private function extractRequestUrl(string $command): ?string
    {
        // Match: curl 'https://adsense.lazada.vn/...' or curl "https://..."
        if (preg_match('/\bcurl\b\s+[\'"]?(https?:\/\/[^\s\'"\\\\]+)[\'"]?/i', $command, $matches)) {
            return trim($matches[1]);
        }

        return null;
    }

    private function extractDataPayload(string $command): ?string
    {
        $pattern = "/(?:--data-raw|--data-binary|--data)\s*[\^]*(['\"])(.*?)[\^]*\1/is";
        if (preg_match($pattern, $command, $matches)) {
            $value = $matches[2];
            $value = str_replace('\\' . $matches[1], $matches[1], $value);

            return trim($value);
        }

        return null;
    }

    private function extractCookieArgument(string $command): ?string
    {
        $pattern = "/(?:-b|--cookie)\s*[\^]*(['\"])\s*(.*?)[\^]*\1/is";
        if (preg_match($pattern, $command, $matches)) {
            $value = $matches[2];
            $value = str_replace('\\' . $matches[1], $matches[1], $value);

            return trim($value);
        }

        return null;
    }

    private function extractHeader(string $headerName, string $command): ?string
    {
        $pattern = "/(?:-H|--header)\s*[\^]*(['\"])\s*" . preg_quote($headerName, '/') . "\s*:\s*(.*?)[\^]*\1/is";

        if (preg_match($pattern, $command, $matches)) {
            $value = $matches[2];
            $value = str_replace('\\' . $matches[1], $matches[1], $value);

            return trim($value);
        }

        $patternNoQuotes = "/(?:-H|--header)\s*" . preg_quote($headerName, '/') . "\s*:\s*([^\s'-]+)/is";
        if (preg_match($patternNoQuotes, $command, $matches)) {
            return trim($matches[1]);
        }

        return null;
    }

    /**
     * @return list<array{name: string, value: string}>
     */
    private function extractAllHeaderLines(string $command): array
    {
        $headers = [];
        $pattern = "/(?:-H|--header)\s*[\^]*(['\"])(.*?)[\^]*\1/is";

        if (preg_match_all($pattern, $command, $matches, PREG_SET_ORDER) !== false) {
            foreach ($matches as $match) {
                $raw   = str_replace('\\' . $match[1], $match[1], (string) ($match[2] ?? ''));
                $parts = explode(':', $raw, 2);
                if (count($parts) !== 2) {
                    continue;
                }

                $name  = trim($parts[0]);
                $value = trim($parts[1]);
                if ($name === '' || $value === '') {
                    continue;
                }

                $headers[] = [
                    'name'  => $name,
                    'value' => $value,
                ];
            }
        }

        return $headers;
    }

    /**
     * @param  list<array{name: string, value: string}>  $lines
     * @return array<string, string>
     */
    private function extractAllHeaders(array $lines): array
    {
        $headers = [];

        foreach ($lines as $line) {
            $name  = mb_strtolower(trim((string) ($line['name'] ?? '')));
            $value = trim((string) ($line['value'] ?? ''));
            if ($name === '' || $value === '') {
                continue;
            }
            $headers[$name] = $value;
        }

        return $headers;
    }
}
