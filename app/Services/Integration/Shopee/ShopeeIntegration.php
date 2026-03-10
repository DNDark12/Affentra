<?php

declare(strict_types=1);

namespace App\Services\Integration\Shopee;

use App\DataTransferObjects\CapabilitySet;
use App\Models\PlatformConnection;
use App\Services\Integration\BaseIntegration;
use App\Support\ShopeeDomainValidator;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use App\Services\Integration\CookieRotationService;
use RuntimeException;

class ShopeeIntegration extends BaseIntegration
{
    /** @var list<int> */
    private const COOKIE_SOFT_BLOCK_CODES = [90309999];

    private const COOKIE_REPORT_ENDPOINT = 'https://affiliate.shopee.vn/api/v3/report/list';
    private const COOKIE_DASHBOARD_ENDPOINT = 'https://affiliate.shopee.vn/api/v3/dashboard/detail';
    private const COOKIE_CLICK_REPORT_ENDPOINT = 'https://affiliate.shopee.vn/api/v1/click_report/list';
    private const COOKIE_BILLING_LIST_ENDPOINT = 'https://affiliate.shopee.vn/api/v3/payment/billing_list';
    private const GQL_ENDPOINT = 'https://affiliate.shopee.vn/api/v3/gql';
    private const COOKIE_OFFER_PRODUCT_ENDPOINT = 'https://affiliate.shopee.vn/api/v3/offer/product';
    private const PUBLIC_ITEM_API_ENDPOINT = 'https://shopee.vn/api/v4/item/get';

    // Referers
    private const COOKIE_DASHBOARD_REFERER = 'https://affiliate.shopee.vn/dashboard';
    private const COOKIE_CONVERSION_REFERER = 'https://affiliate.shopee.vn/report/conversion_report';
    private const COOKIE_CLICK_REPORT_REFERER = 'https://affiliate.shopee.vn/report/click_report';
    private const GQL_CAMPAIGN_REFERER = 'https://affiliate.shopee.vn/campaign/campaign_list';
    private const COOKIE_BILLING_REFERER = 'https://affiliate.shopee.vn/payment/billing';
    private const GQL_PAYMENT_PAYOUT_REFERER = 'https://affiliate.shopee.vn/payment/payout_record';
    private const GQL_PAYMENT_SERVICE_FEE_REFERER = 'https://affiliate.shopee.vn/payment/service_fee_invoice';

    /**
     * Shopee Affiliate API is GraphQL-based.
     * Base URL varies by region (e.g., .vn, .sg, .com.br).
     */
    private string $baseUrl;
    private int $backfillDays;

    /**
     * Runtime cache used within the same sync execution to enrich click_report rows
     * with metadata extracted from conversion_report keyed by click_id.
     *
     * @var array<string, array<string, array{sub_id: string|null, campaign_id: string|null, item_ids: array<string, true>}>>
     */
    private array $cookieClickAttributionCache = [];

    public function __construct()
    {
        $this->baseUrl = config('integrations.shopee.base_url', 'https://open-api.affiliate.shopee.vn/graphql');
        $this->backfillDays = (int) config('integrations.shopee.backfill_days', 14);
    }

    public function capabilities(): CapabilitySet
    {
        return new CapabilitySet(
            supportsAutoSync: true,
            supportsOfferDiscovery: true,
            supportsShortLink: true,
            supportsSubId: true,
        );
    }

    // ─── Auth & Signature ───────────────────────────────────────────────────

    /**
     * Build SHA256 authorization header per Shopee Affiliate API spec.
     *
     * @param  string  $payload  The GraphQL JSON body
     */
    private function buildAuthHeader(PlatformConnection $connection, string $payload, int $timestamp): string
    {
        $appId = $connection->app_id;
        $secret = $connection->app_secret;

        $raw = $appId . $timestamp . $payload . $secret;

        return hash('sha256', $raw);
    }

    /**
     * Send a GraphQL request to Shopee Affiliate API.
     *
     * @param  array<string, mixed>  $variables
     * @return array<string, mixed>
     *
     * @throws \RuntimeException on auth failure or API error
     */
    private function graphql(PlatformConnection $connection, string $query, array $variables = []): array
    {
        $payload = json_encode([
            'query'     => $query,
            'variables' => $variables,
        ], JSON_THROW_ON_ERROR);

        $timestamp = now()->timestamp;
        $signature = $this->buildAuthHeader($connection, $payload, $timestamp);

        $response = Http::withHeaders([
            'Content-Type'  => 'application/json',
            'Authorization' => "SHA256 Credential={$connection->app_id},Timestamp={$timestamp},Signature={$signature}",
        ])->timeout(30)->post($this->baseUrl, json_decode($payload, true));

        if ($response->status() === 401) {
            $this->handleAuthFailure($connection);
            throw new \RuntimeException('Shopee API authentication failed — token may be expired.');
        }

        if ($response->status() === 429) {
            throw new \RuntimeException('Shopee API rate limit exceeded.');
        }

        if (! $response->successful()) {
            Log::error('Shopee API error', [
                'status'        => $response->status(),
                'connection_id' => $connection->id,
                'platform'      => 'shopee',
            ]);
            throw new \RuntimeException("Shopee API error: HTTP {$response->status()}");
        }

        $data = $response->json();

        if (isset($data['errors']) && count($data['errors']) > 0) {
            $errorMsg = $data['errors'][0]['message'] ?? 'Unknown GraphQL error';
            throw new \RuntimeException("Shopee GraphQL error: {$errorMsg}");
        }

        return $data['data'] ?? [];
    }

    /**
     * Handle 401 auth failure — attempt token refresh with Redis lock.
     */
    private function handleAuthFailure(PlatformConnection $connection): void
    {
        $lockKey = "refresh_token:shopee:{$connection->id}";

        // Only one job should refresh at a time (thundering herd prevention)
        $lock = Cache::lock($lockKey, 30);

        if ($lock->get()) {
            try {
                $connection->update([
                    'status'         => 'error',
                    'last_error'     => 'Authentication failed — token refresh needed',
                    'last_error_at'  => now(),
                ]);
            } finally {
                $lock->release();
            }
        }
    }

    // ─── IntegrationContract Methods ────────────────────────────────────────

    public function testConnection(PlatformConnection $connection): bool
    {
        $result = $this->testConnectionDetailed($connection);

        return (bool) ($result['valid'] ?? false);
    }

    /**
     * @return array{
     *   valid: bool,
     *   checks: array{
     *     dashboard: array{ok: bool, status: int, code: int|null, message: string, soft_block?: bool},
     *     conversion_report: array{ok: bool, status: int, code: int|null, message: string, soft_block?: bool},
     *     click_report: array{ok: bool, status: int, code: int|null, message: string, soft_block?: bool}
     *   },
     *   message: string
     * }
     */
    public function testConnectionDetailed(PlatformConnection $connection): array
    {
        if ($connection->method === 'portal_export') {
            if ($connection->status !== 'active') {
                $connection->update(['status' => 'active']);
            }
            return [
                'valid' => true,
                'checks' => [],
                'message' => 'Portal export connection is active.',
            ];
        }

        if ($connection->method === 'cookie') {
            try {
                $start = now()->startOfDay()->timestamp;
                $end = now()->endOfDay()->timestamp;

                $checks = [
                    'dashboard' => $this->probeCookieEndpoint(
                        connection: $connection,
                        endpoint: self::COOKIE_DASHBOARD_ENDPOINT,
                        referer: self::COOKIE_DASHBOARD_REFERER,
                        query: ['start_time' => $start, 'end_time' => $end]
                    ),
                    'conversion_report' => $this->probeCookieEndpoint(
                        connection: $connection,
                        endpoint: self::COOKIE_REPORT_ENDPOINT,
                        referer: self::COOKIE_CONVERSION_REFERER,
                        query: [
                            'page_size' => 1,
                            'page_num' => 1,
                            'purchase_time_s' => $start,
                            'purchase_time_e' => $end,
                            'version' => 1,
                        ]
                    ),
                    'click_report' => $this->probeCookieEndpoint(
                        connection: $connection,
                        endpoint: self::COOKIE_CLICK_REPORT_ENDPOINT,
                        referer: self::COOKIE_CLICK_REPORT_REFERER,
                        query: [
                            'page_size' => 1,
                            'page_num' => 1,
                            'click_time_s' => $start,
                            'click_time_e' => $end,
                            'version' => 1,
                        ]
                    ),
                ];

                $hasHardSuccess = collect($checks)->contains(static fn (array $check): bool => $check['ok'] === true);
                $hasSoftBlock = collect($checks)->contains(static fn (array $check): bool => ($check['soft_block'] ?? false) === true);
                $valid = $hasHardSuccess || $hasSoftBlock;
                $failedChecks = collect($checks)
                    ->filter(static fn (array $check): bool => $check['ok'] === false && (($check['soft_block'] ?? false) !== true))
                    ->map(static fn (array $check, string $name): string => "{$name}: {$check['message']}")
                    ->values()
                    ->all();
                $softBlockedChecks = collect($checks)
                    ->filter(static fn (array $check): bool => ($check['soft_block'] ?? false) === true)
                    ->map(static fn (array $check, string $name): string => "{$name}: {$check['message']}")
                    ->values()
                    ->all();

                if ($valid) {
                    $warningParts = [];
                    if ($failedChecks !== []) {
                        $warningParts[] = 'Partial cookie validation: ' . implode(' | ', $failedChecks);
                    }
                    if ($softBlockedChecks !== []) {
                        $warningParts[] = 'Shopee anti-bot challenge detected: ' . implode(' | ', $softBlockedChecks);
                    }
                    $warning = $warningParts !== []
                        ? mb_substr(implode(' | ', $warningParts), 0, 500)
                        : null;

                    $connection->update([
                        'status' => 'active',
                        'cookie_validated_at' => now(),
                        'last_error' => $warning,
                        'last_error_at' => $warning !== null ? now() : null,
                    ]);

                    return [
                        'valid' => true,
                        'checks' => $checks,
                        'message' => $softBlockedChecks !== []
                            ? 'Cookie hợp lệ nhưng Shopee đang chặn một phần request (anti-bot challenge).'
                            : ($warning !== null
                                ? 'Cookie is usable, but some endpoints failed.'
                                : 'Connection valid.'),
                    ];
                }

                $failureMessage = 'Cookie test failed for all probes: ' . implode(' | ', $failedChecks);
                $this->markCookieAuthFailure($connection, mb_substr($failureMessage, 0, 500));

                return [
                    'valid' => false,
                    'checks' => $checks,
                    'message' => $failureMessage,
                ];
            } catch (RuntimeException $e) {
                if (str_contains(strtolower($e->getMessage()), 'missing cookie')) {
                    $this->markCookieAuthFailure($connection, 'Missing cookie credentials.');
                    return [
                        'valid' => false,
                        'checks' => [],
                        'message' => 'Missing cookie credentials.',
                    ];
                }

                throw $e;
            } catch (\Throwable $e) {
                Log::warning('Shopee cookie test failed', [
                    'connection_id' => $connection->id,
                    'error_class' => $e::class,
                ]);

                $this->markCookieAuthFailure($connection, 'Cookie connection test failed.');

                return [
                    'valid' => false,
                    'checks' => [],
                    'message' => 'Cookie connection test failed.',
                ];
            }
        }

        try {
            // Use a minimal query to verify credentials (open_api)
            $this->graphql($connection, '{ __typename }');
            return [
                'valid' => true,
                'checks' => [],
                'message' => 'Connection valid.',
            ];
        } catch (RuntimeException $e) {
            return [
                'valid' => false,
                'checks' => [],
                'message' => $e->getMessage(),
            ];
        }
    }

    /**
     * Extract the raw Cookie: header string from a connection's cookie_header JSON.
     *
     * cookie_header is stored as a JSON string:
     *   {"cookie": "SPC_F=abc; SPC_EC=def; ...", "af_ac_enc_dat": "...", "profiles": {...}}
     *
     * For the proxy endpoint we only need the raw cookie string — no jar injection.
     * Cookie may come from any browser (Chrome, Firefox, Safari, Edge) — header-only
     * approach is browser-agnostic.
     */
    private function extractCookieRaw(PlatformConnection $connection): string
    {
        $raw = trim((string) $connection->cookie_header);
        if ($raw === '') {
            return '';
        }

        if (str_starts_with($raw, '{')) {
            $decoded = json_decode($raw, true);
            if (is_array($decoded) && isset($decoded['cookie']) && is_string($decoded['cookie'])) {
                return $decoded['cookie'];
            }
        }

        // Plain cookie string (legacy format)
        return $raw;
    }

    /**
     * Proxy a single HTTP request through the Python Scraper (Camoufox + Firefox TLS).
     *
     * Returns a normalized internal DTO:
     * @return array{ok: bool, status: int, json: array|null, error_type: string|null, blocked_hint: bool, message: string}
     *
     * Error types (from scraper):
     *   - auth_failure   → call markCookieAuthFailure() + throw
     *   - blocked_bot    → throw with hint to update profile
     *   - rate_limited   → throw with rate-limit message
     *   - network        → throw with transient error message
     *   - null           → success
     */
    private function proxyToPythonScraper(
        PlatformConnection $connection,
        string $endpoint,
        string $referer,
        string $method = 'GET',
        array $queryParams = [],
        array $postBody = [],
        string $profile = '',
    ): array {
        $scraperUrl = config('services.scraper.url', 'http://scraper:8080');
        $scraperToken = config('services.scraper.token', '');

        $headers = $this->buildCookieHeaders($connection, $referer);
        $cookieRaw = $this->extractCookieRaw($connection);

        // Log cookie hash for correlation — never raw value
        $cookieHash = $cookieRaw !== '' ? substr(hash('sha256', $cookieRaw), 0, 12) : 'empty';

        Log::info('ShopeeIntegration::proxyToPythonScraper', [
            'connection_id' => $connection->id,
            'endpoint'      => $endpoint,
            'method'        => $method,
            'cookie_hash'   => $cookieHash,
            'profile'       => $profile ?: 'default',
        ]);

        $payload = [
            'url'     => $endpoint,
            'method'  => $method,
            'headers' => empty($headers) ? new \stdClass() : $headers,
            'params'  => empty($queryParams) ? new \stdClass() : $queryParams,
            'body'    => $method === 'POST' ? (empty($postBody) ? new \stdClass() : $postBody) : null,
            'cookies' => $cookieRaw,
        ];

        $response = Http::withHeaders(['X-Internal-Token' => $scraperToken])
            ->timeout(35)
            ->post("{$scraperUrl}/api/v1/shopee/proxy", $payload);

        if (! $response->successful()) {
            $responseJson = $response->json();
            $errorSummary = null;

            if (is_array($responseJson) && is_array($responseJson['detail'] ?? null)) {
                $errorSummary = array_map(
                    static function (mixed $item): array {
                        if (! is_array($item)) {
                            return ['message' => (string) $item];
                        }

                        return [
                            'loc' => $item['loc'] ?? null,
                            'msg' => $item['msg'] ?? null,
                            'type' => $item['type'] ?? null,
                        ];
                    },
                    $responseJson['detail']
                );
            }

            $bodySnippet = $errorSummary !== null
                ? json_encode($errorSummary, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)
                : mb_substr((string) $response->body(), 0, 200);

            Log::warning('ShopeeIntegration::proxyToPythonScraper.non_success', [
                'connection_id' => $connection->id,
                'endpoint' => $endpoint,
                'status' => $response->status(),
                'body_snippet' => $bodySnippet,
            ]);

            throw new RuntimeException(
                "Shopee scraper proxy call failed: HTTP {$response->status()} | body: {$bodySnippet}"
            );
        }

        /** @var array<string, mixed> $dto */
        $dto = $response->json();
        if (! is_array($dto)) {
            throw new RuntimeException('Shopee scraper proxy returned invalid response.');
        }

        $errorType = $dto['error_type'] ?? null;
        $blockedHint = (bool) ($dto['blocked_hint'] ?? false);
        $message = (string) ($dto['error'] ?? '');
        $status = (int) ($dto['status'] ?? 0);
        $responseJson = is_array($dto['json'] ?? null) ? $dto['json'] : null;

        // Classify and act on error types
        if ($errorType === 'auth_failure') {
            $this->markCookieAuthFailure($connection, "Cookie auth failed (scraper): {$message}");
            throw new RuntimeException("Cookie authentication failed: {$message}");
        }

        if ($errorType === 'rate_limited') {
            throw new RuntimeException("Shopee API rate limit exceeded (429). {$message}");
        }

        if ($errorType === 'blocked_bot') {
            $hint = $blockedHint
                ? ' Bot block detected — vui lòng cập nhật cookie/profile.'
                : '';
            throw new RuntimeException("Shopee blocked request (bot protection).{$hint} {$message}");
        }

        if ($errorType === 'network') {
            throw new RuntimeException("Shopee scraper network error: {$message}");
        }

        $ok = (bool) ($dto['ok'] ?? false);

        // Cookie rotation: attempt to persist refreshed cookies after successful call
        try {
            app(CookieRotationService::class)->rotateIfEligible($connection, [
                'ok'               => $ok,
                'error_type'       => $errorType,
                'refreshed_cookie' => $dto['refreshed_cookie'] ?? null,
                'cookie_hash'      => $dto['cookie_hash'] ?? null,
            ]);
        } catch (\Throwable $e) {
            Log::warning('cookie_rotation.hook_error', [
                'connection_id' => $connection->id,
                'error' => $e->getMessage(),
            ]);
        }

        return [
            'ok'               => $ok,
            'status'           => $status,
            'json'             => $responseJson,
            'error_type'       => $errorType,
            'blocked_hint'     => $blockedHint,
            'message'          => $message,
            'refreshed_cookie' => $dto['refreshed_cookie'] ?? null,
            'cookie_rotatable' => (bool) ($dto['cookie_rotatable'] ?? false),
            'cookie_hash'      => $dto['cookie_hash'] ?? null,
        ];
    }

    /**
     * @param  array<string, mixed>  $query
     * @return array{ok: bool, status: int, code: int|null, message: string, soft_block?: bool}
     */
    private function probeCookieEndpoint(
        PlatformConnection $connection,
        string $endpoint,
        string $referer,
        array $query = []
    ): array {
        $headers = $this->buildCookieHeaders($connection, $referer);
        $response = Http::withHeaders($headers)->timeout(15)->get($endpoint, $query);
        $payload = $response->json();

        $code = null;
        if (is_array($payload)) {
            $rawCode = $payload['code'] ?? $payload['error'] ?? null;
            if (is_numeric($rawCode)) {
                $code = (int) $rawCode;
            }
        }

        $message = '';
        if (is_array($payload)) {
            $message = (string) ($payload['msg'] ?? $payload['message'] ?? $payload['error'] ?? '');
        }

        if ($message === '') {
            $message = "HTTP {$response->status()}";
        }

        $isSoftBlock = $response->successful()
            && $code !== null
            && in_array($code, self::COOKIE_SOFT_BLOCK_CODES, true);

        $isOk = $response->successful()
            && ($code === null || $code === 0)
            && !(is_array($payload) && array_key_exists('error', $payload));

        $result = [
            'ok' => $isOk,
            'status' => $response->status(),
            'code' => $code,
            'message' => $isOk ? 'OK' : $message,
        ];

        if ($isSoftBlock) {
            $result['soft_block'] = true;
        }

        return $result;
    }

    public function fetchReport(PlatformConnection $connection, Carbon $since, Carbon $until): array
    {
        if ($connection->method === 'portal_export') {
            // Portal export sync is done via manual upload, not through automated fetch.
            return [];
        }

        if ($connection->method === 'cookie') {
            return $this->fetchConversionReportViaCookie($connection, $since, $until);
        }

        $allOrders = [];
        $page = 1;
        $limit = 100;

        // Conversion Report query — endpoint name TBD from Shopee docs
        // Using placeholder query structure based on known GraphQL pattern
        $query = <<<'GRAPHQL'
        query conversionReport($startTime: Long!, $endTime: Long!, $page: Int!, $limit: Int!) {
            conversionReport(
                startTime: $startTime,
                endTime: $endTime,
                page: $page,
                limit: $limit
            ) {
                nodes {
                    orderSn
                    itemId
                    itemName
                    shopId
                    shopName
                    orderAmount
                    commissionAmount
                    commissionRate
                    orderStatus
                    customParameters
                    purchaseTime
                    clickTime
                    payTime
                }
                pageInfo {
                    page
                    limit
                    hasNextPage
                }
            }
        }
        GRAPHQL;

        do {
            $result = $this->graphql($connection, $query, [
                'startTime' => $since->timestamp,
                'endTime'   => $until->timestamp,
                'page'      => $page,
                'limit'     => $limit,
            ]);

            $report = $result['conversionReport'] ?? [];
            $nodes = $report['nodes'] ?? [];
            $pageInfo = $report['pageInfo'] ?? [];

            foreach ($nodes as $node) {
                $allOrders[] = $this->mapConversionToOrder($node);
            }

            $hasNext = $pageInfo['hasNextPage'] ?? false;
            $page++;
        } while ($hasNext && $page <= 200); // Safety cap

        return $allOrders;
    }

    public function fetchClickReport(PlatformConnection $connection, Carbon $since, Carbon $until): array
    {
        if ($connection->method === 'cookie') {
            return $this->fetchClickReportViaCookie($connection, $since, $until);
        }

        // TODO: Handle GraphQL based Open API click report logic if/when supported
        return [];
    }

    public function fetchCampaigns(PlatformConnection $connection): array
    {
        if ($connection->method !== 'cookie') {
            return [];
        }

        return $this->fetchCampaignsViaCookie($connection);
    }

    /**
     * Map a Shopee conversion report node to our standard order array.
     *
     * @param  array<string, mixed>  $node
     * @return array<string, mixed>
     */
    private function mapConversionToOrder(array $node): array
    {
        return [
            'platform'       => 'shopee',
            'order_code'     => $node['orderSn'] ?? '',
            'sub_id'         => $node['customParameters'] ?? null,
            'order_amount'   => (float) ($node['orderAmount'] ?? 0),
            'commission'     => (float) ($node['commissionAmount'] ?? 0),
            'status'         => $this->mapOrderStatus($node['orderStatus'] ?? ''),
            'ordered_at'     => isset($node['purchaseTime'])
                ? Carbon::createFromTimestamp($node['purchaseTime'])
                : now(),
            'approved_at'    => ($this->mapOrderStatus($node['orderStatus'] ?? '') === 'approved' && isset($node['payTime']))
                ? Carbon::createFromTimestamp($node['payTime'])
                : null,
            'source'         => 'api',
        ];
    }

    /**
     * Map Shopee order status string to our OrderStatus enum value.
     */
    private function mapOrderStatus(string $shopeeStatus): string
    {
        $normalized = mb_strtolower(trim($shopeeStatus));

        if ($normalized === '') {
            return 'pending';
        }

        if (
            str_contains($normalized, 'approved')
            || str_contains($normalized, 'settled')
            || str_contains($normalized, 'paid')
            || str_contains($normalized, 'completed')
            || str_contains($normalized, 'hoàn thành')
            || str_contains($normalized, 'thành công')
            || str_contains($normalized, 'đã duyệt')
        ) {
            return 'approved';
        }

        if (
            str_contains($normalized, 'rejected')
            || str_contains($normalized, 'cancel')
            || str_contains($normalized, 'hủy')
            || str_contains($normalized, 'huỷ')
            || str_contains($normalized, 'invalid')
            || str_contains($normalized, 'refunded')
        ) {
            return 'rejected';
        }

        return 'pending';
    }

    /**
     * @return array<string, string>
     */
    public function buildCookieHeaders(PlatformConnection $connection, string $referer): array
    {
        $rawCookie = trim((string) $connection->cookie_header);
        if ($rawCookie === '') {
            throw new RuntimeException('Missing cookie credentials.');
        }

        $headers = [
            'Accept' => 'application/json, text/plain, */*',
            'Accept-Language' => 'vi-VN,vi;q=0.9,en-US;q=0.8,en;q=0.7',
            'affiliate-program-type' => '1',
            'Origin' => 'https://affiliate.shopee.vn',
            'Referer' => $referer,
            'User-Agent' => $connection->cookie_user_agent
                ?? 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/145.0.0.0 Safari/537.36',
            'Priority' => 'u=1, i',
            'sec-ch-ua-mobile' => '?0',
            'sec-fetch-dest' => 'empty',
            'sec-fetch-mode' => 'cors',
            'sec-fetch-site' => 'same-origin',
        ];
        $headers['sec-ch-ua'] = $this->defaultSecChUa((string) $headers['User-Agent']);
        $headers['sec-ch-ua-platform'] = $this->defaultSecChUaPlatform((string) $headers['User-Agent']);

        if (str_starts_with($rawCookie, '{')) {
            $parsed = json_decode($rawCookie, true);
            if (! is_array($parsed) || empty($parsed['cookie'])) {
                throw new RuntimeException('Missing cookie credentials.');
            }

            $profile = [];
            $selectedProfileKey = null;
            $profileKey = $this->inferCookieProfileKeyByReferer($referer);
            if (
                $profileKey !== null
                && is_array($parsed['profiles'] ?? null)
                && is_array($parsed['profiles'][$profileKey] ?? null)
            ) {
                $profile = $parsed['profiles'][$profileKey];
                $selectedProfileKey = $profileKey;
            } elseif ($profileKey !== null && is_array($parsed['profiles'] ?? null) && $profileKey !== 'offer_product') {
                // For non-offer endpoints we can still try a nearby profile to preserve backward compatibility.
                foreach (['campaign_list', 'conversion_report', 'click_report', 'dashboard', 'billing', 'payout_record', 'service_fee_invoice'] as $fallbackKey) {
                    if (is_array($parsed['profiles'][$fallbackKey] ?? null)) {
                        $profile = $parsed['profiles'][$fallbackKey];
                        $selectedProfileKey = $fallbackKey;
                        break;
                    }
                }
            }

            $resolved = [
                'affiliate_program_type' => (string) (
                    $profile['affiliate_program_type']
                    ?? $parsed['affiliate_program_type']
                    ?? '1'
                ),
                'af_ac_enc_dat' => (string) ($profile['af_ac_enc_dat'] ?? $parsed['af_ac_enc_dat'] ?? ''),
                'af_ac_enc_sz_token' => (string) ($profile['af_ac_enc_sz_token'] ?? $parsed['af_ac_enc_sz_token'] ?? ''),
                'csrf_token' => (string) ($profile['csrf_token'] ?? $parsed['csrf_token'] ?? ''),
                'x_sap_ri' => (string) ($profile['x_sap_ri'] ?? $parsed['x_sap_ri'] ?? ''),
                'x_sap_sec' => (string) ($profile['x_sap_sec'] ?? $parsed['x_sap_sec'] ?? ''),
                'x_sz_sdk_version' => (string) ($profile['x_sz_sdk_version'] ?? $parsed['x_sz_sdk_version'] ?? ''),
                'accept_language' => (string) ($profile['accept_language'] ?? $parsed['accept_language'] ?? ''),
                'priority' => (string) ($profile['priority'] ?? $parsed['priority'] ?? ''),
                'sec_ch_ua' => (string) ($profile['sec_ch_ua'] ?? $parsed['sec_ch_ua'] ?? ''),
                'sec_ch_ua_mobile' => (string) ($profile['sec_ch_ua_mobile'] ?? $parsed['sec_ch_ua_mobile'] ?? ''),
                'sec_ch_ua_platform' => (string) ($profile['sec_ch_ua_platform'] ?? $parsed['sec_ch_ua_platform'] ?? ''),
                'sec_fetch_dest' => (string) ($profile['sec_fetch_dest'] ?? $parsed['sec_fetch_dest'] ?? ''),
                'sec_fetch_mode' => (string) ($profile['sec_fetch_mode'] ?? $parsed['sec_fetch_mode'] ?? ''),
                'sec_fetch_site' => (string) ($profile['sec_fetch_site'] ?? $parsed['sec_fetch_site'] ?? ''),
                'referer' => (string) ($profile['referer'] ?? ''),
                'origin' => (string) ($profile['origin'] ?? $parsed['origin'] ?? ''),
            ];
            $rawHeaders = array_merge(
                is_array($parsed['raw_headers'] ?? null) ? $parsed['raw_headers'] : [],
                is_array($profile['raw_headers'] ?? null) ? $profile['raw_headers'] : [],
            );
            $rawHeaderLines = array_merge(
                is_array($parsed['raw_header_lines'] ?? null) ? $parsed['raw_header_lines'] : [],
                is_array($profile['raw_header_lines'] ?? null) ? $profile['raw_header_lines'] : [],
            );

            if (
                $selectedProfileKey === $profileKey
                && $profileKey !== null
                && ($rawHeaders !== [] || $rawHeaderLines !== [])
            ) {
                $strictReplayHeaders = $this->buildStrictReplayHeaders(
                    cookie: (string) $parsed['cookie'],
                    rawHeaders: $rawHeaders,
                    rawHeaderLines: $rawHeaderLines,
                    resolved: $resolved,
                    fallbackReferer: $referer,
                    fallbackUserAgent: (string) ($connection->cookie_user_agent
                        ?? 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/145.0.0.0 Safari/537.36'),
                );

                if ($strictReplayHeaders !== []) {
                    return $strictReplayHeaders;
                }
            }

            $headers['Cookie'] = (string) $parsed['cookie'];

            if ($resolved['affiliate_program_type'] !== '') {
                $headers['affiliate-program-type'] = $resolved['affiliate_program_type'];
            }

            if ($resolved['af_ac_enc_dat'] !== '') {
                $headers['af-ac-enc-dat'] = $resolved['af_ac_enc_dat'];
            }

            if ($resolved['af_ac_enc_sz_token'] !== '') {
                $headers['af-ac-enc-sz-token'] = $resolved['af_ac_enc_sz_token'];
            }

            if ($resolved['csrf_token'] !== '') {
                $headers['csrf-token'] = $resolved['csrf_token'];
            }

            if ($resolved['x_sap_ri'] !== '') {
                $headers['x-sap-ri'] = $resolved['x_sap_ri'];
            }

            if ($resolved['x_sap_sec'] !== '') {
                $headers['x-sap-sec'] = $resolved['x_sap_sec'];
            }

            if ($resolved['x_sz_sdk_version'] !== '') {
                $headers['x-sz-sdk-version'] = $resolved['x_sz_sdk_version'];
            }

            if ($resolved['accept_language'] !== '') {
                $headers['Accept-Language'] = $resolved['accept_language'];
            }

            if ($resolved['priority'] !== '') {
                $headers['Priority'] = $resolved['priority'];
            }

            if ($resolved['sec_ch_ua'] !== '') {
                $headers['sec-ch-ua'] = $resolved['sec_ch_ua'];
            }

            if ($resolved['sec_ch_ua_mobile'] !== '') {
                $headers['sec-ch-ua-mobile'] = $resolved['sec_ch_ua_mobile'];
            }

            if ($resolved['sec_ch_ua_platform'] !== '') {
                $headers['sec-ch-ua-platform'] = $resolved['sec_ch_ua_platform'];
            }

            if ($resolved['sec_fetch_dest'] !== '') {
                $headers['sec-fetch-dest'] = $resolved['sec_fetch_dest'];
            }

            if ($resolved['sec_fetch_mode'] !== '') {
                $headers['sec-fetch-mode'] = $resolved['sec_fetch_mode'];
            }

            if ($resolved['sec_fetch_site'] !== '') {
                $headers['sec-fetch-site'] = $resolved['sec_fetch_site'];
            }

            if ($resolved['referer'] !== '' && $selectedProfileKey === $profileKey) {
                $headers['Referer'] = $resolved['referer'];
            }

            if ($resolved['origin'] !== '' && $selectedProfileKey === $profileKey) {
                $headers['Origin'] = $resolved['origin'];
            }

            foreach ($rawHeaders as $name => $value) {
                $headerName = mb_strtolower(trim((string) $name));
                if ($headerName === '' || in_array($headerName, ['cookie', 'content-length', 'host'], true)) {
                    continue;
                }

                $headers[$headerName] = (string) $value;
            }

            return $headers;
        }

        $headers['Cookie'] = $rawCookie;

        return $headers;
    }

    /**
     * Replay headers captured from the original browser cURL as close as possible.
     *
     * @param  array<string, string>  $rawHeaders
     * @param  list<array{name: string, value: string}>  $rawHeaderLines
     * @param  array<string, string>  $resolved
     * @return array<string, string>
     */
    private function buildStrictReplayHeaders(
        string $cookie,
        array $rawHeaders,
        array $rawHeaderLines,
        array $resolved,
        string $fallbackReferer,
        string $fallbackUserAgent,
    ): array {
        $headers = [
            'Cookie' => $cookie,
        ];

        if ($rawHeaderLines !== []) {
            foreach ($rawHeaderLines as $line) {
                $headerName = trim((string) ($line['name'] ?? ''));
                $headerValue = trim((string) ($line['value'] ?? ''));

                if ($headerName === '' || $headerValue === '') {
                    continue;
                }

                $lower = mb_strtolower($headerName);
                if (in_array($lower, ['cookie', 'content-length', 'host'], true)) {
                    continue;
                }

                if (! $this->hasHeaderKey($headers, $headerName)) {
                    $headers[$headerName] = $headerValue;
                }
            }
        } else {
            foreach ($rawHeaders as $name => $value) {
                $headerName = trim((string) $name);
                $headerValue = trim((string) $value);

                if ($headerName === '' || $headerValue === '') {
                    continue;
                }

                $lower = mb_strtolower($headerName);
                if (in_array($lower, ['cookie', 'content-length', 'host'], true)) {
                    continue;
                }

                if (! $this->hasHeaderKey($headers, $headerName)) {
                    $headers[$headerName] = $headerValue;
                }
            }
        }

        $this->putHeaderIfMissing($headers, 'accept', 'application/json, text/plain, */*');
        $this->putHeaderIfMissing($headers, 'accept-language', $resolved['accept_language'] !== '' ? $resolved['accept_language'] : 'vi-VN,vi;q=0.9,en-US;q=0.8,en;q=0.7');
        $this->putHeaderIfMissing($headers, 'affiliate-program-type', $resolved['affiliate_program_type'] !== '' ? $resolved['affiliate_program_type'] : '1');
        $this->putHeaderIfMissing($headers, 'referer', $resolved['referer'] !== '' ? $resolved['referer'] : $fallbackReferer);
        $this->putHeaderIfMissing($headers, 'user-agent', $fallbackUserAgent);

        $optional = [
            'af-ac-enc-dat' => $resolved['af_ac_enc_dat'],
            'af-ac-enc-sz-token' => $resolved['af_ac_enc_sz_token'],
            'csrf-token' => $resolved['csrf_token'],
            'x-sap-ri' => $resolved['x_sap_ri'],
            'x-sap-sec' => $resolved['x_sap_sec'],
            'x-sz-sdk-version' => $resolved['x_sz_sdk_version'],
            'priority' => $resolved['priority'],
            'sec-ch-ua' => $resolved['sec_ch_ua'],
            'sec-ch-ua-mobile' => $resolved['sec_ch_ua_mobile'],
            'sec-ch-ua-platform' => $resolved['sec_ch_ua_platform'],
            'sec-fetch-dest' => $resolved['sec_fetch_dest'],
            'sec-fetch-mode' => $resolved['sec_fetch_mode'],
            'sec-fetch-site' => $resolved['sec_fetch_site'],
        ];

        foreach ($optional as $name => $value) {
            if ($value === '') {
                continue;
            }
            $this->putHeaderIfMissing($headers, $name, $value);
        }

        if ($resolved['origin'] !== '') {
            $this->putHeaderIfMissing($headers, 'origin', $resolved['origin']);
        }

        return $headers;
    }

    /**
     * @param  array<string, string>  $headers
     */
    private function putHeaderIfMissing(array &$headers, string $name, string $value): void
    {
        if ($value === '' || $this->hasHeaderKey($headers, $name)) {
            return;
        }

        $headers[$name] = $value;
    }

    /**
     * @param  array<string, string>  $headers
     */
    private function hasHeaderKey(array $headers, string $needle): bool
    {
        $target = mb_strtolower($needle);

        foreach (array_keys($headers) as $name) {
            if (mb_strtolower((string) $name) === $target) {
                return true;
            }
        }

        return false;
    }

    private function inferCookieProfileKeyByReferer(string $referer): ?string
    {
        $normalized = mb_strtolower($referer);

        if (str_contains($normalized, '/payment/billing')) {
            return 'billing';
        }

        if (str_contains($normalized, '/payment/payout_record')) {
            return 'payout_record';
        }

        if (str_contains($normalized, '/payment/service_fee_invoice')) {
            return 'service_fee_invoice';
        }

        if (str_contains($normalized, '/report/conversion_report')) {
            return 'conversion_report';
        }

        if (str_contains($normalized, '/report/click_report')) {
            return 'click_report';
        }

        if (str_contains($normalized, '/dashboard')) {
            return 'dashboard';
        }

        if (str_contains($normalized, '/campaign/campaign_list')) {
            return 'campaign_list';
        }

        if (str_contains($normalized, '/offer/product_offer')) {
            return 'offer_product';
        }

        return null;
    }

    /**
     * Ensure cookie credentials contain endpoint-specific profiles required for finance sync.
     */
    public function assertFinanceCookieProfilesReady(PlatformConnection $connection): void
    {
        if ($connection->method !== 'cookie') {
            return;
        }

        $rawCookie = trim((string) $connection->cookie_header);
        if ($rawCookie === '' || ! str_starts_with($rawCookie, '{')) {
            throw new RuntimeException(
                'Cookie credentials chưa đủ cho Finance. Vui lòng cập nhật bằng cURL từ 3 trang: billing, payout_record, service_fee_invoice.'
            );
        }

        $decoded = json_decode($rawCookie, true);
        if (! is_array($decoded)) {
            throw new RuntimeException(
                'Cookie credentials không hợp lệ. Vui lòng cập nhật bằng cURL từ 3 trang Finance của Shopee.'
            );
        }

        $profiles = is_array($decoded['profiles'] ?? null) ? $decoded['profiles'] : [];
        $required = ['billing', 'payout_record', 'service_fee_invoice'];
        $missing = array_values(array_filter($required, static fn(string $key): bool => ! is_array($profiles[$key] ?? null)));

        if ($missing !== []) {
            throw new RuntimeException(
                'Thiếu cURL profile cho Finance: ' . implode(', ', $missing) . '. Vui lòng mở đúng 3 trang billing/payout_record/service_fee_invoice và cập nhật lại kết nối.'
            );
        }
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function fetchCampaignsViaCookie(PlatformConnection $connection): array
    {
        $all = [];
        $hasCampaignProfile = $this->hasCookieProfile($connection, 'campaign_list');
        $campaignProfile = $this->getCookieProfile($connection, 'campaign_list');

        $fallbackBodyRaw = <<<'JSON'
{"operationName":"affiliateCampaignsList","query":"\n      query affiliateCampaignsList ($pageNum: Int, $pageSize: Int){\n        affiliateCampaignsList(pageNum: $pageNum, pageSize: $pageSize) {\n          affiliateCampaignDetailList {\n            campaignId\n            campaignName\n            campaignStartTime\n            campaignEndTime\n            campaignDescription\n            campaignImpressionNum\n            campaignClickNum\n            bannerImageId\n            campaignStatus\n            campaignUrl\n          }\n        }\n      }\n    ","variables":{}}
JSON;

        $requestBodyRaw = trim((string) ($campaignProfile['request_body'] ?? ''));
        if ($requestBodyRaw === '' && $fallbackBodyRaw !== '') {
            $requestBodyRaw = $fallbackBodyRaw;
        }

        // Campaigns endpoint is a POST with a raw JSON body — pass as postBody
        // proxyToPythonScraper() handles auth/bot/rate-limit classification and throws
        $dto = $this->proxyToPythonScraper(
            $connection,
            self::GQL_ENDPOINT . '?q=affiliateCampaignDetailList',
            self::GQL_CAMPAIGN_REFERER,
            'POST',
            [],
            json_decode($requestBodyRaw, true) ?? [],
            'campaign_list',
        );

        $payload = $dto['json'];
        if (! is_array($payload)) {
            throw new RuntimeException('Shopee cookie campaign API returned invalid payload.');
        }

        $code = $payload['code'] ?? $payload['error'] ?? 0;
        if (is_numeric($code) && (int) $code !== 0) {
            $message = (string) ($payload['msg'] ?? $payload['message'] ?? 'Unknown error');

            if (in_array((int) $code, self::COOKIE_SOFT_BLOCK_CODES, true)) {
                if (! $hasCampaignProfile) {
                    throw new RuntimeException(
                        "Shopee anti-bot challenge (code {$code}): {$message}. ".
                        'Vui lòng cập nhật cURL từ trang Campaign List để tạo profile campaign_list.'
                    );
                }

                throw new RuntimeException("Shopee anti-bot challenge (code {$code}): {$message}");
            }

            throw new RuntimeException("Shopee cookie campaign API returned code {$code}: {$message}");
        }

        $rows = $this->extractCookieCampaignRows($payload);
        if ($rows === []) {
            return [];
        }

        foreach ($rows as $row) {
            $all[] = $row;
        }

        return $all;
    }

    private function hasCookieProfile(PlatformConnection $connection, string $profileKey): bool
    {
        if ($connection->method !== 'cookie') {
            return false;
        }

        $rawCookie = trim((string) $connection->cookie_header);
        if ($rawCookie === '' || ! str_starts_with($rawCookie, '{')) {
            return false;
        }

        $decoded = json_decode($rawCookie, true);
        if (! is_array($decoded)) {
            return false;
        }

        return is_array($decoded['profiles'][$profileKey] ?? null);
    }

    /**
     * @return array<string, mixed>
     */
    private function getCookieProfile(PlatformConnection $connection, string $profileKey): array
    {
        if ($connection->method !== 'cookie') {
            return [];
        }

        $rawCookie = trim((string) $connection->cookie_header);
        if ($rawCookie === '' || ! str_starts_with($rawCookie, '{')) {
            return [];
        }

        $decoded = json_decode($rawCookie, true);
        if (! is_array($decoded)) {
            return [];
        }

        $profile = $decoded['profiles'][$profileKey] ?? null;
        return is_array($profile) ? $profile : [];
    }

    private function defaultSecChUa(string $userAgent): string
    {
        if (preg_match('/Chrome\\/([0-9]+)/i', $userAgent, $matches) === 1) {
            $major = $matches[1];
            return sprintf('"Not:A-Brand";v="99", "Google Chrome";v="%s", "Chromium";v="%s"', $major, $major);
        }

        return '"Not:A-Brand";v="99", "Google Chrome";v="145", "Chromium";v="145"';
    }

    private function defaultSecChUaPlatform(string $userAgent): string
    {
        $ua = mb_strtolower($userAgent);
        if (str_contains($ua, 'mac os') || str_contains($ua, 'macintosh')) {
            return '"macOS"';
        }

        if (str_contains($ua, 'windows')) {
            return '"Windows"';
        }

        if (str_contains($ua, 'linux')) {
            return '"Linux"';
        }

        return '"macOS"';
    }

    private function markCookieAuthFailure(PlatformConnection $connection, string $message): void
    {
        $connection->update([
            'status' => 'error',
            'sync_mode' => 'manual',
            'cookie_validated_at' => null,
            'last_error' => $message,
            'last_error_at' => now(),
        ]);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function fetchConversionReportViaCookie(PlatformConnection $connection, Carbon $since, Carbon $until): array
    {
        $orders = [];
        $page = 1;
        $pageSize = 50;
        $maxPages = 200;
        $this->resetCookieClickAttribution($connection, $since, $until);

        while ($page <= $maxPages) {
            // proxyToPythonScraper() handles error classification and throws on failure
            $dto = $this->proxyToPythonScraper(
                $connection,
                self::COOKIE_REPORT_ENDPOINT,
                self::COOKIE_CONVERSION_REFERER,
                'GET',
                [
                    'page_size'      => $pageSize,
                    'page_num'       => $page,
                    'purchase_time_s' => $since->timestamp,
                    'purchase_time_e' => $until->timestamp,
                    'version'        => 1,
                ],
            );

            $payload = $dto['json'];
            if (! is_array($payload)) {
                throw new RuntimeException('Shopee cookie report API returned invalid payload.');
            }

            if (array_key_exists('code', $payload) && (int) $payload['code'] !== 0) {
                $code = (int) $payload['code'];
                $message = (string) ($payload['msg'] ?? $payload['message'] ?? 'Unknown error');
                throw new RuntimeException("Shopee cookie report API returned code {$code}: {$message}");
            }

            $rows = $this->extractCookieConversionRows($payload);
            $this->storeCookieClickAttribution($connection, $since, $until, $rows);
            foreach ($rows as $row) {
                $mappedOrders = $this->mapCookieConversionRowToOrders($row);
                foreach ($mappedOrders as $mapped) {
                    $orders[] = $mapped;
                }
            }

            if (! $this->hasNextCookieConversionPage($payload, $page, $pageSize, count($rows))) {
                break;
            }

            $page++;
        }

        return $orders;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function fetchClickReportViaCookie(PlatformConnection $connection, Carbon $since, Carbon $until): array
    {
        $clicks = [];
        $page = 1;
        $pageSize = 100;
        $maxPages = 100; // max 10k clicks per sync window
        $attributionByClickId = $this->getCookieClickAttribution($connection, $since, $until);

        while ($page <= $maxPages) {
            // proxyToPythonScraper() handles auth/bot/rate-limit classification and throws
            $dto = $this->proxyToPythonScraper(
                $connection,
                self::COOKIE_CLICK_REPORT_ENDPOINT,
                self::COOKIE_CLICK_REPORT_REFERER,
                'GET',
                [
                    'page_size'   => $pageSize,
                    'page_num'    => $page,
                    'click_time_s' => $since->timestamp,
                    'click_time_e' => $until->timestamp,
                    'version'     => 1,
                ],
            );

            $payload = $dto['json'];
            if (! is_array($payload)) {
                throw new RuntimeException('Shopee cookie click report API returned invalid payload.');
            }

            if (array_key_exists('code', $payload) && (int) $payload['code'] !== 0) {
                $code = (int) $payload['code'];
                $message = (string) ($payload['msg'] ?? $payload['message'] ?? 'Unknown error');
                throw new RuntimeException("Shopee cookie click report API returned error: {$message}");
            }

            $data = $payload['data'] ?? [];
            $list = $data['list'] ?? [];

            if (! is_array($list)) {
                throw new RuntimeException('Shopee cookie click report API returned unexpected list format.');
            }

            foreach ($list as $row) {
                $clicks[] = $this->mapCookieClickRow($row, $attributionByClickId);
            }

            $totalCount = (int) ($data['total_count'] ?? 0);
            $totalFetched = $page * $pageSize;

            if (count($list) < $pageSize || $totalFetched >= $totalCount) {
                break;
            }

            $page++;
        }

        return $clicks;
    }

    /**
     * @param  array<string, array{sub_id: string|null, campaign_id: string|null, item_id: string|null}>  $attributionByClickId
     */
    private function mapCookieClickRow(array $row, array $attributionByClickId = []): array
    {
        $subId = $this->firstValueByPaths($row, ['sub_id1', 'sub_id', 'subId', 'custom_param', 'customParameter']);
        $campaignId = $this->firstValueByPaths($row, ['campaign_id', 'campaignId']);
        $itemId = $this->firstValueByPaths($row, [
            'item_id',
            'itemId',
            'itemid',
            'product_id',
            'productId',
            'offer_item_id',
            'goods_id',
        ]);
        $clickId = $this->normalizeLooseString($this->firstValueByPaths($row, ['click_id', 'clickId']));

        if ($clickId !== null && isset($attributionByClickId[$clickId])) {
            $context = $attributionByClickId[$clickId];

            if ($this->normalizeSubIdCandidate($subId) === null && $context['sub_id'] !== null) {
                $subId = $context['sub_id'];
            }

            if ($this->normalizeLooseString($campaignId) === null && $context['campaign_id'] !== null) {
                $campaignId = $context['campaign_id'];
            }

            if ($this->normalizeLooseString($itemId) === null && $context['item_id'] !== null) {
                $itemId = $context['item_id'];
            }
        }

        if (($itemId === null || trim((string) $itemId) === '') && is_string($this->firstValueByPaths($row, ['item_link', 'itemLink', 'product_link', 'url']))) {
            $url = (string) $this->firstValueByPaths($row, ['item_link', 'itemLink', 'product_link', 'url']);
            if (preg_match('~/(?:product|i)/\d+/(\d+)~', $url, $matches) === 1) {
                $itemId = $matches[1];
            }
        }

        $amountRaw = $this->firstValueByPaths($row, ['click_count', 'clicks', 'count', 'total_clicks']);

        $clickTimeRaw = $this->firstValueByPaths($row, ['click_time', 'clickTime', 'created_at', 'createdAt', 'time']);
        $clickTime = $this->asCarbon($clickTimeRaw);

        return [
            'platform' => 'shopee',
            'click_time' => $clickTime ?? now(),
            'click_id' => $clickId,
            'sub_id' => $this->normalizeSubIdCandidate($subId),
            'campaign_id' => $this->normalizeLooseString($campaignId),
            'item_id' => $this->normalizeLooseString($itemId),
            'amount' => max(1, (int) ($amountRaw ?? 1)),
            'raw_data' => $row,
        ];
    }

    private function resetCookieClickAttribution(PlatformConnection $connection, Carbon $since, Carbon $until): void
    {
        $this->cookieClickAttributionCache[$this->cookieClickAttributionCacheKey($connection, $since, $until)] = [];
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     */
    private function storeCookieClickAttribution(PlatformConnection $connection, Carbon $since, Carbon $until, array $rows): void
    {
        $cacheKey = $this->cookieClickAttributionCacheKey($connection, $since, $until);
        $existing = $this->cookieClickAttributionCache[$cacheKey] ?? [];

        foreach ($rows as $row) {
            $clickId = $this->normalizeLooseString($this->firstValueByPaths($row, ['click_id', 'clickId']));
            if ($clickId === null) {
                continue;
            }

            $current = $existing[$clickId] ?? [
                'sub_id' => null,
                'campaign_id' => null,
                'item_ids' => [],
            ];

            $subId = $this->normalizeSubIdCandidate($this->firstValueByPaths($row, [
                'utm_content',
                'sub_id',
                'subId',
                'custom_parameters',
                'customParameters',
            ]));

            if ($current['sub_id'] === null && $subId !== null) {
                $current['sub_id'] = $subId;
            }

            $campaignId = $this->normalizeLooseString($this->firstValueByPaths($row, [
                'campaign_id',
                'campaignId',
                'campaign_mcn_id',
                'campaignMcnId',
            ]));
            if ($current['campaign_id'] === null && $campaignId !== null) {
                $current['campaign_id'] = $campaignId;
            }

            foreach ($this->extractCookieConversionItemIds($row) as $itemId) {
                $current['item_ids'][$itemId] = true;
            }

            $existing[$clickId] = $current;
        }

        $this->cookieClickAttributionCache[$cacheKey] = $existing;
    }

    /**
     * @return array<string, array{sub_id: string|null, campaign_id: string|null, item_id: string|null}>
     */
    private function getCookieClickAttribution(PlatformConnection $connection, Carbon $since, Carbon $until): array
    {
        $cacheKey = $this->cookieClickAttributionCacheKey($connection, $since, $until);
        $raw = $this->cookieClickAttributionCache[$cacheKey] ?? [];

        $normalized = [];
        foreach ($raw as $clickId => $context) {
            $itemIds = array_keys($context['item_ids'] ?? []);
            $itemId = count($itemIds) === 1 ? (string) $itemIds[0] : null;

            $normalized[$clickId] = [
                'sub_id' => $context['sub_id'] ?? null,
                'campaign_id' => $context['campaign_id'] ?? null,
                'item_id' => $itemId,
            ];
        }

        return $normalized;
    }

    private function cookieClickAttributionCacheKey(PlatformConnection $connection, Carbon $since, Carbon $until): string
    {
        return implode(':', [
            (string) $connection->id,
            (string) $since->timestamp,
            (string) $until->timestamp,
        ]);
    }

    /**
     * @param  array<string, mixed>  $row
     * @return list<string>
     */
    private function extractCookieConversionItemIds(array $row): array
    {
        $ids = [];
        $direct = $this->normalizeLooseString($this->firstValueByPaths($row, ['item_id', 'itemId', 'product_id', 'productId']));
        if ($direct !== null) {
            $ids[] = $direct;
        }

        $orders = $row['orders'] ?? null;
        if (is_array($orders)) {
            foreach ($orders as $order) {
                if (! is_array($order)) {
                    continue;
                }

                $orderItemId = $this->normalizeLooseString($this->firstValueByPaths($order, ['item_id', 'itemId', 'product_id', 'productId']));
                if ($orderItemId !== null) {
                    $ids[] = $orderItemId;
                }

                $items = $order['items'] ?? null;
                if (! is_array($items)) {
                    continue;
                }

                foreach ($items as $item) {
                    if (! is_array($item)) {
                        continue;
                    }
                    $itemId = $this->normalizeLooseString($this->firstValueByPaths($item, ['item_id', 'itemId', 'product_id', 'productId']));
                    if ($itemId !== null) {
                        $ids[] = $itemId;
                    }
                }
            }
        }

        return array_values(array_unique($ids));
    }

    private function normalizeLooseString(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $normalized = trim((string) $value);
        if ($normalized === '') {
            return null;
        }

        $lowered = mb_strtolower($normalized);
        if (in_array($lowered, ['-', '--', '---', '----', 'n/a', 'na', 'none', 'null', 'undefined', '(not set)'], true)) {
            return null;
        }

        return $normalized;
    }

    private function normalizeSubIdCandidate(mixed $value): ?string
    {
        if (is_array($value)) {
            $value = json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        }

        return $this->normalizeLooseString($value);
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return list<array<string, mixed>>
     */
    private function extractCookieConversionRows(array $payload): array
    {
        $candidates = [
            'data.list',
            'data.rows',
            'data.records',
            'data.items',
            'data.order_list',
            'list',
            'rows',
            'records',
        ];

        foreach ($candidates as $path) {
            $value = $this->getValueByPath($payload, $path);
            if (is_array($value) && array_is_list($value)) {
                return array_values(array_filter($value, static fn($item): bool => is_array($item)));
            }
        }

        return [];
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return list<array<string, mixed>>
     */
    private function extractCookieCampaignRows(array $payload): array
    {
        $candidates = [
            'data.affiliateCampaignsList.affiliateCampaignDetailList',
            'data.affiliateCampaignDetailList',
            'data.list',
            'data.rows',
            'list',
        ];

        foreach ($candidates as $path) {
            $value = $this->getValueByPath($payload, $path);
            if (is_array($value) && array_is_list($value)) {
                return array_values(array_filter($value, static fn($item): bool => is_array($item)));
            }
        }

        return [];
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function hasNextCookieConversionPage(array $payload, int $page, int $pageSize, int $currentCount): bool
    {
        $total = $this->firstValueByPaths($payload, [
            'data.total_count',
            'data.total',
            'data.count',
            'total_count',
            'total',
            'count',
        ]);

        if (is_numeric($total) && (int) $total > 0) {
            return ($page * $pageSize) < (int) $total;
        }

        $totalPages = $this->firstValueByPaths($payload, [
            'data.total_pages',
            'data.total_page',
            'data.page_total',
            'total_pages',
            'total_page',
        ]);

        if (is_numeric($totalPages) && (int) $totalPages > 0) {
            return $page < (int) $totalPages;
        }

        $hasNext = $this->firstValueByPaths($payload, [
            'data.has_next',
            'data.hasNext',
            'data.page_info.has_next',
            'data.pageInfo.hasNext',
            'has_next',
            'hasNext',
            'next_page',
        ]);

        if (is_bool($hasNext)) {
            return $hasNext;
        }

        if (is_numeric($hasNext)) {
            return (int) $hasNext > 0;
        }

        // Fallback: if API does not expose pagination flags, infer by page size.
        return $currentCount === $pageSize && $currentCount > 0;
    }

    /**
     * @param  array<string, mixed>  $row
     * @return array<string, mixed>|null
     */
    private function mapCookieConversionToOrder(array $row): ?array
    {
        [$orderCode, $legacyOrderCode] = $this->resolveCookieOrderCodes($row, $row);
        if ($orderCode === null) {
            return null;
        }

        $items = is_array($row['items'] ?? null) ? $row['items'] : [];
        $status = $this->resolveCookieOrderStatus($row, $row, $items);

        $orderedAt = $this->asCarbon($this->firstValueByPaths($row, [
            'purchase_time',
            'purchaseTime',
            'order_time',
            'orderTime',
            'create_time',
            'createTime',
            'ordered_at',
            'order.ordered_at',
            'order_info.purchase_time',
        ])) ?? now();

        $approvedAt = null;
        if ($status === 'approved') {
            $approvedAt = $this->asCarbon($this->firstValueByPaths($row, [
                'settled_time',
                'settledTime',
                'approved_time',
                'approvedTime',
                'complete_time',
                'completeTime',
                'pay_time',
                'payTime',
            ]));
        }

        $commissionBreakdown = $this->resolveCookieCommissionBreakdown($row, $row, $items, true);
        $commission = $commissionBreakdown['total'];
        $orderAmount = $this->resolveCookieOrderAmount($row, $row, $items, true);
        $listedAmount = $this->resolveCookieListedAmount($row, $row, $items, true);
        $details = $this->resolveCookieOrderDetails($row, $row, $items, $orderCode, $legacyOrderCode);

        $subId = $this->firstValueByPaths($row, [
            'sub_id',
            'subId',
            'custom_parameters',
            'customParameters',
            'custom_id',
            'customId',
            'aff_sub',
            'utm_content',
        ]);

        if (is_array($subId)) {
            $subId = json_encode($subId);
        }

        return [
            'platform' => 'shopee',
            'order_code' => $orderCode,
            'legacy_order_code' => $legacyOrderCode,
            'external_order_id' => $details['external_order_id'],
            'sub_id' => $subId !== null ? (string) $subId : null,
            'shop_id' => $details['shop_id'],
            'shop_name' => $details['shop_name'],
            'product_id' => $details['product_id'],
            'product_model_id' => $details['product_model_id'],
            'product_name' => $details['product_name'],
            'product_link' => $details['product_link'],
            'product_quantity' => $details['product_quantity'],
            'order_amount' => $orderAmount,
            'listed_amount' => $listedAmount,
            'commission' => $commission,
            'commission_platform' => $commissionBreakdown['platform'],
            'commission_brand' => $commissionBreakdown['brand'],
            'commission_other' => $commissionBreakdown['other'],
            'status' => $status,
            'ordered_at' => $orderedAt,
            'click_at' => $details['click_at'],
            'approved_at' => $approvedAt,
            'completed_at' => $details['completed_at'],
            'source_meta' => $details['source_meta'],
            'source' => 'api',
        ];
    }

    /**
     * @param  array<string, mixed>  $row
     * @return list<array<string, mixed>>
     */
    private function mapCookieConversionRowToOrders(array $row): array
    {
        $orders = $row['orders'] ?? null;
        if (! is_array($orders) || $orders === []) {
            $single = $this->mapCookieConversionToOrder($row);

            return $single !== null ? [$single] : [];
        }

        $mapped = [];
        $orderCount = count(array_filter($orders, static fn($order): bool => is_array($order)));
        $singleOrderRow = $orderCount <= 1;

        foreach ($orders as $order) {
            if (! is_array($order)) {
                continue;
            }

            [$orderCode, $legacyOrderCode] = $this->resolveCookieOrderCodes($order, $row);
            if ($orderCode === null) {
                continue;
            }

            $items = is_array($order['items'] ?? null) ? $order['items'] : [];
            $status = $this->resolveCookieOrderStatus($row, $order, $items);

            $orderedAt = $this->asCarbon($this->firstValueByPaths($row, [
                'purchase_time',
                'purchaseTime',
                'checkout_complete_time',
            ])) ?? $this->asCarbon($this->firstValueByPaths($order, [
                'purchase_time',
                'create_time',
                'order_time',
                'complete_time',
            ])) ?? now();

            $approvedAt = null;
            if ($status === 'approved') {
                $approvedAt = $this->asCarbon($this->firstValueByPaths($order, [
                    'complete_time',
                    'approved_time',
                    'settled_time',
                ])) ?? $this->asCarbon($this->firstValueByPaths($row, [
                    'checkout_complete_time',
                ]));
            }

            $subId = $this->firstValueByPaths($row, [
                'utm_content',
                'sub_id',
                'subId',
                'custom_parameters',
                'customParameters',
            ]);
            if (is_array($subId)) {
                $subId = json_encode($subId);
            }

            $amountRaw = $this->resolveCookieOrderAmount($row, $order, $items, $singleOrderRow);
            $listedAmount = $this->resolveCookieListedAmount($row, $order, $items, $singleOrderRow);
            $commissionBreakdown = $this->resolveCookieCommissionBreakdown($row, $order, $items, $singleOrderRow);
            $commissionRaw = $commissionBreakdown['total'];
            $details = $this->resolveCookieOrderDetails($row, $order, $items, $orderCode, $legacyOrderCode);

            $mapped[] = [
                'platform' => 'shopee',
                'order_code' => $orderCode,
                'legacy_order_code' => $legacyOrderCode,
                'external_order_id' => $details['external_order_id'],
                'sub_id' => $subId !== null ? (string) $subId : null,
                'shop_id' => $details['shop_id'],
                'shop_name' => $details['shop_name'],
                'product_id' => $details['product_id'],
                'product_model_id' => $details['product_model_id'],
                'product_name' => $details['product_name'],
                'product_link' => $details['product_link'],
                'product_quantity' => $details['product_quantity'],
                'order_amount' => $amountRaw,
                'listed_amount' => $listedAmount,
                'commission' => $commissionRaw,
                'commission_platform' => $commissionBreakdown['platform'],
                'commission_brand' => $commissionBreakdown['brand'],
                'commission_other' => $commissionBreakdown['other'],
                'status' => $status,
                'ordered_at' => $orderedAt,
                'click_at' => $details['click_at'],
                'approved_at' => $approvedAt,
                'completed_at' => $details['completed_at'],
                'source_meta' => $details['source_meta'],
                'source' => 'api',
            ];
        }

        return $mapped;
    }

    /**
     * @param  array<string, mixed>  $row
     * @param  array<string, mixed>  $order
     * @return array{0: string|null, 1: string|null}
     */
    private function resolveCookieOrderCodes(array $order, array $row): array
    {
        $orderSn = $this->firstValueByPaths($order, [
            'order_sn',
            'orderSn',
            'order.order_sn',
        ]) ?? $this->firstValueByPaths($row, [
            'order_sn',
            'orderSn',
        ]);

        $orderId = $this->firstValueByPaths($order, [
            'order_id',
            'orderId',
            'order_code',
            'orderCode',
            'order.id',
            'order.order_id',
            'order_info.order_id',
        ]) ?? $this->firstValueByPaths($row, [
            'order_id',
            'orderId',
            'order_code',
            'orderCode',
            'order.id',
            'order.order_id',
            'order_info.order_id',
        ]);

        $primary = trim((string) ($orderSn ?? $orderId ?? ''));
        if ($primary === '') {
            return [null, null];
        }

        $legacy = null;
        if ($orderSn !== null && $orderId !== null) {
            $legacyCandidate = trim((string) $orderId);
            if ($legacyCandidate !== '' && $legacyCandidate !== $primary) {
                $legacy = $legacyCandidate;
            }
        }

        return [$primary, $legacy];
    }

    /**
     * @param  array<string, mixed>  $row
     * @param  array<string, mixed>  $order
     * @param  array<int, mixed>  $items
     * @return array{
     *   external_order_id: string|null,
     *   shop_id: string|null,
     *   shop_name: string|null,
     *   product_id: string|null,
     *   product_model_id: string|null,
     *   product_name: string|null,
     *   product_link: string|null,
     *   product_quantity: int|null,
     *   click_at: Carbon|null,
     *   completed_at: Carbon|null,
     *   source_meta: array<string, mixed>
     * }
     */
    private function resolveCookieOrderDetails(
        array $row,
        array $order,
        array $items,
        string $orderCode,
        ?string $legacyOrderCode,
    ): array {
        $firstItem = null;
        foreach ($items as $item) {
            if (is_array($item)) {
                $firstItem = $item;
                break;
            }
        }

        $externalOrderId = trim((string) ($this->firstValueByPaths($order, ['order_id', 'orderId'])
            ?? $this->firstValueByPaths($row, ['order_id', 'orderId'])
            ?? ''));
        if ($externalOrderId === '' || $externalOrderId === $orderCode) {
            $externalOrderId = $legacyOrderCode ?: null;
        }

        $shopId = trim((string) ($this->firstValueByPaths($firstItem ?? [], ['shop_id', 'shopId']) ?? ''));
        $shopName = trim((string) ($this->firstValueByPaths($firstItem ?? [], ['shop_name', 'shopName']) ?? ''));

        $productId = trim((string) ($this->firstValueByPaths($firstItem ?? [], ['item_id', 'itemId']) ?? ''));
        $productModelId = trim((string) ($this->firstValueByPaths($firstItem ?? [], ['model_id', 'modelId']) ?? ''));
        $productName = trim((string) ($this->firstValueByPaths($firstItem ?? [], ['item_name', 'itemName']) ?? ''));

        $productLink = trim((string) ($this->firstValueByPaths($firstItem ?? [], ['item_link', 'itemLink']) ?? ''));
        if ($productLink === '' && $shopId !== '' && $productId !== '') {
            $productLink = "https://shopee.vn/product/{$shopId}/{$productId}";
        }

        $quantity = 0;
        foreach ($items as $item) {
            if (! is_array($item)) {
                continue;
            }
            $qty = (int) ($this->firstValueByPaths($item, ['qty', 'quantity']) ?? 0);
            $quantity += max($qty, 0);
        }
        $quantity = $quantity > 0 ? $quantity : null;

        $clickAt = $this->asCarbon($this->firstValueByPaths($order, ['click_time', 'clickTime']))
            ?? $this->asCarbon($this->firstValueByPaths($row, ['click_time', 'clickTime']));

        $completedAt = $this->asCarbon($this->firstValueByPaths($order, [
            'complete_time',
            'completeTime',
            'fraud_complete_time',
            'fraudCompleteTime',
        ])) ?? $this->asCarbon($this->firstValueByPaths($row, [
            'checkout_complete_time',
            'checkoutCompleteTime',
        ]));

        $affiliateItemStatuses = [];
        $itemStatuses = [];
        $fraudStatuses = [];
        $fraudReasons = [];
        foreach ($items as $item) {
            if (! is_array($item)) {
                continue;
            }
            $affiliateItemStatuses[] = $item['affiliate_item_status'] ?? null;
            $itemStatuses[] = $item['item_status'] ?? null;
            $fraudStatuses[] = $item['fraud_status'] ?? null;
            $fraudReasons[] = $item['fraud_reason'] ?? null;
        }

        $sourceMeta = array_filter([
            'order_status' => $this->firstValueByPaths($order, ['order_status', 'orderStatus']),
            'display_order_status' => $this->firstValueByPaths($order, ['display_order_status']),
            'conversion_status' => $this->firstValueByPaths($row, ['conversion_status']),
            'checkout_status' => $this->firstValueByPaths($row, ['checkout_status']),
            'cancel_reason' => $this->firstValueByPaths($order, ['cancel_reason']),
            'image_url' => $this->normalizeShopeeImageUrl(
                $this->firstValueByPaths($firstItem ?? [], ['image_url', 'imageUrl', 'img_url', 'imgUrl', 'thumb', 'thumbnail'])
                ?? $this->firstValueByPaths($order, ['image_url', 'imageUrl', 'img_url', 'imgUrl', 'thumb', 'thumbnail'])
                ?? $this->firstValueByPaths($row, ['image_url', 'imageUrl', 'img_url', 'imgUrl', 'thumb', 'thumbnail'])
            ),
            'affiliate_item_statuses' => array_values(array_filter(array_unique($affiliateItemStatuses), static fn($value): bool => $value !== null && $value !== '')),
            'item_statuses' => array_values(array_filter(array_unique($itemStatuses), static fn($value): bool => $value !== null && $value !== '')),
            'fraud_statuses' => array_values(array_filter(array_unique($fraudStatuses), static fn($value): bool => $value !== null && $value !== '')),
            'fraud_reasons' => array_values(array_filter(array_unique($fraudReasons), static fn($value): bool => $value !== null && trim((string) $value) !== '')),
        ], static fn($value): bool => !($value === null || $value === '' || $value === []));

        return [
            'external_order_id' => $externalOrderId !== '' ? $externalOrderId : null,
            'shop_id' => $shopId !== '' ? $shopId : null,
            'shop_name' => $shopName !== '' ? $shopName : null,
            'product_id' => $productId !== '' ? $productId : null,
            'product_model_id' => $productModelId !== '' ? $productModelId : null,
            'product_name' => $productName !== '' ? $productName : null,
            'product_link' => $productLink !== '' ? $productLink : null,
            'product_quantity' => $quantity,
            'click_at' => $clickAt,
            'completed_at' => $completedAt,
            'source_meta' => $sourceMeta,
        ];
    }

    /**
     * @param  array<string, mixed>  $row
     * @param  array<string, mixed>  $order
     * @param  array<int, mixed>  $items
     */
    private function resolveCookieOrderStatus(array $row, array $order, array $items): string
    {
        $texts = [
            $this->firstValueByPaths($order, ['order_status', 'orderStatus', 'status', 'shopee_order_status']),
            $this->firstValueByPaths($row, ['checkout_status', 'conversion_status']),
            $this->firstValueByPaths($order, ['cancel_reason']),
        ];

        $codes = [];
        $statusCandidates = [
            $this->firstValueByPaths($order, ['display_order_status', 'conversion_status', 'affiliate_item_status', 'fraud_status']),
            $this->firstValueByPaths($row, ['display_order_status', 'conversion_status']),
        ];

        foreach ($items as $item) {
            if (! is_array($item)) {
                continue;
            }

            $statusCandidates[] = $item['affiliate_item_status'] ?? null;
            $statusCandidates[] = $item['display_item_status'] ?? null;
            $statusCandidates[] = $item['fraud_status'] ?? null;
            $statusCandidates[] = $item['item_status'] ?? null;
            $statusCandidates[] = $item['fraud_reason'] ?? null;
        }

        foreach ($statusCandidates as $candidate) {
            if ($candidate === null || $candidate === '') {
                continue;
            }

            if (is_numeric($candidate) || (is_string($candidate) && preg_match('/^\d+$/', trim($candidate)) === 1)) {
                $codes[] = (int) $candidate;
                continue;
            }

            $texts[] = $candidate;
        }

        foreach ($texts as $text) {
            $normalized = mb_strtolower(trim((string) $text));
            if ($normalized === '') {
                continue;
            }

            if (
                str_contains($normalized, 'invalid')
                || str_contains($normalized, 'fraud')
                || str_contains($normalized, 'reject')
                || str_contains($normalized, 'cancel')
                || str_contains($normalized, 'return')
                || str_contains($normalized, 'refund')
                || str_contains($normalized, 'hủy')
                || str_contains($normalized, 'huỷ')
                || str_contains($normalized, 'từ chối')
                || str_contains($normalized, 'không hợp lệ')
            ) {
                return 'rejected';
            }
        }

        if (in_array(3, $codes, true)) {
            return 'rejected';
        }

        if (in_array(2, $codes, true)) {
            return 'approved';
        }

        if (in_array(1, $codes, true) || in_array(0, $codes, true)) {
            return 'pending';
        }

        $statusRaw = (string) ($this->firstValueByPaths($order, [
            'order_status',
            'orderStatus',
            'status',
            'display_order_status',
        ]) ?? $this->firstValueByPaths($row, ['conversion_status', 'checkout_status']) ?? '');

        return $this->mapOrderStatus($statusRaw);
    }

    /**
     * @param  array<string, mixed>  $row
     * @param  array<string, mixed>  $order
     * @param  array<int, mixed>  $items
     */
    private function resolveCookieOrderAmount(array $row, array $order, array $items, bool $singleOrderRow): float
    {
        $itemAmount = 0.0;
        foreach ($items as $item) {
            if (! is_array($item)) {
                continue;
            }

            $itemAmount += $this->normalizeMoneyValue($this->firstValueByPaths($item, [
                'actual_amount',
                'actualAmount',
                'item_price',
                'itemPrice',
                'final_price',
                'finalPrice',
                'payment_amount',
            ]));
        }

        $orderAmount = $this->normalizeMoneyValue($this->firstValueByPaths($order, [
            'order_amount',
            'orderAmount',
            'actual_amount',
            'actualAmount',
            'payment_amount',
            'paymentAmount',
            'final_price',
            'finalPrice',
            'total_amount',
            'totalAmount',
            'price',
        ]));

        $rowAmount = $singleOrderRow
            ? $this->normalizeMoneyValue($this->firstValueByPaths($row, [
                'order_amount',
                'actual_amount',
                'payment_amount',
                'total_amount',
            ]))
            : 0.0;

        $candidates = array_filter([$itemAmount, $orderAmount, $rowAmount], static fn(float $value): bool => $value > 0);
        if ($candidates === []) {
            return 0.0;
        }

        return round(max($candidates), 2);
    }

    /**
     * @param  array<string, mixed>  $row
     * @param  array<string, mixed>  $order
     * @param  array<int, mixed>  $items
     */
    private function resolveCookieListedAmount(array $row, array $order, array $items, bool $singleOrderRow): float
    {
        $itemListedAmount = 0.0;
        foreach ($items as $item) {
            if (! is_array($item)) {
                continue;
            }

            $itemListedAmount += $this->normalizeMoneyValue($this->firstValueByPaths($item, [
                'item_price',
                'itemPrice',
                'list_price',
                'listPrice',
                'origin_price',
                'originPrice',
            ]));
        }

        $orderListedAmount = $this->normalizeMoneyValue($this->firstValueByPaths($order, [
            'item_price',
            'itemPrice',
            'order_list_price',
            'orderListPrice',
            'list_price',
            'listPrice',
        ]));

        $rowListedAmount = $singleOrderRow
            ? $this->normalizeMoneyValue($this->firstValueByPaths($row, [
                'item_price',
                'itemPrice',
                'order_list_price',
                'orderListPrice',
                'list_price',
                'listPrice',
            ]))
            : 0.0;

        $candidates = array_filter([$itemListedAmount, $orderListedAmount, $rowListedAmount], static fn(float $value): bool => $value > 0);
        if ($candidates === []) {
            return 0.0;
        }

        return round(max($candidates), 2);
    }

    /**
     * @param  array<string, mixed>  $row
     * @param  array<string, mixed>  $order
     * @param  array<int, mixed>  $items
     */
    private function resolveCookieCommission(array $row, array $order, array $items, bool $singleOrderRow): float
    {
        return $this->resolveCookieCommissionBreakdown($row, $order, $items, $singleOrderRow)['total'];
    }

    /**
     * @param  array<string, mixed>  $row
     * @param  array<string, mixed>  $order
     * @param  array<int, mixed>  $items
     * @return array{platform: float, brand: float, other: float, total: float}
     */
    private function resolveCookieCommissionBreakdown(array $row, array $order, array $items, bool $singleOrderRow): array
    {
        $itemGross = 0.0;
        $itemBrand = 0.0;
        foreach ($items as $item) {
            if (! is_array($item)) {
                continue;
            }

            $itemGross += $this->normalizeMoneyValue($this->firstValueByPaths($item, [
                'item_commission',
                'itemCommission',
                'commission',
                'commission_amount',
                'gross_commission',
            ]));

            $itemBrand += $this->normalizeMoneyValue($this->firstValueByPaths($item, [
                'capped_brand_commission',
                'brand_commission',
                'brandCommission',
                'campaign_mcn_brand_gross_commission',
            ]));
        }

        $orderNet = $this->normalizeMoneyValue($this->firstValueByPaths($order, [
            'affiliate_net_commission',
            'estimated_total_commission',
            'total_commission',
            'totalCommission',
        ]));

        $orderGross = $this->normalizeMoneyValue($this->firstValueByPaths($order, [
            'gross_commission',
            'capped_commission',
            'order_commission',
            'orderCommission',
            'product_commission',
            'productCommission',
        ]));

        $orderBrand = $this->normalizeMoneyValue($this->firstValueByPaths($order, [
            'total_brand_commission',
            'brand_commission',
        ]));

        $rowNet = $singleOrderRow
            ? $this->normalizeMoneyValue($this->firstValueByPaths($row, [
                'affiliate_net_commission',
                'estimated_total_commission',
                'total_commission',
            ]))
            : 0.0;

        $rowGross = $singleOrderRow
            ? $this->normalizeMoneyValue($this->firstValueByPaths($row, [
                'gross_commission',
                'capped_commission',
                'order_commission',
                'product_commission',
            ]))
            : 0.0;

        $rowBrand = $singleOrderRow
            ? $this->normalizeMoneyValue($this->firstValueByPaths($row, [
                'total_brand_commission',
            ]))
            : 0.0;

        $platform = max($itemGross, $orderGross, $rowGross);
        $brand = max($itemBrand, $orderBrand, $rowBrand);
        $combined = round($platform + $brand, 2);
        $net = max($orderNet, $rowNet);
        $total = round(max($combined, $net), 2);
        $other = round(max($total - $combined, 0), 2);

        return [
            'platform' => round($platform, 2),
            'brand' => round($brand, 2),
            'other' => $other,
            'total' => $total,
        ];
    }

    /**
     * @param  array<string, mixed>  $source
     * @param  list<string>  $paths
     */
    private function firstValueByPaths(array $source, array $paths): mixed
    {
        foreach ($paths as $path) {
            $value = $this->getValueByPath($source, $path);
            if ($value !== null && $value !== '') {
                return $value;
            }
        }

        return null;
    }

    private function normalizeShopeeImageUrl(?string $url): ?string
    {
        if ($url === null || trim($url) === '') {
            return null;
        }

        $url = trim($url);
        // Ensure Shopee CDN images have an extension
        if (str_contains($url, 'susercontent.com') && !preg_match('/\.(jpe?g|png|gif|webp)$/i', $url)) {
            // Remove any trailing suffixes like _tn
            $url = preg_replace('/_tn$/i', '', $url);
            $url .= '.jpeg';
        }

        return $url;
    }

    /**
     * @param  array<string, mixed>  $source
     */
    private function getValueByPath(array $source, string $path): mixed
    {
        $segments = explode('.', $path);
        $current = $source;

        foreach ($segments as $segment) {
            if (! is_array($current) || ! array_key_exists($segment, $current)) {
                return null;
            }
            $current = $current[$segment];
        }

        return $current;
    }

    private function asCarbon(mixed $value): ?Carbon
    {
        if ($value instanceof Carbon) {
            return $value;
        }

        if (is_numeric($value)) {
            $timestamp = (int) $value;
            if ($timestamp <= 0) {
                return null;
            }

            if ($timestamp > 10_000_000_000) {
                $timestamp = (int) floor($timestamp / 1000);
            }

            return Carbon::createFromTimestamp($timestamp);
        }

        if (is_string($value) && trim($value) !== '') {
            try {
                return Carbon::parse($value);
            } catch (\Throwable) {
                return null;
            }
        }

        return null;
    }

    private function asFloat(mixed $value): float
    {
        if (is_int($value) || is_float($value)) {
            return (float) $value;
        }

        if (! is_string($value)) {
            return 0.0;
        }

        $normalized = trim($value);
        if ($normalized === '') {
            return 0.0;
        }

        $normalized = preg_replace('/[^\d,.\-]/', '', $normalized) ?? '';
        if ($normalized === '' || $normalized === '-' || $normalized === '.' || $normalized === ',') {
            return 0.0;
        }

        // e.g. 235.400 (VN thousand separator) -> 235400
        if (preg_match('/^\d{1,3}(\.\d{3})+$/', $normalized) === 1) {
            return (float) str_replace('.', '', $normalized);
        }

        // e.g. 235,400 (US thousand separator) -> 235400
        if (preg_match('/^\d{1,3}(,\d{3})+$/', $normalized) === 1) {
            return (float) str_replace(',', '', $normalized);
        }

        // If both separators exist, determine decimal separator by the last occurrence.
        if (str_contains($normalized, ',') && str_contains($normalized, '.')) {
            $lastComma = strrpos($normalized, ',');
            $lastDot = strrpos($normalized, '.');

            if ($lastComma !== false && $lastDot !== false && $lastComma > $lastDot) {
                $normalized = str_replace('.', '', $normalized);
                $normalized = str_replace(',', '.', $normalized);
            } else {
                $normalized = str_replace(',', '', $normalized);
            }
        } elseif (str_contains($normalized, ',')) {
            $normalized = str_replace(',', '.', $normalized);
        }

        return (float) $normalized;
    }

    private function normalizeMoneyValue(mixed $value): float
    {
        $amount = $this->asFloat($value);
        if ($amount === 0.0) {
            return 0.0;
        }

        if (is_int($value) || is_float($value)) {
            if (abs((float) $value) >= 1_000_000) {
                return round($amount / 100_000, 2);
            }

            return round($amount, 2);
        }

        if (is_string($value)) {
            $trimmed = trim($value);
            if ($trimmed !== '' && preg_match('/^\d+$/', $trimmed) === 1 && abs($amount) >= 1_000_000) {
                return round($amount / 100_000, 2);
            }
        }

        return round($amount, 2);
    }

    public function getOfferDetail(PlatformConnection $connection, string $offerId, array $filters = []): array
    {
        if ($connection->method === 'portal_export') {
            return [];
        }

        $normalizedOfferId = trim($offerId);
        if ($normalizedOfferId === '' || ! ctype_digit($normalizedOfferId)) {
            return [];
        }

        $queryFilters = [
            'itemId' => (int) $normalizedOfferId,
            'page' => 1,
            'limit' => 1,
        ];

        $shopId = $filters['shopId'] ?? $filters['shop_id'] ?? null;
        if (is_scalar($shopId)) {
            $shopIdString = trim((string) $shopId);
            if ($shopIdString !== '' && ctype_digit($shopIdString)) {
                $queryFilters['shopId'] = (int) $shopIdString;
            }
        }

        $offers = $this->getOffers($connection, $queryFilters);
        $nodes = is_array($offers['nodes'] ?? null) ? $offers['nodes'] : [];
        foreach ($nodes as $node) {
            if (is_array($node) && (string) ($node['itemId'] ?? '') === $normalizedOfferId) {
                return $node;
            }
        }

        if (! array_key_exists('shopId', $queryFilters)) {
            return [];
        }

        unset($queryFilters['shopId']);
        $offers = $this->getOffers($connection, $queryFilters);
        $nodes = is_array($offers['nodes'] ?? null) ? $offers['nodes'] : [];
        foreach ($nodes as $node) {
            if (is_array($node) && (string) ($node['itemId'] ?? '') === $normalizedOfferId) {
                return $node;
            }
        }

        return [];
    }

    public function getOffers(PlatformConnection $connection, array $filters): array
    {
        if ($connection->method === 'portal_export') {
            return ['nodes' => [], 'pageInfo' => []];
        }

        if ($connection->method === 'cookie') {
            $itemId = isset($filters['itemId']) ? (int) $filters['itemId'] : 0;
            $keyword = $filters['keyword'] ?? null;

            if ($itemId > 0) {
                $shopId = isset($filters['shopId']) ? (int) $filters['shopId'] : null;
                $node = $this->fetchOfferProductViaScraper($connection, $itemId, $shopId);
                return [
                    'nodes' => $node !== null ? [$node] : [],
                    'pageInfo' => ['page' => 1, 'limit' => 1, 'hasNextPage' => false],
                ];
            } elseif ($keyword !== null && $keyword !== '') {
                $limit = isset($filters['limit']) ? (int) $filters['limit'] : 20;
                $nodes = $this->searchOfferProductsViaScraper($connection, $keyword, $limit);
                return [
                    'nodes' => $nodes,
                    'pageInfo' => ['page' => 1, 'limit' => max(1, count($nodes)), 'hasNextPage' => false],
                ];
            }

            return ['nodes' => [], 'pageInfo' => ['page' => 1, 'limit' => 1, 'hasNextPage' => false]];
        }

        $query = <<<'GRAPHQL'
        query productOfferV2($keyword: String, $listType: Int, $sortType: Int, $page: Int, $limit: Int, $shopId: Long, $itemId: Long, $productCatId: Int) {
            productOfferV2(
                keyword: $keyword,
                listType: $listType,
                sortType: $sortType,
                page: $page,
                limit: $limit,
                shopId: $shopId,
                itemId: $itemId,
                productCatId: $productCatId
            ) {
                nodes {
                    itemId
                    productName
                    productLink
                    offerLink
                    imageUrl
                    priceMin
                    priceMax
                    priceDiscountRate
                    sales
                    ratingStar
                    commissionRate
                    sellerCommissionRate
                    shopeeCommissionRate
                    shopId
                    shopName
                    shopType
                    periodStartTime
                    periodEndTime
                }
                pageInfo {
                    page
                    limit
                    hasNextPage
                }
            }
        }
        GRAPHQL;

        $variables = array_filter([
            'keyword'       => $filters['keyword'] ?? null,
            'listType'      => isset($filters['listType']) ? (int) $filters['listType'] : null,
            'sortType'      => isset($filters['sortType']) ? (int) $filters['sortType'] : null,
            'page'          => (int) ($filters['page'] ?? 1),
            'limit'         => min((int) ($filters['limit'] ?? 20), 50),
            'shopId'        => isset($filters['shopId']) ? (int) $filters['shopId'] : null,
            'itemId'        => isset($filters['itemId']) ? (int) $filters['itemId'] : null,
            'productCatId'  => isset($filters['productCatId']) ? (int) $filters['productCatId'] : null,
        ], fn($v) => $v !== null);

        $result = $this->graphql($connection, $query, $variables);

        return $result['productOfferV2'] ?? ['nodes' => [], 'pageInfo' => []];
    }

    /**
     * @return array<string, mixed>|null
     */
    /**
     * @return array<string, mixed>|null
     */
    private function fetchOfferProductViaScraper(PlatformConnection $connection, int $itemId, ?int $shopId): ?array
    {
        $payload = ['item_id' => $itemId];
        if ($shopId !== null && $shopId > 0) {
            $payload['shop_id'] = $shopId;
        }

        $result = $this->callPythonScraper($connection, '/api/v1/shopee/product', $payload);
        if ($result === null) {
            // Scraper failed or anti-bot block - fallback to public API
            return $this->fetchOfferProductViaPublicApi($itemId, $shopId);
        }

        return $this->normalizeScraperNode($result, $itemId, $shopId);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function searchOfferProductsViaScraper(PlatformConnection $connection, string $keyword, int $limit): array
    {
        $payload = [
            'keyword' => $keyword,
            'page_limit' => $limit,
        ];

        $results = $this->callPythonScraper($connection, '/api/v1/shopee/search', $payload);
        if (!is_array($results)) {
            return [];
        }

        $nodes = [];
        foreach ($results as $item) {
            if (is_array($item)) {
                $node = $this->normalizeScraperNode(
                    $item,
                    (int) ($item['item_id'] ?? 0),
                    isset($item['shop_id']) ? (int) $item['shop_id'] : null
                );
                if ($node !== null) {
                    $nodes[] = $node;
                }
            }
        }

        return $nodes;
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>|null|list<array<string, mixed>>
     */
    private function callPythonScraper(PlatformConnection $connection, string $endpoint, array $payload)
    {
        if (!config('services.scraper.enabled', true)) {
            return null;
        }

        $cookies = $connection->cookie_header;
        if (!$cookies) {
            return null;
        }

        $payload['cookies'] = $cookies;

        $baseUrl = config('services.scraper.url', 'http://scraper:8000');
        $apiKey  = config('services.scraper.api_key', 'dev-secret-key');
        $timeout = (int) config('services.scraper.timeout', 30);

        try {
            $response = Http::withHeaders([
                'Accept'           => 'application/json',
                'Content-Type'     => 'application/json',
                'X-Internal-Token' => $apiKey,
            ])
                ->timeout($timeout)
                ->post("{$baseUrl}{$endpoint}", $payload);

            if (!$response->successful()) {
                Log::warning("shopee_scraper.internal_service_http_failed", [
                    'endpoint'    => $endpoint,
                    'http_status' => $response->status(),
                ]);
                return null;
            }

            $json = $response->json();

            if (!is_array($json) || !($json['ok'] ?? false)) {
                $errorCode = $json['error_code'] ?? null;
                if ($errorCode === 'MISSING_COOKIES' || $errorCode === 'SCRAPE_FAILED') {
                    if (str_contains($json['message'] ?? '', 'expired') || str_contains($json['message'] ?? '', 'login')) {
                        $this->markCookieAuthFailure($connection, "Cookie authentication failed (Scraper reported expired).");
                        throw new RuntimeException("Cookie authentication failed.");
                    }
                }
                
                if ($errorCode === 'BLOCKED_BOT' || str_contains($json['message'] ?? '', '90309999')) {
                    throw new RuntimeException("Shopee anti-bot 90309999 challenge.");
                }
                Log::warning('shopee_scraper.internal_service_error', [
                    'endpoint'   => $endpoint,
                    'error_code' => $errorCode,
                    'message'    => $json['message'] ?? null,
                ]);
                return null;
            }

            return $json['data'] ?? null;
        } catch (RuntimeException $e) {
            throw $e;
        } catch (\Exception $e) {
            Log::warning('shopee_scraper.internal_service_exception', [
                'error'    => $e->getMessage(),
                'endpoint' => $endpoint,
            ]);
            return null;
        }
    }

    /**
     * @param array<string, mixed> $product
     * @return array<string, mixed>|null
     */
    private function normalizeScraperNode(array $product, int $itemId, ?int $shopId): ?array
    {
        if (empty($product['item_name']) && empty($product['title'])) {
            return null;
        }

        $priceMin = $this->normalizeMoneyValue($product['price_min'] ?? $product['price'] ?? 0);
        $priceMax = $this->normalizeMoneyValue($product['price_max'] ?? $product['price'] ?? 0);

        return [
            'itemId'               => $itemId,
            'shopId'               => $shopId ?? (isset($product['shop_id']) ? (int) $product['shop_id'] : null),
            'productName'          => $product['item_name'] ?? $product['title'] ?? null,
            'offerLink'            => null,
            'imageUrl'             => $product['image_url'] ?? $product['image'] ?? null,
            'priceMin'             => $priceMin,
            'priceMax'             => $priceMax,
            'priceDiscountRate'    => null,
            'sales'                => (int) ($product['sales'] ?? 0),
            'ratingStar'           => (float) ($product['rating_star'] ?? 0),
            'commissionRate'       => null,
            'sellerCommissionRate' => null,
            'shopeeCommissionRate' => null,
        ];
    }

    /**
     * Fallback: fetch product info from Shopee's public (unauthenticated) item API.
     * Used when the affiliate cookie API returns an anti-bot challenge (90309999).
     * Commission rate will not be available — caller should handle gracefully.
     *
     * SSRF safety: $itemId and $shopId are already validated as integers by the caller.
     *
     * @return array<string, mixed>|null
     */
    private function fetchOfferProductViaPublicApi(int $itemId, ?int $shopId): ?array
    {
        $query = ['itemid' => $itemId];
        if ($shopId !== null && $shopId > 0) {
            $query['shopid'] = $shopId;
        }

        try {
            $response = Http::withHeaders([
                'Accept'          => 'application/json',
                'Accept-Language' => 'vi-VN,vi;q=0.9',
                'Referer'         => 'https://shopee.vn/',
                'User-Agent'      => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/124.0.0.0 Safari/537.36',
            ])
                ->timeout(15)
                ->get(self::PUBLIC_ITEM_API_ENDPOINT, $query);

            if (! $response->successful()) {
                Log::warning('Shopee public item API fallback failed, using mock product', [
                    'item_id'     => $itemId,
                    'shop_id'     => $shopId,
                    'http_status' => $response->status(),
                ]);
                return $this->buildMockProductFallback($itemId, $shopId);
            }

            $payload = $response->json();
            if (! is_array($payload)) {
                return $this->buildMockProductFallback($itemId, $shopId);
            }

            // Shopee public API response structure: { error: 0, item: {...} }
            $error = (int) ($payload['error'] ?? 0);
            if ($error !== 0) {
                Log::warning('Shopee public item API returned error, using mock product', [
                    'item_id'      => $itemId,
                    'error_code'   => $error,
                    'error_msg'    => $payload['error_msg'] ?? '',
                ]);
                return $this->buildMockProductFallback($itemId, $shopId);
            }

            $item = $this->firstValueByPaths($payload, [
                'item',
                'data.item',
                'data',
            ]);

            if (! is_array($item) || $item === []) {
                return $this->buildMockProductFallback($itemId, $shopId);
            }

            // Extract base fields from public API payload.
            $resolvedItemId  = (int) ($item['itemid'] ?? $item['item_id'] ?? $itemId);
            $resolvedShopId  = isset($item['shopid']) ? (int) $item['shopid'] : $shopId;

            $priceMin = $this->normalizeMoneyValue($this->firstValueByPaths($item, [
                'price_min', 'price', 'price_before_discount',
            ]));
            $priceMax = $this->normalizeMoneyValue($this->firstValueByPaths($item, [
                'price_max', 'price', 'price_before_discount',
            ]));
            if ($priceMax <= 0) $priceMax = $priceMin;
            if ($priceMin <= 0) $priceMin = $priceMax;

            // Image: public API stores hash in images[0] without URL prefix.
            $imageUrl = null;
            $images = $item['images'] ?? [];
            if (is_array($images) && isset($images[0]) && is_string($images[0])) {
                $raw = trim($images[0]);
                $imageUrl = str_starts_with($raw, 'http')
                    ? $raw
                    : 'https://down-vn.img.susercontent.com/file/' . $raw;
            }
            if ($imageUrl === null && isset($item['image']) && is_string($item['image'])) {
                $raw = trim($item['image']);
                $imageUrl = str_starts_with($raw, 'http')
                    ? $raw
                    : 'https://down-vn.img.susercontent.com/file/' . $raw;
            }

            $offerLink   = "https://affiliate.shopee.vn/offer/product_offer/{$resolvedItemId}";
            $productLink = $resolvedShopId !== null
                ? "https://shopee.vn/product/{$resolvedShopId}/{$resolvedItemId}"
                : '';

            return [
                'itemId'              => (string) $resolvedItemId,
                'productName'         => (string) ($item['name'] ?? $item['item_name'] ?? ''),
                'productLink'         => $productLink,
                'offerLink'           => $offerLink,
                'imageUrl'            => $imageUrl,
                'priceMin'            => $priceMin,
                'priceMax'            => $priceMax,
                'priceDiscountRate'   => $this->asFloat($item['raw_discount'] ?? $item['discount'] ?? null),
                'sales'               => (int) ($item['sold'] ?? $item['historical_sold'] ?? 0),
                'ratingStar'          => $this->asFloat($item['item_rating']['rating_star'] ?? $item['rating_star'] ?? null),
                'commissionRate'      => 0.0,   // not available via public API
                'sellerCommissionRate' => 0.0,
                'shopeeCommissionRate' => 0.0,
                'shopId'              => $resolvedShopId !== null ? (string) $resolvedShopId : null,
                'shopName'            => $item['shop_name'] ?? $item['shopName'] ?? null,
                'shopType'            => null,
                'periodStartTime'     => null,
                'periodEndTime'       => null,
                '_fallback'           => true,  // signal to UI that commission info is unavailable
            ];
        } catch (\Throwable $e) {
            Log::warning('Shopee public item API fallback exception, using mock product', [
                'item_id' => $itemId,
                'error'   => $e->getMessage(),
            ]);
            return $this->buildMockProductFallback($itemId, $shopId);
        }
    }

    /**
     * Build a mock product payload when all Shopee APIs fail (Anti-bot / 403 blocks),
     * ensuring users can still generate affiliate tracking links for valid IDs.
     *
     * @return array<string, mixed>
     */
    private function buildMockProductFallback(int $itemId, ?int $shopId): array
    {
        $offerLink   = "https://affiliate.shopee.vn/offer/product_offer/{$itemId}";
        $productLink = $shopId !== null && $shopId > 0
            ? "https://shopee.vn/product/{$shopId}/{$itemId}"
            : "https://shopee.vn/product/-/{$itemId}";

        return [
            'itemId'              => (string) $itemId,
            'productName'         => "Sản phẩm mã {$itemId} (ẩn thông tin)",
            'productLink'         => $productLink,
            'offerLink'           => $offerLink,
            'imageUrl'            => null,
            'priceMin'            => 0.0,
            'priceMax'            => 0.0,
            'priceDiscountRate'   => 0.0,
            'sales'               => 0,
            'ratingStar'          => 0.0,
            'commissionRate'      => 0.0,
            'sellerCommissionRate' => 0.0,
            'shopeeCommissionRate' => 0.0,
            'shopId'              => $shopId !== null && $shopId > 0 ? (string) $shopId : null,
            'shopName'            => null,
            'shopType'            => null,
            'periodStartTime'     => null,
            'periodEndTime'       => null,
            '_fallback'           => true, // Signal to UI
        ];
    }

    /**
     * @param  array<string, mixed>  $product
     * @return array<string, mixed>
     */
    private function normalizeCookieOfferNode(int $itemId, ?int $requestedShopId, array $product): array
    {
        $resolvedItemId = (string) ($this->firstValueByPaths($product, ['item_id', 'itemId', 'itemid', 'id']) ?? $itemId);
        $resolvedShopId = $this->firstValueByPaths($product, ['shop_id', 'shopId', 'shopid']);
        $resolvedShopId = is_numeric($resolvedShopId)
            ? (int) $resolvedShopId
            : ($requestedShopId !== null ? (int) $requestedShopId : null);

        $priceMin = $this->normalizeMoneyValue($this->firstValueByPaths($product, [
            'price_min',
            'priceMin',
            'price',
            'price_value',
        ]));
        $priceMax = $this->normalizeMoneyValue($this->firstValueByPaths($product, [
            'price_max',
            'priceMax',
            'price',
            'price_value',
        ]));
        if ($priceMax <= 0) {
            $priceMax = $priceMin;
        }
        if ($priceMin <= 0) {
            $priceMin = $priceMax;
        }

        $commissionRate = $this->normalizeCookieCommissionRate($this->firstValueByPaths($product, [
            'commission_rate',
            'commissionRate',
            'platform_commission_rate',
        ]));
        $sellerRate = $this->normalizeCookieCommissionRate($this->firstValueByPaths($product, [
            'seller_commission_rate',
            'sellerCommissionRate',
        ]));
        $shopeeRate = $this->normalizeCookieCommissionRate($this->firstValueByPaths($product, [
            'shopee_commission_rate',
            'shopeeCommissionRate',
        ]));

        $offerLink = trim((string) ($this->firstValueByPaths($product, ['offer_link', 'offerLink']) ?? ''));
        if ($offerLink === '') {
            $offerLink = "https://affiliate.shopee.vn/offer/product_offer/{$resolvedItemId}";
        }

        $productLink = trim((string) ($this->firstValueByPaths($product, ['product_link', 'productLink', 'item_url', 'itemUrl']) ?? ''));
        if ($productLink === '' && $resolvedShopId !== null) {
            $productLink = "https://shopee.vn/product/{$resolvedShopId}/{$resolvedItemId}";
        }

        return [
            'itemId' => $resolvedItemId,
            'productName' => (string) ($this->firstValueByPaths($product, ['product_name', 'productName', 'name', 'item_name']) ?? ''),
            'productLink' => $productLink,
            'offerLink' => $offerLink,
            'imageUrl' => $this->extractCookieOfferImageUrl($product),
            'priceMin' => $priceMin,
            'priceMax' => $priceMax,
            'priceDiscountRate' => $this->asFloat($this->firstValueByPaths($product, ['price_discount_rate', 'priceDiscountRate'])),
            'sales' => (int) ($this->firstValueByPaths($product, ['sales', 'sold_count']) ?? 0),
            'ratingStar' => $this->asFloat($this->firstValueByPaths($product, ['rating_star', 'ratingStar'])),
            'commissionRate' => $commissionRate,
            'sellerCommissionRate' => $sellerRate,
            'shopeeCommissionRate' => $shopeeRate,
            'shopId' => $resolvedShopId !== null ? (string) $resolvedShopId : null,
            'shopName' => $this->firstValueByPaths($product, ['shop_name', 'shopName']),
            'shopType' => $this->firstValueByPaths($product, ['shop_type', 'shopType']),
            'periodStartTime' => $this->firstValueByPaths($product, ['period_start_time', 'periodStartTime']),
            'periodEndTime' => $this->firstValueByPaths($product, ['period_end_time', 'periodEndTime']),
        ];
    }

    /**
     * @param  array<string, mixed>  $product
     */
    private function extractCookieOfferImageUrl(array $product): ?string
    {
        $direct = $this->firstValueByPaths($product, ['image_url', 'imageUrl', 'img_url', 'imgUrl', 'image']);
        if (is_string($direct) && trim($direct) !== '') {
            $value = trim($direct);
            if (str_starts_with($value, 'http://') || str_starts_with($value, 'https://')) {
                return $value;
            }

            return 'https://down-vn.img.susercontent.com/file/' . ltrim($value, '/');
        }

        $images = $this->firstValueByPaths($product, ['images', 'image_urls', 'imageUrls']);
        if (is_array($images) && isset($images[0]) && is_string($images[0]) && trim($images[0]) !== '') {
            $value = trim($images[0]);
            if (str_starts_with($value, 'http://') || str_starts_with($value, 'https://')) {
                return $value;
            }

            return 'https://down-vn.img.susercontent.com/file/' . ltrim($value, '/');
        }

        return null;
    }

    private function normalizeCookieCommissionRate(mixed $value): float
    {
        $rate = $this->asFloat($value);
        if ($rate <= 0) {
            return 0.0;
        }

        if ($rate > 100) {
            $rate = $rate / 1000;
        }

        return round($rate, 4);
    }

    /**
     * Fetch Shopee product category tree for offer filtering.
     *
     * @return list<array{catId: int, catName: string, children?: list<mixed>}>
     */
    public function getCategories(PlatformConnection $connection): array
    {
        if (in_array($connection->method, ['cookie', 'portal_export'])) {
            return [];
        }

        $query = <<<'GRAPHQL'
        query getProductCategory {
            getProductCategory {
                catId
                catName
                children {
                    catId
                    catName
                    children {
                        catId
                        catName
                    }
                }
            }
        }
        GRAPHQL;

        try {
            $result = $this->graphql($connection, $query);
            return $result['getProductCategory'] ?? [];
        } catch (\RuntimeException $e) {
            Log::warning('Shopee getCategories failed', [
                'connection_id' => $connection->id,
                'error'         => $e->getMessage(),
            ]);
            return [];
        }
    }

    public function generateShortLink(PlatformConnection $connection, string $originalUrl, ?string $subId = null): ?string
    {
        if (in_array($connection->method, ['cookie', 'portal_export'])) {
            // These methods don't currently support short link generation seamlessly.
            return null;
        }

        // SSRF guard: only allow Shopee domains.
        ShopeeDomainValidator::assertAllowedUrl($originalUrl);

        // Short link query — placeholder based on known Shopee Affiliate API patterns
        $query = <<<'GRAPHQL'
        mutation generateShortLink($originUrl: String!, $subId: String) {
            generateShortLink(
                originUrl: $originUrl,
                subId: $subId
            ) {
                shortLink
            }
        }
        GRAPHQL;

        $result = $this->graphql($connection, $query, [
            'originUrl' => $originalUrl,
            'subId'     => $subId,
        ]);

        return $result['generateShortLink']['shortLink'] ?? null;
    }

    // ─── Helpers ─────────────────────────────────────────────────────────────

    /**
     * Get the effective backfill window in days for a connection.
     */
    public function getBackfillDays(PlatformConnection $connection): int
    {
        return $connection->backfill_days_override ?? $this->backfillDays;
    }

    public function fetchBillings(PlatformConnection $connection, Carbon $since, Carbon $until): array
    {
        if ($connection->method !== 'cookie') {
            return [];
        }

        $rows = [];
        $page = 1;
        $pageSize = 100;
        $maxPages = 50;

        while ($page <= $maxPages) {
            $headers = $this->buildCookieHeaders($connection, self::COOKIE_BILLING_REFERER);
            $response = Http::withHeaders($headers)
                ->timeout(30)
                ->get(self::COOKIE_BILLING_LIST_ENDPOINT, [
                    'order_completed_start_time' => $since->timestamp,
                    'order_completed_end_time' => $until->timestamp,
                    'settlement_cycle' => 4,
                    'page_num' => $page,
                    'page_size' => $pageSize,
                ]);

            if (in_array($response->status(), [401, 403], true)) {
                $this->markCookieAuthFailure($connection, "Cookie authentication failed (HTTP {$response->status()}).");
                throw new RuntimeException("Cookie authentication failed (HTTP {$response->status()}).");
            }

            if (! $response->successful()) {
                throw new RuntimeException("Shopee billing list API error: HTTP {$response->status()}");
            }

            $payload = $response->json();
            if (! is_array($payload)) {
                throw new RuntimeException('Shopee billing list API returned invalid payload.');
            }

            if (array_key_exists('code', $payload) && (int) $payload['code'] !== 0) {
                $code = (int) $payload['code'];
                $message = (string) ($payload['msg'] ?? $payload['message'] ?? 'Unknown error');

                if (in_array($code, [401, 403], true)) {
                    $this->markCookieAuthFailure($connection, "Cookie authentication failed (code {$code}). {$message}");
                    throw new RuntimeException("Cookie authentication failed ({$code}).");
                }

                throw new RuntimeException("Shopee billing list API returned code {$code}: {$message}");
            }

            $list = $this->firstValueByPaths($payload, [
                'data.billing_list',
                'data.list',
                'data.rows',
                'data.items',
                'billing_list',
                'list',
            ]);
            if (! is_array($list) || ! array_is_list($list)) {
                $list = [];
            }

            foreach ($list as $row) {
                if (is_array($row)) {
                    $rows[] = $row;
                }
            }

            $totalCount = (int) ($this->firstValueByPaths($payload, [
                'data.pagination.total_count',
                'data.pagination.totalCount',
                'data.total_count',
                'data.total',
                'total_count',
                'total',
            ]) ?? 0);

            if (count($list) < $pageSize || ($totalCount > 0 && ($page * $pageSize) >= $totalCount)) {
                break;
            }

            $page++;
        }

        return $rows;
    }

    public function fetchPayouts(PlatformConnection $connection, Carbon $since, Carbon $until): array
    {
        if ($connection->method !== 'cookie') {
            return [];
        }

        $query = <<<'GRAPHQL'
        query getPaymentPayoutBillingList($pageSize: Int, $pageNum: Int) {
            getPaymentPayoutBillingList(pageSize: $pageSize, pageNum: $pageNum) {
                payoutList {
                    paymentPayout {
                        payoutCreatedTime
                        payoutId
                        totalPaymentAmount
                        payoutPaymentStatus
                        paidFailedReasonType
                        payArrivalTime
                        transferredToOffline
                        cancelReason
                        cancelType
                        closeReason
                    }
                    payoutInvoice {
                        invoiceStatus
                        dismissReason
                    }
                    payoutExternalInfo {
                        mergeInvoiceStatus
                        serviceFeeInvoiceStatus
                    }
                }
                payoutTotalAmount
                pagination {
                    pageNum
                    pageSize
                    totalCount
                }
            }
        }
        GRAPHQL;

        $rows = [];
        $page = 1;
        $pageSize = 100;
        $maxPages = 50;

        while ($page <= $maxPages) {
            $headers = $this->buildCookieHeaders($connection, self::GQL_PAYMENT_PAYOUT_REFERER);
            $headers['Content-Type'] = 'application/json; charset=UTF-8';
            $response = Http::withHeaders($headers)
                ->timeout(30)
                ->post(self::GQL_ENDPOINT . '?q=getPayoutList', [
                    'operationName' => 'getPaymentPayoutBillingList',
                    'query' => $query,
                    'variables' => [
                        'pageNum' => $page,
                        'pageSize' => $pageSize,
                    ],
                ]);

            if (in_array($response->status(), [401, 403], true)) {
                $this->markCookieAuthFailure($connection, "Cookie authentication failed (HTTP {$response->status()}).");
                throw new RuntimeException("Cookie authentication failed (HTTP {$response->status()}).");
            }

            if (! $response->successful()) {
                throw new RuntimeException("Shopee payout list API error: HTTP {$response->status()}");
            }

            $payload = $response->json();
            if (! is_array($payload)) {
                throw new RuntimeException('Shopee payout list API returned invalid payload.');
            }

            if (! empty($payload['errors']) && is_array($payload['errors'])) {
                $message = (string) ($payload['errors'][0]['message'] ?? 'GraphQL error');
                $normalized = mb_strtolower($message);
                if (
                    str_contains($normalized, 'unauthorized')
                    || str_contains($normalized, 'forbidden')
                    || str_contains($normalized, 'auth')
                ) {
                    $this->markCookieAuthFailure($connection, "Cookie authentication failed. {$message}");
                }

                throw new RuntimeException("Shopee payout list GraphQL error: {$message}");
            }

            $root = $this->firstValueByPaths($payload, [
                'data.getPaymentPayoutBillingList',
                'data.getPayoutList',
                'data.getPaymentPayoutList',
            ]);
            if (! is_array($root)) {
                throw new RuntimeException(
                    'Shopee payout response missing data. Hãy lấy lại cURL từ trang payout_record và cập nhật kết nối.'
                );
            }

            $list = $this->firstValueByPaths($payload, [
                'data.getPayoutList.payoutList',
                'data.getPaymentPayoutList.payoutList',
                'data.getPaymentPayoutBillingList.payoutList',
                'data.list',
                'list',
            ]);
            if (! is_array($list) || ! array_is_list($list)) {
                $list = [];
            }

            foreach ($list as $row) {
                if (is_array($row)) {
                    $paymentPayout = is_array($row['paymentPayout'] ?? null) ? $row['paymentPayout'] : [];
                    $payoutInvoice = is_array($row['payoutInvoice'] ?? null) ? $row['payoutInvoice'] : [];
                    $payoutExternalInfo = is_array($row['payoutExternalInfo'] ?? null) ? $row['payoutExternalInfo'] : [];

                    $rows[] = [
                        'payout_id' => $paymentPayout['payoutId'] ?? null,
                        'payout_time' => $paymentPayout['payoutCreatedTime'] ?? ($paymentPayout['payArrivalTime'] ?? null),
                        'amount' => $paymentPayout['totalPaymentAmount'] ?? 0,
                        'status' => $paymentPayout['payoutPaymentStatus'] ?? null,
                        'currency' => 'VND',
                        'bank_name' => null,
                        'bank_account_number' => null,
                        'invoice_status' => $payoutInvoice['invoiceStatus'] ?? null,
                        'invoice_dismiss_reason' => $payoutInvoice['dismissReason'] ?? null,
                        'merge_invoice_status' => $payoutExternalInfo['mergeInvoiceStatus'] ?? null,
                        'service_fee_invoice_status' => $payoutExternalInfo['serviceFeeInvoiceStatus'] ?? null,
                        'raw_payload' => $row,
                    ];
                }
            }

            $totalCount = (int) ($this->firstValueByPaths($payload, [
                'data.getPayoutList.pagination.totalCount',
                'data.getPaymentPayoutList.pagination.totalCount',
                'data.getPaymentPayoutBillingList.pagination.totalCount',
                'data.total_count',
                'total_count',
            ]) ?? 0);

            if (count($list) < $pageSize || ($totalCount > 0 && ($page * $pageSize) >= $totalCount)) {
                break;
            }

            $page++;
        }

        return $rows;
    }

    public function fetchBillFeeInvoices(PlatformConnection $connection, Carbon $since, Carbon $until): array
    {
        if ($connection->method !== 'cookie') {
            return [];
        }

        $query = <<<'GRAPHQL'
        query GetPaymentSummaryBillFeeInvoiceListQuery($pageNum: Int, $pageSize: Int, $serviceFeeInvoiceFilter: ServiceFeeInvoiceFilterInput) {
            getPaymentSummaryBillFeeInvoiceList(
                pageNum: $pageNum
                pageSize: $pageSize
                serviceFeeInvoiceFilter: $serviceFeeInvoiceFilter
            ) {
                pageNum
                pageSize
                paymentSummaryBillFeeInvoices {
                    affiliateId
                    affiliateName
                    billFeeStatus
                    paymentCompletePeriodEndTime
                    paymentCompletePeriodStartTime
                    paymentSummaryBillFeeInvoices {
                        dismissReason
                        fileUrl
                        invoiceNumber
                        invoiceStatus
                        invoiceType
                        rejectReason
                    }
                    totalServiceFee
                    validationId
                }
                totalCount
            }
        }
        GRAPHQL;

        $rows = [];
        $page = 1;
        $pageSize = 100;
        $maxPages = 50;

        while ($page <= $maxPages) {
            $headers = $this->buildCookieHeaders($connection, self::GQL_PAYMENT_SERVICE_FEE_REFERER);
            $headers['Content-Type'] = 'application/json; charset=UTF-8';

            $response = Http::withHeaders($headers)
                ->timeout(30)
                ->post(self::GQL_ENDPOINT . '?q=getPaymentSummaryBillFeeInvoiceList', [
                    'operationName' => 'GetPaymentSummaryBillFeeInvoiceListQuery',
                    'query' => $query,
                    'variables' => [
                        'pageNum' => $page,
                        'pageSize' => $pageSize,
                        'serviceFeeInvoiceFilter' => [],
                    ],
                ]);

            if (in_array($response->status(), [401, 403], true)) {
                $this->markCookieAuthFailure($connection, "Cookie authentication failed (HTTP {$response->status()}).");
                throw new RuntimeException("Cookie authentication failed (HTTP {$response->status()}).");
            }

            if (! $response->successful()) {
                throw new RuntimeException("Shopee bill fee invoice list API error: HTTP {$response->status()}");
            }

            $payload = $response->json();
            if (! is_array($payload)) {
                throw new RuntimeException('Shopee bill fee invoice list API returned invalid payload.');
            }

            if (! empty($payload['errors']) && is_array($payload['errors'])) {
                $message = (string) ($payload['errors'][0]['message'] ?? 'GraphQL error');
                $normalized = mb_strtolower($message);
                if (
                    str_contains($normalized, 'unauthorized')
                    || str_contains($normalized, 'forbidden')
                    || str_contains($normalized, 'auth')
                ) {
                    $this->markCookieAuthFailure($connection, "Cookie authentication failed. {$message}");
                }

                throw new RuntimeException("Shopee bill fee invoice GraphQL error: {$message}");
            }

            $root = $this->firstValueByPaths($payload, [
                'data.getPaymentSummaryBillFeeInvoiceList',
                'data.getBillFeeInvoiceList',
                'data',
            ]);
            if (! is_array($root)) {
                throw new RuntimeException(
                    'Shopee service fee invoice response missing data. Hãy lấy lại cURL từ trang service_fee_invoice và cập nhật kết nối.'
                );
            }

            $list = $this->firstValueByPaths($payload, [
                'data.getBillFeeInvoiceList.paymentSummaryBillFeeInvoices',
                'data.getPaymentSummaryBillFeeInvoiceList.paymentSummaryBillFeeInvoices',
                'data.list',
                'list',
            ]);
            if (! is_array($list) || ! array_is_list($list)) {
                $list = [];
            }

            foreach ($list as $row) {
                if (! is_array($row)) {
                    continue;
                }

                $invoiceList = is_array($row['paymentSummaryBillFeeInvoices'] ?? null)
                    ? array_values(array_filter($row['paymentSummaryBillFeeInvoices'], static fn($invoice): bool => is_array($invoice)))
                    : [];
                $primaryInvoice = $invoiceList[0] ?? [];

                $rows[] = [
                    'validation_id' => $row['validationId'] ?? null,
                    'affiliate_id' => $row['affiliateId'] ?? null,
                    'affiliate_name' => $row['affiliateName'] ?? null,
                    'status' => $row['billFeeStatus'] ?? null,
                    'period_start' => $row['paymentCompletePeriodStartTime'] ?? null,
                    'period_end' => $row['paymentCompletePeriodEndTime'] ?? null,
                    'total_service_fee' => $row['totalServiceFee'] ?? 0,
                    'invoice_number' => $primaryInvoice['invoiceNumber'] ?? null,
                    'invoice_status' => $primaryInvoice['invoiceStatus'] ?? null,
                    'invoice_type' => $primaryInvoice['invoiceType'] ?? null,
                    'file_url' => $primaryInvoice['fileUrl'] ?? null,
                    'dismiss_reason' => $primaryInvoice['dismissReason'] ?? null,
                    'reject_reason' => $primaryInvoice['rejectReason'] ?? null,
                    'invoices' => $invoiceList,
                    'raw_payload' => $row,
                ];
            }

            $totalCount = (int) ($this->firstValueByPaths($payload, [
                'data.getPaymentSummaryBillFeeInvoiceList.totalCount',
                'data.total_count',
                'total_count',
            ]) ?? 0);

            if (count($list) < $pageSize || ($totalCount > 0 && ($page * $pageSize) >= $totalCount)) {
                break;
            }

            $page++;
        }

        return $rows;
    }
}
