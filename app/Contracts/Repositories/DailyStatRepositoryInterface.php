<?php

declare(strict_types=1);

namespace App\Contracts\Repositories;

use Illuminate\Support\Collection;
use Prettus\Repository\Contracts\RepositoryInterface;

interface DailyStatRepositoryInterface extends RepositoryInterface
{
    /**
     * Get aggregated KPI totals via SQL SUM().
     *
     * @param  list<int>|null  $userIds  Null means unscoped/global.
     * @return array{clicks: int, orders: int, approved: int, commission: float}
     */
    public function getKpiTotals(?array $userIds, string $period = '30days'): array;

    /**
     * Get daily series data grouped by date (for charts).
     *
     * @param  list<int>|null  $userIds  Null means unscoped/global.
     * @return Collection
     */
    public function getDailySeries(?array $userIds, string $period = '30days'): Collection;

    /**
     * Apply incremental delta from DeltaDetector output.
     *
     * @param  array<string, mixed>  $delta
     */
    public function applyDelta(array $delta): void;
}
