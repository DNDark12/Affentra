<?php

declare(strict_types=1);

namespace App\Repositories\Clicks;

use App\DTOs\Clicks\ClickReportFilter;
use App\Models\Click;
use App\Models\DailyStat;
use App\Models\Order;
use App\Models\TrackingLink;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

class ClickAnalyticsRepository
{
    public function getSummary(ClickReportFilter $filter, User $actor): array
    {
        $base = $this->baseDailyStatQuery($filter, $actor);

        $stats = (clone $base)->selectRaw('
            COALESCE(SUM(clicks), 0) as total_clicks,
            COALESCE(SUM(unique_clicks), 0) as unique_clicks,
            COALESCE(SUM(valid_clicks), 0) as valid_clicks,
            COALESCE(SUM(bot_clicks), 0) as bot_clicks,
            COALESCE(SUM(orders), 0) as total_orders,
            COALESCE(SUM(approved), 0) as total_approved,
            COALESCE(SUM(commission), 0) as total_commission
        ')->first();

        $totalClicks = (int) ($stats?->total_clicks ?? 0);
        $totalOrders = (int) ($stats?->total_orders ?? 0);
        $totalApproved = (int) ($stats?->total_approved ?? 0);
        $totalCommission = (float) ($stats?->total_commission ?? 0);

        $cvr = $totalClicks > 0 ? ($totalOrders / $totalClicks) * 100 : 0.0;
        $approvedRate = $totalOrders > 0 ? ($totalApproved / $totalOrders) * 100 : 0.0;
        $epc = $totalClicks > 0 ? ($totalCommission / $totalClicks) : 0.0;

        $trend = (clone $base)
            ->selectRaw('
                date,
                COALESCE(SUM(clicks), 0) as clicks,
                COALESCE(SUM(orders), 0) as orders,
                COALESCE(SUM(approved), 0) as approved,
                COALESCE(SUM(commission), 0) as commission
            ')
            ->groupBy('date')
            ->orderBy('date')
            ->get()
            ->map(static fn ($row): array => [
                'date' => $row->date instanceof Carbon ? $row->date->toDateString() : (string) $row->date,
                'clicks' => (int) $row->clicks,
                'orders' => (int) $row->orders,
                'approved' => (int) $row->approved,
                'commission' => round((float) $row->commission, 2),
            ])
            ->all();

        $campaignBreakdown = (clone $base)
            ->leftJoin('campaigns as cp', 'daily_stats.campaign_id', '=', 'cp.id')
            ->selectRaw('
                daily_stats.campaign_id,
                COALESCE(cp.name, "Unattributed") as campaign_name,
                COALESCE(SUM(daily_stats.clicks), 0) as clicks,
                COALESCE(SUM(daily_stats.orders), 0) as orders,
                COALESCE(SUM(daily_stats.approved), 0) as approved,
                COALESCE(SUM(daily_stats.commission), 0) as commission
            ')
            ->groupBy('daily_stats.campaign_id', 'cp.name')
            ->orderByDesc('clicks')
            ->limit(10)
            ->get()
            ->map(static fn ($row): array => [
                'campaign_id' => $row->campaign_id !== null ? (int) $row->campaign_id : null,
                'campaign_name' => (string) $row->campaign_name,
                'clicks' => (int) $row->clicks,
                'orders' => (int) $row->orders,
                'approved' => (int) $row->approved,
                'commission' => round((float) $row->commission, 2),
            ])
            ->all();

        return [
            'funnel' => [
                'clicks' => $totalClicks,
                'orders' => $totalOrders,
                'approved' => $totalApproved,
            ],
            'totals' => [
                'clicks' => $totalClicks,
                'unique_clicks' => (int) ($stats?->unique_clicks ?? 0),
                'valid_clicks' => (int) ($stats?->valid_clicks ?? 0),
                'bot_clicks' => (int) ($stats?->bot_clicks ?? 0),
                'orders' => $totalOrders,
                'approved' => $totalApproved,
                'commission' => round($totalCommission, 2),
            ],
            'cvr' => round($cvr, 2),
            'approved_rate' => round($approvedRate, 2),
            'epc' => round($epc, 2),
            'trend_by_day' => $trend,
            'campaign_breakdown' => $campaignBreakdown,
        ];
    }

    public function getReportData(ClickReportFilter $filter, User $actor): LengthAwarePaginator
    {
        $query = Click::query();

        $this->scopeClickQueryByActor($query, $actor);

        if ($filter->dateFrom) {
            $query->where('created_at', '>=', $filter->dateFrom);
        }
        if ($filter->dateTo) {
            $query->where('created_at', '<=', $filter->dateTo);
        }
        if ($filter->trackingLinkId) {
            $query->where('tracking_link_id', $filter->trackingLinkId);
        }
        if ($filter->utmSources) {
            $query->whereIn('utm_source', $filter->utmSources);
        }
        if ($filter->utmCampaigns) {
            $query->whereIn('utm_campaign', $filter->utmCampaigns);
        }
        if ($filter->devices) {
            $query->whereIn('device_type', $filter->devices);
        }
        if ($filter->isBot !== null) {
            $query->where('is_bot', $filter->isBot);
        }
        if ($filter->searchQuery) {
            $query->where(function ($q) use ($filter): void {
                $q->where('utm_source', 'like', "%{$filter->searchQuery}%")
                  ->orWhere('utm_campaign', 'like', "%{$filter->searchQuery}%");
            });
        }

        // Map allowed group_by values to actual SQL selection and grouping logic
        $groupMap = [
            'date' => [
                'select' => 'DATE(created_at) as grouped_by',
                'group'  => 'DATE(created_at)'
            ],
            'tracking_link_id' => [
                'select' => 'tracking_link_id as grouped_by',
                'group'  => 'tracking_link_id',
                'with'   => ['trackingLink.campaign'] // Needs eager load mapping manually in UI/Resource
            ],
            'utm_source' => [
                'select' => 'COALESCE(utm_source, "Unknown") as grouped_by',
                'group'  => 'utm_source'
            ],
            'utm_campaign' => [
                'select' => 'COALESCE(utm_campaign, "Unknown") as grouped_by',
                'group'  => 'utm_campaign'
            ],
            'device_type' => [
                'select' => 'COALESCE(device_type, "Unknown") as grouped_by',
                'group'  => 'device_type'
            ],
        ];

        $groupConfig = $groupMap[$filter->groupBy] ?? $groupMap['date'];

        $query->selectRaw("
            {$groupConfig['select']},
            COUNT(*) as total_clicks,
            SUM(CASE WHEN is_bot = 1 THEN 1 ELSE 0 END) as bot_clicks,
            COUNT(DISTINCT ip) as unique_ips
        ");

        $query->groupByRaw($groupConfig['group']);

        if (isset($groupConfig['with'])) {
            $query->with($groupConfig['with']);
        }

        // Note: Sort fields from filter must map to the grouped aggregation aliases or specific group column
        $allowedSorts = ['grouped_by', 'total_clicks', 'bot_clicks', 'unique_ips'];
        $sort = in_array($filter->sort, $allowedSorts, true) ? $filter->sort : 'total_clicks';
        $direction = strtolower($filter->direction) === 'asc' ? 'asc' : 'desc';

        return $query->orderBy($sort, $direction)
            ->paginate($filter->perPage, ['*'], 'page', $filter->page);
    }

    public function getConversionData(ClickReportFilter $filter, User $actor): LengthAwarePaginator
    {
        $query = Order::query()
            ->with(['trackingLink.campaign', 'user']);

        if ($actor->isLeader()) {
            $query->whereHas('user', function ($q) use ($actor): void {
                $q->where('id', $actor->id)
                    ->orWhere('parent_id', $actor->id);
            });
        } elseif ($actor->isPartner()) {
            $query->where('user_id', $actor->id);
        }

        if ($filter->dateFrom) {
            $query->where('ordered_at', '>=', $filter->dateFrom);
        }
        if ($filter->dateTo) {
            $query->where('ordered_at', '<=', $filter->dateTo);
        }
        if ($filter->trackingLinkId) {
            $query->where('tracking_link_id', $filter->trackingLinkId);
        }
        if ($filter->campaignId) {
            $query->whereHas('trackingLink', function ($q) use ($filter): void {
                $q->where('campaign_id', $filter->campaignId);
            });
        }
        if ($filter->searchQuery) {
            $query->where(function ($q) use ($filter): void {
                $q->where('order_code', 'like', "%{$filter->searchQuery}%")
                    ->orWhere('external_order_id', 'like', "%{$filter->searchQuery}%");
            });
        }

        $sortMap = [
            'created_at' => 'ordered_at',
            'ordered_at' => 'ordered_at',
            'approved_at' => 'approved_at',
            'commission' => 'commission',
            'order_amount' => 'order_amount',
            'status' => 'status',
        ];
        $sortField = $sortMap[$filter->sort] ?? 'ordered_at';
        $direction = strtolower($filter->direction) === 'asc' ? 'asc' : 'desc';

        return $query->orderBy($sortField, $direction)
            ->paginate($filter->perPage, ['*'], 'page', $filter->page);
    }

    /**
     * @param  list<string>  $subIds
     * @return Collection<string, TrackingLink>
     */
    public function resolveLinksBySubIds(array $subIds, ?int $platformConnectionId = null): Collection
    {
        if ($subIds === []) {
            return collect();
        }

        $query = TrackingLink::with('user.parent')
            ->whereIn('sub_id', $subIds);

        if ($platformConnectionId !== null) {
            $query->where('platform_connection_id', $platformConnectionId);
        }

        return $query->get()->keyBy('sub_id');
    }

    public function cleanupSyncedClicks(int $connectionId, Carbon $since, Carbon $until, string $source = 'shopee_sync'): int
    {
        return Click::query()
            ->where('referer_domain', $source)
            ->where('connection_id', $connectionId)
            ->whereBetween('created_at', [$since, $until])
            ->delete();
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     */
    public function bulkInsertSyncedClicks(array $rows, int $chunkSize = 500): int
    {
        if ($rows === []) {
            return 0;
        }

        $inserted = 0;
        foreach (array_chunk($rows, $chunkSize) as $chunk) {
            Click::insert($chunk);
            $inserted += count($chunk);
        }

        return $inserted;
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     * @return array{min_date: string|null, max_date: string|null, affected_link_ids: list<int>}
     */
    public function summarizeAffectedWindow(array $rows): array
    {
        if ($rows === []) {
            return [
                'min_date' => null,
                'max_date' => null,
                'affected_link_ids' => [],
            ];
        }

        $minDate = null;
        $maxDate = null;
        $linkIds = [];

        foreach ($rows as $row) {
            $date = Carbon::parse((string) $row['created_at'])->toDateString();

            if ($minDate === null || $date < $minDate) {
                $minDate = $date;
            }

            if ($maxDate === null || $date > $maxDate) {
                $maxDate = $date;
            }

            if (isset($row['tracking_link_id']) && $row['tracking_link_id'] !== null) {
                $linkIds[(int) $row['tracking_link_id']] = true;
            }
        }

        return [
            'min_date' => $minDate,
            'max_date' => $maxDate,
            'affected_link_ids' => array_map('intval', array_keys($linkIds)),
        ];
    }

    private function baseDailyStatQuery(ClickReportFilter $filter, User $actor): Builder
    {
        $query = DailyStat::query();

        if ($actor->isLeader()) {
            $query->where('leader_id', $actor->id);
        } elseif ($actor->isPartner()) {
            $query->where('partner_user_id', $actor->id);
        }

        if ($filter->dateFrom) {
            $query->where('date', '>=', $filter->dateFrom);
        }
        if ($filter->dateTo) {
            $query->where('date', '<=', $filter->dateTo);
        }
        if ($filter->campaignId) {
            $query->where('campaign_id', $filter->campaignId);
        }
        if ($filter->trackingLinkId) {
            $query->where('tracking_link_id', $filter->trackingLinkId);
        }

        return $query;
    }

    private function scopeClickQueryByActor(Builder $query, User $actor): void
    {
        if ($actor->isLeader()) {
            $query->where('leader_id', $actor->id);
        } elseif ($actor->isPartner()) {
            $query->where('partner_user_id', $actor->id);
        }
    }
}
