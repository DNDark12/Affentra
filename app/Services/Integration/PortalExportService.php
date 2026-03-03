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
        $originalFilename = $file->getClientOriginalName();
        $filename = now()->format('Y_m_d_H_i_s') . '_' . $originalFilename;
        
        $path = $file->storeAs($directory, $filename, 'local');

        if (! $path) {
            throw new RuntimeException('Failed to store the uploaded portal export file.');
        }

        $detectedType = $this->detectReportTypeByFilename($originalFilename);
        $warnings = [];
        if ($detectedType !== null && $detectedType !== $type) {
            $warnings[] = "Selected type '{$type}' does not match detected filename type '{$detectedType}'.";
        }

        // Log a SyncRun to indicate file reception (Phase A)
        // In later phases, this file will be parsed via Jobs.
        $connection->syncRuns()->create([
            'user_id' => $connection->user_id,
            'integration' => $connection->platform,
            'type' => 'manual',
            'status' => 'completed', 
            'records_fetched' => 0,
            'records_upserted' => 0,
            'records_failed' => 0,
            'error_message' => "Stored {$originalFilename} ({$type}) for later parsing.",
            'details' => [
                'modules' => [
                    'portal_export' => [
                        'status' => 'stored',
                        'selected_type' => $type,
                        'detected_type' => $detectedType,
                        'original_filename' => $originalFilename,
                        'storage_path' => $path,
                    ],
                ],
                'warnings' => $warnings,
            ],
            'started_at' => now(),
            'finished_at' => now(),
        ]);
        
        $connection->update([
            'last_sync_at' => now(),
            'last_sync_status' => 'completed',
        ]);
    }

    private function detectReportTypeByFilename(string $filename): ?string
    {
        $normalized = mb_strtolower(trim($filename));
        if ($normalized === '') {
            return null;
        }

        if (str_contains($normalized, 'affiliatecommissionreport')) {
            return 'conversion';
        }

        if (str_contains($normalized, 'affiliateclickreport')) {
            return 'click';
        }

        if (str_contains($normalized, 'affiliateoffer') || str_contains($normalized, 'offerreport')) {
            return 'offer';
        }

        return null;
    }
}
