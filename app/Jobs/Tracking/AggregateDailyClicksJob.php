<?php

declare(strict_types=1);

namespace App\Jobs\Tracking;

use App\Models\DailyStat;
use Carbon\Carbon;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class AggregateDailyClicksJob implements ShouldQueue, ShouldBeUnique
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(
        public readonly string $platform,
        public readonly string $minDate,
        public readonly string $maxDate,
    ) {
        $this->onQueue('sync');
        $this->uniqueFor = (int) config('integrations.sync.aggregate_unique_for_seconds', 900);
    }

    public int $uniqueFor = 900;

    public function uniqueId(): string
    {
        return "agg_clicks:{$this->platform}:{$this->minDate}:{$this->maxDate}";
    }

    /**
     * @return array<int, object>
     */
    public function middleware(): array
    {
        $releaseAfter = (int) config('integrations.sync.aggregate_lock_release_after_seconds', 15);
        $expireAfter = (int) config('integrations.sync.aggregate_lock_expire_after_seconds', 300);

        return [
            (new WithoutOverlapping("agg_clicks_platform:{$this->platform}"))
                ->releaseAfter($releaseAfter)
                ->expireAfter($expireAfter),
        ];
    }

    public function handle(): void
    {
        Log::info('Aggregating daily clicks', [
            'platform' => $this->platform,
            'minDate'  => $this->minDate,
            'maxDate'  => $this->maxDate,
        ]);

        $platformExpr = "COALESCE(t.platform, pc.platform, 'shopee')";
        $linkUserExpr = 'COALESCE(t.user_id, c.ctv_user_id, c.leader_id, c.owner_id)';

        // 1. Reset click metrics for the affected range
        DailyStat::where('platform', $this->platform)
            ->whereBetween('date', [$this->minDate, $this->maxDate])
            ->update([
                'clicks'        => 0,
                'unique_clicks' => 0,
                'valid_clicks'  => 0,
                'bot_clicks'    => 0,
            ]);

        // 2. Fetch grouped aggregates from Clicks
        $clicks = DB::table('clicks as c')
            ->leftJoin('tracking_links as t', 'c.tracking_link_id', '=', 't.id')
            ->leftJoin('platform_connections as pc', 'c.connection_id', '=', 'pc.id')
            ->selectRaw('
                DATE(c.created_at) as stat_date,
                ' . $platformExpr . ' as platform,
                ' . $linkUserExpr . ' as link_user_id,
                t.campaign_id,
                c.tracking_link_id,
                c.owner_id,
                c.leader_id,
                c.ctv_user_id,
                COUNT(c.id) as total_clicks,
                COUNT(DISTINCT c.fingerprint_hash) as unique_clicks,
                COUNT(DISTINCT CASE WHEN c.is_bot = 0 THEN c.fingerprint_hash END) as valid_clicks,
                SUM(c.is_bot) as bot_clicks
            ')
            ->where('c.created_at', '>=', $this->minDate . ' 00:00:00')
            ->where('c.created_at', '<=', Carbon::parse($this->maxDate)->endOfDay()->toDateTimeString())
            ->whereRaw($platformExpr . ' = ?', [$this->platform])
            ->whereRaw($linkUserExpr . ' IS NOT NULL')
            ->groupByRaw('
                DATE(c.created_at), 
                ' . $platformExpr . ',
                ' . $linkUserExpr . ',
                t.campaign_id, 
                c.tracking_link_id, 
                c.owner_id, 
                c.leader_id, 
                c.ctv_user_id
            ')
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

        DB::transaction(function () use ($clicks, $existingStats, &$upsertedCount) {
            foreach ($clicks as $row) {
                // We use link_user_id as the primary identifier mapped to 'user_id' in daily_stats
                // because orders also group by user_id representing the tracking link owner.
                $key = "{$row->stat_date}_{$row->link_user_id}_{$row->campaign_id}_{$row->tracking_link_id}";

                /** @var DailyStat|null $stat */
                $stat = $existingStats->get($key);

                if (! $stat) {
                    $stat = new DailyStat([
                        'date'             => $row->stat_date,
                        'platform'         => $this->platform,
                        'user_id'          => $row->link_user_id,
                        'campaign_id'      => $row->campaign_id,
                        'tracking_link_id' => $row->tracking_link_id,
                    ]);
                }

                // Update scope and click stats
                $stat->owner_id      = $row->owner_id;
                $stat->leader_id     = $row->leader_id;
                $stat->ctv_user_id   = $row->ctv_user_id;
                $stat->clicks        = $row->total_clicks;
                $stat->unique_clicks  = $row->unique_clicks;
                $stat->valid_clicks  = $row->valid_clicks;
                $stat->bot_clicks    = $row->bot_clicks;

                $stat->save();

                $upsertedCount++;
            }
        }, 3);

        Log::info('Completed aggregating daily clicks', [
            'platform' => $this->platform,
            'minDate' => $this->minDate,
            'maxDate' => $this->maxDate,
            'upserted_rows' => $upsertedCount,
        ]);
    }
}
