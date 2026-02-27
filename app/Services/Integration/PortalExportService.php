<?php

declare(strict_types=1);

namespace App\Services\Integration;

use App\Models\PlatformConnection;
use Illuminate\Http\UploadedFile;
use RuntimeException;

class PortalExportService
{
    /**
     * Store the uploaded portal export file and log a SyncRun.
     *
     * @param string $type "conversion", "click", or "offer"
     */
    public function process(PlatformConnection $connection, string $type, UploadedFile $file): void
    {
        $directory = "portal_exports/{$connection->id}/{$type}";
        $filename = now()->format('Y_m_d_H_i_s') . '_' . $file->getClientOriginalName();
        
        $path = $file->storeAs($directory, $filename, 'local');

        if (! $path) {
            throw new RuntimeException('Failed to store the uploaded portal export file.');
        }

        // Log a SyncRun to indicate file reception (Phase A)
        // In later phases, this file will be parsed via Jobs.
        $connection->syncRuns()->create([
            'type' => 'manual_portal_export',
            'status' => 'completed', 
            'records_fetched' => 0,
            'records_upserted' => 0,
            'records_failed' => 0,
            'error_message' => "Stored {$file->getClientOriginalName()} ($type) for later parsing.",
            'started_at' => now(),
            'finished_at' => now(),
        ]);
        
        $connection->update([
            'last_sync_at' => now(),
            'last_sync_status' => 'completed',
        ]);
    }
}
