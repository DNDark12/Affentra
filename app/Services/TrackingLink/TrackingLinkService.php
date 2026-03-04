<?php

declare(strict_types=1);

namespace App\Services\TrackingLink;

use App\Contracts\Repositories\CampaignRepositoryInterface;
use App\Contracts\Repositories\TrackingLinkRepositoryInterface;
use App\Enums\LinkStatus;
use App\Enums\Platform;
use App\Models\DailyStat;
use App\Models\Order;
use App\Models\TrackingLink;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use App\Services\Scope\ScopeResolver;
use App\Services\Scraping\ProductScraperService;
use App\Support\TrackingLinkIdentity;
use Carbon\CarbonInterface;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

class TrackingLinkService
{
    public function __construct(
        private readonly TrackingLinkRepositoryInterface $trackingLinkRepository,
        private readonly CampaignRepositoryInterface $campaignRepository,
        private readonly ScopeResolver $scopeResolver,
        private readonly AuditLogger $auditLogger,
        private readonly ProductScraperService $scraperService,
    ) {}

    /**
     * Paginated list of tracking links scoped to user + optional filters.
     *
     * @param  array<string, mixed>  $filters  ['status','search','campaign_id','per_page']
     */
    public function listForUser(User $user, array $filters = []): LengthAwarePaginator
    {
        $normalized = $this->normalizeFilters($filters);
        $scopeUserIds = $this->scopeResolver->resolveVisibleUserIds($user);
        $startedAt = hrtime(true);

        $links = $this->trackingLinkRepository->listForScope($scopeUserIds, $normalized);

        $elapsedMs = (hrtime(true) - $startedAt) / 1_000_000;
        Log::info('tracking_links.list', [
            'actor_id' => $user->id,
            'scope_size' => $scopeUserIds === null ? 'all' : count($scopeUserIds),
            'duration_ms' => round($elapsedMs, 2),
            'filters' => $normalized,
        ]);

        return $links;
    }

    /**
     * Get aggregate counters for current user scope + filters.
     *
     * @param  array<string, mixed>  $filters
     * @return array{
     *   total_clicks:int,
     *   total_orders:int,
     *   total_commission:float,
     *   total_approved:int,
     *   unattributed_clicks:int,
     *   unattributed_click_reasons:list<array{reason:string,label:string,count:int}>,
     *   unattributed_orders:int,
     *   unattributed_order_reasons:list<array{reason:string,label:string,count:int}>
     * }
     */
    public function summarizeForUser(User $user, array $filters = []): array
    {
        $scopeUserIds = $this->scopeResolver->resolveVisibleUserIds($user);
        $normalizedFilters = $this->normalizeFilters($filters);

        $totals = $this->trackingLinkRepository->aggregateCountersForScope(
            scopeUserIds: $scopeUserIds,
            filters: $normalizedFilters,
        );
        $attributionGaps = $this->trackingLinkRepository->attributionGapSummaryForScope(
            scopeUserIds: $scopeUserIds,
            filters: $normalizedFilters,
        );

        return [
            ...$totals,
            ...$attributionGaps,
        ];
    }

    /**
     * Resolve a tracking link by visibility scope. Returns 404 for out-of-scope IDs.
     */
    public function findOrFailForUser(User $user, int $trackingLinkId): TrackingLink
    {
        $link = $this->trackingLinkRepository->findByIdForScope(
            id: $trackingLinkId,
            scopeUserIds: $this->scopeResolver->resolveVisibleUserIds($user),
        );

        if ($link === null) {
            throw new NotFoundHttpException('Not found.');
        }

        return $link;
    }

    /**
     * Detail payload for link view/API.
     *
     * @return array<string, mixed>
     */
    public function detailForUser(User $user, int $trackingLinkId): array
    {
        $link = $this->findOrFailForUser($user, $trackingLinkId);

        $recentClicks = $link->clicks()
            ->latest('created_at')
            ->limit(20)
            ->get(['id', 'sub_id', 'ip', 'referer', 'created_at'])
            ->map(static fn ($click): array => [
                'id' => $click->id,
                'sub_id' => $click->sub_id,
                'ip' => $click->ip,
                'referer' => $click->referer,
                'created_at' => $click->created_at?->toIso8601String(),
            ])
            ->all();

        $metrics30 = DailyStat::query()
            ->where('tracking_link_id', $link->id)
            ->where('date', '>=', now()->subDays(30)->toDateString())
            ->selectRaw('COALESCE(SUM(clicks), 0) as clicks')
            ->selectRaw('COALESCE(SUM(orders), 0) as orders')
            ->selectRaw('COALESCE(SUM(approved), 0) as approved')
            ->selectRaw('COALESCE(SUM(commission), 0) as commission')
            ->first();

        $chart = DailyStat::query()
            ->where('tracking_link_id', $link->id)
            ->where('date', '>=', now()->subDays(14)->toDateString())
            ->select(['date', 'clicks', 'orders', 'commission'])
            ->orderBy('date')
            ->get()
            ->map(static fn ($point): array => [
                'date' => $point->date instanceof CarbonInterface ? $point->date->toDateString() : (string) $point->date,
                'clicks' => (int) $point->clicks,
                'orders' => (int) $point->orders,
                'commission' => round((float) $point->commission, 2),
            ])
            ->all();

        return [
            'id' => $link->id,
            'short_code' => $link->short_code,
            'track_url' => route('redirect', $link->short_code),
            'destination_url' => $link->destination_url,
            'status' => $link->status->value,
            'platform' => $link->platform->value,
            'source' => $link->source,
            'channel' => $link->channel,
            'sub_id' => $link->sub_id,
            'product_name' => $link->product_name,
            'product_image_urls' => $link->product_image_urls ?? [],
            'clicks_count' => $link->clicks_count,
            'orders_count' => $link->orders_count,
            'metrics_clicks_30d' => (int) ($metrics30?->clicks ?? 0),
            'metrics_orders_30d' => (int) ($metrics30?->orders ?? 0),
            'metrics_approved_30d' => (int) ($metrics30?->approved ?? 0),
            'metrics_commission_30d' => round((float) ($metrics30?->commission ?? 0), 2),
            'campaign' => $link->campaign ? [
                'id' => $link->campaign->id,
                'name' => $link->campaign->name,
            ] : null,
            'owner' => $link->user ? [
                'id' => $link->user->id,
                'name' => $link->user->name,
            ] : null,
            'created_at' => $link->created_at?->toIso8601String(),
            'updated_at' => $link->updated_at?->toIso8601String(),
            'recent_clicks' => $recentClicks,
            'clicks_series_14d' => $chart,
        ];
    }

    /**
     * Create a new tracking link with an auto-generated short_code if not provided.
     *
     * @param  array<string, mixed>  $data
     */
    public function create(User $user, array $data): TrackingLink
    {
        if (array_key_exists('campaign_id', $data) && $data['campaign_id'] !== null) {
            $this->assertCampaignVisible($user, (int) $data['campaign_id']);
        }

        $data['platform'] = $data['platform'] ?? Platform::Shopee;
        $platform = $data['platform'] instanceof Platform
            ? $data['platform']->value
            : (string) $data['platform'];
        $incomingMeta = is_array($data['meta'] ?? null) ? $data['meta'] : null;
        $destinationUrl = (string) ($data['destination_url'] ?? '');

        $existing = $this->findExistingEquivalentLink(
            user: $user,
            platform: $platform,
            destinationUrl: $destinationUrl,
            meta: $incomingMeta,
        );

        if ($existing !== null) {
            Log::info('tracking_links.reused_existing', [
                'actor_id' => $user->id,
                'tracking_link_id' => $existing->id,
                'platform' => $platform,
                'short_code' => $existing->short_code,
            ]);

            return $existing;
        }

        if (empty($data['short_code'])) {
            $data['short_code'] = $this->generateUniqueCode();
        }

        if (empty($data['product_name']) && !empty($destinationUrl)) {
            try {
                $scraped = $this->scraperService->scrape($destinationUrl, $user);
                if (! $this->hasMeaningfulScrapedData($scraped)) {
                    throw new \RuntimeException('Không lấy được dữ liệu sản phẩm từ URL này.');
                }
                $data['product_name'] = $scraped['data']['title'] ?? null;
                $data['product_price'] = $scraped['data']['price_display'] ?? null;
                $data['product_price_value'] = $scraped['data']['price_value'] ?? null;
                $data['product_image_urls'] = $scraped['data']['images'] ?? null;
                $data['product_scrape_confidence'] = $scraped['confidence'] ?? null;
                $data['product_scrape_source'] = $scraped['source'] ?? null;
                $data['product_last_scraped_at'] = now();
                
                if ($data['product_name'] && mb_strlen($data['product_name']) > 1000) {
                    $data['product_name'] = mb_substr($data['product_name'], 0, 997) . '...';
                }
            } catch (\Exception $e) {
                Log::warning('tracking_links.scrape_failed', [
                    'url' => $destinationUrl,
                    'error' => $e->getMessage(),
                ]);
                $data['product_scrape_error'] = $e->getMessage();
            }
        }

        $data['user_id']  = $user->id;
        $data['status']   = LinkStatus::Active;
        $created = $this->trackingLinkRepository->createLink($data);

        $this->auditLogger->log(
            actor: $user,
            action: 'tracking_link.create',
            target: $created,
            previousState: null,
            newState: $this->snapshotTrackingLink($created),
        );

        Log::info('tracking_links.created', [
            'actor_id' => $user->id,
            'tracking_link_id' => $created->id,
            'status' => $created->status->value,
            'campaign_id' => $created->campaign_id,
        ]);

        return $created;
    }

    /**
     * Update an existing tracking link.
     *
     * @param  array<string, mixed>  $data
     */
    public function updateForUser(User $user, int $trackingLinkId, array $data): TrackingLink
    {
        $link = $this->findOrFailForUser($user, $trackingLinkId);
        $previousState = $this->snapshotTrackingLink($link);
        $normalized = $this->normalizePayload($data);

        if (array_key_exists('destination_url', $normalized)) {
            $incomingMeta = is_array($normalized['meta'] ?? null)
                ? $normalized['meta']
                : (is_array($link->meta) ? $link->meta : null);
            $duplicate = $this->findExistingEquivalentLink(
                user: $user,
                platform: $link->platform->value,
                destinationUrl: (string) $normalized['destination_url'],
                meta: $incomingMeta,
                excludeId: $link->id,
            );

            if ($duplicate !== null) {
                throw new \DomainException("Tracking link already exists ({$duplicate->short_code}).");
            }
        }

        if (array_key_exists('campaign_id', $normalized) && $normalized['campaign_id'] !== null) {
            $this->assertCampaignVisible($user, (int) $normalized['campaign_id']);
        }

        if (array_key_exists('status', $normalized)) {
            $nextStatus = LinkStatus::from((string) $normalized['status']);
            $this->trackingLinkRepository->assertStatusTransition($link->status, $nextStatus);
        }

        // Invalidate cached redirect for this short_code
        Cache::forget('short_code:' . $link->short_code);

        $updated = $this->trackingLinkRepository->updateLink($link->id, $normalized);

        $this->auditLogger->log(
            actor: $user,
            action: 'tracking_link.update',
            target: $updated,
            previousState: $previousState,
            newState: $this->snapshotTrackingLink($updated),
        );

        Log::info('tracking_links.updated', [
            'actor_id' => $user->id,
            'tracking_link_id' => $updated->id,
            'from_status' => $link->status->value,
            'to_status' => $updated->status->value,
            'campaign_id' => $updated->campaign_id,
        ]);

        return $updated;
    }

    /**
     * Archive a tracking link (soft-delete equivalent).
     */
    public function archiveForUser(User $user, int $trackingLinkId): TrackingLink
    {
        $link = $this->findOrFailForUser($user, $trackingLinkId);
        $previousState = $this->snapshotTrackingLink($link);
        $this->trackingLinkRepository->assertStatusTransition($link->status, LinkStatus::Archived);

        // Invalidate cached redirect — archived links must not resolve
        Cache::forget('short_code:' . $link->short_code);

        $updated = $this->trackingLinkRepository->updateLink($link->id, ['status' => LinkStatus::Archived]);

        $this->auditLogger->log(
            actor: $user,
            action: 'tracking_link.archive',
            target: $updated,
            previousState: $previousState,
            newState: $this->snapshotTrackingLink($updated),
        );

        Log::info('tracking_links.archived', [
            'actor_id' => $user->id,
            'tracking_link_id' => $updated->id,
            'from_status' => $link->status->value,
        ]);

        return $updated;
    }

    /**
     * Generate a collision-resistant 8-char alphanumeric code.
     */
    private function generateUniqueCode(int $attempts = 0): string
    {
        if ($attempts > 10) {
            throw new \RuntimeException('Unable to generate unique short code after 10 attempts.');
        }

        $code = Str::lower(Str::random(8));

        if ($this->trackingLinkRepository->existsByShortCode($code)) {
            return $this->generateUniqueCode($attempts + 1);
        }

        return $code;
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return array<string, mixed>
     */
    private function normalizeFilters(array $filters): array
    {
        $normalized = $filters;

        if (isset($normalized['status'])) {
            $normalized['status'] = $this->normalizeStatus((string) $normalized['status']);
            if ($normalized['status'] === 'all') {
                unset($normalized['status']);
            }
        }

        if (($normalized['status'] ?? null) === 'high_commission') {
            unset($normalized['status']);
            $normalized['preset'] = 'top_performing';
        }

        if (($normalized['status'] ?? null) === 'low_cr') {
            unset($normalized['status']);
            $normalized['preset'] = 'underperforming';
        }

        if (($normalized['preset'] ?? null) === 'top_performing' && empty($normalized['sort'])) {
            $normalized['sort'] = 'orders_count';
            $normalized['direction'] = 'desc';
        }

        if (($normalized['preset'] ?? null) === 'underperforming' && empty($normalized['sort'])) {
            $normalized['sort'] = 'clicks_count';
            $normalized['direction'] = 'desc';
        }

        if (isset($normalized['search'])) {
            $normalized['search'] = trim((string) $normalized['search']);
        }

        if (! empty($normalized['date_from'])) {
            $normalized['date_from'] = (string) $normalized['date_from'];
        }

        if (! empty($normalized['date_to'])) {
            $normalized['date_to'] = (string) $normalized['date_to'];
        }

        if (isset($normalized['campaign_id']) && $normalized['campaign_id'] !== null) {
            $normalized['campaign_id'] = (int) $normalized['campaign_id'];
        }

        return $normalized;
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function normalizePayload(array $payload): array
    {
        if (isset($payload['status'])) {
            $payload['status'] = $this->normalizeStatus((string) $payload['status']);
        }

        return $payload;
    }

    private function normalizeStatus(string $status): string
    {
        return $status === 'inactive' ? LinkStatus::Paused->value : $status;
    }

    /**
     * @return array<string, mixed>
     */
    private function snapshotTrackingLink(TrackingLink $link): array
    {
        return [
            'id' => $link->id,
            'user_id' => $link->user_id,
            'campaign_id' => $link->campaign_id,
            'short_code' => $link->short_code,
            'destination_url' => $link->destination_url,
            'status' => $link->status->value,
            'platform' => $link->platform->value,
            'source' => $link->source,
            'channel' => $link->channel,
            'sub_id' => $link->sub_id,
            'product_name' => $link->product_name,
            'product_price' => $link->product_price,
            'product_price_value' => $link->product_price_value,
            'product_last_scraped_at' => $link->product_last_scraped_at?->toIso8601String(),
            'product_scrape_confidence' => $link->product_scrape_confidence,
            'product_scrape_source' => $link->product_scrape_source,
        ];
    }

    private function findExistingEquivalentLink(
        User $user,
        string $platform,
        string $destinationUrl,
        ?array $meta = null,
        ?int $excludeId = null,
    ): ?TrackingLink {
        $incomingIdentity = TrackingLinkIdentity::identityKey($platform, $destinationUrl, $meta);
        if ($incomingIdentity === null) {
            return null;
        }

        $query = TrackingLink::query()
            ->where('user_id', $user->id)
            ->where('platform', $platform)
            ->where('status', '!=', LinkStatus::Archived->value);

        if ($excludeId !== null) {
            $query->where('id', '!=', $excludeId);
        }

        $candidates = $query->get();

        foreach ($candidates as $candidate) {
            $candidateMeta = is_array($candidate->meta) ? $candidate->meta : null;
            $candidateIdentity = TrackingLinkIdentity::identityKey(
                $platform,
                $candidate->destination_url,
                $candidateMeta,
            );

            if ($candidateIdentity === $incomingIdentity) {
                return $candidate;
            }
        }

        return null;
    }

    private function assertCampaignVisible(User $user, int $campaignId): void
    {
        $campaign = $this->campaignRepository->findByIdForScope(
            id: $campaignId,
            scopeUserIds: $this->scopeResolver->resolveVisibleUserIds($user),
        );

        if ($campaign === null) {
            throw new \DomainException('Campaign is not visible in your scope.');
        }
    }

    public function refreshProductInfo(User $user, int $trackingLinkId): TrackingLink
    {
        $link = $this->findOrFailForUser($user, $trackingLinkId);
        $previousState = $this->snapshotTrackingLink($link);

        try {
            $scraped = null;
            $primaryError = null;

            try {
                $scraped = $this->scraperService->scrape($link->destination_url, $user);
            } catch (\Throwable $e) {
                $primaryError = $e;
            }

            if (! is_array($scraped) || ! $this->hasMeaningfulScrapedData($scraped)) {
                $orderFallback = $this->buildOrderBasedProductFallback($link);
                if ($orderFallback !== null) {
                    $scraped = $orderFallback;
                }
            }

            if (! is_array($scraped) || ! $this->hasMeaningfulScrapedData($scraped)) {
                if ($primaryError instanceof \Throwable) {
                    throw $primaryError;
                }

                throw new \RuntimeException('Không lấy được dữ liệu sản phẩm từ Shopee. Vui lòng thử lại sau.');
            }
            
            $updateData = [
                'product_name' => $scraped['data']['title'] ?? $link->product_name,
                'product_price' => $scraped['data']['price_display'] ?? $link->product_price,
                'product_price_value' => $scraped['data']['price_value'] ?? $link->product_price_value,
                'product_image_urls' => $scraped['data']['images'] ?? $link->product_image_urls,
                'product_scrape_confidence' => $scraped['confidence'] ?? null,
                'product_scrape_source' => $scraped['source'] ?? null,
                'product_last_scraped_at' => now(),
                'product_scrape_error' => null,
            ];

            if ($updateData['product_name'] && mb_strlen($updateData['product_name']) > 1000) {
                $updateData['product_name'] = mb_substr($updateData['product_name'], 0, 997) . '...';
            }

            $updated = $this->trackingLinkRepository->updateLink($link->id, $updateData);

            $this->auditLogger->log(
                actor: $user,
                action: 'tracking_link.refresh_product',
                target: $updated,
                previousState: $previousState,
                newState: $this->snapshotTrackingLink($updated),
            );

            return $updated;
        } catch (\Exception $e) {
            $this->trackingLinkRepository->updateLink($link->id, [
                'product_scrape_error' => $e->getMessage(),
                'product_last_scraped_at' => now(),
            ]);
            throw $e;
        }
    }

    /**
     * @param  array<string, mixed>  $scraped
     */
    private function hasMeaningfulScrapedData(array $scraped): bool
    {
        $data = is_array($scraped['data'] ?? null) ? $scraped['data'] : [];

        $title = trim((string) ($data['title'] ?? ''));
        $price = $data['price_value'] ?? null;
        $images = is_array($data['images'] ?? null) ? $data['images'] : [];

        return $title !== ''
            || (is_numeric($price) && (float) $price > 0)
            || ! empty($images);
    }

    /**
     * Build a safe fallback payload from the latest mapped order of this tracking link.
     *
     * @return array<string, mixed>|null
     */
    private function buildOrderBasedProductFallback(TrackingLink $link): ?array
    {
        $latestOrder = Order::query()
            ->where('tracking_link_id', $link->id)
            ->whereNotNull('product_name')
            ->orderByDesc('ordered_at')
            ->orderByDesc('id')
            ->first([
                'user_id',
                'product_name',
                'product_id',
                'order_amount',
                'listed_amount',
                'product_link',
                'source_meta',
            ]);

        if ($latestOrder === null || blank($latestOrder->product_name)) {
            // Secondary fallback: match by product_id across this user's order history.
            $itemId = $this->extractShopeeItemIdFromUrl((string) $link->destination_url);
            if ($itemId !== null) {
                $latestOrder = Order::query()
                    ->where('user_id', $link->user_id)
                    ->where('product_id', (string) $itemId)
                    ->whereNotNull('product_name')
                    ->orderByDesc('ordered_at')
                    ->orderByDesc('id')
                    ->first([
                        'user_id',
                        'product_name',
                        'product_id',
                        'order_amount',
                        'listed_amount',
                        'product_link',
                        'source_meta',
                    ]);
            }

            if ($latestOrder === null || blank($latestOrder->product_name)) {
                return null;
            }
        }

        $priceValue = null;
        if (is_numeric($latestOrder->order_amount) && (float) $latestOrder->order_amount > 0) {
            $priceValue = (float) $latestOrder->order_amount;
        } elseif (is_numeric($latestOrder->listed_amount) && (float) $latestOrder->listed_amount > 0) {
            $priceValue = (float) $latestOrder->listed_amount;
        }

        $images = is_array($link->product_image_urls) ? $link->product_image_urls : [];
        $sourceMetaImage = $this->extractImageFromOrderSourceMeta(is_array($latestOrder->source_meta) ? $latestOrder->source_meta : null);
        if ($sourceMetaImage !== null && $sourceMetaImage !== '') {
            $images = [$sourceMetaImage];
        }

        return [
            'data' => [
                'title' => (string) $latestOrder->product_name,
                'price_value' => $priceValue,
                'price_display' => $priceValue !== null
                    ? number_format($priceValue, 0, ',', '.') . '₫'
                    : null,
                'images' => $images,
                'description' => null,
                'product_link' => $latestOrder->product_link,
            ],
            'missing_fields' => [],
            'confidence' => 0.56,
            'source' => 'order_fallback',
            'source_details' => [
                'tracking_link_id' => $link->id,
                'order_source' => ($latestOrder->tracking_link_id ?? null) === $link->id
                    ? 'latest_mapped_order'
                    : 'latest_user_order_by_product_id',
            ],
        ];
    }

    private function extractShopeeItemIdFromUrl(string $url): ?int
    {
        if ($url === '') {
            return null;
        }

        $path = parse_url($url, PHP_URL_PATH);
        if (! is_string($path) || $path === '') {
            return null;
        }

        if (preg_match('#/product/\d+/(\d+)#', $path, $matches) === 1) {
            return (int) $matches[1];
        }

        if (preg_match('#/offer/product_offer/(\d+)#', $path, $matches) === 1) {
            return (int) $matches[1];
        }

        if (preg_match('#/(\d+)$#', $path, $matches) === 1) {
            return (int) $matches[1];
        }

        return null;
    }

    /**
     * @param  array<string, mixed>|null  $sourceMeta
     */
    private function extractImageFromOrderSourceMeta(?array $sourceMeta): ?string
    {
        if ($sourceMeta === null) {
            return null;
        }

        $imageCandidates = [
            $sourceMeta['image_url'] ?? null,
            $sourceMeta['imageUrl'] ?? null,
            $sourceMeta['img_url'] ?? null,
            $sourceMeta['imgUrl'] ?? null,
            $sourceMeta['thumbnail'] ?? null,
            $sourceMeta['thumb'] ?? null,
        ];

        foreach ($imageCandidates as $candidate) {
            if (is_string($candidate) && trim($candidate) !== '') {
                return trim($candidate);
            }
        }

        $imgCode = $sourceMeta['img_code'] ?? $sourceMeta['imgCode'] ?? null;
        if (is_string($imgCode) && trim($imgCode) !== '') {
            return 'https://down-vn.img.susercontent.com/file/' . trim($imgCode);
        }

        return null;
    }
}
