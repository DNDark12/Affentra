<?php

declare(strict_types=1);

namespace App\Services\Integration\TikTok;

use App\DataTransferObjects\CapabilitySet;
use App\Models\PlatformConnection;
use App\Services\Integration\BaseIntegration;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * TikTok Shop Affiliate integration adapter.
 *
 * Uses HMAC-SHA256 signed Open API requests.
 * Global app credentials live in config('integrations.tiktok').
 * Per-connection data: access_token, refresh_token, shop_cipher, shop_id.
 */
class TikTokIntegration extends BaseIntegration
{
    private readonly string $appKey;
    private readonly string $appSecret;
    private readonly string $baseUrl;

    public function __construct()
    {
        $this->appKey    = (string) config('integrations.tiktok.app_key');
        $this->appSecret = (string) config('integrations.tiktok.app_secret');
        $this->baseUrl   = rtrim((string) config('integrations.tiktok.base_url'), '/');
    }

    // ─── Capabilities ────────────────────────────────────────────────────────

    public function capabilities(): CapabilitySet
    {
        return new CapabilitySet(
            supportsAutoSync: true,
            supportsOfferDiscovery: false,  // Phase 2
            supportsShortLink: true,
            supportsSubId: false,
        );
    }

    // ─── Connection Test ─────────────────────────────────────────────────────

    public function testConnection(PlatformConnection $connection): bool
    {
        // shop_cipher is required for all business API calls
        if (blank($connection->shop_cipher)) {
            Log::warning('TikTok testConnection: shop_cipher is missing', [
                'connection_id' => $connection->id,
            ]);
            return false;
        }

        $token = $this->getValidToken($connection);
        $version = config('integrations.tiktok.api_versions.authorized_shop', '202309');
        $path = "/authorization/{$version}/shops";

        $response = $this->signedGet($path, [], $token);

        $shops = $response['data']['shops'] ?? [];

        return collect($shops)->contains(fn (array $shop) =>
            (string) ($shop['id'] ?? '') === $connection->shop_id
        );
    }

    // ─── Fetch Report (Order Sync) ───────────────────────────────────────────

    public function fetchReport(PlatformConnection $connection, Carbon $since, Carbon $until): array
    {
        $token   = $this->getValidToken($connection);
        $version = config('integrations.tiktok.api_versions.orders_search', '202309');
        $path    = "/affiliate_seller/{$version}/orders/search";

        $orders  = [];
        $cursor  = null;
        $maxPages = 50;
        $page     = 0;

        do {
            $queryParams = [
                'shop_cipher'    => $connection->shop_cipher,
                'page_size'      => 50,
                'create_time_ge' => $since->getTimestamp(),
                'create_time_lt' => $until->getTimestamp(),
            ];

            if ($cursor !== null) {
                $queryParams['cursor'] = $cursor;
            }

            $response = $this->signedGet($path, $queryParams, $token);

            $data = $response['data'] ?? [];
            $rows = $data['orders'] ?? [];

            foreach ($rows as $row) {
                $orders[] = $this->normalizeOrder($row);
            }

            $cursor = $data['next_cursor'] ?? null;
            $page++;
        } while (filled($cursor) && $page < $maxPages);

        return $orders;
    }

    // ─── Generate Short Link ─────────────────────────────────────────────────

    public function generateShortLink(PlatformConnection $connection, string $originalUrl, array $context = []): ?string
    {
        $productId = $context['product_id'] ?? $this->extractProductId($originalUrl);

        if (blank($productId)) {
            Log::warning('TikTok: cannot extract product_id for short link', [
                'connection_id' => $connection->id,
                'url'           => $originalUrl,
            ]);
            return null;
        }

        $token   = $this->getValidToken($connection);
        $version = config('integrations.tiktok.api_versions.promotion_link', '202405');
        $path    = "/affiliate_seller/{$version}/products/{$productId}/promotion_link/generate";

        $queryParams = [
            'shop_cipher' => $connection->shop_cipher,
        ];

        $response = $this->signedPost($path, $queryParams, [], $token);

        return $response['data']['promotion_link'] ?? null;
    }

    // ─── HMAC-SHA256 Signed Requests ─────────────────────────────────────────

    /**
     * @param  array<string, mixed>  $queryParams
     * @return array<string, mixed>
     */
    private function signedGet(string $path, array $queryParams, string $accessToken): array
    {
        $timestamp = time();
        $sign = $this->buildSign($path, $queryParams, $timestamp);

        $response = Http::withHeaders([
            'x-tts-access-token' => $accessToken,
            'Content-Type'       => 'application/json',
        ])->get("{$this->baseUrl}{$path}", array_merge($queryParams, [
            'app_key'   => $this->appKey,
            'timestamp' => $timestamp,
            'sign'      => $sign,
        ]));

        if (! $response->successful()) {
            throw new RuntimeException("TikTok API error [{$response->status()}]: {$response->body()}");
        }

        $body = $response->json();

        if (($body['code'] ?? 0) !== 0) {
            throw new RuntimeException("TikTok API business error: " . ($body['message'] ?? 'Unknown'));
        }

        return $body;
    }

    /**
     * @param  array<string, mixed>  $queryParams
     * @param  array<string, mixed>  $body
     * @return array<string, mixed>
     */
    private function signedPost(string $path, array $queryParams, array $body, string $accessToken): array
    {
        $timestamp = time();
        $sign = $this->buildSign($path, $queryParams, $timestamp, $body);

        $response = Http::withHeaders([
            'x-tts-access-token' => $accessToken,
            'Content-Type'       => 'application/json',
        ])->post("{$this->baseUrl}{$path}?" . http_build_query(array_merge($queryParams, [
            'app_key'   => $this->appKey,
            'timestamp' => $timestamp,
            'sign'      => $sign,
        ])), $body);

        if (! $response->successful()) {
            throw new RuntimeException("TikTok API error [{$response->status()}]: {$response->body()}");
        }

        $data = $response->json();

        if (($data['code'] ?? 0) !== 0) {
            throw new RuntimeException("TikTok API business error: " . ($data['message'] ?? 'Unknown'));
        }

        return $data;
    }

    /**
     * HMAC-SHA256 sign per TikTok Open API spec.
     *
     * sign = HMAC-SHA256(app_secret, app_secret + path + sorted_params + body + app_secret)
     *
     * @param  array<string, mixed>  $queryParams  (excluding app_key, sign, access_token)
     * @param  array<string, mixed>  $body
     */
    private function buildSign(string $path, array $queryParams, int $timestamp, array $body = []): string
    {
        // Merge query params that participate in signing
        $signParams = $queryParams;
        $signParams['app_key']   = $this->appKey;
        $signParams['timestamp'] = $timestamp;

        ksort($signParams);

        $paramString = '';
        foreach ($signParams as $key => $value) {
            $paramString .= $key . (string) $value;
        }

        $bodyString = ! empty($body) ? json_encode($body, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) : '';

        $signInput = $this->appSecret . $path . $paramString . $bodyString . $this->appSecret;

        return hash_hmac('sha256', $signInput, $this->appSecret);
    }

    // ─── Token Management ────────────────────────────────────────────────────

    /**
     * Get a valid access token, refreshing if expired.
     * Uses cache mutex to prevent concurrent refreshes.
     */
    private function getValidToken(PlatformConnection $connection): string
    {
        if (! $connection->isTokenExpired()) {
            return $connection->access_token;
        }

        if (blank($connection->refresh_token)) {
            throw new RuntimeException('TikTok token expired and no refresh_token available. Re-authorize required.');
        }

        // Mutex lock to prevent concurrent refreshes
        $lockKey = "tiktok_refresh_{$connection->id}";

        return Cache::lock($lockKey, 30)->block(10, function () use ($connection): string {
            // Double-check after lock acquisition
            $connection->refresh();
            if (! $connection->isTokenExpired()) {
                return $connection->access_token;
            }

            $response = Http::post((string) config('integrations.tiktok.refresh_url'), [
                'app_key'       => $this->appKey,
                'app_secret'    => $this->appSecret,
                'refresh_token' => $connection->refresh_token,
                'grant_type'    => 'refresh_token',
            ]);

            if (! $response->successful()) {
                throw new RuntimeException("TikTok token refresh failed [{$response->status()}]");
            }

            $data = $response->json('data') ?? $response->json();

            if (empty($data['access_token'])) {
                throw new RuntimeException('TikTok token refresh returned no access_token.');
            }

            $connection->update([
                'access_token'       => $data['access_token'],
                'refresh_token'      => $data['refresh_token'] ?? $connection->refresh_token,
                'token_expires_at'   => now()->addSeconds($data['access_token_expire_in'] ?? 0),
                'token_refreshed_at' => now(),
            ]);

            return $data['access_token'];
        });
    }

    // ─── Helpers ──────────────────────────────────────────────────────────────

    /**
     * Extract TikTok product_id from a URL.
     * Supports patterns like: /product/12345 or product_id=12345
     */
    private function extractProductId(string $url): ?string
    {
        // Pattern: /product/12345 or /products/12345
        if (preg_match('#/products?/(\d+)#', $url, $matches) === 1) {
            return $matches[1];
        }

        // Query param fallback
        $parsed = parse_url($url, PHP_URL_QUERY);
        if ($parsed !== null && $parsed !== false) {
            parse_str($parsed, $query);
            if (! empty($query['product_id'])) {
                return (string) $query['product_id'];
            }
        }

        // Pure numeric ID
        if (ctype_digit(trim($url))) {
            return trim($url);
        }

        return null;
    }

    /**
     * Normalize a TikTok order row to match OrderService::upsertFromApiSync schema.
     *
     * Required keys: order_code, platform, status, order_amount, commission,
     *                product_id, product_name, product_quantity, source_meta, ordered_at
     *
     * @param  array<string, mixed>  $row
     * @return array<string, mixed>
     */
    private function normalizeOrder(array $row): array
    {
        $itemPrice = (float) ($row['item_price'] ?? 0);
        $quantity  = (int) ($row['quantity'] ?? 1);

        return [
            'platform'           => 'tiktok',
            'order_code'         => (string) ($row['order_id'] ?? ''),
            'external_order_id'  => (string) ($row['order_id'] ?? ''),
            'product_id'         => (string) ($row['product_id'] ?? ''),
            'product_name'       => (string) ($row['product_name'] ?? ''),
            'order_amount'       => $itemPrice * $quantity,
            'listed_amount'      => $itemPrice * $quantity,
            'product_quantity'   => $quantity,
            'commission'         => (float) ($row['commission_amount'] ?? 0),
            'commission_platform' => 0,
            'commission_brand'   => 0,
            'commission_other'   => 0,
            'status'             => $this->mapOrderStatus($row['order_status'] ?? ''),
            'ordered_at'         => isset($row['create_time'])
                ? Carbon::createFromTimestamp($row['create_time'])->toIso8601String()
                : null,
            'approved_at'        => $this->mapOrderStatus($row['order_status'] ?? '') === 'approved'
                ? (isset($row['update_time'])
                    ? Carbon::createFromTimestamp($row['update_time'])->toIso8601String()
                    : null)
                : null,
            'source'             => 'api',
            'source_meta'        => [
                'commission_rate'   => (float) ($row['commission_rate'] ?? 0),
                'item_price'        => $itemPrice,
                'tiktok_status_raw' => $row['order_status'] ?? null,
            ],
        ];
    }

    /**
     * Map TikTok status to OrderStatus enum values: pending, approved, rejected.
     */
    private function mapOrderStatus(string $status): string
    {
        return match (strtolower($status)) {
            'paid', 'completed'           => 'approved',
            'cancelled', 'refunded'       => 'rejected',
            'awaiting_shipment', 'shipped' => 'pending',
            default                        => 'pending',
        };
    }
}
