<?php

declare(strict_types=1);

namespace App\Services\Integration\Parsers;

use Illuminate\Support\Str;
use RuntimeException;

class CurlCookieParserService
{
    /**
     * Parse one or many cURL commands from a textarea input.
     *
     * @return list<array{
     *   cookie: string,
     *   user_agent: string|null,
     *   accept_language: string|null,
     *   raw_headers: array<string, string>,
     *   raw_header_lines: list<array{name: string, value: string}>,
     *   af_ac_enc_dat: string|null,
     *   af_ac_enc_sz_token: string|null,
     *   affiliate_program_type: string|null,
     *   csrf_token: string|null,
     *   x_sap_ri: string|null,
     *   x_sap_sec: string|null,
     *   x_sz_sdk_version: string|null,
     *   priority: string|null,
     *   sec_ch_ua: string|null,
     *   sec_ch_ua_mobile: string|null,
     *   sec_ch_ua_platform: string|null,
     *   sec_fetch_dest: string|null,
     *   sec_fetch_mode: string|null,
     *   sec_fetch_site: string|null,
     *   origin: string|null,
     *   request_url: string|null,
     *   referer: string|null,
     *   request_body: string|null,
     *   endpoint_key: string|null
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
     * Parse a cURL command string to extract Cookie and User-Agent headers.
     *
     * @return array{
     *   cookie: string,
     *   user_agent: string|null,
     *   accept_language: string|null,
     *   raw_headers: array<string, string>,
     *   raw_header_lines: list<array{name: string, value: string}>,
     *   af_ac_enc_dat: string|null,
     *   af_ac_enc_sz_token: string|null,
     *   affiliate_program_type: string|null,
     *   csrf_token: string|null,
     *   x_sap_ri: string|null,
     *   x_sap_sec: string|null,
     *   x_sz_sdk_version: string|null,
     *   priority: string|null,
     *   sec_ch_ua: string|null,
     *   sec_ch_ua_mobile: string|null,
     *   sec_ch_ua_platform: string|null,
     *   sec_fetch_dest: string|null,
     *   sec_fetch_mode: string|null,
     *   sec_fetch_site: string|null,
     *   origin: string|null,
     *   request_url: string|null,
     *   referer: string|null,
     *   request_body: string|null,
     *   endpoint_key: string|null
     * }
     */
    public function parse(string $curlCommand): array
    {
        // 1. Verify the domain is affiliate.shopee.vn (or other variants if needed)
        // Ensure the actual URL matches, not just a referer header
        if (!preg_match("/\bcurl\b.*https?:\/\/affiliate\.shopee\.vn(?:\/|\b)/is", trim($curlCommand))) {
            throw new RuntimeException('Invalid cURL command. URL must target affiliate.shopee.vn.');
        }

        // 2. Extract headers
        $cookie = $this->extractHeader('Cookie', $curlCommand) ?? $this->extractHeader('cookie', $curlCommand);
        
        // If not found in -H, try extracting from -b or --cookie
        if (empty($cookie)) {
            $cookie = $this->extractCookieArgument($curlCommand);
        }
        
        $userAgent = $this->extractHeader('User-Agent', $curlCommand) ?? $this->extractHeader('user-agent', $curlCommand);
        $acceptLanguage = $this->extractHeader('accept-language', $curlCommand) ?? $this->extractHeader('Accept-Language', $curlCommand);
        $rawHeaderLines = $this->extractAllHeaderLines($curlCommand);
        $rawHeaders = $this->extractAllHeaders($rawHeaderLines);

        $afAcEncDat = $this->extractHeader('af-ac-enc-dat', $curlCommand);
        $afAcEncSzToken = $this->extractHeader('af-ac-enc-sz-token', $curlCommand);
        $affiliateProgramType = $this->extractHeader('affiliate-program-type', $curlCommand);
        $csrfToken = $this->extractHeader('csrf-token', $curlCommand);
        $xSapRi = $this->extractHeader('x-sap-ri', $curlCommand);
        $xSapSec = $this->extractHeader('x-sap-sec', $curlCommand);
        $xSzSdkVersion = $this->extractHeader('x-sz-sdk-version', $curlCommand);
        $priority = $this->extractHeader('priority', $curlCommand);
        $secChUa = $this->extractHeader('sec-ch-ua', $curlCommand);
        $secChUaMobile = $this->extractHeader('sec-ch-ua-mobile', $curlCommand);
        $secChUaPlatform = $this->extractHeader('sec-ch-ua-platform', $curlCommand);
        $secFetchDest = $this->extractHeader('sec-fetch-dest', $curlCommand);
        $secFetchMode = $this->extractHeader('sec-fetch-mode', $curlCommand);
        $secFetchSite = $this->extractHeader('sec-fetch-site', $curlCommand);
        $origin = $this->extractHeader('origin', $curlCommand) ?? $this->extractHeader('Origin', $curlCommand);
        $referer = $this->extractHeader('referer', $curlCommand) ?? $this->extractHeader('Referer', $curlCommand);
        $requestBody = $this->extractDataPayload($curlCommand);
        $requestUrl = $this->extractRequestUrl($curlCommand);
        $endpointKey = $this->inferEndpointKey($requestUrl, $referer);

        if (empty($cookie)) {
            throw new RuntimeException('Failed to extract Cookie from cURL command.');
        }

        // 3. Validate Cookie content (must contain SPC_EC for Shopee authenticated sessions)
        if (!Str::contains($cookie, 'SPC_EC=')) {
            throw new RuntimeException('Cookie is missing Shopee authentication (SPC_EC). Please ensure you copied a cURL for a Shopee API request, not Google Analytics.');
        }

        return [
            'cookie' => $cookie,
            'user_agent' => $userAgent,
            'accept_language' => $acceptLanguage,
            'raw_headers' => $rawHeaders,
            'raw_header_lines' => $rawHeaderLines,
            'af_ac_enc_dat' => $afAcEncDat,
            'af_ac_enc_sz_token' => $afAcEncSzToken,
            'affiliate_program_type' => $affiliateProgramType,
            'csrf_token' => $csrfToken,
            'x_sap_ri' => $xSapRi,
            'x_sap_sec' => $xSapSec,
            'x_sz_sdk_version' => $xSzSdkVersion,
            'priority' => $priority,
            'sec_ch_ua' => $secChUa,
            'sec_ch_ua_mobile' => $secChUaMobile,
            'sec_ch_ua_platform' => $secChUaPlatform,
            'sec_fetch_dest' => $secFetchDest,
            'sec_fetch_mode' => $secFetchMode,
            'sec_fetch_site' => $secFetchSite,
            'origin' => $origin,
            'request_url' => $requestUrl,
            'referer' => $referer,
            'request_body' => $requestBody,
            'endpoint_key' => $endpointKey,
        ];
    }

    /**
     * @return array<string, string>
     */
    private function extractAllHeaders(array $lines): array
    {
        $headers = [];

        foreach ($lines as $line) {
            $name = mb_strtolower(trim((string) ($line['name'] ?? '')));
            $value = trim((string) ($line['value'] ?? ''));
            if ($name === '' || $value === '') {
                continue;
            }
            $headers[$name] = $value;
        }

        return $headers;
    }

    /**
     * @return list<array{name: string, value: string}>
     */
    private function extractAllHeaderLines(string $command): array
    {
        $headers = [];
        $pattern = "/(?:-H|--header)\\s*[\\^]*(['\"])(.*?)[\\^]*\\1/is";

        if (preg_match_all($pattern, $command, $matches, PREG_SET_ORDER) !== false) {
            foreach ($matches as $match) {
                $raw = str_replace('\\' . $match[1], $match[1], (string) ($match[2] ?? ''));
                $parts = explode(':', $raw, 2);
                if (count($parts) !== 2) {
                    continue;
                }

                $name = trim($parts[0]);
                $value = trim($parts[1]);
                if ($name === '' || $value === '') {
                    continue;
                }

                $headers[] = [
                    'name' => $name,
                    'value' => $value,
                ];
            }
        }

        return $headers;
    }

    private function extractDataPayload(string $command): ?string
    {
        $pattern = "/(?:--data-raw|--data-binary|--data)\\s*[\\^]*(['\"])(.*?)[\\^]*\\1/is";
        if (preg_match($pattern, $command, $matches)) {
            $value = $matches[2];
            $value = str_replace('\\' . $matches[1], $matches[1], $value);
            return trim($value);
        }

        return null;
    }

    private function extractRequestUrl(string $command): ?string
    {
        if (preg_match('/\bcurl\b\s+[\'"]?(https?:\/\/affiliate\.shopee\.vn[^\s\'"]*)[\'"]?/i', $command, $matches)) {
            return trim($matches[1]);
        }

        return null;
    }

    private function inferEndpointKey(?string $requestUrl, ?string $referer): ?string
    {
        $haystack = mb_strtolower(trim(($requestUrl ?? '') . ' ' . ($referer ?? '')));
        if ($haystack === '') {
            return null;
        }

        if (str_contains($haystack, '/payment/billing') || str_contains($haystack, 'billing_list')) {
            return 'billing';
        }

        if (str_contains($haystack, '/payment/payout_record') || str_contains($haystack, 'q=getpayoutlist')) {
            return 'payout_record';
        }

        if (str_contains($haystack, '/payment/service_fee_invoice') || str_contains($haystack, 'getpaymentsummarybillfeeinvoicelist')) {
            return 'service_fee_invoice';
        }

        if (str_contains($haystack, '/report/conversion_report') || str_contains($haystack, '/api/v3/report/list')) {
            return 'conversion_report';
        }

        if (str_contains($haystack, '/report/click_report') || str_contains($haystack, '/api/v1/click_report/list')) {
            return 'click_report';
        }

        if (str_contains($haystack, '/dashboard') || str_contains($haystack, '/api/v3/dashboard/detail')) {
            return 'dashboard';
        }

        if (str_contains($haystack, '/campaign/campaign_list') || str_contains($haystack, 'affiliatecampaigndetaillist')) {
            return 'campaign_list';
        }

        if (str_contains($haystack, '/offer/product_offer') || str_contains($haystack, '/api/v3/offer/product')) {
            return 'offer_product';
        }

        return null;
    }

    private function extractCookieArgument(string $command): ?string
    {
        // Matches -b '...' or --cookie '...'
        $pattern = "/(?:-b|--cookie)\s*[\\^]*(['\"])\s*(.*?)[\\^]*\\1/is";

        if (preg_match($pattern, $command, $matches)) {
            $value = $matches[2];
            $value = str_replace('\\' . $matches[1], $matches[1], $value);
            return trim($value);
        }

        return null;
    }

    private function extractHeader(string $headerName, string $command): ?string
    {
        // Headers in cURL usually look like: -H 'Cookie: SPC_EC=...' or --header "Cookie: SPC_EC=..."
        // Or sometimes -H ^"Cookie: ...^" in Windows.
        // We'll use a regex that handles both single and double quotes, and doesn't stop at newlines inside the quotes.

        // Pattern explanation:
        // (?:-H|--header)\s*      : Matches -H or --header followed by spaces (or escaped carriage returns)
        // (['"])                  : Captures the opening quote (' or ")
        // \s*                     : Optional spaces after quote
        // preg_quote($headerName) : The header name (e.g., Cookie)
        // \s*:\s*                 : Colon with optional spaces
        // (.*?)                   : Catches the actual header value (lazy)
        // \1                      : The closing quote (must match opening quote)
        
        $pattern = "/(?:-H|--header)\s*[\\^]*(['\"])\s*" . preg_quote($headerName, '/') . "\s*:\s*(.*?)[\\^]*\\1/is";

        if (preg_match($pattern, $command, $matches)) {
            $value = $matches[2];
            // Unescape escaped quotes if necessary (e.g., \' inside single quotes)
            $value = str_replace('\\' . $matches[1], $matches[1], $value);
            return trim($value);
        }

        // Try catching without quotes, e.g., -H Cookie:SPC_EC=...
        $patternNoQuotes = "/(?:-H|--header)\s*" . preg_quote($headerName, '/') . "\s*:\s*([^\s'-]+)/is";
        if (preg_match($patternNoQuotes, $command, $matches)) {
            return trim($matches[1]);
        }

        return null;
    }
}
