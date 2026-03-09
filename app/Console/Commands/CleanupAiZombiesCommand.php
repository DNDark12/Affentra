<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\ContentGeneration;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;

class CleanupAiZombiesCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'ai:cleanup-zombies {--hours=4 : Keep records younger than this}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Mark stale "running" content generations as "failed".';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $hours = (int) $this->option('hours');
        $cutoff = Carbon::now()->subHours($hours);

        $this->info("Cleaning up AI zombies older than {$hours} hours (Cutoff: {$cutoff->toDateTimeString()})...");

        // Find tasks stuck in 'running' or 'queued' for too long
        $staleTasks = ContentGeneration::whereIn('status', ['running', 'queued'])
            ->where('updated_at', '<', $cutoff)
            ->get();

        if ($staleTasks->isEmpty()) {
            $this->info("No zombie tasks found.");
            return 0;
        }

        $count = $staleTasks->count();
        $this->info("Found {$count} stale task(s). Marking as failed...");

        foreach ($staleTasks as $task) {
            $task->update([
                'status'         => 'failed',
                'error_payload'  => [
                    'message'    => "Task timed out after being stale for more than {$hours} hours.",
                    'cleaned_at' => now()->toIso8601String(),
                ],
            ]);

            Log::warning('ai.cleanup_zombies.marked_failed', [
                'generation_id' => $task->id,
                'last_updated'  => $task->updated_at->toIso8601String(),
            ]);
        }

        $this->info("Successfully cleaned up {$count} task(s).");

        return 0;
    }
}
