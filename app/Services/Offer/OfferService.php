<?php

declare(strict_types=1);

namespace App\Services\Offer;

use App\Contracts\Repositories\PlatformConnectionRepositoryInterface;
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

        if (!IntegrationFactory::supports($connection->platform)) {
            throw new \DomainException('Platform not supported.');
        }

        $adapter = IntegrationFactory::make($connection->platform);
        if (!$adapter->capabilities()->supportsOfferDiscovery) {
            throw new \DomainException('This platform does not support offer discovery.');
        }

        $filters = [
            'keyword'      => $validated['keyword'] ?? null,
            'listType'     => $validated['listType'] ?? null,
            'sortType'     => $validated['sortType'] ?? null,
            'page'         => $validated['page'] ?? null,
            'limit'        => $validated['limit'] ?? null,
            'shopId'       => $validated['shopId'] ?? null,
            'itemId'       => $validated['itemId'] ?? null,
            'productCatId' => $validated['productCatId'] ?? null,
        ];

        $filterHash = md5(json_encode($filters, JSON_THROW_ON_ERROR));
        $cacheKey = "offers:{$connection->platform}:{$connection->id}:{$filterHash}";
        $cacheTtl = config("integrations.{$connection->platform}.offer_cache_ttl", 30);

        return Cache::remember($cacheKey, now()->addMinutes((int) $cacheTtl), function () use ($adapter, $connection, $filters): array {
            return $adapter->getOffers($connection, array_filter($filters, static fn (mixed $value): bool => $value !== null));
        });
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
            throw new \DomainException('Platform not supported.');
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

        $trackingLink = $this->trackingLinkService->create($actor, [
            'campaign_id'     => $validated['campaign_id'] ?? null,
            'destination_url' => $shortLink ?? $validated['offer_link'],
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
            throw new \DomainException('Platform not supported.');
        }

        $adapter = IntegrationFactory::make($connection->platform);
        if (! $adapter->capabilities()->supportsOfferDiscovery) {
            throw new \DomainException('This platform does not support offer detail.');
        }

        if (in_array($connection->method, ['cookie', 'portal_export'], true)) {
            throw new \DomainException('Offer detail is only available for Open API connections.');
        }

        $offerId = trim((string) ($validated['offer_id'] ?? ''));
        if ($offerId === '') {
            throw new \InvalidArgumentException('Offer ID is required.');
        }

        $shopId = isset($validated['shop_id']) && $validated['shop_id'] !== null
            ? trim((string) $validated['shop_id'])
            : null;
        if ($shopId === '') {
            $shopId = null;
        }

        $cacheKey = sprintf(
            'offer_detail:%s:%d:%s:%s',
            $connection->platform,
            $connection->id,
            $offerId,
            $shopId ?? '-',
        );
        $cacheTtl = config("integrations.{$connection->platform}.offer_cache_ttl", 30);

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
    }

    /**
     * @param  array<string, mixed>  $raw
     * @return array<string, mixed>
     */
    private function normalizeOfferDetailPayload(array $raw): array
    {
        $priceMin = (float) ($raw['priceMin'] ?? 0);
        $priceMax = (float) ($raw['priceMax'] ?? 0);
        $commissionRate = (float) ($raw['commissionRate'] ?? 0);
        $offerLink = (string) ($raw['offerLink'] ?? '');
        $productLink = (string) ($raw['productLink'] ?? '');
        $itemUrl = $offerLink !== '' ? $offerLink : $productLink;

        return [
            'item_id' => (string) ($raw['itemId'] ?? ''),
            'shop_id' => isset($raw['shopId']) ? (string) $raw['shopId'] : null,
            'item_name' => (string) ($raw['productName'] ?? 'Sản phẩm'),
            'item_url' => $itemUrl,
            'offer_link' => $offerLink !== '' ? $offerLink : null,
            'product_link' => $productLink !== '' ? $productLink : null,
            'image_url' => $raw['imageUrl'] ?? null,
            'price' => $priceMin > 0 ? $priceMin : $priceMax,
            'price_min' => $priceMin,
            'price_max' => $priceMax,
            'price_discount_rate' => isset($raw['priceDiscountRate']) ? (float) $raw['priceDiscountRate'] : null,
            'commission_rate' => $commissionRate,
            'estimated_commission' => round(($priceMin > 0 ? $priceMin : $priceMax) * $commissionRate / 100, 2),
            'seller_commission_rate' => isset($raw['sellerCommissionRate']) ? (float) $raw['sellerCommissionRate'] : null,
            'shopee_commission_rate' => isset($raw['shopeeCommissionRate']) ? (float) $raw['shopeeCommissionRate'] : null,
            'sales' => isset($raw['sales']) ? (int) $raw['sales'] : null,
            'rating_star' => isset($raw['ratingStar']) ? (float) $raw['ratingStar'] : null,
            'shop_name' => $raw['shopName'] ?? null,
            'shop_type' => $raw['shopType'] ?? null,
            'period_start_at' => $this->normalizeUnixTimestamp($raw['periodStartTime'] ?? null),
            'period_end_at' => $this->normalizeUnixTimestamp($raw['periodEndTime'] ?? null),
            'commission_tiers' => array_values(array_filter([
                isset($raw['commissionRate']) ? [
                    'name' => 'Total',
                    'rate' => (float) $raw['commissionRate'],
                ] : null,
                isset($raw['sellerCommissionRate']) ? [
                    'name' => 'Seller',
                    'rate' => (float) $raw['sellerCommissionRate'],
                ] : null,
                isset($raw['shopeeCommissionRate']) ? [
                    'name' => 'Shopee',
                    'rate' => (float) $raw['shopeeCommissionRate'],
                ] : null,
            ])),
            'raw' => $raw,
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

    private function resolveVisibleConnection(User $actor, int $connectionId): PlatformConnection
    {
        $connection = $this->platformConnectionRepository->findVisibleById($connectionId, $actor);

        if ($connection === null) {
            throw new NotFoundHttpException('Not found.');
        }

        return $connection;
    }
}
