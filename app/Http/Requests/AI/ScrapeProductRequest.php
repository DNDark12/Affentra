<?php

declare(strict_types=1);

namespace App\Http\Requests\AI;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class ScrapeProductRequest extends FormRequest
{
    /**
     * Allowed e-commerce domains (exact host match OR *.subdomain of these).
     * Only HTTPS is permitted. Add new platforms here when needed.
     */
    private const ALLOWED_DOMAINS = [
        'shopee.vn',
        'shopee.co.id',
        'lazada.vn',
        'lazada.com.my',
        'tiktok.com',
        'vn.shp.ee',
        'shp.ee',
    ];

    /**
     * Private / reserved IP CIDR ranges to block (defense-in-depth).
     */
    private const BLOCKED_CIDRS = [
        '127.0.0.0/8',
        '10.0.0.0/8',
        '172.16.0.0/12',
        '192.168.0.0/16',
        '169.254.0.0/16',
        '::1/128',
        'fe80::/10',
        'fc00::/7',
    ];

    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'url' => ['required', 'string', 'max:2048'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $v): void {
            $url = (string) $this->input('url', '');

            if (! $this->validateHttps($url)) {
                $v->errors()->add('url', 'Only HTTPS URLs are allowed.');
                return;
            }

            $host = $this->extractNormalizedHost($url);
            if ($host === null) {
                $v->errors()->add('url', 'Invalid URL format.');
                return;
            }

            if (! $this->isAllowedDomain($host)) {
                $v->errors()->add('url', 'URL domain is not in the allowed list of supported platforms.');
                return;
            }

            if ($this->isPrivateHost($host)) {
                $v->errors()->add('url', 'URL resolves to a private or reserved address.');
            }
        });
    }

    private function validateHttps(string $url): bool
    {
        return str_starts_with(strtolower($url), 'https://');
    }

    private function extractNormalizedHost(string $url): ?string
    {
        $parsed = parse_url($url);
        if (empty($parsed['host'])) {
            return null;
        }

        // Normalize: lowercase, strip trailing dot
        return strtolower(rtrim($parsed['host'], '.'));
    }

    private function isAllowedDomain(string $host): bool
    {
        foreach (self::ALLOWED_DOMAINS as $allowed) {
            $allowed = strtolower($allowed);

            // Exact match
            if ($host === $allowed) {
                return true;
            }

            // Subdomain match — must end with '.' + allowed to avoid shopee.vn.evil.com trap
            if (str_ends_with($host, '.' . $allowed)) {
                return true;
            }
        }

        return false;
    }

    private function isPrivateHost(string $host): bool
    {
        if (in_array($host, ['localhost'], true)) {
            return true;
        }

        // Attempt to resolve the host to an IP for private range check
        $ip = filter_var($host, FILTER_VALIDATE_IP) !== false
            ? $host
            : (gethostbyname($host) ?: null);

        if ($ip === null || $ip === $host) {
            // Could not resolve — fail open (do not block unresolved domains)
            return false;
        }

        foreach (self::BLOCKED_CIDRS as $cidr) {
            if ($this->ipInCidr($ip, $cidr)) {
                return true;
            }
        }

        return false;
    }

    private function ipInCidr(string $ip, string $cidr): bool
    {
        [$subnet, $bits] = explode('/', $cidr);
        $bits = (int) $bits;

        // IPv6
        if (str_contains($subnet, ':')) {
            if (! filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) {
                return false;
            }
            $ipBin = inet_pton($ip);
            $subnetBin = inet_pton($subnet);
            if ($ipBin === false || $subnetBin === false) {
                return false;
            }
            $ipUnpacked = unpack('C*', $ipBin);
            $subnetUnpacked = unpack('C*', $subnetBin);
            $fullBytes = intdiv($bits, 8);
            $remainBits = $bits % 8;
            for ($i = 1; $i <= $fullBytes; $i++) {
                if (($ipUnpacked[$i] ?? 0) !== ($subnetUnpacked[$i] ?? 0)) {
                    return false;
                }
            }
            if ($remainBits > 0) {
                $mask = 0xFF & (0xFF << (8 - $remainBits));
                return (($ipUnpacked[$fullBytes + 1] ?? 0) & $mask) === (($subnetUnpacked[$fullBytes + 1] ?? 0) & $mask);
            }
            return true;
        }

        // IPv4
        if (! filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            return false;
        }

        $ipLong     = ip2long($ip);
        $subnetLong = ip2long($subnet);
        $mask       = $bits === 0 ? 0 : (~0 << (32 - $bits));

        return ($ipLong & $mask) === ($subnetLong & $mask);
    }
}
