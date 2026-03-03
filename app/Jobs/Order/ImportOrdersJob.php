<?php

declare(strict_types=1);

namespace App\Jobs\Order;

use App\Contracts\Repositories\OrderRepositoryInterface;
use App\Models\SyncRun;
use App\Models\User;
use App\Services\Order\OrderService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Http\UploadedFile;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

class ImportOrdersJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries   = 1;   // CSV import should not retry (idempotent upsert handles dupes)
    public int $timeout = 300; // 5 minutes max

    /**
     * @param  string  $filePath   Path relative to storage/app
     * @param  int     $importerId User ID of the importer
     * @param  string  $platform   Target platform (shopee/lazada/tiktok)
     * @param  int     $syncRunId  SyncRun ID for progress tracking
     */
    public function __construct(
        private readonly string $filePath,
        private readonly int $importerId,
        private readonly string $platform,
        private readonly int $syncRunId,
    ) {}

    public function handle(
        OrderService $orderService,
        OrderRepositoryInterface $orderRepository,
    ): void {
        /** @var SyncRun $syncRun */
        $syncRun = SyncRun::findOrFail($this->syncRunId);

        $syncRun->update([
            'status'     => 'running',
            'started_at' => now(),
        ]);

        try {
            $importer = User::findOrFail($this->importerId);

            // Create UploadedFile from stored path
            $fullPath = Storage::disk('local')->path($this->filePath);
            $file     = new UploadedFile($fullPath, basename($fullPath), null, null, true);

            // Stream-parse CSV → structured rows with mapping applied
            $rows = $orderService->parseCsvForImport($file, $importer, $this->platform);

            $syncRun->update(['records_fetched' => count($rows)]);

            if (empty($rows)) {
                $syncRun->update([
                    'status'      => 'completed',
                    'finished_at' => now(),
                    'records_upserted' => 0,
                ]);
                return;
            }

            // Validate rows and separate valid/invalid
            $validRows  = [];
            $errorRows  = [];
            $lineNumber = 2; // Line 1 = header

            $minDate = null;
            $maxDate = null;

            foreach ($rows as $row) {
                $errors = $this->validateRow($row);

                if (! empty($errors)) {
                    $errorRows[] = array_merge(
                        ['line' => $lineNumber, 'errors' => implode('; ', $errors)],
                        array_intersect_key($row, array_flip(['order_code', 'platform'])),
                    );
                } else {
                    $validRows[] = $row;

                    $date = substr($row['ordered_at'], 0, 10);
                    if ($minDate === null || $date < $minDate) {
                        $minDate = $date;
                    }
                    if ($maxDate === null || $date > $maxDate) {
                        $maxDate = $date;
                    }
                }

                $lineNumber++;
            }

            // Upsert valid rows in chunks
            $result = $orderRepository->upsertFromImport($validRows);

            // Write error log if any errors
            $errorLogPath = null;
            if (! empty($errorRows)) {
                $errorLogPath = $this->writeErrorLog($errorRows);
            }

            $syncRun->update([
                'status'           => 'completed',
                'finished_at'      => now(),
                'records_upserted' => $result['upserted'],
                'records_failed'   => count($errorRows) + $result['failed'],
                'error_log_path'   => $errorLogPath,
            ]);

            if ($result['upserted'] > 0 && $minDate && $maxDate) {
                AggregateDailyStatsJob::dispatch($this->platform, $minDate, $maxDate);
            }

            Log::info('Order import completed', [
                'sync_run_id' => $this->syncRunId,
                'fetched'     => count($rows),
                'upserted'    => $result['upserted'],
                'errors'      => count($errorRows),
            ]);

        } catch (\Throwable $e) {
            Log::error('Order import failed', [
                'sync_run_id' => $this->syncRunId,
                'error'       => $e->getMessage(),
            ]);

            $syncRun->update([
                'status'        => 'failed',
                'finished_at'   => now(),
                'error_message' => mb_substr($e->getMessage(), 0, 1000),
            ]);

            throw $e;
        } finally {
            // Cleanup uploaded file
            Storage::disk('local')->delete($this->filePath);
        }
    }

    /**
     * Validate a single mapped row.
     *
     * @return list<string>  List of error messages (empty = valid)
     */
    private function validateRow(array $row): array
    {
        $errors = [];

        if (empty($row['order_code'])) {
            $errors[] = 'Missing order_code';
        }

        if (empty($row['ordered_at'])) {
            $errors[] = 'Missing or invalid ordered_at';
        }

        if (! in_array($row['status'] ?? '', ['pending', 'approved', 'rejected'], true)) {
            $errors[] = 'Invalid status: ' . ($row['status'] ?? '');
        }

        if (($row['order_amount'] ?? 0) < 0) {
            $errors[] = 'Negative order_amount';
        }

        return $errors;
    }

    /**
     * Write row-level errors to JSON log file.
     * Returns the storage-relative path.
     */
    private function writeErrorLog(array $errorRows): string
    {
        $filename = 'imports/errors/' . date('Y-m-d') . '/' . $this->syncRunId . '_errors.json';

        Storage::disk('local')->put(
            $filename,
            json_encode($errorRows, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE),
        );

        return $filename;
    }
}
