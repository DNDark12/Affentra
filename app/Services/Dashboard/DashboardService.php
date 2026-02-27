<?php

declare(strict_types=1);

namespace App\Services\Dashboard;

use App\Contracts\Repositories\DailyStatRepositoryInterface;
use App\Contracts\Repositories\UserRepositoryInterface;
use App\Models\User;


class DashboardService
{
    public function __construct(
        private readonly DailyStatRepositoryInterface $dailyStatRepository,
        private readonly UserRepositoryInterface $userRepository,
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
     *   scope_user_ids: list<int>
     * }
     */
    public function getSummary(User $user, string $period = '30days'): array
    {
        $userIds = $this->resolveScopeIds($user);

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

    /**
     * Resolve which user IDs are in scope for this user's role.
     *
     * @return list<int>
     */
    private function resolveScopeIds(User $user): array
    {
        if ($user->isOwner()) {
            // Owner sees entire tree
            return array_merge([$user->id], $this->userRepository->getDescendantIds($user->id));
        }

        if ($user->isLeader()) {
            // Leader sees self + direct CTVs — push filter to DB, avoid in-memory scan
            $directIds = $this->userRepository->getDirectChildIds($user->id);

            return array_merge([$user->id], $directIds);
        }

        // CTV sees only themselves
        return [$user->id];
    }
}
