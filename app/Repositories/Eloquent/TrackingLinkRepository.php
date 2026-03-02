<?php

declare(strict_types=1);

namespace App\Repositories\Eloquent;

use App\Contracts\Repositories\TrackingLinkRepositoryInterface;
use App\Enums\LinkStatus;
use App\Models\TrackingLink;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Facades\DB;
use Prettus\Repository\Criteria\RequestCriteria;
use Prettus\Repository\Eloquent\BaseRepository;

class TrackingLinkRepository extends BaseRepository implements TrackingLinkRepositoryInterface
{
    public function model(): string
    {
        return TrackingLink::class;
    }

    public function boot(): void
    {
        $this->pushCriteria(app(RequestCriteria::class));
    }

    public function findByShortCode(string $shortCode): ?TrackingLink
    {
        /** @var TrackingLink|null */
        return $this->model->newQuery()
            ->where('short_code', $shortCode)
            ->where('status', LinkStatus::Active)
            ->first();
    }

    public function existsByShortCode(string $shortCode): bool
    {
        return $this->model->newQuery()
            ->where('short_code', $shortCode)
            ->exists();
    }

    public function incrementClicksCount(int $linkId): void
    {
        $this->model->newQuery()
            ->where('id', $linkId)
            ->increment('clicks_count');
    }

    /**
     * @param  list<int>  $linkIds
     */
    public function recomputeClicksCountBulk(array $linkIds): int
    {
        $ids = array_values(array_unique(array_map('intval', array_filter($linkIds, static fn ($id): bool => (int) $id > 0))));
        if ($ids === []) {
            return 0;
        }

        $placeholders = implode(', ', array_fill(0, count($ids), '?'));
        $driver = DB::getDriverName();

        if (in_array($driver, ['mysql', 'mariadb'], true)) {
            $sql = <<<SQL
UPDATE tracking_links tl
LEFT JOIN (
    SELECT tracking_link_id, COUNT(*) as cnt
    FROM clicks
    WHERE tracking_link_id IN ({$placeholders})
    GROUP BY tracking_link_id
) c ON c.tracking_link_id = tl.id
SET tl.clicks_count = COALESCE(c.cnt, 0)
WHERE tl.id IN ({$placeholders})
SQL;

            return DB::update($sql, array_merge($ids, $ids));
        }

        // SQLite / PostgreSQL fallback with a single atomic UPDATE statement.
        $sql = <<<SQL
UPDATE tracking_links
SET clicks_count = COALESCE(
    (
        SELECT COUNT(*)
        FROM clicks
        WHERE clicks.tracking_link_id = tracking_links.id
    ),
    0
)
WHERE id IN ({$placeholders})
SQL;

        return DB::update($sql, $ids);
    }

    /**
     * @param  list<int>  $linkIds
     */
    public function recomputeOrdersCountBulk(array $linkIds): int
    {
        $ids = array_values(array_unique(array_map('intval', array_filter($linkIds, static fn ($id): bool => (int) $id > 0))));
        if ($ids === []) {
            return 0;
        }

        $placeholders = implode(', ', array_fill(0, count($ids), '?'));
        $driver = DB::getDriverName();

        if (in_array($driver, ['mysql', 'mariadb'], true)) {
            $sql = <<<SQL
UPDATE tracking_links tl
LEFT JOIN (
    SELECT tracking_link_id, COUNT(*) as cnt
    FROM orders
    WHERE tracking_link_id IN ({$placeholders})
    GROUP BY tracking_link_id
) o ON o.tracking_link_id = tl.id
SET tl.orders_count = COALESCE(o.cnt, 0)
WHERE tl.id IN ({$placeholders})
SQL;

            return DB::update($sql, array_merge($ids, $ids));
        }

        // SQLite / PostgreSQL fallback with a single atomic UPDATE statement.
        $sql = <<<SQL
UPDATE tracking_links
SET orders_count = COALESCE(
    (
        SELECT COUNT(*)
        FROM orders
        WHERE orders.tracking_link_id = tracking_links.id
    ),
    0
)
WHERE id IN ({$placeholders})
SQL;

        return DB::update($sql, $ids);
    }

    public function listForScope(?array $scopeUserIds, array $filters = []): LengthAwarePaginator
    {
        $useRangeMetrics = $this->hasDateRangeFilter($filters);
        $query = $this->baseScopeQuery($scopeUserIds);
        $metricsSub = $this->buildMetricsSubQuery($scopeUserIds, $filters);

        $query->leftJoinSub($metricsSub, 'metrics', static function ($join): void {
            $join->on('tracking_links.id', '=', 'metrics.tracking_link_id');
        });

        $this->applyFilters($query, $filters, $useRangeMetrics);

        $sortBy = (string) ($filters['sort'] ?? 'created_at');
        $direction = strtolower((string) ($filters['direction'] ?? 'desc')) === 'asc' ? 'asc' : 'desc';

        $allowedSorts = ['created_at', 'clicks_count', 'orders_count'];
        if (! in_array($sortBy, $allowedSorts, true)) {
            $sortBy = 'created_at';
        }

        $query->select('tracking_links.*')
            ->selectRaw(
                $useRangeMetrics
                    ? 'COALESCE(metrics.metric_clicks, 0) as metrics_clicks'
                    : 'COALESCE(metrics.metric_clicks, tracking_links.clicks_count) as metrics_clicks'
            )
            ->selectRaw(
                $useRangeMetrics
                    ? 'COALESCE(metrics.metric_orders, 0) as metrics_orders'
                    : 'COALESCE(metrics.metric_orders, tracking_links.orders_count) as metrics_orders'
            )
            ->selectRaw('COALESCE(metrics.metric_approved, 0) as metrics_approved')
            ->selectRaw('COALESCE(metrics.metric_commission, 0) as metrics_commission');

        if ($sortBy === 'clicks_count') {
            $query->orderByRaw(($useRangeMetrics ? 'COALESCE(metrics.metric_clicks, 0)' : 'COALESCE(metrics.metric_clicks, tracking_links.clicks_count)') . " {$direction}");
        } elseif ($sortBy === 'orders_count') {
            $query->orderByRaw(($useRangeMetrics ? 'COALESCE(metrics.metric_orders, 0)' : 'COALESCE(metrics.metric_orders, tracking_links.orders_count)') . " {$direction}");
        } else {
            $query->orderBy('tracking_links.created_at', $direction);
        }

        $query->orderByDesc('tracking_links.id');

        $perPage = min(max((int) ($filters['per_page'] ?? 15), 10), 100);

        return $query->paginate($perPage)->withQueryString();
    }

    public function findByIdForScope(int $id, ?array $scopeUserIds): ?TrackingLink
    {
        $query = $this->baseScopeQuery($scopeUserIds);

        /** @var TrackingLink|null */
        return $query->where('id', $id)->first();
    }

    public function aggregateCountersForScope(?array $scopeUserIds, array $filters = []): array
    {
        $useRangeMetrics = $this->hasDateRangeFilter($filters);
        $query = $this->baseScopeQuery($scopeUserIds, withRelations: false);
        $metricsSub = $this->buildMetricsSubQuery($scopeUserIds, $filters);

        $query->leftJoinSub($metricsSub, 'metrics', static function ($join): void {
            $join->on('tracking_links.id', '=', 'metrics.tracking_link_id');
        });

        $this->applyFilters($query, $filters, $useRangeMetrics);

        $clicksExpr = $useRangeMetrics
            ? 'COALESCE(metrics.metric_clicks, 0)'
            : 'COALESCE(metrics.metric_clicks, tracking_links.clicks_count)';
        $ordersExpr = $useRangeMetrics
            ? 'COALESCE(metrics.metric_orders, 0)'
            : 'COALESCE(metrics.metric_orders, tracking_links.orders_count)';

        $row = $query
            ->selectRaw("COALESCE(SUM({$clicksExpr}), 0) as total_clicks")
            ->selectRaw("COALESCE(SUM({$ordersExpr}), 0) as total_orders")
            ->selectRaw('COALESCE(SUM(COALESCE(metrics.metric_approved, 0)), 0) as total_approved')
            ->selectRaw('COALESCE(SUM(COALESCE(metrics.metric_commission, 0)), 0) as total_commission')
            ->first();

        return [
            'total_clicks' => (int) ($row?->total_clicks ?? 0),
            'total_orders' => (int) ($row?->total_orders ?? 0),
            'total_approved' => (int) ($row?->total_approved ?? 0),
            'total_commission' => round((float) ($row?->total_commission ?? 0), 2),
        ];
    }

    public function attributionGapSummaryForScope(?array $scopeUserIds, array $filters = []): array
    {
        $clickQuery = DB::table('clicks')
            ->where('attribution_status', 'unattributed');

        if ($scopeUserIds !== null) {
            $clickQuery->whereIn('owner_id', $scopeUserIds);
        }

        if (! empty($filters['date_from'])) {
            $clickQuery->whereDate('created_at', '>=', (string) $filters['date_from']);
        }

        if (! empty($filters['date_to'])) {
            $clickQuery->whereDate('created_at', '<=', (string) $filters['date_to']);
        }

        $unattributedClicks = (clone $clickQuery)->count();
        $clickReasons = $this->aggregateJsonReasonCounts(
            query: $clickQuery,
            jsonColumn: 'source_meta',
            path: '$.attribution_source',
            limit: 5,
        );

        $orderQuery = DB::table('orders')
            ->whereNull('tracking_link_id');

        if ($scopeUserIds !== null) {
            $orderQuery->whereIn('user_id', $scopeUserIds);
        }

        if (! empty($filters['date_from'])) {
            $orderQuery->whereDate(DB::raw('COALESCE(ordered_at, created_at)'), '>=', (string) $filters['date_from']);
        }

        if (! empty($filters['date_to'])) {
            $orderQuery->whereDate(DB::raw('COALESCE(ordered_at, created_at)'), '<=', (string) $filters['date_to']);
        }

        $unattributedOrders = (clone $orderQuery)->count();
        $orderReasons = $this->aggregateJsonReasonCounts(
            query: $orderQuery,
            jsonColumn: 'source_meta',
            path: '$.attribution_source',
            limit: 5,
        );

        return [
            'unattributed_clicks' => (int) $unattributedClicks,
            'unattributed_click_reasons' => $clickReasons,
            'unattributed_orders' => (int) $unattributedOrders,
            'unattributed_order_reasons' => $orderReasons,
        ];
    }

    public function createLink(array $data): TrackingLink
    {
        /** @var TrackingLink */
        return $this->model->newQuery()->create($data);
    }

    public function updateLink(int $id, array $data): TrackingLink
    {
        /** @var TrackingLink $link */
        $link = $this->model->newQuery()->findOrFail($id);
        $link->update($data);

        return $link->fresh();
    }

    public function assertStatusTransition(LinkStatus $from, LinkStatus $to): void
    {
        if ($from === $to) {
            return;
        }

        $isAllowed = match ($from) {
            LinkStatus::Active => in_array($to, [LinkStatus::Paused, LinkStatus::Archived], true),
            LinkStatus::Paused => in_array($to, [LinkStatus::Active, LinkStatus::Archived], true),
            LinkStatus::Archived => false,
        };

        if (! $isAllowed) {
            throw new \DomainException("Invalid status transition from {$from->value} to {$to->value}.");
        }
    }

    /**
     * @param  list<int>|null  $scopeUserIds
     * @return Builder<TrackingLink>
     */
    private function baseScopeQuery(?array $scopeUserIds, bool $withRelations = true): Builder
    {
        $query = $this->model->newQuery();

        if ($withRelations) {
            $query->with([
                'campaign:id,name',
                'user:id,name',
            ]);
        }

        if ($scopeUserIds !== null) {
            $query->whereIn('user_id', $scopeUserIds);
        }

        return $query;
    }

    /**
     * @param  Builder<TrackingLink>  $query
     * @param  array<string, mixed>  $filters
     */
    private function applyFilters(Builder $query, array $filters, bool $useRangeMetrics = false): void
    {
        if (! empty($filters['status'])) {
            $statusEnum = LinkStatus::tryFrom((string) $filters['status']);
            if ($statusEnum !== null) {
                $query->where('status', $statusEnum);
            }
        }

        if (! empty($filters['search'])) {
            $term = '%' . trim((string) $filters['search']) . '%';
            $query->where(function (Builder $builder) use ($term): void {
                $builder->where('destination_url', 'LIKE', $term)
                    ->orWhere('short_code', 'LIKE', $term)
                    ->orWhere('source', 'LIKE', $term)
                    ->orWhere('channel', 'LIKE', $term);
            });
        }

        if (! empty($filters['campaign_id'])) {
            $query->where('campaign_id', (int) $filters['campaign_id']);
        }

        if (! empty($filters['preset'])) {
            $preset = (string) $filters['preset'];
            $clicksExpr = $useRangeMetrics
                ? 'COALESCE(metrics.metric_clicks, 0)'
                : 'COALESCE(metrics.metric_clicks, tracking_links.clicks_count)';
            $ordersExpr = $useRangeMetrics
                ? 'COALESCE(metrics.metric_orders, 0)'
                : 'COALESCE(metrics.metric_orders, tracking_links.orders_count)';
            $commissionExpr = 'COALESCE(metrics.metric_commission, 0)';

            if ($preset === 'underperforming') {
                $minClicks = (int) config('tracking.presets.underperforming.min_clicks', 100);
                $maxOrderRate = (float) config('tracking.presets.underperforming.max_order_rate', 0.01);

                $query
                    ->whereRaw("{$clicksExpr} >= ?", [$minClicks])
                    ->whereRaw(
                        "CASE WHEN {$clicksExpr} = 0 THEN 0 ELSE ({$ordersExpr} * 1.0 / {$clicksExpr}) END <= ?",
                        [$maxOrderRate],
                    );
            }

            if ($preset === 'top_performing') {
                $minOrders = (int) config('tracking.presets.top_performing.min_orders', 1);
                $minCommission = (float) config('tracking.presets.top_performing.min_commission', 50000);

                $query
                    ->whereRaw("{$ordersExpr} >= ?", [$minOrders])
                    ->whereRaw("{$commissionExpr} >= ?", [$minCommission]);
            }
        }
    }

    /**
     * @param  list<int>|null  $scopeUserIds
     */
    private function buildMetricsSubQuery(?array $scopeUserIds, array $filters): QueryBuilder
    {
        $sub = DB::table('daily_stats')
            ->selectRaw('tracking_link_id')
            ->selectRaw('COALESCE(SUM(clicks), 0) as metric_clicks')
            ->selectRaw('COALESCE(SUM(orders), 0) as metric_orders')
            ->selectRaw('COALESCE(SUM(approved), 0) as metric_approved')
            ->selectRaw('COALESCE(SUM(commission), 0) as metric_commission')
            ->whereNotNull('tracking_link_id')
            ->groupBy('tracking_link_id');

        if ($scopeUserIds !== null) {
            $sub->whereIn('user_id', $scopeUserIds);
        }

        if (! empty($filters['date_from'])) {
            $sub->whereDate('date', '>=', (string) $filters['date_from']);
        }

        if (! empty($filters['date_to'])) {
            $sub->whereDate('date', '<=', (string) $filters['date_to']);
        }

        return $sub;
    }

    private function hasDateRangeFilter(array $filters): bool
    {
        return ! empty($filters['date_from']) || ! empty($filters['date_to']);
    }

    /**
     * @return list<array{reason:string,label:string,count:int}>
     */
    private function aggregateJsonReasonCounts(
        QueryBuilder $query,
        string $jsonColumn,
        string $path,
        int $limit = 5,
    ): array {
        $driver = DB::getDriverName();
        $rows = [];

        if (in_array($driver, ['mysql', 'mariadb'], true)) {
            $expr = "COALESCE(NULLIF(JSON_UNQUOTE(JSON_EXTRACT({$jsonColumn}, '{$path}')), ''), 'unknown')";
            $rows = (clone $query)
                ->selectRaw("{$expr} as reason, COUNT(*) as total")
                ->groupByRaw($expr)
                ->orderByDesc('total')
                ->limit($limit)
                ->get();
        } else {
            $counts = [];
            foreach ((clone $query)->select($jsonColumn)->cursor() as $row) {
                $raw = $row->{$jsonColumn} ?? null;
                $sourceMeta = is_array($raw) ? $raw : (is_string($raw) ? json_decode($raw, true) : null);
                $reason = is_array($sourceMeta) ? (string) ($sourceMeta['attribution_source'] ?? '') : '';
                $normalized = $reason !== '' ? $reason : 'unknown';
                $counts[$normalized] = ($counts[$normalized] ?? 0) + 1;
            }

            arsort($counts);
            foreach (array_slice($counts, 0, $limit, true) as $reason => $count) {
                $rows[] = (object) ['reason' => (string) $reason, 'total' => (int) $count];
            }
        }

        $result = [];
        foreach ($rows as $row) {
            $reason = (string) ($row->reason ?? 'unknown');
            $result[] = [
                'reason' => $reason,
                'label' => $this->attributionReasonLabel($reason),
                'count' => (int) ($row->total ?? 0),
            ];
        }

        return $result;
    }

    private function attributionReasonLabel(string $reason): string
    {
        return match ($reason) {
            'sub_id' => 'Khớp theo Sub ID',
            'item_id' => 'Khớp theo Item ID',
            'product_key' => 'Khớp theo sản phẩm',
            'auto_link' => 'Tự tạo link từ dữ liệu order',
            'missing_sub_id' => 'Shopee không trả Sub ID',
            'ambiguous_product' => 'Trùng nhiều link cùng sản phẩm',
            'unmatched_product' => 'Không tìm thấy link theo sản phẩm',
            'direct' => 'Không có tín hiệu attribution từ Shopee',
            'none' => 'Shopee không trả Sub ID/Item ID',
            default => 'Không xác định',
        };
    }
}
