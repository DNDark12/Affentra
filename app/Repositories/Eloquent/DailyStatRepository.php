<?php

declare(strict_types=1);

namespace App\Repositories\Eloquent;

use App\Contracts\Repositories\DailyStatRepositoryInterface;
use App\Models\DailyStat;
use Illuminate\Support\Collection;
use Prettus\Repository\Eloquent\BaseRepository;

class DailyStatRepository extends BaseRepository implements DailyStatRepositoryInterface
{
    public function model(): string
    {
        return DailyStat::class;
    }

    /**
     * Get aggregated KPI totals for the dashboard.
     *
     * @param  list<int>  $userIds
     * @return array{clicks: int, orders: int, approved: int, commission: float}
     */
    public function getKpiTotals(array $userIds, string $period = '30days'): array
    {
        $from = $this->resolvePeriodStart($period);

        $result = $this->model->newQuery()
            ->selectRaw('COALESCE(SUM(clicks), 0) as clicks')
            ->selectRaw('COALESCE(SUM(orders), 0) as orders')
            ->selectRaw('COALESCE(SUM(approved), 0) as approved')
            ->selectRaw('COALESCE(SUM(commission), 0) as commission')
            ->whereIn('user_id', $userIds)
            ->where('date', '>=', $from)
            ->first();

        return [
            'clicks'     => (int) $result->clicks,
            'orders'     => (int) $result->orders,
            'approved'   => (int) $result->approved,
            'commission' => (float) $result->commission,
        ];
    }

    /**
     * Get daily series data for charts (grouped by date, aggregated via SQL).
     *
     * @param  list<int>  $userIds
     * @return Collection<int, DailyStat>
     */
    public function getDailySeries(array $userIds, string $period = '30days'): Collection
    {
        $from = $this->resolvePeriodStart($period);

        return $this->model->newQuery()
            ->selectRaw('date, SUM(clicks) as clicks, SUM(orders) as orders, SUM(approved) as approved, SUM(commission) as commission')
            ->whereIn('user_id', $userIds)
            ->where('date', '>=', $from)
            ->groupBy('date')
            ->orderBy('date', 'asc')
            ->get();
    }

    /**
     * @param  array<string, mixed>  $delta
     */
    public function applyDelta(array $delta): void
    {
        $this->model->newQuery()->upsert(
            [$delta],
            ['date', 'platform', 'user_id', 'campaign_id', 'tracking_link_id'],
            ['clicks', 'orders', 'approved', 'commission'],
        );
    }

    private function resolvePeriodStart(string $period): string
    {
        return match ($period) {
            '7days'  => now()->subDays(7)->toDateString(),
            '90days' => now()->subDays(90)->toDateString(),
            default  => now()->subDays(30)->toDateString(),
        };
    }
}
