<?php

declare(strict_types=1);

namespace App\Services\Offer;

use App\Contracts\Repositories\PlatformConnectionRepositoryInterface;
use App\Models\PlatformConnection;
use App\Models\User;
use App\Services\Integration\IntegrationFactory;
use App\Services\TrackingLink\TrackingLinkService;
use App\Support\ShopeeDomainValidator;
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
            'campaign_id'     => null,
            'destination_url' => $shortLink ?? $validated['offer_link'],
            'sub_id'          => $subId,
            'platform'        => $connection->platform,
            'source'          => 'offer',
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
            'sub_id'          => $subId,
        ];
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
