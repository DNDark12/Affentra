<?php

declare(strict_types=1);

namespace App\Services\Offer;

use App\Contracts\Repositories\PlatformConnectionRepositoryInterface;
use App\Exceptions\OfferDomainException;
use App\Models\PlatformConnection;
use App\Models\User;
use App\Services\Integration\IntegrationFactory;
use App\Services\TrackingLink\TrackingLinkService;
use App\Support\ShopeeDomainValidator;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

class OfferService
{
    public function __construct(
        private readonly PlatformConnectionRepositoryInterface $platformConnectionRepository,
        private readonly TrackingLinkService $trackingLinkService,
    ) {}

    /**
     * @param  array<string, mixed>  $validated
     * @return array<string, mixed>
     */
    public function search(User $actor, array $validated): array
    {
        $connection = $this->resolveVisibleConnection(
            actor: $actor,
            connectionId: (int) $validated['connection_id'],
        );

        if (! IntegrationFactory::supports($connection->platform)) {
            throw new OfferDomainException('Platform not supported.', 'OFFER_CONNECTION_UNSUPPORTED');
        }

        $adapter = IntegrationFactory::make($connection->platform);
        if (! $adapter->capabilities()->supportsOfferDiscovery) {
            throw new OfferDomainException('This platform does not support offer discovery.', 'OFFER_CONNECTION_UNSUPPORTED');
        }

        $searchType = $validated['search_type'] ?? null;

        // Explicit detail mode: resolve URL/ID → return single product detail
        if ($searchType === 'detail') {
            return $this->searchByDetail($actor, $connection, $adapter, $validated);
        }

        // Explicit keyword mode: always do list search (no URL/ID auto-detect)
        if ($searchType === 'keyword') {
            return $this->searchByKeyword($connection, $adapter, $validated);
        }

        // null → legacy auto-detect (backward compat for existing callers)
        $filters = $this->buildSearchFilters($connection, $validated);

        $filterHash = md5(json_encode($filters, JSON_THROW_ON_ERROR));
        $cacheKey = "offers:{$connection->platform}:{$connection->method}:{$connection->id}:{$filterHash}";
        $cacheTtl = $connection->method === 'cookie'
            ? 10
            : (int) config("integrations.{$connection->platform}.offer_cache_ttl", 30);

        try {
            return Cache::remember($cacheKey, now()->addMinutes((int) $cacheTtl), function () use ($adapter, $connection, $filters): array {
                return $adapter->getOffers($connection, array_filter($filters, static fn (mixed $value): bool => $value !== null));
            });
        } catch (\RuntimeException $e) {
            $this->throwAdapterOfferException($e);
        }
    }

    /**
     * search_type=detail: resolve URL / item ID and return single-product detail.
     *
     * @param  array<string, mixed>  $validated
     * @return array<string, mixed>
     */
    private function searchByDetail(User $actor, PlatformConnection $connection, object $adapter, array $validated): array
    {
        $keyword = trim((string) ($validated['keyword'] ?? ''));
        $itemId = isset($validated['itemId']) ? (int) $validated['itemId'] : null;
        $shopId = isset($validated['shopId']) ? (int) $validated['shopId'] : null;

        // For Open-API connections the itemId query param already resolves directly
        if ($connection->method !== 'cookie' && $itemId !== null && $itemId > 0) {
            $cacheKey = sprintf(
                'offer_detail:%s:%s:%d:%s:%s',
                $connection->platform, $connection->method, $connection->id, $itemId, $shopId ?? '-'
            );
            $cacheTtl = (int) config("integrations.{$connection->platform}.offer_cache_ttl", 30);
            try {
                return Cache::remember($cacheKey, now()->addMinutes($cacheTtl), function () use ($adapter, $connection, $itemId, $shopId): array {
                    $raw = $adapter->getOfferDetail($connection, (string) $itemId, ['shopId' => $shopId]);
                    if ($raw === []) {
                        throw new NotFoundHttpException('Not found.');
                    }
                    return $this->normalizeOfferDetailPayload($raw);
                });
            } catch (\RuntimeException $e) {
                $this->throwAdapterOfferException($e);
            }
        }

        // Cookie connections: resolve keyword/URL → itemId
        if ($keyword === '' && ($itemId === null || $itemId <= 0)) {
            throw new OfferDomainException(
                'Vui lòng nhập Item ID hoặc URL sản phẩm để tra cứu chi tiết.',
                'OFFER_DETAIL_INPUT_REQUIRED'
            );
        }

        if ($keyword !== '' && ($itemId === null || $itemId <= 0)) {
            $resolved = $this->resolveCookieLookupFromInput($keyword);
            $itemId = $resolved['itemId'];
            $shopId = $resolved['shopId'] ?? $shopId;
        }

        if ($itemId === null || $itemId <= 0) {
            throw new OfferDomainException(
                'Không thể xác định Item ID từ đầu vào. Vui lòng nhập Item ID số hoặc URL Shopee hợp lệ.',
                'OFFER_ID_INVALID'
            );
        }

        $cacheKey = sprintf(
            'offer_detail:%s:%s:%d:%s:%s',
            $connection->platform, $connection->method, $connection->id, $itemId, $shopId ?? '-'
        );
        $cacheTtl = $connection->method === 'cookie'
            ? 10
            : (int) config("integrations.{$connection->platform}.offer_cache_ttl", 30);

        try {
            return Cache::remember($cacheKey, now()->addMinutes((int) $cacheTtl), function () use ($adapter, $connection, $itemId, $shopId): array {
                $raw = $adapter->getOfferDetail($connection, (string) $itemId, ['shopId' => (string) $shopId]);
                if ($raw === []) {
                    throw new NotFoundHttpException('Not found.');
                }
                return $this->normalizeOfferDetailPayload($raw);
            });
        } catch (\RuntimeException $e) {
            $this->throwAdapterOfferException($e);
        }
    }

    /**
     * search_type=keyword: always do a keyword list search regardless of input format.
     *
     * @param  array<string, mixed>  $validated
     * @return array<string, mixed>
     */
    private function searchByKeyword(PlatformConnection $connection, object $adapter, array $validated): array
    {
        $keyword = trim((string) ($validated['keyword'] ?? ''));
        if ($keyword === '') {
            throw new OfferDomainException(
                'Vui lòng nhập từ khoá để tìm kiếm sản phẩm.',
                'OFFER_KEYWORD_REQUIRED'
            );
        }

        $filters = [
            'keyword'      => $keyword,
            'listType'     => $validated['listType'] ?? null,
            'sortType'     => $validated['sortType'] ?? null,
            'page'         => $validated['page'] ?? 1,
            'limit'        => $validated['limit'] ?? 20,
            'productCatId' => $validated['productCatId'] ?? null,
        ];

        $filterHash = md5(json_encode($filters, JSON_THROW_ON_ERROR));
        $cacheKey = "offers:{$connection->platform}:{$connection->method}:{$connection->id}:kw:{$filterHash}";
        $cacheTtl = $connection->method === 'cookie'
            ? 10
            : (int) config("integrations.{$connection->platform}.offer_cache_ttl", 30);

        try {
            return Cache::remember($cacheKey, now()->addMinutes((int) $cacheTtl), function () use ($adapter, $connection, $filters): array {
                return $adapter->getOffers($connection, array_filter($filters, static fn (mixed $value): bool => $value !== null));
            });
        } catch (\RuntimeException $e) {
            $this->throwAdapterOfferException($e);
        }
    }

    /**
     * @param  array<string, mixed>  $validated
     * @return array<string, mixed>
     */
    public function getLink(User $actor, array $validated): array
    {
        $connection = $this->resolveVisibleConnection(
            actor: $actor,
            connectionId: (int) $validated['connection_id'],
        );

        if (! IntegrationFactory::supports($connection->platform)) {
            throw new OfferDomainException('Platform not supported.', 'OFFER_CONNECTION_UNSUPPORTED');
        }

        if ($connection->platform === 'shopee') {
            ShopeeDomainValidator::assertAllowedUrl((string) $validated['offer_link']);
        }

        $adapter = IntegrationFactory::make($connection->platform);
        $subId = 'offer_' . Str::random(8);

        $shortLink = null;
        if ($adapter->capabilities()->supportsShortLink) {
            try {
                $shortLink = $adapter->generateShortLink($connection, (string) $validated['offer_link'], $subId);
            } catch (\RuntimeException $e) {
                Log::warning('ShortLink generation failed, using local fallback', [
                    'connection_id' => $connection->id,
                    'error'         => $e->getMessage(),
                ]);
            }
        }

        $fallbackUrl = $validated['offer_link'];
        if ($connection->platform === 'shopee') {
            $itemId = $validated['item_id'];
            $shopId = isset($validated['shop_id']) && $validated['shop_id'] !== null ? $validated['shop_id'] : '-';
            $fallbackUrl = "https://shopee.vn/product/{$shopId}/{$itemId}";
        }

        $trackingLink = $this->trackingLinkService->create($actor, [
            'campaign_id'     => $validated['campaign_id'] ?? null,
            'platform_connection_id' => $connection->id,
            'destination_url' => $shortLink ?? $fallbackUrl,
            'sub_id'          => $subId,
            'platform'        => $connection->platform,
            'source'          => 'offer',
            'product_name'    => $validated['product_name'] ?? null,
            'product_image_urls' => isset($validated['image_url']) ? [$validated['image_url']] : null,
            'meta'            => [
                'offer_item_id'            => $validated['item_id'],
                'offer_shop_id'            => $validated['shop_id'] ?? null,
                'offer_product_name'       => $validated['product_name'],
                'commission_rate_snapshot' => $validated['commission_rate'] ?? null,
                'image_url'                => $validated['image_url'] ?? null,
                'platform_short_link'      => $shortLink,
            ],
        ]);

        return [
            'id'              => $trackingLink->id,
            'short_code'      => $trackingLink->short_code,
            'destination_url' => $trackingLink->destination_url,
            'track_url'       => route('redirect', $trackingLink->short_code),
            'sub_id'          => $trackingLink->sub_id,
            'reused_existing' => ! ((bool) ($trackingLink->wasRecentlyCreated ?? false)),
        ];
    }

    /**
     * Fetch product category tree for use as offer discovery filters.
     * Cache key: offer_categories:{platform}:{connection_id}
     * TTL: from config('integrations.{platform}.offer_cache_ttl')
     *
     * @return list<array{catId: int, catName: string, children?: list<mixed>}>
     */
    public function getCategories(User $actor, int $connectionId): array
    {
        $connection = $this->resolveVisibleConnection(actor: $actor, connectionId: $connectionId);

        if (!IntegrationFactory::supports($connection->platform)) {
            return [];
        }

        $adapter = IntegrationFactory::make($connection->platform);
        if (!$adapter->capabilities()->supportsOfferDiscovery) {
            return [];
        }

        $cacheKey = "offer_categories:{$connection->platform}:{$connection->id}";
        $cacheTtl = config("integrations.{$connection->platform}.offer_cache_ttl", 30);

        return Cache::remember(
            $cacheKey,
            now()->addMinutes((int) $cacheTtl),
            fn () => $adapter->getCategories($connection),
        );
    }

    /**
     * @param  array<string, mixed>  $validated
     * @return array<string, mixed>
     */
    public function getDetail(User $actor, array $validated): array
    {
        $connection = $this->resolveVisibleConnection(
            actor: $actor,
            connectionId: (int) $validated['connection_id'],
        );

        if (! IntegrationFactory::supports($connection->platform)) {
            throw new OfferDomainException('Platform not supported.', 'OFFER_CONNECTION_UNSUPPORTED');
        }

        $adapter = IntegrationFactory::make($connection->platform);
        if (! $adapter->capabilities()->supportsOfferDiscovery) {
            throw new OfferDomainException('This platform does not support offer detail.', 'OFFER_CONNECTION_UNSUPPORTED');
        }

        $offerId = trim((string) ($validated['offer_id'] ?? ''));
        if ($offerId === '') {
            throw new OfferDomainException('Offer ID is required.', 'OFFER_ID_INVALID');
        }

        if ($connection->method === 'cookie' && ! ctype_digit($offerId)) {
            throw new OfferDomainException('Offer ID phải là item_id số khi dùng kết nối cookie.', 'OFFER_ID_INVALID');
        }

        $shopId = isset($validated['shop_id']) && $validated['shop_id'] !== null
            ? trim((string) $validated['shop_id'])
            : null;
        if ($shopId === '') {
            $shopId = null;
        }

        $cacheKey = sprintf(
            'offer_detail:%s:%s:%d:%s:%s',
            $connection->platform,
            $connection->method,
            $connection->id,
            $offerId,
            $shopId ?? '-',
        );
        $cacheTtl = $connection->method === 'cookie'
            ? 10
            : (int) config("integrations.{$connection->platform}.offer_cache_ttl", 30);

        try {
            return Cache::remember(
                $cacheKey,
                now()->addMinutes((int) $cacheTtl),
                function () use ($adapter, $connection, $offerId, $shopId): array {
                    $raw = $adapter->getOfferDetail($connection, $offerId, [
                        'shopId' => $shopId,
                    ]);

                    if ($raw === []) {
                        throw new NotFoundHttpException('Not found.');
                    }

                    return $this->normalizeOfferDetailPayload($raw);
                },
            );
        } catch (\RuntimeException $e) {
            $this->throwAdapterOfferException($e);
        }
    }

    /**
     * @param  array<string, mixed>  $raw
     * @return array<string, mixed>
     */
    private function normalizeOfferDetailPayload(array $raw): array
    {
        $itemId = $this->normalizeId($raw['itemId'] ?? $raw['item_id'] ?? null) ?? '';
        $shopId = $this->normalizeId($raw['shopId'] ?? $raw['shop_id'] ?? null);
        $priceMin = $this->normalizeMoneyValue($raw['priceMin'] ?? $raw['price_min'] ?? $raw['price'] ?? null);
        $priceMax = $this->normalizeMoneyValue($raw['priceMax'] ?? $raw['price_max'] ?? $raw['price'] ?? null);
        $commissionRate = $this->normalizeCommissionRate(
            $raw['commissionRate']
            ?? $raw['commission_rate']
            ?? $raw['platform_commission_rate']
            ?? 0
        );
        $offerLink = (string) ($raw['offerLink'] ?? '');
        $productLink = (string) ($raw['productLink'] ?? '');
        if ($offerLink === '' && $itemId !== '') {
            $offerLink = "https://affiliate.shopee.vn/offer/product_offer/{$itemId}";
        }
        if ($productLink === '' && $itemId !== '' && $shopId !== null) {
            $productLink = "https://shopee.vn/product/{$shopId}/{$itemId}";
        }
        $itemUrl = $offerLink !== '' ? $offerLink : $productLink;

        return [
            'item_id' => $itemId,
            'shop_id' => $shopId,
            'item_name' => (string) ($raw['productName'] ?? $raw['product_name'] ?? $raw['item_name'] ?? 'Sản phẩm'),
            'item_url' => $itemUrl,
            'offer_link' => $offerLink !== '' ? $offerLink : null,
            'product_link' => $productLink !== '' ? $productLink : null,
            'image_url' => $raw['imageUrl'] ?? $raw['image_url'] ?? null,
            'price' => $priceMin > 0 ? $priceMin : $priceMax,
            'price_min' => $priceMin,
            'price_max' => $priceMax,
            'price_discount_rate' => isset($raw['priceDiscountRate']) ? (float) $raw['priceDiscountRate'] : (isset($raw['price_discount_rate']) ? (float) $raw['price_discount_rate'] : null),
            'commission_rate' => $commissionRate,
            'estimated_commission' => round(($priceMin > 0 ? $priceMin : $priceMax) * $commissionRate / 100, 2),
            'seller_commission_rate' => isset($raw['sellerCommissionRate']) ? $this->normalizeCommissionRate($raw['sellerCommissionRate']) : (isset($raw['seller_commission_rate']) ? $this->normalizeCommissionRate($raw['seller_commission_rate']) : null),
            'shopee_commission_rate' => isset($raw['shopeeCommissionRate']) ? $this->normalizeCommissionRate($raw['shopeeCommissionRate']) : (isset($raw['shopee_commission_rate']) ? $this->normalizeCommissionRate($raw['shopee_commission_rate']) : null),
            'sales' => isset($raw['sales']) ? (int) $raw['sales'] : (isset($raw['sold_count']) ? (int) $raw['sold_count'] : null),
            'rating_star' => isset($raw['ratingStar']) ? (float) $raw['ratingStar'] : (isset($raw['rating_star']) ? (float) $raw['rating_star'] : null),
            'shop_name' => $raw['shopName'] ?? $raw['shop_name'] ?? null,
            'shop_type' => $raw['shopType'] ?? $raw['shop_type'] ?? null,
            'period_start_at' => $this->normalizeUnixTimestamp($raw['periodStartTime'] ?? $raw['period_start_time'] ?? null),
            'period_end_at' => $this->normalizeUnixTimestamp($raw['periodEndTime'] ?? $raw['period_end_time'] ?? null),
            'commission_tiers' => array_values(array_filter([
                isset($raw['commissionRate']) || isset($raw['commission_rate']) || isset($raw['platform_commission_rate']) ? [
                    'name' => 'Total',
                    'rate' => $commissionRate,
                ] : null,
                isset($raw['sellerCommissionRate']) || isset($raw['seller_commission_rate']) ? [
                    'name' => 'Seller',
                    'rate' => isset($raw['sellerCommissionRate']) ? $this->normalizeCommissionRate($raw['sellerCommissionRate']) : $this->normalizeCommissionRate($raw['seller_commission_rate']),
                ] : null,
                isset($raw['shopeeCommissionRate']) || isset($raw['shopee_commission_rate']) ? [
                    'name' => 'Shopee',
                    'rate' => isset($raw['shopeeCommissionRate']) ? $this->normalizeCommissionRate($raw['shopeeCommissionRate']) : $this->normalizeCommissionRate($raw['shopee_commission_rate']),
                ] : null,
            ])),
            'commission_unavailable' => (bool) ($raw['_fallback'] ?? false),
            'raw' => $raw,
        ];
    }

    /**
     * @param  array<string, mixed>  $validated
     * @return array<string, mixed>
     */
    private function buildSearchFilters(PlatformConnection $connection, array $validated): array
    {
        if ($connection->method !== 'cookie') {
            return [
                'keyword'      => $validated['keyword'] ?? null,
                'listType'     => $validated['listType'] ?? null,
                'sortType'     => $validated['sortType'] ?? null,
                'page'         => $validated['page'] ?? null,
                'limit'        => $validated['limit'] ?? null,
                'shopId'       => $validated['shopId'] ?? null,
                'itemId'       => $validated['itemId'] ?? null,
                'productCatId' => $validated['productCatId'] ?? null,
            ];
        }

        $itemId = isset($validated['itemId']) ? (int) $validated['itemId'] : null;
        $shopId = isset($validated['shopId']) ? (int) $validated['shopId'] : null;
        $keyword = trim((string) ($validated['keyword'] ?? ''));

        if (($itemId === null || $itemId <= 0) && $keyword !== '') {
            $resolved = $this->resolveCookieLookupFromInput($keyword);
            $itemId = $resolved['itemId'];
            $shopId = $resolved['shopId'] ?? $shopId;
        }

        if ($itemId === null || $itemId <= 0) {
            throw new OfferDomainException(
                'Cookie mode chỉ hỗ trợ tra cứu theo Item ID hoặc URL Shopee hợp lệ.',
                'OFFER_COOKIE_ITEMID_REQUIRED'
            );
        }

        return array_filter([
            'itemId' => $itemId,
            'shopId' => $shopId !== null && $shopId > 0 ? $shopId : null,
            'page' => 1,
            'limit' => 1,
        ], static fn (mixed $value): bool => $value !== null);
    }

    /**
     * @return array{itemId: int|null, shopId: int|null}
     */
    private function resolveCookieLookupFromInput(string $input): array
    {
        $trimmed = trim($input);
        if ($trimmed === '') {
            return ['itemId' => null, 'shopId' => null];
        }

        if (ctype_digit($trimmed)) {
            return ['itemId' => (int) $trimmed, 'shopId' => null];
        }

        $candidateUrl = $trimmed;
        if (! str_contains($candidateUrl, '://') && str_contains($candidateUrl, '.')) {
            $candidateUrl = 'https://' . ltrim($candidateUrl, '/');
        }

        $host = ShopeeDomainValidator::extractHost($candidateUrl);
        if ($host === null || ! ShopeeDomainValidator::isAllowedHost($host)) {
            throw new OfferDomainException(
                'Cookie mode chỉ hỗ trợ tra cứu theo Item ID hoặc URL Shopee hợp lệ.',
                'OFFER_COOKIE_ITEMID_REQUIRED'
            );
        }

        $path = (string) (parse_url($candidateUrl, PHP_URL_PATH) ?? '');
        $queryString = (string) (parse_url($candidateUrl, PHP_URL_QUERY) ?? '');
        parse_str($queryString, $query);

        $itemId = null;
        $shopId = null;

        if (preg_match('#/offer/product_offer/(\d+)#', $path, $matches) === 1) {
            $itemId = (int) $matches[1];
        } elseif (preg_match('#/offer/product/(\d+)#', $path, $matches) === 1) {
            $itemId = (int) $matches[1];
        } elseif (preg_match('#-i\.(\d+)\.(\d+)#', $path, $matches) === 1) {
            $shopId = (int) $matches[1];
            $itemId = (int) $matches[2];
        } elseif (preg_match('#/product/(\d+)/(\d+)#', $path, $matches) === 1) {
            $shopId = (int) $matches[1];
            $itemId = (int) $matches[2];
        }

        if ($itemId === null) {
            $rawItemId = $query['itemid'] ?? $query['item_id'] ?? $query['itemId'] ?? null;
            if (is_scalar($rawItemId)) {
                $raw = trim((string) $rawItemId);
                if ($raw !== '' && ctype_digit($raw)) {
                    $itemId = (int) $raw;
                }
            }
        }

        if ($shopId === null) {
            $rawShopId = $query['shopid'] ?? $query['shop_id'] ?? $query['shopId'] ?? null;
            if (is_scalar($rawShopId)) {
                $raw = trim((string) $rawShopId);
                if ($raw !== '' && ctype_digit($raw)) {
                    $shopId = (int) $raw;
                }
            }
        }

        if ($itemId === null && preg_match('#/(\d{6,})$#', $path, $matches) === 1) {
            $itemId = (int) $matches[1];
        }

        return [
            'itemId' => $itemId,
            'shopId' => $shopId,
        ];
    }

    private function normalizeUnixTimestamp(mixed $value): ?string
    {
        if (! is_numeric($value)) {
            return null;
        }

        $timestamp = (int) $value;
        if ($timestamp <= 0) {
            return null;
        }

        return Carbon::createFromTimestamp($timestamp)->toIso8601String();
    }

    private function normalizeMoneyValue(mixed $value): float
    {
        if ($value === null || $value === '') {
            return 0.0;
        }

        $amount = is_numeric($value) ? (float) $value : (float) preg_replace('/[^\d.\-]/', '', (string) $value);
        if ($amount === 0.0) {
            return 0.0;
        }

        if ($amount >= 1_000_000) {
            return round($amount / 100_000, 2);
        }

        return round($amount, 2);
    }

    private function normalizeCommissionRate(mixed $value): float
    {
        if ($value === null || $value === '') {
            return 0.0;
        }

        $rate = is_numeric($value) ? (float) $value : (float) preg_replace('/[^\d.\-]/', '', (string) $value);
        if ($rate <= 0.0) {
            return 0.0;
        }

        if ($rate > 100.0) {
            $rate = $rate / 1000;
        }

        return round($rate, 4);
    }

    private function normalizeId(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        $normalized = trim((string) $value);
        return $normalized !== '' ? $normalized : null;
    }

    private function throwAdapterOfferException(\RuntimeException $exception): never
    {
        $message = $exception->getMessage();
        if (str_contains($message, '90309999')) {
            throw new OfferDomainException(
                'Shopee anti-bot challenge (code 90309999). Hãy cập nhật cURL profile offer_product và thử lại.',
                'SHOPEE_ANTIBOT_90309999'
            );
        }

        throw new \DomainException($message);
    }

    private function resolveVisibleConnection(User $actor, int $connectionId): PlatformConnection
    {
        $connection = $this->platformConnectionRepository->findVisibleById($connectionId, $actor);

        if ($connection === null) {
            throw new NotFoundHttpException('Not found.');
        }

        return $connection;
    }
}
