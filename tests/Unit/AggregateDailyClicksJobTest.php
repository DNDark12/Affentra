<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Jobs\Tracking\AggregateDailyClicksJob;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Tests\TestCase;

class AggregateDailyClicksJobTest extends TestCase
{
    public function test_unique_key_and_lock_settings_are_configurable(): void
    {
        config()->set('integrations.sync.aggregate_unique_for_seconds', 777);
        config()->set('integrations.sync.aggregate_lock_release_after_seconds', 19);
        config()->set('integrations.sync.aggregate_lock_expire_after_seconds', 333);

        $job = new AggregateDailyClicksJob('shopee', '2026-02-20', '2026-02-28');

        $this->assertSame('agg_clicks:shopee:2026-02-20:2026-02-28', $job->uniqueId());
        $this->assertSame(777, $job->uniqueFor);
        $this->assertNull($job->queue);

        $middleware = $job->middleware();
        $this->assertCount(1, $middleware);
        $this->assertInstanceOf(WithoutOverlapping::class, $middleware[0]);

        /** @var WithoutOverlapping $lock */
        $lock = $middleware[0];
        $this->assertSame('agg_clicks_platform:shopee', $lock->key);
        $this->assertSame(19, $lock->releaseAfter);
        $this->assertSame(333, $lock->expiresAfter);
    }
}
