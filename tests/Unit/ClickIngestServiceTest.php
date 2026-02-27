<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Enums\LinkStatus;
use App\Jobs\Tracking\RecordClickJob;
use App\Models\TrackingLink;
use App\Models\User;
use App\Services\Tracking\ClickIngestService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

class ClickIngestServiceTest extends TestCase
{
    use RefreshDatabase;

    private ClickIngestService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = app(ClickIngestService::class);
    }

    public function test_ingest_dispatches_job_and_returns_url_for_valid_code(): void
    {
        Bus::fake([RecordClickJob::class]);

        $user = User::factory()->create();
        $link = TrackingLink::create([
            'user_id'         => $user->id,
            'campaign_id'     => null,
            'short_code'      => 'testcode',
            'destination_url' => 'https://example.com/dest',
            'status'          => LinkStatus::Active,
            'sub_id'          => 'sub123',
        ]);

        $request = Request::create('/go/testcode', 'GET', [], [], [], [
            'REMOTE_ADDR'     => '192.168.1.1',
            'HTTP_USER_AGENT' => 'TestAgent',
            'HTTP_REFERER'    => 'https://referrer.com',
        ]);

        $url = $this->service->ingest('testcode', $request);

        $this->assertEquals('https://example.com/dest', $url);

        // Assert the job was dispatched (async click recording)
        Bus::assertDispatched(RecordClickJob::class, function (RecordClickJob $job) use ($link) {
            return true; // Job was dispatched = click will be recorded
        });

        // Check if cache is populated
        $this->assertTrue(Cache::has('short_code:testcode'));
    }

    public function test_ingest_returns_null_for_invalid_code(): void
    {
        Bus::fake([RecordClickJob::class]);

        $request = Request::create('/go/invalid', 'GET');

        $url = $this->service->ingest('invalid', $request);

        $this->assertNull($url);

        // No job should be dispatched for invalid codes
        Bus::assertNotDispatched(RecordClickJob::class);
    }
}
