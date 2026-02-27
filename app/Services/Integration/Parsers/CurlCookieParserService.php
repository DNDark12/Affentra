<?php

declare(strict_types=1);

namespace App\Services\Integration\Parsers;

use Illuminate\Support\Str;
use RuntimeException;

class CurlCookieParserService
{
    /**
     * Parse a cURL command string to extract Cookie and User-Agent headers.
     *
     * @return array{
     *   cookie: string,
     *   user_agent: string|null,
     *   af_ac_enc_dat: string|null,
     *   af_ac_enc_sz_token: string|null,
     *   affiliate_program_type: string|null,
     *   csrf_token: string|null,
     *   x_sap_ri: string|null,
     *   x_sap_sec: string|null,
     *   x_sz_sdk_version: string|null
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

        $afAcEncDat = $this->extractHeader('af-ac-enc-dat', $curlCommand);
        $afAcEncSzToken = $this->extractHeader('af-ac-enc-sz-token', $curlCommand);
        $affiliateProgramType = $this->extractHeader('affiliate-program-type', $curlCommand);
        $csrfToken = $this->extractHeader('csrf-token', $curlCommand);
        $xSapRi = $this->extractHeader('x-sap-ri', $curlCommand);
        $xSapSec = $this->extractHeader('x-sap-sec', $curlCommand);
        $xSzSdkVersion = $this->extractHeader('x-sz-sdk-version', $curlCommand);

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
            'af_ac_enc_dat' => $afAcEncDat,
            'af_ac_enc_sz_token' => $afAcEncSzToken,
            'affiliate_program_type' => $affiliateProgramType,
            'csrf_token' => $csrfToken,
            'x_sap_ri' => $xSapRi,
            'x_sap_sec' => $xSapSec,
            'x_sz_sdk_version' => $xSzSdkVersion,
        ];
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
