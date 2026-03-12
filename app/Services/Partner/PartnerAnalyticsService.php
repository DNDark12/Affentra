<?php

declare(strict_types=1);

namespace App\Services\Partner;

use App\Models\User;
use Carbon\Carbon;
use Carbon\CarbonPeriod;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Collection;
use Illuminate\Pagination\LengthAwarePaginator;

class PartnerAnalyticsService
{
    /**
     * @return array{
     *   totals: array{clicks:int, orders:int, approved_orders:int, rejected_orders:int, commission:float, conversion_rate:float, approved_rate:float, rejected_rate:float},
     *   platforms: list<array{platform:string, clicks:int, orders:int, approved_orders:int, commission:float, conversion_rate:float, connections_count:int}>
     * }
     */
    public function summary(User $partner, ?int $days, ?string $platform): array
    {
        $query = DB::table('daily_stats')->where('user_id', $partner->id);

        if ($days !== null) {
            $query->where('date', '>=', Carbon::today()->subDays($days)->toDateString());
        }

        if ($platform !== null) {
            $query->where('platform', $platform);
        }

        $allStats = $query->get();

        $totals = [
            'clicks' => (int) $allStats->sum('clicks'),
            'orders' => (int) $allStats->sum('orders'),
            'approved_orders' => (int) $allStats->sum('approved'),
            'commission' => (float) $allStats->sum('commission'),
            'rejected_orders' => 0, // We calculate this as orders - approved
        ];
        
        $totals['rejected_orders'] = max(0, $totals['orders'] - $totals['approved_orders']);
        $totals['conversion_rate'] = $totals['clicks'] > 0 ? round($totals['orders'] / $totals['clicks'], 4) : 0.0;
        $totals['approved_rate'] = $totals['orders'] > 0 ? round($totals['approved_orders'] / $totals['orders'], 4) : 0.0;
        $totals['rejected_rate'] = $totals['orders'] > 0 ? round($totals['rejected_orders'] / $totals['orders'], 4) : 0.0;

        $platforms = [];
        $groupedByPlatform = $allStats->groupBy('platform');
        
        // Count connections per platform for this user
        $connectionsCount = DB::table('platform_connections')
            ->where('user_id', $partner->id)
            ->select('platform', DB::raw('COUNT(*) as count'))
            ->groupBy('platform')
            ->pluck('count', 'platform');

        foreach ($groupedByPlatform as $plat => $rows) {
            $pClicks = (int) $rows->sum('clicks');
            $pOrders = (int) $rows->sum('orders');
            $pApproved = (int) $rows->sum('approved');
            $pCommission = (float) $rows->sum('commission');
            
            $platforms[] = [
                'platform' => $plat,
                'clicks' => $pClicks,
                'orders' => $pOrders,
                'approved_orders' => $pApproved,
                'commission' => $pCommission,
                'conversion_rate' => $pClicks > 0 ? round($pOrders / $pClicks, 4) : 0.0,
                'connections_count' => $connectionsCount->get($plat, 0),
            ];
        }

        return [
            'totals' => $totals,
            'platforms' => $platforms,
        ];
    }

    /**
     * @return list<array{date:string, platform:string, platform_connection_id:int|null, clicks:int, orders:int, approved_orders:int, commission:float}>
     */
    public function trend(User $partner, ?int $days = 30, ?string $platform = null, ?int $platformConnectionId = null): array
    {
        $query = DB::table('daily_stats')->where('user_id', $partner->id);

        $startDate = Carbon::today()->subDays(30);
        if ($days !== null) {
            $startDate = Carbon::today()->subDays($days);
        } else {
            // "All" time - get first date or default to 30 days if none
            $oldestStat = DB::table('daily_stats')->where('user_id', $partner->id)->orderBy('date')->first();
            if ($oldestStat) {
                $startDate = Carbon::parse($oldestStat->date);
            }
            
            // Limit to max 1 year for daily resolution to prevent memory issues
            if ($startDate->copy()->addDays(365)->isBefore(Carbon::today())) {
                 $startDate = Carbon::today()->subDays(365); // For phase 12.2, cap at 1 year max for daily points.
            }
        }
        
        $query->where('date', '>=', $startDate->toDateString());

        if ($platform !== null) {
            $query->where('platform', $platform);
        }

        if ($platformConnectionId !== null) {
            $query->where('platform_connection_id', $platformConnectionId);
        }

        // Group by date and platform
        $stats = $query->select(
            'date',
            'platform',
            'platform_connection_id',
            DB::raw('SUM(clicks) as clicks'),
            DB::raw('SUM(orders) as orders'),
            DB::raw('SUM(approved) as approved_orders'),
            DB::raw('SUM(commission) as commission')
        )
        ->groupBy('date', 'platform', 'platform_connection_id')
        ->orderBy('date')
        ->get();

        // Get unique platforms to ensure we fill zeros for all existing platforms in the timeframe
        $existingPlatforms = $stats->pluck('platform')->unique()->toArray();
        if (empty($existingPlatforms) && $platform !== null) {
            $existingPlatforms = [$platform]; // If filtered but no data, still show the filtered platform line
        }

        $result = [];
        $period = CarbonPeriod::create($startDate, Carbon::today());
        $groupedStats = $stats->groupBy('date');

        foreach ($period as $dateObj) {
            $dateStr = $dateObj->toDateString();
            $dateStats = $groupedStats->get($dateStr, collect());

            foreach ($existingPlatforms as $platStr) {
                 $platStats = $dateStats->where('platform', $platStr);

                 if ($platStats->isEmpty()) {
                     $result[] = [
                         'date' => $dateStr,
                         'platform' => $platStr,
                         'platform_connection_id' => $platformConnectionId,
                         'clicks' => 0,
                         'orders' => 0,
                         'approved_orders' => 0,
                         'commission' => 0.0,
                     ];
                 } else {
                     $result[] = [
                         'date' => $dateStr,
                         'platform' => $platStr,
                         'platform_connection_id' => $platStats->first()->platform_connection_id,
                         'clicks' => (int) $platStats->sum('clicks'),
                         'orders' => (int) $platStats->sum('orders'),
                         'approved_orders' => (int) $platStats->sum('approved_orders'),
                         'commission' => (float) $platStats->sum('commission'),
                     ];
                 }
            }
        }

        return $result;
    }

    /**
     * @return LengthAwarePaginator
     */
    public function topTrackingLinks(User $partner, int $perPage = 15, ?string $platform = null): LengthAwarePaginator
    {
        $query = DB::table('daily_stats')
            ->where('daily_stats.user_id', $partner->id)
            ->whereNotNull('daily_stats.tracking_link_id');

        if ($platform !== null) {
            $query->where('daily_stats.platform', $platform);
        }

        $query->join('tracking_links', 'daily_stats.tracking_link_id', '=', 'tracking_links.id')
            ->select(
                'tracking_links.id',
                'tracking_links.sub_id',
                'daily_stats.platform',
                DB::raw('SUM(daily_stats.clicks) as clicks'),
                DB::raw('SUM(daily_stats.orders) as orders'),
                DB::raw('SUM(daily_stats.approved) as approved_orders'),
                DB::raw('SUM(daily_stats.commission) as approved_commission')
            )
            ->groupBy('tracking_links.id', 'tracking_links.sub_id', 'daily_stats.platform')
            ->orderByDesc('approved_commission')
            ->orderByDesc('orders')
            ->orderByDesc('clicks');

        return $query->paginate($perPage);
    }
}
