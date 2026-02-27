<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\LinkStatus;
use App\Jobs\Tracking\RecordClickJob;
use App\Models\TrackingLink;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Tests\TestCase;

class RedirectControllerTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware(\Illuminate\Foundation\Http\Middleware\VerifyCsrfToken::class);
    }

    public function test_redirect_returns_302_and_dispatches_click_job(): void
    {
        Bus::fake([RecordClickJob::class]);

        $user = User::factory()->create();
        $link = TrackingLink::create([
            'user_id'         => $user->id,
            'short_code'      => 'aabbccdd',
            'destination_url' => 'https://example.com/dest',
            'status'          => LinkStatus::Active,
        ]);

        $response = $this->get('/go/aabbccdd');

        $response->assertStatus(302);
        $response->assertRedirect('https://example.com/dest');

        // Click is now recorded asynchronously via job
        Bus::assertDispatched(RecordClickJob::class);
    }

    public function test_invalid_code_returns_404(): void
    {
        Bus::fake([RecordClickJob::class]);

        $response = $this->get('/go/invalid123');

        $response->assertStatus(404);
        Bus::assertNotDispatched(RecordClickJob::class);
    }
}
