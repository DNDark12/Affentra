<?php

namespace Tests\Feature;

use App\Models\SyncRun;
use App\Models\TrackingLink;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class OrderImportTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;
    private User $leader;
    private User $ctv;

    protected function setUp(): void
    {
        parent::setUp();
        
        $this->withoutMiddleware(\Illuminate\Foundation\Http\Middleware\ValidateCsrfToken::class);
        $this->withoutMiddleware(\Illuminate\Foundation\Http\Middleware\VerifyCsrfToken::class);

        // Ensure rate limiter is clean for tests
        app(\Illuminate\Cache\RateLimiter::class)->clear('orders.import');

        $this->owner = User::factory()->create([
            'role'   => 'owner',
            'status' => 'active',
        ]);
        
        $this->leader = User::factory()->create([
            'role'      => 'leader',
            'status'    => 'active',
            'parent_id' => $this->owner->id,
            'path'      => current_path($this->owner),
        ]);
        
        $this->ctv = User::factory()->create([
            'role'      => 'ctv',
            'status'    => 'active',
            'parent_id' => $this->leader->id,
            'path'      => current_path($this->leader),
        ]);
    }

    public function test_ctv_cannot_import_orders(): void
    {
        Storage::fake('local');
        $file = UploadedFile::fake()->create('orders.csv', 100, 'text/csv');

        $response = $this->actingAs($this->ctv)->postJson(route('api.orders.import'), [
            'file'     => $file,
            'platform' => 'shopee',
        ]);

        $response->assertStatus(403);
    }

    public function test_owner_can_import_csv_and_updates_database(): void
    {
        Storage::fake('local');
        \Illuminate\Support\Facades\Config::set('queue.default', 'sync');
        
        // Create a tracking link to test mapping
        $link = TrackingLink::create([
            'user_id'         => $this->ctv->id,
            'campaign_id'     => null,
            'sub_id'          => 'SUB123',
            'short_code'      => 'TEST1234',
            'destination_url' => 'https://shopee.vn',
            'status'          => 'active',
        ]);

        $csvContent = "order_code,status,order_amount,commission,ordered_at,approved_at,sub_id\n";
        $csvContent .= "SHP123,pending,100000,10000,2024-01-01 10:00:00,,SUB123\n"; // Valid, maps to CTV
        $csvContent .= "SHP456,approved,200000,20000,2024-01-02 10:00:00,2024-01-03 10:00:00,\n"; // Valid, no sub_id -> orphaned (owner)

        $file = UploadedFile::fake()->createWithContent('orders.csv', $csvContent);

        $response = $this->actingAs($this->owner)->postJson(route('api.orders.import'), [
            'file'     => $file,
            'platform' => 'shopee',
        ]);

        $response->assertStatus(202);
        
        $syncRunId = $response->json('data.sync_run_id');
        $this->assertNotNull($syncRunId);

        // Assert orders were created
        $this->assertDatabaseCount('orders', 2);

        // View mapped order 1 (CTV)
        $this->assertDatabaseHas('orders', [
            'order_code'       => 'SHP123',
            'user_id'          => $this->ctv->id,
            'tracking_link_id' => $link->id,
            'platform'         => 'shopee',
            'status'           => 'pending',
        ]);

        // View mapped order 2 (Owner - orphaned)
        $this->assertDatabaseHas('orders', [
            'order_code' => 'SHP456',
            'user_id'    => $this->owner->id,
            'platform'   => 'shopee',
            'status'     => 'approved',
        ]);

        // Assert sync run status updated
        $this->assertDatabaseHas('sync_runs', [
            'id'               => $syncRunId,
            'status'           => 'completed',
            'records_fetched'  => 2,
            'records_upserted' => 2,
            'records_failed'   => 0,
        ]);
    }

    public function test_leader_can_view_downline_import_status(): void
    {
        $syncRun = SyncRun::create([
            'user_id'                => $this->ctv->id,
            'platform_connection_id' => null,
            'integration'            => 'shopee',
            'type'                   => 'import',
            'status'                 => 'completed',
            'records_fetched'        => 10,
            'records_upserted'       => 9,
            'records_failed'         => 1,
        ]);

        $response = $this->actingAs($this->leader)
            ->getJson(route('api.orders.import.status', ['syncRunId' => $syncRun->id]));

        $response->assertStatus(200)
            ->assertJsonPath('data.status', 'completed');
    }

    public function test_ctv_cannot_view_other_users_import_status(): void
    {
        $otherCtv = User::factory()->create([
            'role'      => 'ctv',
            'status'    => 'active',
            'parent_id' => $this->leader->id,
            'path'      => current_path($this->leader),
        ]);

        $syncRun = SyncRun::create([
            'user_id'                => $otherCtv->id,
            'platform_connection_id' => null,
            'integration'            => 'shopee',
            'type'                   => 'import',
            'status'                 => 'processing',
            'records_fetched'        => 0,
            'records_upserted'       => 0,
            'records_failed'         => 0,
        ]);

        $response = $this->actingAs($this->ctv)
            ->getJson(route('api.orders.import.status', ['syncRunId' => $syncRun->id]));

        $response->assertStatus(404);
    }
}

// Helper to calculate paths for mock users
function current_path(User $u): string {
    return $u->path ? $u->path . '/' . $u->id : (string)$u->id;
}
