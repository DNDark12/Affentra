<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\PlatformConnection;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class PortalExportUploadTest extends TestCase
{
    use RefreshDatabase;

    public function test_portal_export_upload_creates_manual_sync_run_with_details(): void
    {
        Storage::fake('local');

        $owner = User::factory()->create(['role' => 'owner']);
        $connection = PlatformConnection::factory()->create([
            'user_id' => $owner->id,
            'platform' => 'shopee',
            'method' => 'portal_export',
            'status' => 'active',
        ]);

        $file = UploadedFile::fake()->create(
            'AffiliateCommissionReport202603021337.csv',
            24,
            'text/csv'
        );

        $response = $this->actingAs($owner)->post(
            route('api.integrations.portal-export.upload', $connection->id),
            [
                'type' => 'conversion',
                'file' => $file,
            ]
        );

        $response
            ->assertOk()
            ->assertJsonPath('ok', true);

        $this->assertDatabaseHas('sync_runs', [
            'platform_connection_id' => $connection->id,
            'user_id' => $owner->id,
            'integration' => 'shopee',
            'type' => 'manual',
            'status' => 'completed',
        ]);

        $run = $connection->syncRuns()->latest('id')->firstOrFail();
        $details = is_array($run->details) ? $run->details : [];

        $this->assertSame('conversion', $details['modules']['portal_export']['selected_type'] ?? null);
        $this->assertSame('conversion', $details['modules']['portal_export']['detected_type'] ?? null);
        $this->assertSame('AffiliateCommissionReport202603021337.csv', $details['modules']['portal_export']['original_filename'] ?? null);
        $this->assertNotNull($details['modules']['portal_export']['storage_path'] ?? null);
    }
}

