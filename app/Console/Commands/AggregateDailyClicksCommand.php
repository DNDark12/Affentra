<?php

namespace App\Console\Commands;

use App\Jobs\Tracking\AggregateDailyClicksJob;
use Carbon\Carbon;
use Illuminate\Console\Command;

class AggregateDailyClicksCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'analytics:aggregate-clicks
                            {--date= : The specific date to aggregate (Y-m-d)}
                            {--days=1 : Number of past days to aggregate if date is not provided}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Aggregate click metrics into daily_stats table';

    /**
     * Execute the console command.
     */
    public function handle(): void
    {
        $dateOpt = $this->option('date');
        
        if ($dateOpt) {
            $minDate = Carbon::parse($dateOpt)->toDateString();
            $maxDate = $minDate;
        } else {
            $days = (int) $this->option('days');
            $maxDate = now()->toDateString();
            $minDate = now()->subDays($days)->toDateString();
        }

        $this->info("Dispatching AggregateDailyClicksJob from {$minDate} to {$maxDate}");
        
        AggregateDailyClicksJob::dispatchSync($minDate, $maxDate);

        $this->info('Done.');
    }
}
