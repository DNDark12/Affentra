<?php

declare(strict_types=1);

namespace App\Jobs\Order;

use App\Models\DailyStat;
use App\Models\Order;
use Carbon\Carbon;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class AggregateDailyStatsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(
        public readonly string $platform,
        public readonly string $minDate,
        public readonly string $maxDate,
    ) {}

    public function handle(): void
    {
        Log::info('Aggregating daily stats', [
            'platform' => $this->platform,
            'minDate'  => $this->minDate,
            'maxDate'  => $this->maxDate,
        ]);

        // 1. Reset order metrics for the affected range
        DailyStat::where('platform', $this->platform)
            ->whereBetween('date', [$this->minDate, $this->maxDate])
            ->update([
                'orders'     => 0,
                'approved'   => 0,
                'commission' => 0,
            ]);

        // 2. Fetch grouped aggregates from Orders
        $orders = Order::query()
            ->selectRaw('
                DATE(ordered_at) as stat_date,
                platform,
                user_id,
                campaign_id,
                tracking_link_id,
                COUNT(*) as total_orders,
                SUM(CASE WHEN status = "approved" THEN 1 ELSE 0 END) as total_approved,
                SUM(commission) as total_commission
            ')
            ->where('platform', $this->platform)
            ->where('ordered_at', '>=', $this->minDate . ' 00:00:00')
            ->where('ordered_at', '<=', Carbon::parse($this->maxDate)->endOfDay()->toDateTimeString())
            ->groupByRaw('DATE(ordered_at), platform, user_id, campaign_id, tracking_link_id')
            ->get();

        // 3. Preload existing daily_stats to avoid N+1 SELECTs
        $existingStats = DailyStat::query()
            ->where('platform', $this->platform)
            ->where('date', '>=', $this->minDate)
            ->where('date', '<=', Carbon::parse($this->maxDate)->toDateString())
            ->get()
            ->keyBy(function ($stat) {
                return "{$stat->date}_{$stat->user_id}_{$stat->campaign_id}_{$stat->tracking_link_id}";
            });

        $upsertedCount = 0;
        
        DB::transaction(function () use ($orders, $existingStats, &$upsertedCount) {
            foreach ($orders as $row) {
                $key = "{$row->stat_date}_{$row->user_id}_{$row->campaign_id}_{$row->tracking_link_id}";
                
                /** @var DailyStat|null $stat */
                $stat = $existingStats->get($key);

                if (! $stat) {
                    $stat = new DailyStat([
                        'date'             => $row->stat_date,
                        'platform'         => $row->platform,
                        'user_id'          => $row->user_id,
                        'campaign_id'      => $row->campaign_id,
                        'tracking_link_id' => $row->tracking_link_id,
                    ]);
                }

                $stat->orders     = $row->total_orders;
                $stat->approved   = $row->total_approved;
                $stat->commission = round((float) $row->total_commission, 2);
                $stat->save();

                $upsertedCount++;
            }
        });

        Log::info('Completed aggregating daily stats', [
            'upserted_rows' => $upsertedCount,
        ]);
    }
}
