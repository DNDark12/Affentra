<?php

declare(strict_types=1);

namespace App\Repositories\Clicks;

use App\DTOs\Clicks\ClickReportFilter;
use App\Models\DailyStat;
use App\Models\Click;
use App\Models\Order;
use App\Models\User;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;

class ClickAnalyticsRepository
{
    public function __construct()
    {
    }

    public function getSummary(ClickReportFilter $filter, User $actor): array
    {
        $query = DailyStat::query();
        
        // Scope by actor
        if ($actor->role === 'leader') {
            $query->where('leader_id', $actor->id);
        } elseif ($actor->role === 'ctv') {
            $query->where('ctv_user_id', $actor->id);
        }

        // Apply filters
        if ($filter->dateFrom) {
            $query->where('date', '>=', $filter->dateFrom);
        }
        if ($filter->dateTo) {
            $query->where('date', '<=', $filter->dateTo);
        }
        // TODO: apply campaign_id, platform, zone_id, etc block if present in DTO

        $stats = $query->selectRaw('
            SUM(clicks) as total_clicks,
            SUM(unique_clicks) as unique_clicks,
            SUM(valid_clicks) as valid_clicks,
            SUM(bot_clicks) as bot_clicks,
            SUM(orders) as total_orders,
            SUM(approved) as total_approved,
            SUM(commission) as total_commission
        ')->first();

        $cvr = $stats->total_clicks > 0 ? ($stats->total_orders / $stats->total_clicks) * 100 : 0;
        $approved_rate = $stats->total_orders > 0 ? ($stats->total_approved / $stats->total_orders) * 100 : 0;
        $epc = $stats->total_clicks > 0 ? ($stats->total_commission / $stats->total_clicks) : 0;

        return [
            'funnel' => [
                'clicks'   => (int) $stats->total_clicks,
                'orders'   => (int) $stats->total_orders,
                'approved' => (int) $stats->total_approved,
            ],
            'cvr'           => round($cvr, 2),
            'approved_rate' => round($approved_rate, 2),
            'epc'           => round((float) $epc, 2),
        ];
    }

    public function getReportData(ClickReportFilter $filter, User $actor): LengthAwarePaginator
    {
        $query = Click::query()
            ->with(['trackingLink.campaign', 'user']);

        // Scope by actor
        if ($actor->role === 'leader') {
            $query->where('leader_id', $actor->id);
        } elseif ($actor->role === 'ctv') {
            $query->where('ctv_user_id', $actor->id);
        }

        // Apply filters
        if ($filter->dateFrom) {
            $query->where('created_at', '>=', $filter->dateFrom);
        }
        if ($filter->dateTo) {
            $query->where('created_at', '<=', $filter->dateTo);
        }
        if ($filter->trackingLinkId) {
            $query->where('tracking_link_id', $filter->trackingLinkId);
        }
        if ($filter->isBot !== null) {
            $query->where('is_bot', $filter->isBot);
        }
        if ($filter->searchQuery) {
            $query->where(function ($q) use ($filter) {
                $q->where('id', 'like', "%{$filter->searchQuery}%")
                  ->orWhere('ip_address', 'like', "%{$filter->searchQuery}%");
            });
        }

        return $query->orderBy($filter->sort, $filter->direction)
            ->paginate($filter->perPage, ['*'], 'page', $filter->page);
    }

    public function getConversionData(ClickReportFilter $filter, User $actor): LengthAwarePaginator
    {
        $query = Order::query()
            ->with(['trackingLink.campaign', 'user']);

        // Scope by actor
        if ($actor->role === 'leader') {
            $query->whereHas('user', function ($q) use ($actor) {
                $q->where('id', $actor->id)
                  ->orWhere('parent_id', $actor->id);
            });
        } elseif ($actor->role === 'ctv') {
            $query->where('user_id', $actor->id);
        }

        // Apply filters
        if ($filter->dateFrom) {
            $query->where('ordered_at', '>=', $filter->dateFrom);
        }
        if ($filter->dateTo) {
            $query->where('ordered_at', '<=', $filter->dateTo);
        }
        if ($filter->trackingLinkId) {
            $query->where('tracking_link_id', $filter->trackingLinkId);
        }
        if ($filter->searchQuery) {
            $query->where('order_code', 'like', "%{$filter->searchQuery}%");
        }

        $sortField = $filter->sort === 'created_at' ? 'ordered_at' : $filter->sort;
        
        return $query->orderBy($sortField, $filter->direction)
            ->paginate($filter->perPage, ['*'], 'page', $filter->page);
    }
}
