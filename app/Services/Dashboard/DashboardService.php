<?php

declare(strict_types=1);

namespace App\Services\Dashboard;

use App\Models\AlertIncident;
use App\Models\Campaign;
use App\Models\Click;
use App\Models\Order;
use App\Models\TrackingLink;
use App\Contracts\Repositories\DailyStatRepositoryInterface;
use App\Models\User;
use App\Services\Scope\ScopeResolver;
use Carbon\CarbonImmutable;
use Carbon\CarbonPeriod;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;


class DashboardService
{
    public function __construct(
        private readonly DailyStatRepositoryInterface $dailyStatRepository,
        private readonly ScopeResolver $scopeResolver,
    ) {}

    /**
     * Get KPI summary for the dashboard, respecting role scope.
     *
     * @return array{
     *   period: string,
     *   clicks: int,
     *   orders: int,
     *   approved: int,
     *   commission: float,
     *   active_links: int,
     *   active_campaigns: int,
     *   unattributed_orders: int,
     *   pending_commission: float,
     *   paid_commission: float,
     *   open_alerts_count: int,
     *   unseen_alerts_count: int,
     *   alerts: list<array{id:int,message:string,severity:string,created_at:string,status:string}>,
     *   daily: list<array{date:string,clicks:int,orders:int,approved:int,commission:float}>,
     *   scope_user_ids: list<int>|null
     * }
     */
    public function getSummary(User $user, string $period = '30days'): array
    {
        $userIds = $this->scopeResolver->resolveVisibleUserIds($user);
        $cacheTtl = max(0, (int) config('dashboard.cache_ttl_seconds', 120));

        if ($cacheTtl === 0) {
            return $this->buildSummary($period, $userIds);
        }

        $scopeHash = $userIds === null
            ? 'all'
            : sha1(implode(',', array_map('strval', $userIds)));
        $cacheKey = sprintf(
            'dashboard:summary:v1:user:%d:period:%s:scope:%s',
            $user->id,
            $period,
            $scopeHash,
        );

        return Cache::remember(
            $cacheKey,
            now()->addSeconds($cacheTtl),
            fn (): array => $this->buildSummary($period, $userIds),
        );
    }

    /**
     * @param  list<int>|null  $userIds
     * @return array{
     *   period: string,
     *   clicks: int,
     *   orders: int,
     *   approved: int,
     *   commission: float,
     *   active_links: int,
     *   active_campaigns: int,
     *   unattributed_orders: int,
     *   pending_commission: float,
     *   paid_commission: float,
     *   open_alerts_count: int,
     *   unseen_alerts_count: int,
     *   alerts: list<array{id:int,message:string,severity:string,created_at:string,status:string}>,
     *   daily: list<array{date:string,clicks:int,orders:int,approved:int,commission:float}>,
     *   scope_user_ids: list<int>|null
     * }
     */
    private function buildSummary(string $period, ?array $userIds): array
    {
        $from = $this->resolvePeriodStart($period);
        $until = CarbonImmutable::now()->endOfDay();

        $dailyFromStats = $this->dailyStatRepository->getDailySeries($userIds, $period);
        $kpiFromStats = $this->dailyStatRepository->getKpiTotals($userIds, $period);

        // Fallback to raw event tables when daily_stats has not been aggregated yet.
        if ($this->shouldFallbackToRaw($dailyFromStats, $kpiFromStats)) {
            $daily = $this->buildDailySeriesFromRaw($userIds, $from, $until);
            $kpi = $this->totalsFromDaily($daily);
        } else {
            $daily = $this->fillMissingDatesFromStats($dailyFromStats, $from, $until);
            $kpi = [
                'clicks' => (int) ($kpiFromStats['clicks'] ?? 0),
                'orders' => (int) ($kpiFromStats['orders'] ?? 0),
                'approved' => (int) ($kpiFromStats['approved'] ?? 0),
                'commission' => (float) ($kpiFromStats['commission'] ?? 0.0),
            ];
        }

        $ordersBaseQuery = $this->scopeOrdersQuery($userIds)
            ->whereBetween('ordered_at', [$from->startOfDay(), $until]);

        $alertsBaseQuery = $this->scopeAlertIncidentsQuery($userIds)
            ->whereNull('resolved_at');

        $alerts = $alertsBaseQuery
            ->latest('id')
            ->limit(5)
            ->get(['id', 'message', 'seen_at', 'created_at'])
            ->map(static function (AlertIncident $incident): array {
                return [
                    'id' => (int) $incident->id,
                    'message' => (string) $incident->message,
                    'severity' => $incident->seen_at ? 'warning' : 'danger',
                    'created_at' => $incident->created_at?->toIso8601String() ?? now()->toIso8601String(),
                    'status' => $incident->seen_at ? 'Đang mở' : 'Chưa xem',
                ];
            })
            ->values()
            ->all();

        return [
            'period'         => $period,
            'clicks'         => (int) $kpi['clicks'],
            'orders'         => (int) $kpi['orders'],
            'approved'       => (int) $kpi['approved'],
            'commission'     => (float) $kpi['commission'],
            'active_links'   => $this->scopeTrackingLinksQuery($userIds)->where('status', 'active')->count(),
            'active_campaigns' => $this->scopeCampaignsQuery($userIds)->where('status', 'active')->count(),
            'unattributed_orders' => (clone $ordersBaseQuery)->whereNull('tracking_link_id')->count(),
            'pending_commission' => (float) (clone $ordersBaseQuery)
                ->whereIn('payout_status', ['unpaid', 'processing'])
                ->sum('commission'),
            'paid_commission' => (float) (clone $ordersBaseQuery)
                ->where('payout_status', 'paid')
                ->sum('commission'),
            'open_alerts_count' => (clone $alertsBaseQuery)->count(),
            'unseen_alerts_count' => (clone $alertsBaseQuery)->whereNull('seen_at')->count(),
            'alerts' => $alerts,
            'daily' => $daily->values()->all(),
            'scope_user_ids' => $userIds,
        ];
    }

    /**
     * @param  Collection<int, mixed>  $dailyFromStats
     * @param  array<string, mixed>  $kpiFromStats
     */
    private function shouldFallbackToRaw(Collection $dailyFromStats, array $kpiFromStats): bool
    {
        if ($dailyFromStats->isNotEmpty()) {
            return false;
        }

        return ((int) ($kpiFromStats['clicks'] ?? 0) === 0)
            && ((int) ($kpiFromStats['orders'] ?? 0) === 0)
            && ((int) ($kpiFromStats['approved'] ?? 0) === 0)
            && ((float) ($kpiFromStats['commission'] ?? 0.0) === 0.0);
    }

    /**
     * @param  Collection<int, mixed>  $dailyFromStats
     * @return Collection<int, array{date:string,clicks:int,orders:int,approved:int,commission:float}>
     */
    private function fillMissingDatesFromStats(Collection $dailyFromStats, CarbonImmutable $from, CarbonImmutable $until): Collection
    {
        $rowsByDate = $dailyFromStats
            ->mapWithKeys(static function ($row): array {
                $dateValue = data_get($row, 'date');
                $date = is_string($dateValue)
                    ? $dateValue
                    : CarbonImmutable::parse((string) $dateValue)->toDateString();

                return [$date => [
                    'date' => $date,
                    'clicks' => (int) data_get($row, 'clicks', 0),
                    'orders' => (int) data_get($row, 'orders', 0),
                    'approved' => (int) data_get($row, 'approved', 0),
                    'commission' => (float) data_get($row, 'commission', 0),
                ]];
            });

        $series = collect();
        foreach (CarbonPeriod::create($from->startOfDay(), '1 day', $until->startOfDay()) as $date) {
            $key = CarbonImmutable::parse($date)->toDateString();
            $series->push($rowsByDate->get($key, [
                'date' => $key,
                'clicks' => 0,
                'orders' => 0,
                'approved' => 0,
                'commission' => 0.0,
            ]));
        }

        return $series;
    }

    /**
     * @param  list<int>|null  $userIds
     * @return Collection<int, array{date:string,clicks:int,orders:int,approved:int,commission:float}>
     */
    private function buildDailySeriesFromRaw(?array $userIds, CarbonImmutable $from, CarbonImmutable $until): Collection
    {
        $clicksByDate = $this->scopeClicksQuery($userIds)
            ->whereBetween('clicks.created_at', [$from->startOfDay(), $until])
            ->selectRaw('DATE(clicks.created_at) as event_date, COUNT(*) as total_clicks')
            ->groupBy(DB::raw('DATE(clicks.created_at)'))
            ->pluck('total_clicks', 'event_date');

        $ordersByDate = $this->scopeOrdersQuery($userIds)
            ->whereBetween('ordered_at', [$from->startOfDay(), $until])
            ->selectRaw('DATE(ordered_at) as event_date')
            ->selectRaw('COUNT(*) as total_orders')
            ->selectRaw("SUM(CASE WHEN status = 'approved' THEN 1 ELSE 0 END) as total_approved")
            ->selectRaw("SUM(CASE WHEN status = 'approved' THEN commission ELSE 0 END) as total_commission")
            ->groupBy(DB::raw('DATE(ordered_at)'))
            ->get()
            ->mapWithKeys(static fn ($row): array => [
                (string) $row->event_date => [
                    'orders' => (int) $row->total_orders,
                    'approved' => (int) $row->total_approved,
                    'commission' => (float) $row->total_commission,
                ],
            ]);

        $series = collect();
        foreach (CarbonPeriod::create($from->startOfDay(), '1 day', $until->startOfDay()) as $date) {
            $key = CarbonImmutable::parse($date)->toDateString();
            $orderRow = $ordersByDate->get($key, ['orders' => 0, 'approved' => 0, 'commission' => 0.0]);
            $series->push([
                'date' => $key,
                'clicks' => (int) ($clicksByDate[$key] ?? 0),
                'orders' => (int) ($orderRow['orders'] ?? 0),
                'approved' => (int) ($orderRow['approved'] ?? 0),
                'commission' => (float) ($orderRow['commission'] ?? 0.0),
            ]);
        }

        return $series;
    }

    /**
     * @param  Collection<int, array{date:string,clicks:int,orders:int,approved:int,commission:float}>  $daily
     * @return array{clicks:int,orders:int,approved:int,commission:float}
     */
    private function totalsFromDaily(Collection $daily): array
    {
        return [
            'clicks' => (int) $daily->sum('clicks'),
            'orders' => (int) $daily->sum('orders'),
            'approved' => (int) $daily->sum('approved'),
            'commission' => round((float) $daily->sum('commission'), 2),
        ];
    }

    /**
     * @param  list<int>|null  $userIds
     */
    private function scopeOrdersQuery(?array $userIds): Builder
    {
        $query = Order::query();
        if ($userIds !== null) {
            $query->whereIn('user_id', $userIds);
        }

        return $query;
    }

    /**
     * @param  list<int>|null  $userIds
     */
    private function scopeTrackingLinksQuery(?array $userIds): Builder
    {
        $query = TrackingLink::query();
        if ($userIds !== null) {
            $query->whereIn('user_id', $userIds);
        }

        return $query;
    }

    /**
     * @param  list<int>|null  $userIds
     */
    private function scopeCampaignsQuery(?array $userIds): Builder
    {
        $query = Campaign::query();
        if ($userIds !== null) {
            $query->whereIn('user_id', $userIds);
        }

        return $query;
    }

    /**
     * @param  list<int>|null  $userIds
     */
    private function scopeAlertIncidentsQuery(?array $userIds): Builder
    {
        $query = AlertIncident::query();
        if ($userIds !== null) {
            $query->whereIn('user_id', $userIds);
        }

        return $query;
    }

    /**
     * @param  list<int>|null  $userIds
     */
    private function scopeClicksQuery(?array $userIds): Builder
    {
        $query = Click::query()->leftJoin('tracking_links as tl', 'tl.id', '=', 'clicks.tracking_link_id');
        if ($userIds !== null) {
            $query->where(static function (Builder $builder) use ($userIds): void {
                $builder->whereIn('clicks.partner_user_id', $userIds)
                    ->orWhereIn('tl.user_id', $userIds);
            });
        }

        return $query;
    }

    private function resolvePeriodStart(string $period): CarbonImmutable
    {
        return match ($period) {
            '7days' => CarbonImmutable::now()->subDays(6),
            '90days' => CarbonImmutable::now()->subDays(89),
            default => CarbonImmutable::now()->subDays(29),
        };
    }
}
