<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class CleanOldClicksCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'analytics:clean-clicks {--days=60 : Number of days to retain}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Clean up old click records to manage database size';

    /**
     * Execute the console command.
     */
    public function handle(): void
    {
        $days = (int) $this->option('days');
        $cutoffDate = now()->subDays($days)->toDateTimeString();

        $this->info("Cleaning clicks older than {$cutoffDate}");

        $deletedCount = DB::table('clicks')
            ->where('created_at', '<', $cutoffDate)
            ->delete();

        Log::info('Cleaned old clicks', ['deleted_count' => $deletedCount, 'cutoff_date' => $cutoffDate]);

        $this->info("Deleted {$deletedCount} old clicks.");
    }
}
