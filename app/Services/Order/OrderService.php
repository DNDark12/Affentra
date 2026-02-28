<?php

declare(strict_types=1);

namespace App\Services\Order;

use App\Contracts\Repositories\OrderRepositoryInterface;
use App\Contracts\Repositories\SyncRunRepositoryInterface;
use App\Contracts\Repositories\UserRepositoryInterface;
use App\Jobs\Order\AggregateDailyStatsJob;
use App\Jobs\Order\ImportOrdersJob;
use App\Models\Order;
use App\Models\PlatformConnection;
use App\Models\SyncRun;
use App\Models\TrackingLink;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Http\UploadedFile;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class OrderService
{
    public function __construct(
        private readonly OrderRepositoryInterface $orderRepository,
        private readonly UserRepositoryInterface $userRepository,
        private readonly SyncRunRepositoryInterface $syncRunRepository,
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
     * @return array{upserted: int, failed: int, affected_partitions: list<array{date: string, user_id: int, platform: string}>}
     */
    public function upsertFromApiSync(PlatformConnection $connection, array $orders): array
    {
        $chunkSize = config('integrations.sync.upsert_chunk_size', 500);
        $totalUpserted = 0;
        $totalFailed = 0;
        $affectedPartitions = [];

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
                    $linksByProductKey,
                    &$totalUpserted,
                    &$affectedPartitions,
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
                )->onQueue('sync');
            }
        }

        return [
            'upserted'            => $totalUpserted,
            'failed'              => $totalFailed,
            'affected_partitions' => $affectedPartitions,
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
            ->get(['id', 'user_id', 'campaign_id', 'destination_url', 'meta']);

        foreach ($links as $link) {
            $productKey = $this->extractShopProductKeyFromUrl($link->destination_url);
            if ($productKey === null && is_array($link->meta)) {
                $shopId = trim((string) ($link->meta['offer_shop_id'] ?? ''));
                $itemId = trim((string) ($link->meta['offer_item_id'] ?? ''));
                if ($shopId !== '' && $itemId !== '') {
                    $productKey = "{$shopId}:{$itemId}";
                }
            }
            if ($productKey === null) {
                continue;
            }

            if (
                isset($map[$productKey])
                && (($map[$productKey]['ambiguous'] ?? false) === true
                    || ($map[$productKey]['id'] ?? null) !== $link->id)
            ) {
                $ambiguousKeys[$productKey] = true;
                $map[$productKey] = ['ambiguous' => true];
                continue;
            }

            if (isset($ambiguousKeys[$productKey])) {
                $map[$productKey] = ['ambiguous' => true];
                continue;
            }

            $map[$productKey] = [
                'id' => $link->id,
                'user_id' => $link->user_id,
                'campaign_id' => $link->campaign_id,
            ];
        }

        return $map;
    }

    private function buildOrderProductKey(array $order): ?string
    {
        $shopId = trim((string) ($order['shop_id'] ?? ''));
        $productId = trim((string) ($order['product_id'] ?? ''));

        if ($shopId === '' || $productId === '') {
            return null;
        }

        return "{$shopId}:{$productId}";
    }

    private function extractShopProductKeyFromUrl(?string $destinationUrl): ?string
    {
        if ($destinationUrl === null || trim($destinationUrl) === '') {
            return null;
        }

        $url = trim($destinationUrl);
        $path = parse_url($url, PHP_URL_PATH) ?: '';

        if (preg_match('~/(?:product|i)/(?:[^/]+/)?(\d+)/(\d+)~', $path, $matches) === 1) {
            return "{$matches[1]}:{$matches[2]}";
        }

        if (preg_match('~/product/(\d+)/(\d+)~', $url, $matches) === 1) {
            return "{$matches[1]}:{$matches[2]}";
        }

        parse_str((string) parse_url($url, PHP_URL_QUERY), $query);
        $shopId = trim((string) ($query['shopid'] ?? $query['shop_id'] ?? ''));
        $itemId = trim((string) ($query['itemid'] ?? $query['item_id'] ?? ''));
        if ($shopId !== '' && $itemId !== '') {
            return "{$shopId}:{$itemId}";
        }

        return null;
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
