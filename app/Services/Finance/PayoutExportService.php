<?php

declare(strict_types=1);

namespace App\Services\Finance;

use App\Enums\PayoutBatchStatus;
use App\Models\AffiliatePayout;
use App\Models\PayoutBatch;
use App\Models\User;
use App\Models\UserProfile;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

class PayoutExportService
{
    public const FORMATS = ['generic', 'vietcombank', 'techcombank'];

    /**
     * Generate a CSV StreamedResponse for the given batch and format.
     *
     * @param  'generic'|'vietcombank'|'techcombank'  $format
     */
    public function generateCsv(PayoutBatch $batch, string $format, User $actor): StreamedResponse
    {
        if (! $batch->status->canExport()) {
            throw new ConflictHttpException('Chỉ có thể xuất batch đã được chốt.');
        }

        $needsDecryptedBanking = in_array($format, ['vietcombank', 'techcombank'], true);

        // Load payouts with user and optionally their profile for decrypted bank data
        $with = $needsDecryptedBanking
            ? ['user:id,name,email', 'user.profile']
            : ['user:id,name,email'];

        $payouts = AffiliatePayout::query()
            ->with($with)
            ->where('payout_batch_id', $batch->id)
            ->latest('payout_at')
            ->get();

        $filename = $this->buildFilename($batch, $format);
        $headers  = $this->buildHeaders($format, $filename);
        $columns  = $this->resolveColumns($format);

        return new StreamedResponse(function () use ($payouts, $columns, $batch, $format): void {
            $handle = fopen('php://output', 'w');
            if ($handle === false) {
                return;
            }

            // UTF-8 BOM for Excel compatibility
            fwrite($handle, "\xEF\xBB\xBF");

            // Header row
            fputcsv($handle, $columns, ',');

            $index = 1;
            foreach ($payouts as $payout) {
                /** @var AffiliatePayout $payout */
                $row = $this->buildRow($payout, $format, $index, $batch);
                fputcsv($handle, $row, ',');
                $index++;
            }

            fclose($handle);
        }, 200, $headers);
    }

    /**
     * @return array<string>
     */
    private function resolveColumns(string $format): array
    {
        return match ($format) {
            'vietcombank' => ['STT', 'Số TK người nhận', 'Tên người nhận', 'Ngân hàng người nhận', 'Số tiền', 'Nội dung chuyển khoản'],
            'techcombank' => ['STT', 'Số tài khoản', 'Tên tài khoản', 'Mã ngân hàng', 'Chi nhánh', 'Số tiền', 'Nội dung'],
            default       => ['STT', 'Mã Payout', 'Partner', 'Email', 'Số tiền (VNĐ)', 'Ngân hàng', 'Số TK (ẩn)', 'Ngày payout'],
        };
    }

    /**
     * @return list<string>
     */
    private function buildRow(AffiliatePayout $payout, string $format, int $index, PayoutBatch $batch): array
    {
        $user    = $payout->user;
        $profile = $user?->profile instanceof UserProfile ? $user->profile : null;

        $fullAccountNumber = $profile?->bank_account_number ?? 'CHƯA CẬP NHẬT';
        $accountName       = $profile?->bank_account_name   ?? 'CHƯA CẬP NHẬT';
        $bankCode          = $profile?->bank_code            ?? 'CHƯA CẬP NHẬT';
        $bankName          = $profile?->bank_name            ?? $payout->bank_name ?? 'CHƯA CẬP NHẬT';
        $amount            = number_format((float) $payout->amount, 0, '.', '');
        $content           = $batch->batch_no . ' - ' . ($user?->name ?? '');

        return match ($format) {
            'vietcombank' => [
                (string) $index,
                $fullAccountNumber,
                $accountName,
                $bankName,
                $amount,
                $content,
            ],
            'techcombank' => [
                (string) $index,
                $fullAccountNumber,
                $accountName,
                $bankCode,
                '', // Chi nhánh — blank, user fills in
                $amount,
                $content,
            ],
            default => [
                (string) $index,
                (string) ($payout->payout_id ?? ''),
                (string) ($user?->name ?? '—'),
                (string) ($user?->email ?? '—'),
                $amount,
                (string) ($payout->bank_name ?? '—'),
                (string) ($payout->account_number_masked ?? '—'),
                (string) ($payout->payout_at?->format('d/m/Y') ?? '—'),
            ],
        };
    }

    private function buildFilename(PayoutBatch $batch, string $format): string
    {
        $suffix = match ($format) {
            'vietcombank' => 'VCB',
            'techcombank' => 'TCB',
            default       => 'GENERIC',
        };

        return sprintf('%s_%s_%s.csv', $batch->batch_no, $suffix, now()->format('Ymd'));
    }

    /**
     * @return array<string, string>
     */
    private function buildHeaders(string $format, string $filename): array
    {
        return [
            'Content-Type'        => 'text/csv; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="' . $filename . '"',
            'Cache-Control'       => 'no-store, no-cache',
            'Pragma'              => 'no-cache',
            'X-Robots-Tag'        => 'noindex, nofollow',
        ];
    }
}
