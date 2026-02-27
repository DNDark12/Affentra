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

            if ($preset === 'underperforming') {
                $query->whereRaw("{$clicksExpr} > 0")->whereRaw("{$ordersExpr} = 0");
            }

            if ($preset === 'top_performing') {
                $query->whereRaw("{$ordersExpr} > 0");
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
}
