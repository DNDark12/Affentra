<?php

declare(strict_types=1);

namespace App\Services\Integration\Lazada;

use App\DataTransferObjects\CapabilitySet;
use App\Enums\OrderStatus;
use App\Models\PlatformConnection;
use App\Services\Integration\BaseIntegration;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * Affiliate Lazada integration adapter.
 *
 * Phase 1 — Cookie method only.
 * Syncs conversion/order reports from the Lazada Affiliate Dashboard
 * using a captured browser session cookie.
 *
 * Phase 2 (planned): Lazada AdSense / Open Platform API once credentials
 * are approved. The `method` field on PlatformConnection will gate this.
 *
 * Lazada Affiliate Dashboard base:
 *   VN  → https://adsense.lazada.vn
 *   Others follow the same pattern per region.
 *
 * NOTE: Exact API endpoint paths and response schemas are marked as
 * `@placeholder` — they will be refined once real cURL samples are
 * available. All normalization logic is structured to be swapped easily.
 */
class LazadaIntegration extends BaseIntegration
{
    // ─── Dashboard Base ───────────────────────────────────────────────────────

    /**
     * Lazada Affiliate Dashboard — region-keyed base URLs.
     * @var array<string, string>
     */
    private const DASHBOARD_BASES = [
        'VN' => 'https://adsense.lazada.vn',
        'SG' => 'https://adsense.lazada.com.sg',
        'MY' => 'https://adsense.lazada.com.my',
        'PH' => 'https://adsense.lazada.com.ph',
        'TH' => 'https://adsense.lazada.co.th',
        'ID' => 'https://adsense.lazada.co.id',
    ];

    // ─── Endpoint Paths (placeholders — update once cURL samples confirmed) ──

    /**
     * Report/dashboard health check endpoint.
     * @placeholder — replace once real endpoint confirmed from dashboard cURL.
     */
    private const PATH_DASHBOARD = '/api/report/overview';

    /**
     * Conversion/order report endpoint.
     * @placeholder — replace once real endpoint confirmed from dashboard cURL.
     */
    private const PATH_CONVERSION = '/api/report/conversion';

    /**
     * Click report endpoint.
     * @placeholder — replace once real endpoint confirmed from dashboard cURL.
     */
    private const PATH_CLICK = '/api/report/click';

    // ─── Lazada affiliate status → Affentra OrderStatus mapping ──────────────

    private const STATUS_MAP = [
        'pending'    => OrderStatus::Pending,
        'processing' => OrderStatus::Pending,
        'validation' => OrderStatus::Pending,
        'approved'   => OrderStatus::Approved,
        'confirmed'  => OrderStatus::Approved,
        'paid'       => OrderStatus::Approved,
        'cancelled'  => OrderStatus::Rejected,
        'canceled'   => OrderStatus::Rejected,
        'rejected'   => OrderStatus::Rejected,
        'invalid'    => OrderStatus::Rejected,
        'returned'   => OrderStatus::Rejected,
        'refunded'   => OrderStatus::Rejected,
    ];

    private string $region;
    private int $backfillDays;
    private int $hardLimitDays;

    public function __construct()
    {
        $this->region       = config('integrations.lazada.region', 'VN');
        $this->backfillDays = (int) config('integrations.lazada.backfill_days', 14);
        $this->hardLimitDays = (int) config('integrations.lazada.hard_limit_days', 30);
    }

    // ─── Capabilities ─────────────────────────────────────────────────────────

    public function capabilities(): CapabilitySet
    {
        return new CapabilitySet(
            supportsAutoSync: true,
            supportsOfferDiscovery: false,  // Phase 2+
            supportsShortLink: false,        // Phase 2+
            supportsSubId: false,            // Enable once confirmed from real data
        );
    }

    // ─── IntegrationContract ──────────────────────────────────────────────────

    public function testConnection(PlatformConnection $connection): bool
    {
        $result = $this->testConnectionDetailed($connection);

        return (bool) ($result['valid'] ?? false);
    }

    /**
     * Probe dashboard endpoint and return detailed result.
     *
     * @return array{valid: bool, checks: array<string, mixed>, message: string}
     */
    public function testConnectionDetailed(PlatformConnection $connection): array
    {
        if ($connection->method !== 'cookie') {
            return [
                'valid'   => false,
                'checks'  => [],
                'message' => "Connection method '{$connection->method}' is not yet supported for Lazada. Use 'cookie' method.",
            ];
        }

        try {
            $today = [
                'start' => now()->startOfDay()->timestamp,
                'end'   => now()->endOfDay()->timestamp,
            ];

            $checks = [
                'dashboard' => $this->probeDashboardEndpoint(
                    connection: $connection,
                    path: self::PATH_DASHBOARD,
                    query: ['start_date' => date('Y-m-d'), 'end_date' => date('Y-m-d')],
                ),
            ];

            $anyOk   = collect($checks)->contains(fn (array $c): bool => $c['ok'] === true);
            $anyFail = collect($checks)->contains(fn (array $c): bool => $c['ok'] === false);

            if ($anyOk) {
                $connection->update([
                    'status'               => 'active',
                    'cookie_validated_at'  => now(),
                    'last_error'           => null,
                    'last_error_at'        => null,
                ]);

                return [
                    'valid'   => true,
                    'checks'  => $checks,
                    'message' => 'Kết nối Lazada hợp lệ.',
                ];
            }

            $failedMessages = collect($checks)
                ->filter(fn (array $c): bool => $c['ok'] === false)
                ->map(fn (array $c, string $name): string => "{$name}: {$c['message']}")
                ->values()
                ->implode(' | ');

            $this->markAuthFailure($connection, "Cookie test failed: {$failedMessages}");

            return [
                'valid'   => false,
                'checks'  => $checks,
                'message' => "Cookie không hợp lệ: {$failedMessages}",
            ];
        } catch (\Throwable $e) {
            Log::warning('LazadaIntegration::testConnectionDetailed.error', [
                'connection_id' => $connection->id,
                'error'         => $e->getMessage(),
            ]);

            return [
                'valid'   => false,
                'checks'  => [],
                'message' => $e->getMessage(),
            ];
        }
    }

    /**
     * Fetch affiliate conversion/order report.
     *
     * @return array<int, array<string, mixed>>
     */
    public function fetchReport(PlatformConnection $connection, Carbon $since, Carbon $until): array
    {
        if ($connection->method !== 'cookie') {
            Log::warning('LazadaIntegration::fetchReport — unsupported method', [
                'connection_id' => $connection->id,
                'method'        => $connection->method,
            ]);

            return [];
        }

        return $this->fetchConversionReportViaCookie($connection, $since, $until);
    }

    /**
     * Fetch click report. Returns empty array if endpoint not available.
     *
     * @return array<int, array<string, mixed>>
     */
    public function fetchClickReport(PlatformConnection $connection, Carbon $since, Carbon $until): array
    {
        if ($connection->method !== 'cookie') {
            return [];
        }

        try {
            return $this->fetchClickReportViaCookie($connection, $since, $until);
        } catch (\Throwable $e) {
            Log::warning('LazadaIntegration::fetchClickReport — skipped (endpoint may not be available)', [
                'connection_id' => $connection->id,
                'error'         => $e->getMessage(),
            ]);

            return [];
        }
    }

    // ─── Cookie-Based API Calls ───────────────────────────────────────────────

    /**
     * Pull conversion report pages from the Lazada Affiliate Dashboard.
     *
     * @placeholder — response shape will be updated once real cURL sample is provided.
     *
     * @return array<int, array<string, mixed>>
     */
    private function fetchConversionReportViaCookie(
        PlatformConnection $connection,
        Carbon $since,
        Carbon $until
    ): array {
        $allOrders = [];
        $page      = 1;
        $pageSize  = 50; // Conservative default; update based on actual API limits.

        do {
            $response = $this->callDashboardApi(
                connection: $connection,
                path: self::PATH_CONVERSION,
                query: [
                    'start_date' => $since->format('Y-m-d'),
                    'end_date'   => $until->format('Y-m-d'),
                    'page'       => $page,
                    'page_size'  => $pageSize,
                ],
            );

            // @placeholder — key names will be updated once real response is known.
            $data    = $response['data'] ?? $response['result'] ?? [];
            $rows    = $data['list'] ?? $data['items'] ?? $data ?? [];
            $hasMore = (bool) ($data['has_more'] ?? $data['hasMore'] ?? false);
            $total   = (int) ($data['total'] ?? $data['totalCount'] ?? 0);

            if (! is_array($rows)) {
                break;
            }

            foreach ($rows as $row) {
                $normalized = $this->normalizeConversionRow($row);
                if ($normalized !== null) {
                    $allOrders[] = $normalized;
                }
            }

            $fetchedCount = count($allOrders);

            // Pagination safety: stop if API says no more or if we've outrun total.
            if (! $hasMore || ($total > 0 && $fetchedCount >= $total)) {
                break;
            }

            $page++;
        } while ($page <= 200); // Hard cap to prevent infinite loops.

        Log::info('LazadaIntegration::fetchConversionReportViaCookie.done', [
            'connection_id' => $connection->id,
            'since'         => $since->toDateString(),
            'until'         => $until->toDateString(),
            'total_fetched' => count($allOrders),
        ]);

        return $allOrders;
    }

    /**
     * @placeholder — to be implemented once click report endpoint is confirmed.
     * @return array<int, array<string, mixed>>
     */
    private function fetchClickReportViaCookie(
        PlatformConnection $connection,
        Carbon $since,
        Carbon $until
    ): array {
        $response = $this->callDashboardApi(
            connection: $connection,
            path: self::PATH_CLICK,
            query: [
                'start_date' => $since->format('Y-m-d'),
                'end_date'   => $until->format('Y-m-d'),
                'page'       => 1,
                'page_size'  => 50,
            ],
        );

        $data = $response['data'] ?? $response['result'] ?? [];
        $rows = $data['list'] ?? $data['items'] ?? $data ?? [];

        if (! is_array($rows)) {
            return [];
        }

        return array_map(fn (array $row): array => $this->normalizeClickRow($row), $rows);
    }

    // ─── HTTP Caller ──────────────────────────────────────────────────────────

    /**
     * Execute a GET request against the Lazada Affiliate Dashboard API.
     * Uses the stored session cookie for authentication.
     *
     * @param  array<string, mixed>  $query
     * @return array<string, mixed>
     *
     * @throws \RuntimeException on auth failure or unexpected response.
     */
    private function callDashboardApi(
        PlatformConnection $connection,
        string $path,
        array $query = [],
    ): array {
        $cookieRaw = $this->extractCookieRaw($connection);
        $baseUrl   = $this->dashboardBaseUrl($connection);
        $url       = rtrim($baseUrl, '/') . $path;
        $headers   = $this->buildCookieHeaders($connection);

        $cookieHash = substr(hash('sha256', $cookieRaw), 0, 12);

        Log::info('LazadaIntegration::callDashboardApi', [
            'connection_id' => $connection->id,
            'path'          => $path,
            'cookie_hash'   => $cookieHash,
        ]);

        $response = Http::withHeaders($headers)
            ->withCookies($this->parseCookieString($cookieRaw), parse_url($baseUrl, PHP_URL_HOST) ?? '')
            ->timeout(20)
            ->get($url, $query);

        if ($response->status() === 401 || $response->status() === 403) {
            $this->markAuthFailure(
                $connection,
                "Lazada session expired (HTTP {$response->status()}). Vui lòng cập nhật cookie."
            );
            throw new RuntimeException(
                "Lazada cookie authentication failed (HTTP {$response->status()}). Please refresh your session."
            );
        }

        if (! $response->successful()) {
            throw new RuntimeException(
                "Lazada dashboard API returned HTTP {$response->status()} for {$path}. " .
                mb_substr((string) $response->body(), 0, 200)
            );
        }

        $json = $response->json();

        if (! is_array($json)) {
            throw new RuntimeException("Lazada dashboard returned non-JSON response for {$path}.");
        }

        // Check for API-level auth errors.
        // @placeholder — update error key names once real response is known.
        $code    = $json['code'] ?? $json['error_code'] ?? null;
        $message = $json['message'] ?? $json['msg'] ?? '';

        if (in_array($code, ['INVALID_SESSION', 'SESSION_EXPIRED', '401', 'UNAUTHORIZED'], true)) {
            $this->markAuthFailure($connection, "Lazada session invalid (code: {$code}). Please refresh cookie.");
            throw new RuntimeException("Lazada session is no longer valid. Please update your cookie.");
        }

        return $json;
    }

    // ─── Data Normalization ───────────────────────────────────────────────────

    /**
     * Normalize a raw Lazada conversion row to the Affentra Order schema.
     *
     * @placeholder — field names will be updated once a real dashboard response is available.
     *
     * @param  array<string, mixed>  $row
     * @return array<string, mixed>|null  Returns null if row is too incomplete to upsert.
     */
    private function normalizeConversionRow(array $row): ?array
    {
        // @placeholder: these key names are educated guesses from Lazada API patterns.
        // They MUST be verified against a real response payload.
        $externalOrderId = (string) ($row['order_id'] ?? $row['orderId'] ?? $row['id'] ?? '');

        if ($externalOrderId === '') {
            Log::warning('LazadaIntegration::normalizeConversionRow — skipped row with empty order ID', [
                'row_keys' => array_keys($row),
            ]);

            return null;
        }

        $rawStatus = strtolower((string) ($row['status'] ?? $row['order_status'] ?? $row['orderStatus'] ?? 'pending'));
        $orderStatus = $this->mapStatus($rawStatus);

        $orderedAt  = $this->parseTimestamp($row['order_time'] ?? $row['ordered_at'] ?? $row['create_time'] ?? null);
        $approvedAt = $this->parseTimestamp($row['approved_at'] ?? $row['approve_time'] ?? null);

        return [
            'platform'          => 'lazada',
            'external_order_id' => $externalOrderId,
            'status'            => $orderStatus->value,
            'order_amount'      => (float) ($row['order_amount'] ?? $row['sale_amount'] ?? $row['gmv'] ?? 0),
            'commission'        => (float) ($row['commission'] ?? $row['commission_amount'] ?? $row['earning'] ?? 0),
            'sub_id'            => (string) ($row['sub_id'] ?? $row['sub_id_1'] ?? $row['custom_tracking_id'] ?? ''),
            'product_name'      => (string) ($row['product_name'] ?? $row['item_name'] ?? ''),
            'product_id'        => (string) ($row['item_id'] ?? $row['product_id'] ?? $row['sku_id'] ?? ''),
            'ordered_at'        => $orderedAt,
            'approved_at'       => $approvedAt,
            'source'            => 'lazada_dashboard',
            'source_meta'       => [
                'raw_status'  => $rawStatus,
                'raw_payload' => $row,
            ],
        ];
    }

    /**
     * Normalize a raw click row.
     *
     * @placeholder — update field names once endpoint is confirmed.
     *
     * @param  array<string, mixed>  $row
     * @return array<string, mixed>
     */
    private function normalizeClickRow(array $row): array
    {
        return [
            'platform'   => 'lazada',
            'clicked_at' => $this->parseTimestamp($row['click_time'] ?? $row['clicked_at'] ?? null),
            'sub_id'     => (string) ($row['sub_id'] ?? $row['tracking_id'] ?? ''),
            'clicks'     => (int) ($row['clicks'] ?? $row['click_count'] ?? 1),
            'source_meta' => $row,
        ];
    }

    // ─── Status Mapping ───────────────────────────────────────────────────────

    private function mapStatus(string $rawStatus): OrderStatus
    {
        $mapped = self::STATUS_MAP[$rawStatus] ?? null;

        if ($mapped === null) {
            Log::warning('LazadaIntegration::mapStatus — unknown status, defaulting to Pending', [
                'raw_status' => $rawStatus,
            ]);

            return OrderStatus::Pending;
        }

        return $mapped;
    }

    // ─── Probe ────────────────────────────────────────────────────────────────

    /**
     * @param  array<string, mixed>  $query
     * @return array{ok: bool, status: int, code: int|string|null, message: string}
     */
    private function probeDashboardEndpoint(
        PlatformConnection $connection,
        string $path,
        array $query = [],
    ): array {
        try {
            $result = $this->callDashboardApi($connection, $path, $query);
            $code   = $result['code'] ?? $result['error_code'] ?? null;

            $isError = is_string($code) && in_array($code, ['INVALID_SESSION', 'SESSION_EXPIRED', 'UNAUTHORIZED'], true)
                || (is_int($code) && $code !== 0 && $code !== 200);

            return [
                'ok'      => ! $isError,
                'status'  => 200,
                'code'    => $code,
                'message' => $isError ? (string) ($result['message'] ?? $result['msg'] ?? 'API error') : 'OK',
            ];
        } catch (\Throwable $e) {
            $httpStatus = 0;
            if (preg_match('/HTTP (\d{3})/', $e->getMessage(), $m)) {
                $httpStatus = (int) $m[1];
            }

            return [
                'ok'      => false,
                'status'  => $httpStatus,
                'code'    => null,
                'message' => mb_substr($e->getMessage(), 0, 200),
            ];
        }
    }

    // ─── Cookie Helpers ───────────────────────────────────────────────────────

    private function extractCookieRaw(PlatformConnection $connection): string
    {
        $raw = trim((string) $connection->cookie_header);
        if ($raw === '') {
            throw new RuntimeException('Cookie credentials not found for Lazada connection.');
        }

        if (str_starts_with($raw, '{')) {
            $decoded = json_decode($raw, true);
            if (is_array($decoded) && isset($decoded['cookie']) && is_string($decoded['cookie'])) {
                return $decoded['cookie'];
            }
        }

        return $raw;
    }

    /**
     * @return array<string, string>
     */
    private function buildCookieHeaders(PlatformConnection $connection): array
    {
        $rawCookie = trim((string) $connection->cookie_header);

        $userAgent = $connection->cookie_user_agent
            ?? 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/130.0.0.0 Safari/537.36';

        // Base headers that Lazada Dashboard generally expects.
        $headers = [
            'Accept'          => 'application/json, text/plain, */*',
            'Accept-Language' => 'vi-VN,vi;q=0.9,en-US;q=0.8,en;q=0.7',
            'User-Agent'      => $userAgent,
            'Referer'         => $this->dashboardBaseUrl($connection) . '/',
            'Origin'          => $this->dashboardBaseUrl($connection),
            'sec-fetch-dest'  => 'empty',
            'sec-fetch-mode'  => 'cors',
            'sec-fetch-site'  => 'same-origin',
        ];

        // If cookie_header is JSON, extract additional headers captured from cURL.
        if (str_starts_with($rawCookie, '{')) {
            $parsed = json_decode($rawCookie, true);
            if (is_array($parsed)) {
                if (! empty($parsed['user_agent'])) {
                    $headers['User-Agent'] = $parsed['user_agent'];
                }
                if (! empty($parsed['csrf_token'])) {
                    $headers['x-csrf-token'] = $parsed['csrf_token'];
                }
                if (! empty($parsed['authorization'])) {
                    $headers['Authorization'] = $parsed['authorization'];
                }
                if (! empty($parsed['accept_language'])) {
                    $headers['Accept-Language'] = $parsed['accept_language'];
                }
            }
        }

        return $headers;
    }

    /**
     * Parse "key=value; key2=value2" cookie string into an associative array.
     *
     * @return array<string, string>
     */
    private function parseCookieString(string $cookieStr): array
    {
        $cookies = [];
        foreach (explode(';', $cookieStr) as $part) {
            $part = trim($part);
            if ($part === '') {
                continue;
            }
            $eqPos = strpos($part, '=');
            if ($eqPos === false) {
                $cookies[$part] = '';
                continue;
            }
            $cookies[substr($part, 0, $eqPos)] = substr($part, $eqPos + 1);
        }

        return $cookies;
    }

    // ─── Error Handling ───────────────────────────────────────────────────────

    private function markAuthFailure(PlatformConnection $connection, string $reason): void
    {
        $connection->update([
            'status'      => 'error',
            'last_error'  => mb_substr($reason, 0, 500),
            'last_error_at' => now(),
        ]);

        Log::error('LazadaIntegration::markAuthFailure', [
            'connection_id' => $connection->id,
            'reason'        => mb_substr($reason, 0, 500),
        ]);
    }

    // ─── Region / URL Helpers ─────────────────────────────────────────────────

    private function dashboardBaseUrl(PlatformConnection $connection): string
    {
        // Allow per-connection region override stored in meta/label, fallback to config.
        $region = $this->region;

        return self::DASHBOARD_BASES[$region] ?? self::DASHBOARD_BASES['VN'];
    }

    // ─── Timestamp Helper ─────────────────────────────────────────────────────

    private function parseTimestamp(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        try {
            if (is_numeric($value)) {
                // Unix timestamp (seconds or milliseconds).
                $ts = (int) $value;

                return ($ts > 1_000_000_000_000)
                    ? Carbon::createFromTimestampMs($ts)->toDateTimeString()
                    : Carbon::createFromTimestamp($ts)->toDateTimeString();
            }

            return Carbon::parse((string) $value)->toDateTimeString();
        } catch (\Throwable) {
            return null;
        }
    }
}
