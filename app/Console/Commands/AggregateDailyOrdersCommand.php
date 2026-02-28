<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Jobs\Order\AggregateDailyStatsJob;
use Carbon\Carbon;
use Illuminate\Console\Command;

class AggregateDailyOrdersCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'analytics:aggregate-orders
                            {--platform=shopee : Platform to aggregate}
                            {--date= : Specific date (Y-m-d)}
                            {--days=2 : Number of past days when --date is omitted}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Aggregate order metrics into daily_stats table';

    /**
     * Execute the console command.
     */
    public function handle(): void
    {
        $platform = (string) $this->option('platform');
        $dateOpt = $this->option('date');

        if ($dateOpt) {
            $minDate = Carbon::parse((string) $dateOpt)->toDateString();
            $maxDate = $minDate;
        } else {
            $days = max((int) $this->option('days'), 1);
            $maxDate = now()->toDateString();
            $minDate = now()->subDays($days)->toDateString();
        }

        $this->info("Aggregating orders for {$platform} from {$minDate} to {$maxDate}");

        AggregateDailyStatsJob::dispatchSync(
            platform: $platform,
            minDate: $minDate,
            maxDate: $maxDate,
        );

        $this->info('Done.');
    }
}

