<?php

declare(strict_types=1);

namespace App\Services\Order;

use App\Contracts\Repositories\OrderRepositoryInterface;
use App\Contracts\Repositories\SyncRunRepositoryInterface;
use App\Contracts\Repositories\TrackingLinkRepositoryInterface;
use App\Contracts\Repositories\UserRepositoryInterface;
use App\Jobs\Order\AggregateDailyStatsJob;
use App\Jobs\Order\ImportOrdersJob;
use App\Models\Order;
use App\Models\PlatformConnection;
use App\Models\SyncRun;
use App\Models\TrackingLink;
use App\Models\User;
use App\Support\TrackingLinkIdentity;
use Carbon\Carbon;
use Illuminate\Http\UploadedFile;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Database\QueryException;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class OrderService
{
    public function __construct(
        private readonly OrderRepositoryInterface $orderRepository,
        private readonly UserRepositoryInterface $userRepository,
        private readonly SyncRunRepositoryInterface $syncRunRepository,
        private readonly TrackingLinkRepositoryInterface $trackingLinkRepository,
    ) {}

    /**
     * List orders scoped by user role hierarchy.
     *
     * @param  array<string, mixed>  $filters
     */
    public function listForUser(User $user, array $filters = [], int $perPage = 20): LengthAwarePaginator
    {
        $userIds = $this->resolveScopeIds($user);

        return $this->orderRepository->listForUsers($userIds, $filters, $perPage);
    }

    /**
     * Get aggregate stats scoped by user role.
     *
     * @return array{total_orders: int, approved_orders: int, total_commission: float, total_amount: float}
     */
    public function getStats(User $user, string $period = '30days'): array
    {
        $userIds = $this->resolveScopeIds($user);

        return $this->orderRepository->getStatsByUsers($userIds, $period);
    }

    /**
     * Parse CSV file and prepare rows for import.
     * Returns structured data with mapping rules applied.
     *
     * @return list<array<string, mixed>>
     */
    public function parseCsvForImport(UploadedFile $file, User $importer, string $platform = 'shopee'): array
    {
        $rawRows = [];
        $handle  = fopen($file->getRealPath(), 'r');

        if ($handle === false) {
            throw new \RuntimeException('Cannot open CSV file');
        }

        $header = fgetcsv($handle);
        if ($header === false) {
            fclose($handle);
            throw new \RuntimeException('CSV file is empty or invalid');
        }

        $header = array_map(fn ($col) => mb_strtolower(trim($col)), $header);

        while (($row = fgetcsv($handle)) !== false) {
            if (count($row) !== count($header)) {
                continue;
            }
            $rawRows[] = array_combine($header, $row);
        }
        fclose($handle);

        // Preload tracking links to prevent N+1 queries
        $subIds = array_filter(array_map(fn($r) => $r['sub_id'] ?? $r['aff_sub'] ?? $r['utm_content'] ?? null, $rawRows));
        $linksBySubId = [];
        if (!empty($subIds)) {
            $linksBySubId = TrackingLink::whereIn('sub_id', array_unique($subIds))
                ->get(['id', 'user_id', 'campaign_id', 'sub_id'])
                ->keyBy('sub_id')
                ->all();
        }

        $rows = [];
        foreach ($rawRows as $data) {
            $mapped = $this->mapCsvRow($data, $importer, $platform, $linksBySubId);

            if ($mapped !== null) {
                $rows[] = $mapped;
            }
        }

        return $rows;
    }

    public function startImport(User $user, UploadedFile $file, string $platform): SyncRun
    {
        $path = $file->store('imports/csv', 'local');

        /** @var SyncRun $syncRun */
        $syncRun = $this->syncRunRepository->create([
            'user_id'                => $user->id,
            'platform_connection_id' => null,
            'integration'            => $platform,
            'type'                   => 'import',
            'status'                 => 'pending',
            'started_at'             => null,
            'finished_at'            => null,
            'records_fetched'        => 0,
            'records_upserted'       => 0,
            'records_failed'         => 0,
        ]);

        ImportOrdersJob::dispatch($path, $user->id, $platform, $syncRun->id);

        return $syncRun;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function getImportStatus(User $user, int $syncRunId): ?array
    {
        $syncRun = SyncRun::findOrFail($syncRunId);

        if ($user->cannot('view', $syncRun)) {
            return null;
        }

        return [
            'status'           => $syncRun->status,
            'records_fetched'  => $syncRun->records_fetched,
            'records_upserted' => $syncRun->records_upserted,
            'records_failed'   => $syncRun->records_failed,
            'error_message'    => $syncRun->error_message,
            'started_at'       => $syncRun->started_at?->toIso8601String(),
            'finished_at'      => $syncRun->finished_at?->toIso8601String(),
        ];
    }

    /**
     * Map a single CSV row to an order record.
     * Mapping rules:
     *  - If CSV has sub_id/aff_sub → derive tracking_link_id, campaign_id, user_id from link owner
     *  - Otherwise → user_id = importer
     *
     * @param  array<string, string>  $row
     * @param  array<string, TrackingLink> $linksBySubId
     * @return array<string, mixed>|null  Null if row is invalid
     */
    private function mapCsvRow(array $row, User $importer, string $platform, array $linksBySubId = []): ?array
    {
        $orderCode = $row['order_code'] ?? $row['order_id'] ?? $row['mã đơn hàng'] ?? null;

        if (empty($orderCode)) {
            return null;
        }

        $orderAmount = $this->parseNumeric($row['order_amount'] ?? $row['giá trị đơn'] ?? '0');
        $commission  = $this->parseNumeric($row['commission'] ?? $row['hoa hồng'] ?? '0');
        $orderedAt   = $this->parseDate($row['ordered_at'] ?? $row['order_date'] ?? $row['ngày đặt'] ?? '');
        $approvedAt  = $this->parseDate($row['approved_at'] ?? $row['approval_date'] ?? '');
        $status      = $this->mapStatus($row['status'] ?? $row['trạng thái'] ?? 'pending');

        if ($orderedAt === null) {
            return null; // ordered_at is required
        }

        // Try to map sub_id → tracking link → user/campaign
        $subId    = $row['sub_id'] ?? $row['aff_sub'] ?? $row['utm_content'] ?? null;
        $userId   = $importer->id;
        $campaignId     = null;
        $trackingLinkId = null;

        if (! empty($subId) && isset($linksBySubId[$subId])) {
            $link = $linksBySubId[$subId];
            $userId         = $link->user_id;
            $campaignId     = $link->campaign_id;
            $trackingLinkId = $link->id;
        }

        $now = now()->toDateTimeString();

        return [
            'user_id'          => $userId,
            'campaign_id'      => $campaignId,
            'tracking_link_id' => $trackingLinkId,
            'platform'         => $platform,
            'order_code'       => (string) $orderCode,
            'status'           => $status,
            'order_amount'     => $orderAmount,
            'commission'       => $commission,
            'ordered_at'       => $orderedAt,
            'approved_at'      => $approvedAt,
            'created_at'       => $now,
            'updated_at'       => $now,
        ];
    }

    private function parseNumeric(string $value): float
    {
        // Remove currency symbols and thousand separators
        $cleaned = preg_replace('/[^\d.\-]/', '', str_replace(',', '.', $value));

        return (float) ($cleaned ?: 0);
    }

    private function parseDate(string $value): ?string
    {
        if (empty($value)) {
            return null;
        }

        try {
            return \Carbon\Carbon::parse($value)->toDateTimeString();
        } catch (\Throwable) {
            return null;
        }
    }

    private function mapStatus(string $raw): string
    {
        $lower = mb_strtolower(trim($raw));

        return match (true) {
            in_array($lower, ['approved', 'đã duyệt', 'confirmed']) => 'approved',
            in_array($lower, ['rejected', 'từ chối', 'cancelled'])  => 'rejected',
            default => 'pending',
        };
    }

    /**
     * Upsert orders from API sync (Phase 2).
     * Processes in chunks with hash comparison to skip unchanged records.
     *
     * @param  list<array<string, mixed>>  $orders  Mapped order rows from adapter
     * @return array{
     *   upserted: int,
     *   failed: int,
     *   affected_partitions: list<array{date: string, user_id: int, platform: string}>,
     *   affected_link_ids: list<int>,
     *   auto_links_created: int,
     *   unattributed_orders: int
     * }
     */
    public function upsertFromApiSync(PlatformConnection $connection, array $orders): array
    {
        $chunkSize = config('integrations.sync.upsert_chunk_size', 500);
        $totalUpserted = 0;
        $totalFailed = 0;
        $affectedPartitions = [];
        $affectedLinkIds = [];
        $autoLinksCreated = 0;
        $unattributedOrders = 0;

        // Preload tracking links for sub_id mapping
        $subIds = array_values(array_unique(array_filter(
            array_map(fn (array $row): ?string => $this->normalizeSubId($row['sub_id'] ?? null), $orders),
            static fn (?string $subId): bool => $subId !== null,
        )));
        $linksBySubId = [];
        if (! empty($subIds)) {
            $linksBySubId = TrackingLink::whereIn('sub_id', array_unique($subIds))
                ->get(['id', 'user_id', 'campaign_id', 'sub_id'])
                ->keyBy('sub_id')
                ->all();
        }
        $linksByProductKey = $this->buildLinksByProductKey($connection);

        $chunks = array_chunk($orders, $chunkSize);

        foreach ($chunks as $chunk) {
            try {
                DB::transaction(function () use (
                    $chunk,
                    $connection,
                    $linksBySubId,
                    &$linksByProductKey,
                    &$totalUpserted,
                    &$affectedPartitions,
                    &$affectedLinkIds,
                    &$autoLinksCreated,
                    &$unattributedOrders,
                ) {
                    $upsertRows = [];

                    foreach ($chunk as $order) {
                        $hashSource = $order;
                        unset($hashSource['legacy_order_code']);
                        $hash = hash('sha256', json_encode($hashSource, JSON_THROW_ON_ERROR));

                        $canonicalOrderCode = trim((string) ($order['order_code'] ?? ''));
                        if ($canonicalOrderCode === '') {
                            continue;
                        }

                        $legacyOrderCode = trim((string) ($order['legacy_order_code'] ?? ''));
                        $candidateOrderCodes = array_values(array_filter(array_unique([
                            $canonicalOrderCode,
                            $legacyOrderCode !== '' ? $legacyOrderCode : null,
                        ])));

                        // Check if record exists with same hash (skip unchanged)
                        $existing = Order::where('platform', $order['platform'])
                            ->whereIn('order_code', $candidateOrderCodes)
                            ->first([
                                'id',
                                'order_code',
                                'raw_payload_hash',
                                'ordered_at',
                                'user_id',
                                'campaign_id',
                                'tracking_link_id',
                                'sub_id',
                                'missing_sub_id',
                                'source_meta',
                            ]);

                        // One-time normalization for historical data that used legacy order_id as order_code.
                        if ($existing && $existing->order_code !== $canonicalOrderCode) {
                            $canonicalExisting = Order::where('platform', $order['platform'])
                                ->where('order_code', $canonicalOrderCode)
                                ->first(['id', 'order_code', 'raw_payload_hash', 'ordered_at', 'user_id']);

                            if ($canonicalExisting) {
                                $existing->delete();
                                $existing = $canonicalExisting;
                            } else {
                                $existing->order_code = $canonicalOrderCode;
                                $existing->save();
                                $existing->refresh();
                            }
                        }

                        // Map sub_id to tracking link ownership
                        $userId = $connection->user_id;
                        $campaignId = null;
                        $trackingLinkId = null;
                        $missingSubId = false;
                        $attributionSource = 'direct';
                        $attributionDetail = null;

                        $subId = $this->normalizeSubId($order['sub_id'] ?? null);
                        if (! empty($subId) && isset($linksBySubId[$subId])) {
                            $link = $linksBySubId[$subId];
                            $userId = $link->user_id;
                            $campaignId = $link->campaign_id;
                            $trackingLinkId = $link->id;
                            $attributionSource = 'sub_id';
                            $attributionDetail = $subId;
                        } elseif (! empty($subId)) {
                            $missingSubId = true;
                            $attributionSource = 'missing_sub_id';
                            $attributionDetail = $subId;
                        }

                        $productKey = null;
                        if ($trackingLinkId === null) {
                            $productKey = $this->buildOrderProductKey($order);
                            if ($productKey !== null && isset($linksByProductKey[$productKey])) {
                                $link = $linksByProductKey[$productKey];
                                if (($link['ambiguous'] ?? false) === true) {
                                    $attributionSource = 'ambiguous_product';
                                    $attributionDetail = $productKey;
                                } else {
                                    $userId = $link['user_id'];
                                    $campaignId = $link['campaign_id'];
                                    $trackingLinkId = $link['id'];
                                    $missingSubId = false;
                                    $attributionSource = 'product_key';
                                    $attributionDetail = $productKey;
                                }
                            } elseif ($productKey !== null && $attributionSource === 'direct') {
                                $attributionSource = 'unmatched_product';
                                $attributionDetail = $productKey;
                            }

                            if (
                                $trackingLinkId === null
                                && $productKey !== null
                                && ! (($linksByProductKey[$productKey]['ambiguous'] ?? false) === true)
                            ) {
                                $autoLink = $this->ensureAutoTrackingLinkForOrder(
                                    connection: $connection,
                                    order: $order,
                                    productKey: $productKey,
                                    linksByProductKey: $linksByProductKey,
                                );

                                if ($autoLink !== null) {
                                    $userId = (int) $autoLink['user_id'];
                                    $campaignId = $autoLink['campaign_id'] ?? null;
                                    $trackingLinkId = (int) $autoLink['id'];
                                    $missingSubId = false;
                                    $attributionSource = 'auto_link';
                                    $attributionDetail = $productKey;
                                    $autoLinksCreated++;
                                }
                            }
                        }

                        if ($trackingLinkId === null) {
                            $unattributedOrders++;
                        }

                        $sourceMeta = is_array($order['source_meta'] ?? null) ? $order['source_meta'] : [];
                        $sourceMeta['attribution_source'] = $attributionSource;
                        if ($attributionDetail !== null) {
                            $sourceMeta['attribution_detail'] = $attributionDetail;
                        }
                        $sourceMeta['missing_sub_id'] = $missingSubId;

                        $existingSourceMeta = is_array($existing?->source_meta ?? null) ? $existing->source_meta : [];
                        $attributionUnchanged = (string) ($existingSourceMeta['attribution_source'] ?? '') === $attributionSource
                            && (string) ($existingSourceMeta['attribution_detail'] ?? '') === (string) ($attributionDetail ?? '');

                        $mappingUnchanged = $existing
                            && (int) $existing->user_id === $userId
                            && (string) ($existing->campaign_id ?? '') === (string) ($campaignId ?? '')
                            && (string) ($existing->tracking_link_id ?? '') === (string) ($trackingLinkId ?? '')
                            && (string) ($existing->sub_id ?? '') === (string) ($subId ?? '')
                            && (bool) $existing->missing_sub_id === $missingSubId
                            && $attributionUnchanged;

                        if ($existing && $existing->raw_payload_hash === $hash && $mappingUnchanged) {
                            continue; // Skip — no payload or ownership changes
                        }

                        $now = now()->toDateTimeString();

                        $upsertRows[] = [
                            'user_id'            => $userId,
                            'campaign_id'        => $campaignId,
                            'tracking_link_id'   => $trackingLinkId,
                            'connection_id'      => $connection->id,
                            'platform'           => $order['platform'],
                            'order_code'         => $canonicalOrderCode,
                            'external_order_id'  => $order['external_order_id'] ?? null,
                            'sub_id'             => $subId,
                            'shop_id'            => $order['shop_id'] ?? null,
                            'shop_name'          => $order['shop_name'] ?? null,
                            'product_id'         => $order['product_id'] ?? null,
                            'product_model_id'   => $order['product_model_id'] ?? null,
                            'product_name'       => $order['product_name'] ?? null,
                            'product_link'       => $order['product_link'] ?? null,
                            'product_quantity'   => isset($order['product_quantity']) ? (int) $order['product_quantity'] : null,
                            'status'             => $order['status'] ?? 'pending',
                            'order_amount'       => $order['order_amount'] ?? 0,
                            'listed_amount'      => $order['listed_amount'] ?? 0,
                            'commission'         => $order['commission'] ?? 0,
                            'commission_platform'=> $order['commission_platform'] ?? 0,
                            'commission_brand'   => $order['commission_brand'] ?? 0,
                            'commission_other'   => $order['commission_other'] ?? 0,
                            'ordered_at'         => isset($order['ordered_at']) ? Carbon::parse($order['ordered_at'])->toDateTimeString() : null,
                            'click_at'           => !empty($order['click_at']) ? Carbon::parse($order['click_at'])->toDateTimeString() : null,
                            'approved_at'        => !empty($order['approved_at']) ? Carbon::parse($order['approved_at'])->toDateTimeString() : null,
                            'completed_at'       => !empty($order['completed_at']) ? Carbon::parse($order['completed_at'])->toDateTimeString() : null,
                            'source'             => 'api',
                            'source_updated_at'  => now(),
                            'synced_at'          => now(),
                            'raw_payload_hash'   => $hash,
                            'missing_sub_id'     => $missingSubId,
                            'source_meta'        => json_encode($sourceMeta, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                            'created_at'         => $now,
                            'updated_at'         => $now,
                        ];

                        if ($trackingLinkId !== null) {
                            $affectedLinkIds[(int) $trackingLinkId] = true;
                        }

                        // Track affected partitions for incremental aggregation
                        $orderedDate = null;
                        if (! empty($order['ordered_at'])) {
                            $orderedDate = Carbon::parse($order['ordered_at'])->toDateString();
                            $affectedPartitions[] = [
                                'date'     => $orderedDate,
                                'user_id'  => $userId,
                                'platform' => $order['platform'],
                            ];
                        }

                        if (! empty($order['approved_at'])) {
                            $approvedDate = Carbon::parse($order['approved_at'])->toDateString();
                            if (! isset($orderedDate) || $approvedDate !== $orderedDate) {
                                $affectedPartitions[] = [
                                    'date'     => $approvedDate,
                                    'user_id'  => $userId,
                                    'platform' => $order['platform'],
                                ];
                            }
                        }
                    }

                    if (! empty($upsertRows)) {
                        Order::upsert(
                            $upsertRows,
                            ['platform', 'order_code'],
                            [
                                'user_id', 'campaign_id', 'tracking_link_id', 'connection_id',
                                'sub_id', 'missing_sub_id',
                                'status', 'order_amount', 'listed_amount', 'commission',
                                'commission_platform', 'commission_brand', 'commission_other',
                                'ordered_at', 'approved_at', 'source_updated_at', 'synced_at',
                                'external_order_id', 'shop_id', 'shop_name',
                                'product_id', 'product_model_id', 'product_name', 'product_link', 'product_quantity',
                                'click_at', 'completed_at', 'source_meta',
                                'raw_payload_hash', 'updated_at',
                            ],
                        );
                        $totalUpserted += count($upsertRows);
                    }
                });
            } catch (\Throwable $e) {
                $totalFailed += count($chunk);
                Log::error('OrderService::upsertFromApiSync chunk failed', [
                    'connection_id' => $connection->id,
                    'error'         => $e->getMessage(),
                ]);
            }
        }

        // Deduplicate affected partitions
        $affectedPartitions = array_values(array_unique($affectedPartitions, SORT_REGULAR));

        // Dispatch incremental aggregate job(s) per platform
        if (! empty($affectedPartitions)) {
            $byPlatform = collect($affectedPartitions)->groupBy('platform');
            foreach ($byPlatform as $platform => $partitions) {
                $dates = $partitions->pluck('date');
                AggregateDailyStatsJob::dispatch(
                    platform: $platform,
                    minDate: $dates->min(),
                    maxDate: $dates->max(),
                );
            }
        }

        $affectedLinkIds = array_map('intval', array_keys($affectedLinkIds));
        if ($affectedLinkIds !== []) {
            $this->trackingLinkRepository->recomputeOrdersCountBulk($affectedLinkIds);
        }

        return [
            'upserted'            => $totalUpserted,
            'failed'              => $totalFailed,
            'affected_partitions' => $affectedPartitions,
            'affected_link_ids'   => $affectedLinkIds,
            'auto_links_created'  => $autoLinksCreated,
            'unattributed_orders' => $unattributedOrders,
        ];
    }

    /**
     * @return array<string, array{id?: int, user_id?: int, campaign_id?: int|null, ambiguous?: bool}>
     */
    private function buildLinksByProductKey(PlatformConnection $connection): array
    {
        $map = [];
        $ambiguousKeys = [];

        $links = TrackingLink::query()
            ->where('platform', $connection->platform)
            ->where('user_id', $connection->user_id)
            ->where(function ($query) use ($connection): void {
                $query->where('platform_connection_id', $connection->id)
                    ->orWhereNull('platform_connection_id');
            })
            ->get(['id', 'user_id', 'campaign_id', 'destination_url', 'meta', 'platform_connection_id']);

        foreach ($links as $link) {
            $productKey = TrackingLinkIdentity::extractShopeeProductKey(
                $link->destination_url,
                is_array($link->meta) ? $link->meta : null,
            );
            if ($productKey === null) {
                continue;
            }

            $priority = ((int) ($link->platform_connection_id ?? 0) === (int) $connection->id) ? 2 : 1;
            $existing = $map[$productKey] ?? null;
            if (($existing['ambiguous'] ?? false) === true || isset($ambiguousKeys[$productKey])) {
                $map[$productKey] = ['ambiguous' => true];
                continue;
            }

            if ($existing !== null) {
                $existingPriority = (int) ($existing['priority'] ?? 0);
                if ($priority > $existingPriority) {
                    $map[$productKey] = [
                        'id' => $link->id,
                        'user_id' => $link->user_id,
                        'campaign_id' => $link->campaign_id,
                        'priority' => $priority,
                    ];
                    continue;
                }

                if ($priority === $existingPriority && (int) ($existing['id'] ?? 0) !== (int) $link->id) {
                    $ambiguousKeys[$productKey] = true;
                    $map[$productKey] = ['ambiguous' => true];
                }

                continue;
            }

            $map[$productKey] = [
                'id' => $link->id,
                'user_id' => $link->user_id,
                'campaign_id' => $link->campaign_id,
                'priority' => $priority,
            ];
        }

        return $map;
    }

    private function buildOrderProductKey(array $order): ?string
    {
        $shopId = trim((string) ($order['shop_id'] ?? ''));
        $productId = trim((string) ($order['product_id'] ?? ''));

        if ($shopId === '' || $productId === '') {
            $fromLink = TrackingLinkIdentity::extractShopeeProductKey(
                isset($order['product_link']) ? (string) $order['product_link'] : null
            );

            return $fromLink !== null ? trim($fromLink) : null;
        }

        return "{$shopId}:{$productId}";
    }

    /**
     * @param  array<string, mixed>  $order
     * @param  array<string, array{id?: int, user_id?: int, campaign_id?: int|null, ambiguous?: bool}>  $linksByProductKey
     * @return array{id: int, user_id: int, campaign_id: int|null}|null
     */
    private function ensureAutoTrackingLinkForOrder(
        PlatformConnection $connection,
        array $order,
        string $productKey,
        array &$linksByProductKey,
    ): ?array {
        if (isset($linksByProductKey[$productKey])) {
            $existing = $linksByProductKey[$productKey];
            if (($existing['ambiguous'] ?? false) === true) {
                return null;
            }

            $existingId = (int) ($existing['id'] ?? 0);
            if ($existingId <= 0) {
                return null;
            }

            return [
                'id' => $existingId,
                'user_id' => (int) ($existing['user_id'] ?? $connection->user_id),
                'campaign_id' => isset($existing['campaign_id']) ? (int) $existing['campaign_id'] : null,
            ];
        }

        $destinationUrl = $this->resolveOrderDestinationUrl($order, $productKey);
        if ($destinationUrl === null) {
            return null;
        }

        $meta = [
            'auto_generated' => true,
            'auto_generated_from' => 'order_sync',
            'offer_shop_id' => (string) ($order['shop_id'] ?? ''),
            'offer_item_id' => (string) ($order['product_id'] ?? ''),
            'offer_item_name' => (string) ($order['product_name'] ?? ''),
            'origin_order_code' => (string) ($order['order_code'] ?? ''),
            'identity_key' => "shopee:product:{$productKey}",
        ];

        $existingLink = TrackingLink::query()
            ->where('user_id', $connection->user_id)
            ->where('platform', $connection->platform)
            ->where('platform_connection_id', $connection->id)
            ->where('destination_url', $destinationUrl)
            ->first(['id', 'user_id', 'campaign_id', 'destination_url', 'meta']);

        if (! $existingLink) {
            for ($attempt = 0; $attempt < 5; $attempt++) {
                try {
                    $existingLink = TrackingLink::query()->create([
                        'user_id' => $connection->user_id,
                        'platform_connection_id' => $connection->id,
                        'campaign_id' => null,
                        'short_code' => Str::lower(Str::random(8)),
                        'destination_url' => $destinationUrl,
                        'platform' => $connection->platform,
                        'meta' => $meta,
                        'source' => 'auto_order_sync',
                        'channel' => 'shopee',
                        'sub_id' => null,
                        'status' => 'active',
                        'clicks_count' => 0,
                        'orders_count' => 0,
                    ]);
                    break;
                } catch (QueryException $exception) {
                    // Retry only when generated short code collided.
                    if (! str_contains(mb_strtolower($exception->getMessage()), 'short_code')) {
                        throw $exception;
                    }
                }
            }
        }

        if (! $existingLink) {
            return null;
        }

        $resolvedProductKey = TrackingLinkIdentity::extractShopeeProductKey(
            $existingLink->destination_url,
            is_array($existingLink->meta) ? $existingLink->meta : null,
        ) ?? $productKey;

        $payload = [
            'id' => (int) $existingLink->id,
            'user_id' => (int) $existingLink->user_id,
            'campaign_id' => $existingLink->campaign_id !== null ? (int) $existingLink->campaign_id : null,
            'priority' => 2,
        ];

        $linksByProductKey[$resolvedProductKey] = $payload;
        if ($resolvedProductKey !== $productKey) {
            $linksByProductKey[$productKey] = $payload;
        }

        return $payload;
    }

    /**
     * @param  array<string, mixed>  $order
     */
    private function resolveOrderDestinationUrl(array $order, string $productKey): ?string
    {
        $productLink = trim((string) ($order['product_link'] ?? ''));
        if ($productLink !== '' && preg_match('/^https?:\/\//i', $productLink) === 1) {
            return $productLink;
        }

        [$shopId, $productId] = array_pad(explode(':', $productKey, 2), 2, '');
        $shopId = trim((string) $shopId);
        $productId = trim((string) $productId);

        if ($shopId === '' || $productId === '') {
            return null;
        }

        return "https://shopee.vn/product/{$shopId}/{$productId}";
    }

    private function normalizeSubId(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $subId = trim((string) $value);
        if ($subId === '') {
            return null;
        }

        $normalized = mb_strtolower($subId);
        $invalidTokens = ['-', '--', '---', '----', 'n/a', 'na', 'none', 'null', '(not set)', 'undefined'];
        if (in_array($normalized, $invalidTokens, true)) {
            return null;
        }

        return $subId;
    }

    /**
     * @return list<int>
     */
    private function resolveScopeIds(User $user): array
    {
        if ($user->isOwner()) {
            return array_merge([$user->id], $this->userRepository->getDescendantIds($user->id));
        }

        if ($user->isLeader()) {
            return array_merge([$user->id], $this->userRepository->getDirectChildIds($user->id));
        }

        return [$user->id];
    }
}
