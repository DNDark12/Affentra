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
use RuntimeException;

class ShopeeIntegration extends BaseIntegration
{
    private const COOKIE_REPORT_ENDPOINT = 'https://affiliate.shopee.vn/api/v3/report/list';
    private const COOKIE_DASHBOARD_ENDPOINT = 'https://affiliate.shopee.vn/api/v3/dashboard/detail';
    private const COOKIE_CLICK_REPORT_ENDPOINT = 'https://affiliate.shopee.vn/api/v1/click_report/list';
    private const COOKIE_BILLING_LIST_ENDPOINT = 'https://affiliate.shopee.vn/api/v3/payment/billing_list';
    private const GQL_ENDPOINT = 'https://affiliate.shopee.vn/api/v3/gql';

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
                // TODO: Implement actual OAuth refresh flow when docs are confirmed
                // For now, mark connection as error state
                $connection->update([
                    'status'         => 'error',
                    'last_error'     => 'Authentication failed — token refresh needed',
                    'last_error_at'  => now(),
                ]);
            } finally {
                $lock->release();
            }
        }
        // If lock not acquired, another job is already refreshing — let caller retry
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
     *     dashboard: array{ok: bool, status: int, code: int|null, message: string},
     *     conversion_report: array{ok: bool, status: int, code: int|null, message: string},
     *     click_report: array{ok: bool, status: int, code: int|null, message: string}
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

                $valid = collect($checks)->contains(static fn (array $check): bool => $check['ok'] === true);
                $failedChecks = collect($checks)
                    ->filter(static fn (array $check): bool => $check['ok'] === false)
                    ->map(static fn (array $check, string $name): string => "{$name}: {$check['message']}")
                    ->values()
                    ->all();

                if ($valid) {
                    $warning = $failedChecks !== []
                        ? mb_substr('Partial cookie validation: ' . implode(' | ', $failedChecks), 0, 500)
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
                        'message' => $warning !== null
                            ? 'Cookie is usable, but some endpoints failed.'
                            : 'Connection valid.',
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
     * @param  array<string, mixed>  $query
     * @return array{ok: bool, status: int, code: int|null, message: string}
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

        $isOk = $response->successful()
            && ($code === null || $code === 0)
            && !(is_array($payload) && array_key_exists('error', $payload));

        return [
            'ok' => $isOk,
            'status' => $response->status(),
            'code' => $code,
            'message' => $isOk ? 'OK' : $message,
        ];
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
    private function buildCookieHeaders(PlatformConnection $connection, string $referer): array
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
        ];

        if (str_starts_with($rawCookie, '{')) {
            $parsed = json_decode($rawCookie, true);
            if (! is_array($parsed) || empty($parsed['cookie'])) {
                throw new RuntimeException('Missing cookie credentials.');
            }

            $profile = [];
            $profileKey = $this->inferCookieProfileKeyByReferer($referer);
            if (
                $profileKey !== null
                && is_array($parsed['profiles'] ?? null)
                && is_array($parsed['profiles'][$profileKey] ?? null)
            ) {
                $profile = $parsed['profiles'][$profileKey];
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
            ];

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

            return $headers;
        }

        $headers['Cookie'] = $rawCookie;

        return $headers;
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
        $pageNum = 1;
        $pageSize = 100;
        $maxPages = 20;

        $query = <<<'GRAPHQL'
query affiliateCampaignsList($pageNum: Int, $pageSize: Int) {
  affiliateCampaignsList(pageNum: $pageNum, pageSize: $pageSize) {
    affiliateCampaignDetailList {
      campaignId
      campaignName
      campaignStartTime
      campaignEndTime
      campaignDescription
      campaignImpressionNum
      campaignClickNum
      bannerImageId
      campaignStatus
      campaignUrl
    }
  }
}
GRAPHQL;

        while ($pageNum <= $maxPages) {
            $headers = $this->buildCookieHeaders($connection, self::GQL_CAMPAIGN_REFERER);
            $headers['Content-Type'] = 'application/json; charset=UTF-8';

            $response = Http::withHeaders($headers)
                ->timeout(30)
                ->post(self::GQL_ENDPOINT . '?q=affiliateCampaignDetailList', [
                    'operationName' => 'affiliateCampaignsList',
                    'query' => $query,
                    'variables' => [
                        'pageNum' => $pageNum,
                        'pageSize' => $pageSize,
                    ],
                ]);

            if (in_array($response->status(), [401, 403], true)) {
                $this->markCookieAuthFailure(
                    $connection,
                    "Cookie authentication failed (HTTP {$response->status()})."
                );

                throw new RuntimeException("Cookie authentication failed (HTTP {$response->status()}).");
            }

            if ($response->status() === 429) {
                throw new RuntimeException('Shopee cookie campaign API rate limit exceeded (429).');
            }

            if (! $response->successful()) {
                throw new RuntimeException("Shopee cookie campaign API error: HTTP {$response->status()}");
            }

            $payload = $response->json();
            if (! is_array($payload)) {
                throw new RuntimeException('Shopee cookie campaign API returned invalid payload.');
            }

            $code = $payload['code'] ?? $payload['error'] ?? 0;
            if (is_numeric($code) && (int) $code !== 0) {
                $message = (string) ($payload['msg'] ?? $payload['message'] ?? 'Unknown error');
                if ((int) $code === 90309999 || in_array((int) $code, [401, 403], true)) {
                    $this->markCookieAuthFailure($connection, "Cookie authentication failed (code {$code}). {$message}");
                    throw new RuntimeException("Cookie authentication failed ({$code}).");
                }
                throw new RuntimeException("Shopee cookie campaign API returned code {$code}: {$message}");
            }

            $rows = $this->extractCookieCampaignRows($payload);
            if ($rows === []) {
                break;
            }

            foreach ($rows as $row) {
                $all[] = $row;
            }

            if (count($rows) < $pageSize) {
                break;
            }

            $pageNum++;
        }

        return $all;
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

        while ($page <= $maxPages) {
            $headers = $this->buildCookieHeaders($connection, self::COOKIE_CONVERSION_REFERER);
            $response = Http::withHeaders($headers)
                ->timeout(30)
                ->get(self::COOKIE_REPORT_ENDPOINT, [
                    'page_size' => $pageSize,
                    'page_num' => $page,
                    'purchase_time_s' => $since->timestamp,
                    'purchase_time_e' => $until->timestamp,
                    'version' => 1,
                ]);

            if (in_array($response->status(), [401, 403], true)) {
                $this->markCookieAuthFailure(
                    $connection,
                    "Cookie authentication failed (HTTP {$response->status()})."
                );

                throw new RuntimeException("Cookie authentication failed (HTTP {$response->status()}).");
            }

            if ($response->status() === 429) {
                throw new RuntimeException('Shopee cookie report API rate limit exceeded (429).');
            }

            if (! $response->successful()) {
                throw new RuntimeException("Shopee cookie report API error: HTTP {$response->status()}");
            }

            $payload = $response->json();
            if (! is_array($payload)) {
                throw new RuntimeException('Shopee cookie report API returned invalid payload.');
            }

            if (array_key_exists('code', $payload) && (int) $payload['code'] !== 0) {
                $code = (int) $payload['code'];
                $message = (string) ($payload['msg'] ?? $payload['message'] ?? 'Unknown error');

                if (in_array($code, [401, 403], true)) {
                    $this->markCookieAuthFailure($connection, "Cookie authentication failed (code {$code}). {$message}");
                    throw new RuntimeException("Cookie authentication failed ({$code}).");
                }

                throw new RuntimeException("Shopee cookie report API returned code {$code}: {$message}");
            }

            $rows = $this->extractCookieConversionRows($payload);
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

        while ($page <= $maxPages) {
            $headers = $this->buildCookieHeaders($connection, self::COOKIE_CLICK_REPORT_REFERER);
            $response = Http::withHeaders($headers)
                ->timeout(30)
                ->get(self::COOKIE_CLICK_REPORT_ENDPOINT, [
                    'page_size' => $pageSize,
                    'page_num' => $page,
                    'click_time_s' => $since->timestamp,
                    'click_time_e' => $until->timestamp,
                    'version' => 1,
                ]);

            if (in_array($response->status(), [401, 403], true)) {
                $this->markCookieAuthFailure(
                    $connection,
                    "Cookie authentication failed (HTTP {$response->status()})."
                );

                throw new RuntimeException("Cookie authentication failed (HTTP {$response->status()}).");
            }

            if ($response->status() === 429) {
                throw new RuntimeException('Shopee cookie click report API rate limit exceeded (429).');
            }

            if (! $response->successful()) {
                throw new RuntimeException("Shopee cookie click report API error: HTTP {$response->status()}");
            }

            $payload = $response->json();
            if (! is_array($payload)) {
                throw new RuntimeException('Shopee cookie click report API returned invalid payload.');
            }

            if (array_key_exists('code', $payload) && (int) $payload['code'] !== 0) {
                $code = (int) $payload['code'];
                $message = (string) ($payload['msg'] ?? $payload['message'] ?? 'Unknown error');

                if (in_array($code, [401, 403], true)) {
                    $this->markCookieAuthFailure($connection, "Cookie authentication failed (code {$code}). {$message}");
                    throw new RuntimeException("Cookie authentication failed: {$message}");
                }

                throw new RuntimeException("Shopee cookie click report API returned error: {$message}");
            }

            $data = $payload['data'] ?? [];
            $list = $data['list'] ?? [];

            if (! is_array($list)) {
                throw new RuntimeException('Shopee cookie click report API returned unexpected list format.');
            }

            foreach ($list as $row) {
                $clicks[] = $this->mapCookieClickRow($row);
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

    private function mapCookieClickRow(array $row): array
    {
        return [
            'platform' => 'shopee',
            'click_time' => isset($row['click_time']) ? Carbon::createFromTimestamp($row['click_time']) : now(),
            'sub_id' => $row['sub_id1'] ?? $row['sub_id'] ?? null,
            'campaign_id' => $row['campaign_id'] ?? null,
            'item_id' => $row['item_id'] ?? null,
            'amount' => (int) ($row['click_count'] ?? 1),
            'raw_data' => $row,
        ];
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

    public function getOffers(PlatformConnection $connection, array $filters): array
    {
        if (in_array($connection->method, ['cookie', 'portal_export'])) {
            // These methods don't currently support offer discovery.
            return ['nodes' => [], 'pageInfo' => []];
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

            $rootData = $payload['data'] ?? null;
            if (! is_array($rootData) || ! array_key_exists('getPaymentPayoutBillingList', $rootData)) {
                throw new RuntimeException(
                    'Shopee payout response missing data. Hãy lấy lại cURL từ trang payout_record và cập nhật kết nối.'
                );
            }

            $list = $this->firstValueByPaths($payload, [
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

            $rootData = $payload['data'] ?? null;
            if (! is_array($rootData) || ! array_key_exists('getPaymentSummaryBillFeeInvoiceList', $rootData)) {
                throw new RuntimeException(
                    'Shopee service fee invoice response missing data. Hãy lấy lại cURL từ trang service_fee_invoice và cập nhật kết nối.'
                );
            }

            $list = $this->firstValueByPaths($payload, [
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
