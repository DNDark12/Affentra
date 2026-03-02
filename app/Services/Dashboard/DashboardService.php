<?php

declare(strict_types=1);

namespace App\Services\Dashboard;

use App\Contracts\Repositories\DailyStatRepositoryInterface;
use App\Models\User;
use App\Services\Scope\ScopeResolver;


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
     *   daily: \Illuminate\Support\Collection,
     *   scope_user_ids: list<int>|null
     * }
     */
    public function getSummary(User $user, string $period = '30days'): array
    {
        $userIds = $this->scopeResolver->resolveVisibleUserIds($user);

        // SQL-aggregated KPI totals — no PHP-side sum()
        $kpi   = $this->dailyStatRepository->getKpiTotals($userIds, $period);
        $daily = $this->dailyStatRepository->getDailySeries($userIds, $period);

        return [
            'period'         => $period,
            'clicks'         => $kpi['clicks'],
            'orders'         => $kpi['orders'],
            'approved'       => $kpi['approved'],
            'commission'     => $kpi['commission'],
            'daily'          => $daily,
            'scope_user_ids' => $userIds,
        ];
    }
}
