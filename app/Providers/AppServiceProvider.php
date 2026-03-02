<?php

namespace App\Providers;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Str;
use Illuminate\Queue\Events\JobProcessed;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Queue\Events\JobFailed;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Catch N+1 queries early in dev/test
        Model::preventLazyLoading(! app()->isProduction());

        Queue::createPayloadUsing(static function (): array {
            if (! app()->bound('request_id')) {
                return [];
            }

            $requestId = (string) app('request_id');

            return $requestId !== '' ? ['request_id' => $requestId] : [];
        });

        Queue::before(static function (JobProcessing $event): void {
            $payload = $event->job->payload();
            $requestId = (string) ($payload['request_id'] ?? Str::uuid());

            app()->instance('request_id', $requestId);
            Log::withContext([
                'request_id' => $requestId,
                'job' => $event->job->resolveName(),
                'queue' => $event->job->getQueue(),
            ]);
        });

        Queue::after(static function (JobProcessed $event): void {
            Log::withoutContext();
        });

        Queue::failing(static function (JobFailed $event): void {
            Log::withoutContext();
        });

        // Log slow queries (> 100ms) in non-production
        if (! app()->isProduction()) {
            DB::listen(function ($query) {
                if ($query->time > 100) {
                    Log::warning('Slow query detected', [
                        'sql'      => $query->sql,
                        'bindings' => $query->bindings,
                        'time_ms'  => $query->time,
                    ]);
                }
            });
        }
    }
}
